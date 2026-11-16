<?php
/**
 * WiFiCare CDN — Tahap 15: verifikasi platform AI (multi-provider, monitoring,
 * batas harian, FAQ, dan endpoint bersihkan percakapan).
 *
 * Berjalan terhadap server uji (DB: wificare_cdn_test) di http://localhost:8099.
 * Jalankan server dengan:
 *   $env:DB_NAME='wificare_cdn_test'; $env:APP_ENV='testing'
 *   php -S localhost:8099 -t public public/index.php
 *
 * Status: PASS / FAIL / BLOCKED. BLOCKED hanya untuk verifikasi LIVE yang
 * membutuhkan kredensial provider nyata yang tidak tersedia di lingkungan uji.
 */

declare(strict_types=1);

require_once __DIR__ . '/../tahap10/_config.php';
t10_require_passwords(['T10_PW_ADMIN', 'T10_PW_CS', 'T10_PW_TEK', 'T10_PW_PEL']);

define('T15_BASE', T10_BASE);
define('T15_MARKER', 'UJI-TAHAP15-FAQ-' . date('YmdHis'));

final class T15Sess
{
    private string $jar;
    private int $lastStatus = 0;
    private string $lastBody = '';
    /** @var array<string, string> */
    private array $lastHeaders = [];

    public function __construct(string $name)
    {
        $this->jar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_t15_' . $name . '_' . getmypid() . '.jar';
        @unlink($this->jar);
    }

    /** @return array{status:int, body:string, headers:array<string,string>} */
    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    /** @return array{status:int, body:string, headers:array<string,string>} */
    public function request(string $method, string $path, array $options = []): array
    {
        $ch = curl_init(T15_BASE . $path);
        $headers = [];
        $fields = $options['fields'] ?? null;
        $json = $options['json'] ?? null;

        if (isset($options['headers'])) {
            $headers = array_merge($headers, (array) $options['headers']);
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($json !== null) {
                $headers[] = 'Content-Type: application/json';
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE));
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields ?? []));
            }
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $this->lastStatus = 0;
            $this->lastBody = 'CURL_ERROR: ' . curl_error($ch);
            curl_close($ch);

            return $this->snapshot();
        }

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $this->lastStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $this->lastBody = substr($raw, $headerSize);
        $headerText = substr($raw, 0, $headerSize);
        $this->lastHeaders = [];
        foreach (explode("\r\n", $headerText) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $this->lastHeaders[strtolower(trim($k))] = trim($v);
            }
        }
        curl_close($ch);

        return $this->snapshot();
    }

    public function token(): string
    {
        if (preg_match('/name="csrf-token"\s+content="([a-f0-9]{64})"/', $this->lastBody, $m)) {
            return $m[1];
        }
        if (preg_match('/name="_token"\s+value="([a-f0-9]{64})"/', $this->lastBody, $m)) {
            return $m[1];
        }

        return '';
    }

    public function login(string $username, string $password): bool
    {
        $this->get('/login');
        $token = $this->token();
        $r = $this->request('POST', '/login', [
            'fields' => ['_token' => $token, 'username' => $username, 'password' => $password],
        ]);

        return $r['status'] === 302 && !str_contains($r['headers']['location'] ?? '', '/login');
    }

    /** @return array{status:int, body:string, headers:array<string,string>} */
    private function snapshot(): array
    {
        return ['status' => $this->lastStatus, 'body' => $this->lastBody, 'headers' => $this->lastHeaders];
    }
}

$PASS = 0;
$FAIL = 0;
$BLOCKED = 0;
$FAILED = [];
$BLOCKED_NAMES = [];

function check(string $name, bool $ok, string $detail = ''): void
{
    global $PASS, $FAIL, $FAILED;
    if ($ok) {
        $PASS++;
        echo "PASS  {$name}\n";

        return;
    }
    $FAIL++;
    $FAILED[] = $name . ($detail !== '' ? ' :: ' . $detail : '');
    echo "FAIL  {$name}" . ($detail !== '' ? " :: {$detail}" : '') . "\n";
}

function blocked(string $name, string $reason): void
{
    global $BLOCKED, $BLOCKED_NAMES;
    $BLOCKED++;
    $BLOCKED_NAMES[] = $name . ' :: ' . $reason;
    echo "BLOCKED  {$name} :: {$reason}\n";
}

function adminSave(T15Sess $admin, array $overrides): array
{
    $admin->get('/admin/pengaturan/ai');
    $token = $admin->token();
    $fields = array_merge([
        '_token' => $token,
        'provider' => 'gemini',
        'api_key' => '',
        'model' => 'gemini-2.5-flash',
        'is_enabled' => '1',
        'max_output_tokens' => '1200',
        'timeout_seconds' => '30',
        'rate_limit' => '20',
        'rate_window' => '60',
        'daily_limit' => '100',
        'max_message_length' => '2000',
        'system_instruction' => '',
    ], $overrides);

    return $admin->request('POST', '/admin/pengaturan/ai', ['fields' => $fields]);
}

echo "==== TAHAP 15 — PLATFORM AI (MULTI-PROVIDER, MONITORING, FAQ) ====\n";

$db = new PDO(T10_DB_DSN, T10_DB_USER, T10_DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Mulai bersih agar hasil deterministik (hanya data konfigurasi/uji).
$db->exec('DELETE FROM ai_settings');
$db->exec('DELETE FROM ai_provider_keys');
$db->exec('DELETE FROM ai_usage_log');
$db->exec("DELETE FROM ai_faq WHERE kategori = 'UjiTahap15'");
$db->exec("DELETE FROM audit_log WHERE aksi IN ('pengaturan_ai_disimpan','pengaturan_ai_tes_koneksi','ai_faq_disimpan','ai_faq_status','ai_faq_dihapus')");

$admin = new T15Sess('admin');
$cs = new T15Sess('cs');
$teknisi = new T15Sess('teknisi');
$pelanggan = new T15Sess('pelanggan');
$anon = new T15Sess('anon');

check('login admin', $admin->login('admin.budi', T10_PW_ADMIN));
check('login CS', $cs->login('cs.dewi', T10_PW_CS));
check('login teknisi', $teknisi->login('tek.bayu', T10_PW_TEK));
check('login pelanggan', $pelanggan->login('pppoe.rina', T10_PW_PEL));

try {
    // --- RBAC halaman & FAQ ---------------------------------------------------
    $r = $admin->get('/admin/pengaturan/ai');
    check('admin dapat membuka pengaturan AI', $r['status'] === 200, 'status=' . $r['status']);
    check('UI menyediakan pilihan provider', str_contains($r['body'], 'id="aiProvider"'));
    check('UI menampilkan monitoring penggunaan', str_contains($r['body'], 'Monitoring Penggunaan AI'));
    check('UI menyediakan form FAQ', str_contains($r['body'], 'id="aiFaqForm"'));
    check('UI menyediakan batas harian', str_contains($r['body'], 'id="aiDailyLimit"'));

    foreach (['CS' => $cs, 'Teknisi' => $teknisi, 'Pelanggan' => $pelanggan] as $label => $session) {
        $session->get('/admin/dashboard');
        $r = $session->get('/admin/pengaturan/ai');
        check("{$label} ditolak membuka pengaturan AI (403)", $r['status'] === 403, 'status=' . $r['status']);
    }
    $r = $anon->get('/admin/pengaturan/ai');
    check('anon diarahkan ke login', $r['status'] === 302 && str_contains($r['headers']['location'] ?? '', '/login'));

    // --- Multi-provider & isolasi key ----------------------------------------
    $r = adminSave($admin, ['provider' => 'gemini', 'api_key' => 'GEMINI-UJI-AAAA-1111-2222', 'model' => 'gemini-2.5-flash']);
    check('simpan provider Gemini → redirect', $r['status'] === 302, 'status=' . $r['status']);
    $gemini = $db->query("SELECT * FROM ai_provider_keys WHERE provider = 'gemini'")->fetch();
    check('key Gemini tersimpan terenkripsi', is_array($gemini) && str_starts_with((string) $gemini['api_key_encrypted'], 'v1:'));
    $gemCipher = (string) ($gemini['api_key_encrypted'] ?? '');

    $r = adminSave($admin, ['provider' => 'deepseek', 'api_key' => 'DEEPSEEK-UJI-BBBB-3333-4444', 'model' => 'deepseek-chat']);
    check('simpan provider DeepSeek → redirect', $r['status'] === 302);
    $gemini2 = $db->query("SELECT * FROM ai_provider_keys WHERE provider = 'gemini'")->fetch();
    check('key Gemini lama dipertahankan saat pindah provider', (string) ($gemini2['api_key_encrypted'] ?? '') === $gemCipher);
    $deepseek = $db->query("SELECT * FROM ai_provider_keys WHERE provider = 'deepseek'")->fetch();
    check('key DeepSeek terenkripsi dan berbeda dari Gemini', is_array($deepseek)
        && str_starts_with((string) $deepseek['api_key_encrypted'], 'v1:')
        && $deepseek['api_key_encrypted'] !== $gemCipher);

    $r = adminSave($admin, ['provider' => 'openai', 'api_key' => 'OPENAI-UJI-CCCC-5555-6666', 'model' => 'gpt-4o-mini']);
    check('simpan provider OpenAI → redirect', $r['status'] === 302);
    $active = $db->query('SELECT provider, model FROM ai_settings')->fetch();
    check('provider aktif menjadi OpenAI', ($active['provider'] ?? '') === 'openai');
    check('model aktif tersimpan', ($active['model'] ?? '') === 'gpt-4o-mini');

    $r = adminSave($admin, ['provider' => 'gemini', 'api_key' => '', 'model' => 'gemini-2.5-pro']);
    $gemini3 = $db->query("SELECT * FROM ai_provider_keys WHERE provider = 'gemini'")->fetch();
    check('kembali ke Gemini tanpa key baru → key lama tetap', (string) ($gemini3['api_key_encrypted'] ?? '') === $gemCipher);
    $countKeys = (int) $db->query('SELECT COUNT(*) FROM ai_provider_keys')->fetchColumn();
    check('tiga provider memiliki key masing-masing', $countKeys === 3, 'count=' . $countKeys);

    $r = $admin->get('/admin/pengaturan/ai');
    check('halaman menampilkan tiga provider', str_contains($r['body'], 'Google Gemini')
        && str_contains($r['body'], 'DeepSeek') && str_contains($r['body'], 'OpenAI'));
    check('halaman tidak membocorkan API key uji', !str_contains($r['body'], 'GEMINI-UJI-AAAA')
        && !str_contains($r['body'], 'DEEPSEEK-UJI-BBBB') && !str_contains($r['body'], 'OPENAI-UJI-CCCC'));
    check('halaman tidak membocorkan ciphertext', !str_contains($r['body'], 'v1:'));

    // --- Tes koneksi nyata (hasil aman) --------------------------------------
    $admin->get('/admin/pengaturan/ai');
    $token = $admin->token();
    $r = $admin->request('POST', '/admin/pengaturan/ai/test', [
        'json' => ['_token' => $token, 'provider' => 'gemini', 'model' => 'gemini-2.5-flash', 'api_key' => 'INVALID-GEMINI-KEY-9999'],
        'headers' => ['X-CSRF-Token: ' . $token],
    ]);
    $decoded = json_decode($r['body'], true);
    check('tes Gemini dengan key tidak valid dieksekusi & aman', $r['status'] === 200
        && is_array($decoded) && ($decoded['ok'] ?? null) === false
        && !str_contains($r['body'], 'INVALID-GEMINI-KEY-9999'));

    $r = $admin->request('POST', '/admin/pengaturan/ai/test', [
        'json' => ['_token' => $token, 'provider' => 'deepseek', 'model' => 'deepseek-chat'],
        'headers' => ['X-CSRF-Token: ' . $token],
    ]);
    $decoded = json_decode($r['body'], true);
    check('tes DeepSeek memakai key tersimpan & respons aman', $r['status'] === 200
        && is_array($decoded) && array_key_exists('ok', $decoded)
        && !str_contains($r['body'], 'DEEPSEEK-UJI-BBBB'));
    if (is_array($decoded) && ($decoded['ok'] ?? false) === true) {
        blocked('verifikasi LIVE DeepSeek berhasil', 'kredensial DeepSeek nyata tidak tersedia; hasil tes memakai key uji');
    } else {
        blocked('verifikasi LIVE DeepSeek berhasil', 'kredensial nyata tidak tersedia (kode aman: ' . (string) ($decoded['code'] ?? '?') . ')');
        check('kegagalan DeepSeek terklasifikasi tanpa membocorkan key', in_array((string) ($decoded['code'] ?? ''), ['invalid_key', 'network', 'timeout', 'server', 'quota', 'model_unavailable', 'error'], true));
    }

    $r = $admin->request('POST', '/admin/pengaturan/ai/test', [
        'json' => ['_token' => $token, 'provider' => 'openai', 'model' => 'gpt-4o-mini'],
        'headers' => ['X-CSRF-Token: ' . $token],
    ]);
    $decoded = json_decode($r['body'], true);
    check('tes OpenAI memakai key tersimpan & respons aman', $r['status'] === 200
        && is_array($decoded) && array_key_exists('ok', $decoded)
        && !str_contains($r['body'], 'OPENAI-UJI-CCCC'));
    if (is_array($decoded) && ($decoded['ok'] ?? false) === true) {
        blocked('verifikasi LIVE OpenAI berhasil', 'kredensial OpenAI nyata tidak tersedia; hasil tes memakai key uji');
    } else {
        blocked('verifikasi LIVE OpenAI berhasil', 'kredensial nyata tidak tersedia (kode aman: ' . (string) ($decoded['code'] ?? '?') . ')');
        check('kegagalan OpenAI terklasifikasi tanpa membocorkan key', in_array((string) ($decoded['code'] ?? ''), ['invalid_key', 'network', 'timeout', 'server', 'quota', 'model_unavailable', 'error'], true));
    }

    // --- Monitoring metadata --------------------------------------------------
    $columns = $db->query('SHOW COLUMNS FROM ai_usage_log')->fetchAll(PDO::FETCH_COLUMN);
    check('log penggunaan tidak menyimpan isi percakapan', !in_array('message', $columns, true)
        && !in_array('reply', $columns, true) && !in_array('content', $columns, true)
        && !in_array('api_key', $columns, true));
    $testRows = (int) $db->query("SELECT COUNT(*) FROM ai_usage_log WHERE request_type = 'test'")->fetchColumn();
    check('tes koneksi tercatat pada monitoring', $testRows >= 3, 'rows=' . $testRows);
    $auditTest = (int) $db->query("SELECT COUNT(*) FROM audit_log WHERE aksi = 'pengaturan_ai_tes_koneksi'")->fetchColumn();
    check('audit tes koneksi tercatat', $auditTest >= 3, 'rows=' . $auditTest);

    // --- Batas harian server-side --------------------------------------------
    $r = adminSave($admin, ['provider' => 'gemini', 'api_key' => '', 'model' => 'gemini-2.5-flash', 'daily_limit' => '1']);
    check('batas harian dapat diubah Admin', $r['status'] === 302);

    $pelanggan->get('/ai');
    $chatToken = $pelanggan->token();
    check('token CSRF widget AI diperoleh', $chatToken !== '');
    $r = $pelanggan->request('POST', '/ai/chat', [
        'json' => ['_token' => $chatToken, 'message' => 'Berapa tiket aktif saya?'],
        'headers' => ['X-CSRF-Token: ' . $chatToken],
    ]);
    $first = json_decode($r['body'], true);
    check('permintaan chat pertama dieksekusi (bukan batas harian)', $r['status'] === 200
        && is_array($first) && (($first['code'] ?? '') !== 'daily_limit'), 'status=' . $r['status'] . ' body=' . substr($r['body'], 0, 120));

    $chatRows = (int) $db->query("SELECT COUNT(*) FROM ai_usage_log WHERE request_type = 'chat'")->fetchColumn();
    check('chat tercatat pada log penggunaan', $chatRows >= 1, 'rows=' . $chatRows);

    $r = $pelanggan->request('POST', '/ai/chat', [
        'json' => ['_token' => $chatToken, 'message' => 'Sekali lagi, berapa tiket aktif saya?'],
        'headers' => ['X-CSRF-Token: ' . $chatToken],
    ]);
    $second = json_decode($r['body'], true);
    check('permintaan kedua ditolak batas harian (429)', $r['status'] === 429, 'status=' . $r['status']);
    check('kode batas harian jelas', is_array($second) && ($second['code'] ?? '') === 'daily_limit');
    $chatRows2 = (int) $db->query("SELECT COUNT(*) FROM ai_usage_log WHERE request_type = 'chat'")->fetchColumn();
    check('permintaan yang ditolak tidak menambah log chat', $chatRows2 === $chatRows, "before={$chatRows} after={$chatRows2}");

    $r = $admin->get('/admin/pengaturan/ai');
    check('monitoring menampilkan request hari ini', str_contains($r['body'], 'Request hari ini'));
    check('monitoring menampilkan penggunaan per pengguna', str_contains($r['body'], 'Penggunaan per pengguna')
        && str_contains($r['body'], 'Rina Marlina'));

    // --- Chatbot dinonaktifkan (tanpa jawaban palsu) -------------------------
    $r = adminSave($admin, ['provider' => 'gemini', 'api_key' => '', 'is_enabled' => '0', 'daily_limit' => '100']);
    check('Admin dapat menonaktifkan chatbot', $r['status'] === 302
        && (int) $db->query('SELECT is_enabled FROM ai_settings')->fetchColumn() === 0);
    $pelanggan->get('/ai');
    $disabledToken = $pelanggan->token();
    $r = $pelanggan->request('POST', '/ai/chat', [
        'json' => ['_token' => $disabledToken, 'message' => 'Apakah AI aktif?'],
        'headers' => ['X-CSRF-Token: ' . $disabledToken],
    ]);
    $disabled = json_decode($r['body'], true);
    check('chat saat nonaktif ditolak dengan kode disabled', $r['status'] === 200
        && is_array($disabled) && ($disabled['code'] ?? '') === 'disabled');
    check('pesan nonaktif jelas & bukan jawaban palsu', str_contains((string) ($disabled['error'] ?? ''), 'dinonaktifkan'));
    adminSave($admin, ['provider' => 'gemini', 'api_key' => '', 'is_enabled' => '1', 'daily_limit' => '100']);

    // --- Bersihkan percakapan (session-only) ---------------------------------
    $pelanggan->get('/ai');
    $clearToken = $pelanggan->token();
    $r = $pelanggan->request('POST', '/ai/clear', [
        'json' => ['_token' => $clearToken],
        'headers' => ['X-CSRF-Token: ' . $clearToken],
    ]);
    check('endpoint bersihkan percakapan berhasil', $r['status'] === 200 && (json_decode($r['body'], true)['ok'] ?? false) === true, 'status=' . $r['status']);
    $r = $pelanggan->request('POST', '/ai/clear', ['json' => ['message' => 'x']]);
    check('bersihkan percakapan menolak tanpa CSRF (419)', $r['status'] === 419, 'status=' . $r['status']);
    $anon->get('/login');
    $r = $anon->request('POST', '/ai/clear', ['json' => []]);
    check('bersihkan percakapan menolak tanpa login (401/302)', in_array($r['status'], [401, 302], true), 'status=' . $r['status']);
    $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    check('tidak ada tabel penyimpanan percakapan permanen', !in_array('ai_conversations', $tables, true)
        && !in_array('ai_chat_history', $tables, true));

    // --- FAQ CRUD -------------------------------------------------------------
    $cs->get('/admin/dashboard');
    $csToken = $cs->token();
    $r = $cs->request('POST', '/admin/pengaturan/ai/faq', [
        'fields' => ['_token' => $csToken, 'pertanyaan' => 'CS tidak boleh', 'jawaban' => 'x', 'kategori' => 'UjiTahap15'],
    ]);
    check('CS tidak boleh menambah FAQ (403)', $r['status'] === 403, 'status=' . $r['status']);

    $admin->get('/admin/pengaturan/ai');
    $adminToken = $admin->token();
    $r = $admin->request('POST', '/admin/pengaturan/ai/faq', [
        'fields' => [
            '_token' => $adminToken,
            'pertanyaan' => 'Apa itu ' . T15_MARKER . '?',
            'jawaban' => 'Jawaban resmi untuk ' . T15_MARKER . '.',
            'kategori' => 'UjiTahap15',
            'urutan' => '99',
            'is_active' => '1',
        ],
    ]);
    check('admin menambah FAQ → redirect', $r['status'] === 302, 'status=' . $r['status']);
    $faq = $db->query("SELECT * FROM ai_faq WHERE pertanyaan LIKE '%" . T15_MARKER . "%'")->fetch();
    check('FAQ tersimpan di database', is_array($faq) && (int) $faq['is_active'] === 1);
    $faqId = (int) ($faq['id'] ?? 0);

    $r = $admin->get('/admin/pengaturan/ai?q=' . urlencode(T15_MARKER));
    check('FAQ dapat dicari', str_contains($r['body'], T15_MARKER));
    check('FAQ tampil pada halaman admin', str_contains($r['body'], htmlspecialchars('Apa itu ' . T15_MARKER . '?', ENT_QUOTES)) || str_contains($r['body'], T15_MARKER));

    $admin->get('/admin/pengaturan/ai');
    $adminToken = $admin->token();
    $r = $admin->request('POST', '/admin/pengaturan/ai/faq/status', [
        'fields' => ['_token' => $adminToken, 'id' => (string) $faqId, 'is_active' => '0'],
    ]);
    check('FAQ dapat dinonaktifkan', $r['status'] === 302
        && (int) $db->query('SELECT is_active FROM ai_faq WHERE id = ' . $faqId)->fetchColumn() === 0);

    $admin->get('/admin/pengaturan/ai');
    $adminToken = $admin->token();
    $r = $admin->request('POST', '/admin/pengaturan/ai/faq', [
        'fields' => [
            '_token' => $adminToken,
            'id' => (string) $faqId,
            'pertanyaan' => 'Pertanyaan diperbarui ' . T15_MARKER,
            'jawaban' => 'Jawaban diperbarui.',
            'kategori' => 'UjiTahap15',
            'urutan' => '98',
            'is_active' => '0',
        ],
    ]);
    check('FAQ dapat diedit', $r['status'] === 302
        && str_contains((string) $db->query('SELECT pertanyaan FROM ai_faq WHERE id = ' . $faqId)->fetchColumn(), 'diperbarui'));

    $admin->get('/admin/pengaturan/ai');
    $adminToken = $admin->token();
    $beforeCount = (int) $db->query("SELECT COUNT(*) FROM ai_faq WHERE kategori = 'UjiTahap15'")->fetchColumn();
    $r = $admin->request('POST', '/admin/pengaturan/ai/faq', [
        'fields' => ['_token' => $adminToken, 'pertanyaan' => '', 'jawaban' => '', 'kategori' => 'UjiTahap15'],
    ]);
    $afterCount = (int) $db->query("SELECT COUNT(*) FROM ai_faq WHERE kategori = 'UjiTahap15'")->fetchColumn();
    check('FAQ kosong ditolak validasi', $r['status'] === 302 && $afterCount === $beforeCount);

    $admin->get('/admin/pengaturan/ai');
    $adminToken = $admin->token();
    $r = $admin->request('POST', '/admin/pengaturan/ai/faq/hapus', [
        'fields' => ['_token' => $adminToken, 'id' => (string) $faqId],
    ]);
    check('FAQ dapat dihapus', $r['status'] === 302
        && (int) $db->query('SELECT COUNT(*) FROM ai_faq WHERE id = ' . $faqId)->fetchColumn() === 0);

    $auditFaq = (int) $db->query("SELECT COUNT(*) FROM audit_log WHERE aksi IN ('ai_faq_disimpan','ai_faq_status','ai_faq_dihapus')")->fetchColumn();
    check('aktivitas FAQ tercatat pada audit log', $auditFaq >= 3, 'rows=' . $auditFaq);
    $auditLeak = (string) $db->query("SELECT COALESCE(GROUP_CONCAT(metadata SEPARATOR ' '), '') FROM audit_log WHERE aksi LIKE 'ai_faq_%' OR aksi LIKE 'pengaturan_ai_%'")->fetchColumn();
    check('audit log tidak memuat API key/ciphertext', !str_contains($auditLeak, 'GEMINI-UJI') && !str_contains($auditLeak, 'v1:'));

    // --- UI widget & renderer -------------------------------------------------
    $pelanggan->get('/ai');
    check('widget memiliki tombol bersihkan', str_contains($pelanggan->get('/ai')['body'], 'data-aiw-clear'));
    $js = (string) @file_get_contents(__DIR__ . '/../../public/assets/js/ai-widget.js');
    check('JS widget mendukung salin jawaban', str_contains($js, 'attachCopy') && str_contains($js, 'data-aiw-copy'));
    check('JS widget mendukung bersihkan + endpoint', str_contains($js, 'data-clear-endpoint') && str_contains($js, 'clearEndpoint'));
    $renderJs = (string) @file_get_contents(__DIR__ . '/../../public/assets/js/ai-render.js');
    check('renderer tidak memakai innerHTML untuk teks AI', preg_match('/\.innerHTML\s*=/', $renderJs) !== 1);
    $css = (string) @file_get_contents(__DIR__ . '/../../public/assets/css/style.css');
    check('CSS menyediakan gaya tombol salin', str_contains($css, '.aiw-copy'));
} finally {
    // Kembalikan DB uji ke kondisi memakai konfigurasi .env (tanpa key uji).
    $db->exec('DELETE FROM ai_settings');
    $db->exec('DELETE FROM ai_provider_keys');
    $db->exec("DELETE FROM ai_faq WHERE kategori = 'UjiTahap15'");
    @unlink(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_t15_admin_' . getmypid() . '.jar');
}

echo "----------------------------------------------------------------\n";
echo 'TOTAL: ' . ($PASS + $FAIL) . " | PASS: {$PASS} | FAIL: {$FAIL} | BLOCKED: {$BLOCKED}\n";
if ($BLOCKED_NAMES !== []) {
    echo "BLOCKED (butuh kredensial/lingkungan nyata):\n";
    foreach ($BLOCKED_NAMES as $name) {
        echo '  - ' . $name . "\n";
    }
}
if ($FAIL > 0) {
    echo "GAGAL:\n";
    foreach ($FAILED as $name) {
        echo '  - ' . $name . "\n";
    }
    exit(1);
}

echo "SEMUA TES PLATFORM AI LULUS (kecuali item BLOCKED di atas).\n";
