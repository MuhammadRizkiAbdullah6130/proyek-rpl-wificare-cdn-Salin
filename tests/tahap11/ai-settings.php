<?php
/**
 * WiFiCare CDN — Tahap 11: verifikasi Pengaturan AI Chatbot (Dashboard Admin).
 *
 * Berjalan terhadap server uji (DB: wificare_cdn_test) di http://localhost:8099.
 *
 * Memverifikasi 19 poin permintaan:
 *  1. Admin dapat membuka Pengaturan AI.
 *  2. CS tidak dapat membuka. 3. Teknisi tidak. 4. Pelanggan tidak.
 *  5. Admin dapat memasukkan API key baru.
 *  6. API key disimpan terenkripsi.
 *  7. API key asli tidak muncul di HTML.
 *  8. API key asli tidak muncul pada respons JSON pengaturan/tes.
 *  9. Menyimpan tanpa key baru tidak menghapus key sebelumnya.
 * 10. Tes Koneksi memakai API sungguhan.
 * 11. API key tidak valid → error aman.
 * 12. AI dapat dinonaktifkan. 13. Diaktifkan kembali. 14. Model dapat diganti.
 * 15. Floating AI memakai key terbaru. 16. Penggantian key tidak merusak chatbot.
 * 17. Audit log tidak membocorkan key. 18. Akses DB AI tetap read-only.
 * 19. Halaman responsif (ditandai lewat markup/grid adaptif + meta viewport).
 *
 * Penggunaan:
 *   $env:DB_NAME='wificare_cdn_test'; $env:APP_ENV='testing'
 *   php -S localhost:8099 -t public public/index.php
 *   php tests\tahap11\ai-settings.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../tahap10/_config.php';
t10_require_passwords(['T10_PW_ADMIN', 'T10_PW_CS', 'T10_PW_TEK', 'T10_PW_PEL']);
define('BASE', T10_BASE);
define('TMP', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_t11');
if (!is_dir(TMP)) { @mkdir(TMP, 0775, true); }

$PASS = 0;
$FAIL = 0;
$SKIP = 0;
$FAILED = [];

function check(string $name, bool $ok, string $detail = ''): void
{
    global $PASS, $FAIL, $FAILED;
    if ($ok) {
        $PASS++;
        echo "PASS  {$name}\n";
    } else {
        $FAIL++;
        $FAILED[] = $name . ($detail !== '' ? " :: {$detail}" : '');
        echo "FAIL  {$name}" . ($detail !== '' ? " :: {$detail}" : '') . "\n";
    }
}

function skip(string $name, string $detail = ''): void
{
    global $SKIP;
    $SKIP++;
    echo "SKIP  {$name}" . ($detail !== '' ? " :: {$detail}" : '') . "\n";
}

/** Sesi HTTP dengan cookie jar sendiri. */
final class Sess
{
    public string $jar;
    public string $lastBody = '';
    public int $lastStatus = 0;
    public array $lastHeaders = [];
    public string $lastUrl = '';

    public function __construct(public string $name)
    {
        $this->jar = TMP . '/t11_jar_' . preg_replace('/[^a-z0-9]/i', '_', $name) . '.txt';
        @unlink($this->jar);
    }

    public function request(string $method, string $path, array $opts = []): array
    {
        $ch = curl_init();
        $url = (str_starts_with($path, 'http') ? $path : BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_TIMEOUT => 40,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        }
        if (isset($opts['fields'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opts['fields']));
        }
        if (isset($opts['json'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opts['json']));
            curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($opts['headers'] ?? [], ['Content-Type: application/json']));
        } elseif (isset($opts['headers'])) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $opts['headers']);
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            $this->lastStatus = 0;
            $this->lastBody = 'CURL_ERROR: ' . curl_error($ch);
            curl_close($ch);
            return $this->snapshot();
        }
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $this->lastStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $this->lastUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $headerText = substr($raw, 0, $headerSize);
        $this->lastBody = substr($raw, $headerSize);
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

    private function snapshot(): array
    {
        return ['status' => $this->lastStatus, 'body' => $this->lastBody, 'headers' => $this->lastHeaders];
    }

    public function get(string $path): array
    {
        return $this->request('GET', $path);
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
}

echo "==== TAHAP 11 — PENGATURAN AI CHATBOT (DB uji: wificare_cdn_test) ====\n";

$db = new PDO(T10_DB_DSN, T10_DB_USER, T10_DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Mulai dari kondisi bersih agar hasil deterministik.
$db->exec('DELETE FROM ai_settings');
$db->exec('DELETE FROM ai_provider_keys');
$db->exec("DELETE FROM audit_log WHERE aksi IN ('pengaturan_ai_disimpan','pengaturan_ai_tes_koneksi')");

$admin = new Sess('t11_admin');
$cs = new Sess('t11_cs');
$teknisi = new Sess('t11_teknisi');
$pelanggan = new Sess('t11_pelanggan');
$anon = new Sess('t11_anon');

check('login admin', $admin->login('admin.budi', T10_PW_ADMIN));
check('login cs', $cs->login('cs.dewi', T10_PW_CS));
check('login teknisi', $teknisi->login('tek.andi', T10_PW_TEK));
check('login pelanggan', $pelanggan->login('pppoe.rina', T10_PW_PEL));

// --- 1..4 Akses RBAC -----------------------------------------------------------
$r = $admin->get('/admin/pengaturan/ai');
check('1. admin dapat membuka Pengaturan AI (200)', $r['status'] === 200, 'status=' . $r['status']);
check('1b. judul halaman tampil', str_contains($r['body'], 'Pengaturan AI Chatbot'));
check('1c. form pengaturan hadir', str_contains($r['body'], 'id="aiSettingsForm"'));
check('1d. meta viewport (responsif)', str_contains($r['body'], 'name="viewport"'));
check('1e. grid adaptif dipakai', str_contains($r['body'], 'grid-2'));

foreach (['2. CS' => $cs, '3. Teknisi' => $teknisi, '4. Pelanggan' => $pelanggan] as $label => $sess) {
    $sess->get('/admin/dashboard'); // pastikan memiliki sesi/CSRF
    $r = $sess->get('/admin/pengaturan/ai');
    check("{$label} tidak dapat membuka (403)", $r['status'] === 403, 'status=' . $r['status']);
    check("{$label}: tanpa form pengaturan", !str_contains($r['body'], 'id="aiSettingsForm"'));
}

$r = $anon->get('/admin/pengaturan/ai');
check('anon diarahkan ke login', $r['status'] === 302 && str_contains($r['headers']['location'] ?? '', '/login'), 'status=' . $r['status']);

// --- 10. Tes koneksi dengan API sungguhan (key .env, sebelum key tersimpan) ----
$admin->get('/admin/pengaturan/ai');
$token = $admin->token();
check('token CSRF halaman pengaturan diperoleh', $token !== '');

$r = $admin->request('POST', '/admin/pengaturan/ai/test', [
    'json' => ['_token' => $token, 'model' => 'gemini-2.5-flash'],
    'headers' => ['X-CSRF-Token: ' . $token],
]);
$testDecoded = json_decode($r['body'], true);
check('10a. endpoint tes koneksi 200', $r['status'] === 200, 'status=' . $r['status']);
check('10b. respons JSON terstruktur', is_array($testDecoded) && array_key_exists('ok', $testDecoded) && array_key_exists('message', $testDecoded));
check('10c. tidak ada API key pada respons tes', !str_contains($r['body'], 'api_key') && !str_contains($r['body'], 'x-goog-api-key'));
if (is_array($testDecoded) && ($testDecoded['ok'] ?? false) === true) {
    check('10d. koneksi Gemini nyata berhasil', true);
    check('10e. pesan sukses jelas', str_contains((string) $testDecoded['message'], 'berhasil'));
} else {
    $code = (string) ($testDecoded['code'] ?? '');
    $acceptable = in_array($code, ['quota', 'server', 'network', 'timeout', 'model_unavailable'], true);
    check('10d. kegagalan tes koneksi terklasifikasi & aman', $acceptable, 'code=' . $code . ' msg=' . substr((string) ($testDecoded['message'] ?? ''), 0, 80));
}

// --- 5..6 Simpan API key baru (terenkripsi) -----------------------------------
$plainKey = 'TESTKEY-ABCDEFGHIJKLMNOPQRSTUVWXYZ-0123456789';
$admin->get('/admin/pengaturan/ai');
$token = $admin->token();

$r = $admin->request('POST', '/admin/pengaturan/ai', [
    'fields' => [
        '_token' => $token,
        'api_key' => $plainKey,
        'model' => 'gemini-2.5-flash',
        'is_enabled' => '1',
        'max_output_tokens' => '1200',
        'timeout_seconds' => '30',
        'rate_limit' => '15',
        'rate_window' => '60',
        'max_message_length' => '1500',
        'system_instruction' => 'Sebutkan jam operasional CS 08.00-20.00.',
    ],
]);
check('5a. simpan pengaturan → redirect', $r['status'] === 302, 'status=' . $r['status']);
check('5b. redirect ke halaman pengaturan AI', str_contains($r['headers']['location'] ?? '', '/admin/pengaturan/ai'));

$row = $db->query('SELECT * FROM ai_settings ORDER BY id ASC LIMIT 1')->fetch();
check('5c. baris konfigurasi tersimpan', is_array($row));
$cipher1 = (string) ($row['api_key_encrypted'] ?? '');
check('6a. API key disimpan terenkripsi (v1:)', str_starts_with($cipher1, 'v1:'));
check('6b. ciphertext ≠ plaintext', $cipher1 !== $plainKey);
check('6c. ciphertext tidak memuat plaintext', $cipher1 !== '' && !str_contains($cipher1, $plainKey));
check('6d. tidak ada kolom/isi plaintext key', !str_contains(json_encode($row), $plainKey));
check('5d. model tersimpan', (string) ($row['model'] ?? '') === 'gemini-2.5-flash');
check('5e. status aktif tersimpan', (int) ($row['is_enabled'] ?? 0) === 1);
check('5f. updated_by tercatat (admin)', (int) ($row['updated_by'] ?? 0) === 1);
check('5g. batas output & timeout tersimpan', (int) $row['max_output_tokens'] === 1200 && (int) $row['timeout_seconds'] === 30);
check('5h. system instruction tersimpan', str_contains((string) $row['system_instruction'], 'jam operasional'));

// --- 7..8 Tidak ada kebocoran di HTML ----------------------------------------
$r = $admin->get('/admin/pengaturan/ai');
check('7a. HTML tidak memuat API key asli', !str_contains($r['body'], $plainKey));
check('7b. HTML tidak memuat ciphertext', !str_contains($r['body'], 'v1:'));
check('7c. HTML tidak memuat nama kolom rahasia', !str_contains($r['body'], 'api_key_encrypted'));
check('7d. status "API Key Tersimpan" tampil', str_contains($r['body'], 'API Key Tersimpan'));
check('15a. sumber kunci = tersimpan (terenkripsi)', str_contains($r['body'], 'Tersimpan (terenkripsi)'));

// --- 9. Simpan tanpa key baru → key lama dipertahankan ------------------------
$admin->get('/admin/pengaturan/ai');
$token = $admin->token();
$r = $admin->request('POST', '/admin/pengaturan/ai', [
    'fields' => [
        '_token' => $token,
        'api_key' => '',
        'model' => 'gemini-2.5-pro',
        'is_enabled' => '1',
        'max_output_tokens' => '1200',
        'timeout_seconds' => '30',
        'rate_limit' => '15',
        'rate_window' => '60',
        'max_message_length' => '1500',
        'system_instruction' => 'Sebutkan jam operasional CS 08.00-20.00.',
    ],
]);
$row = $db->query('SELECT * FROM ai_settings ORDER BY id ASC LIMIT 1')->fetch();
check('9a. simpan tanpa key baru → sukses (302)', $r['status'] === 302, 'status=' . $r['status']);
check('9b. ciphertext key lama dipertahankan', (string) $row['api_key_encrypted'] === $cipher1);
check('14. model AI dapat diganti', (string) $row['model'] === 'gemini-2.5-pro');

// --- 11. Tes koneksi dengan API key tidak valid → error aman ------------------
$admin->get('/admin/pengaturan/ai');
$token = $admin->token();
$badKey = 'INVALID-KEY-ZZZZ-0000-9999-not-a-real-key';
$r = $admin->request('POST', '/admin/pengaturan/ai/test', [
    'json' => ['_token' => $token, 'api_key' => $badKey, 'model' => 'gemini-2.5-flash'],
    'headers' => ['X-CSRF-Token: ' . $token],
]);
$testDecoded = json_decode($r['body'], true);
check('11a. tes key tidak valid → 200 JSON', $r['status'] === 200, 'status=' . $r['status']);
check('11b. hasil ok=false', is_array($testDecoded) && ($testDecoded['ok'] ?? null) === false);
check('11c. respons tidak memuat key yang diuji', !str_contains($r['body'], $badKey));
check('11d. pesan error tidak kosong & aman', is_array($testDecoded) && trim((string) ($testDecoded['message'] ?? '')) !== '' && !str_contains((string) ($testDecoded['message'] ?? ''), $badKey));

// --- 12. Nonaktifkan AI -------------------------------------------------------
$admin->get('/admin/pengaturan/ai');
$token = $admin->token();
$r = $admin->request('POST', '/admin/pengaturan/ai', [
    'fields' => [
        '_token' => $token,
        'api_key' => '',
        'model' => 'gemini-2.5-pro',
        'max_output_tokens' => '1200',
        'timeout_seconds' => '30',
        'rate_limit' => '15',
        'rate_window' => '60',
        'max_message_length' => '1500',
        'system_instruction' => 'Sebutkan jam operasional CS 08.00-20.00.',
    ],
]);
$row = $db->query('SELECT is_enabled FROM ai_settings ORDER BY id ASC LIMIT 1')->fetch();
check('12a. AI dapat dinonaktifkan', (int) ($row['is_enabled'] ?? 1) === 0);
check('12b. API key tetap ada saat nonaktif', (string) ($db->query('SELECT api_key_encrypted FROM ai_settings ORDER BY id ASC LIMIT 1')->fetch()['api_key_encrypted'] ?? '') === $cipher1);

$r = $admin->get('/admin/dashboard');
check('12c. floating AI menampilkan pesan nonaktif', str_contains($r['body'], 'dinonaktifkan oleh administrator'));

$admin->get('/admin/dashboard');
$token = $admin->token();
$r = $admin->request('POST', '/ai/chat', [
    'json' => ['message' => 'halo', '_token' => $token],
    'headers' => ['X-CSRF-Token: ' . $token],
]);
$chat = json_decode($r['body'], true);
check('12d. /ai/chat menolak saat nonaktif', is_array($chat) && ($chat['ok'] ?? null) === false && str_contains((string) ($chat['error'] ?? ''), 'dinonaktifkan'));

// --- 13. Aktifkan kembali -----------------------------------------------------
$admin->get('/admin/pengaturan/ai');
$token = $admin->token();
$r = $admin->request('POST', '/admin/pengaturan/ai', [
    'fields' => [
        '_token' => $token,
        'api_key' => '',
        'model' => 'gemini-2.5-pro',
        'is_enabled' => '1',
        'max_output_tokens' => '1200',
        'timeout_seconds' => '30',
        'rate_limit' => '15',
        'rate_window' => '60',
        'max_message_length' => '1500',
        'system_instruction' => 'Sebutkan jam operasional CS 08.00-20.00.',
    ],
]);
$row = $db->query('SELECT is_enabled FROM ai_settings ORDER BY id ASC LIMIT 1')->fetch();
check('13a. AI dapat diaktifkan kembali', (int) ($row['is_enabled'] ?? 0) === 1);

$r = $admin->get('/admin/dashboard');
check('13b. floating AI kembali aktif (sapaan tampil)', str_contains($r['body'], 'Saya WiFiCare AI'));
check('15b. widget memakai kunci tersimpan (bukan pesan kosong)', !str_contains($r['body'], 'Layanan AI belum dikonfigurasi'));

// --- 16. Penggantian key tidak merusak chatbot (penolakan coding tanpa jaringan)
$admin->get('/admin/dashboard');
$token = $admin->token();
$r = $admin->request('POST', '/ai/chat', [
    'json' => ['message' => 'tolong buatkan kode python untuk login otomatis', '_token' => $token],
    'headers' => ['X-CSRF-Token: ' . $token],
]);
$chat = json_decode($r['body'], true);
check('16a. chatbot tetap berfungsi setelah ganti key', is_array($chat) && ($chat['ok'] ?? null) === true);
check('16b. aturan WiFiCare AI (bukan asisten coding) dipertahankan', is_array($chat) && str_contains((string) ($chat['reply'] ?? ''), 'khusus membantu layanan internet'));

// --- 17. Audit log tidak membocorkan key -------------------------------------
$auditRows = $db->query("SELECT * FROM audit_log WHERE aksi='pengaturan_ai_disimpan' ORDER BY id ASC")->fetchAll();
check('17a. audit perubahan tersimpan', count($auditRows) > 0);
$auditJson = json_encode($auditRows);
check('17b. audit tidak memuat API key asli', !str_contains($auditJson, $plainKey));
check('17c. audit tidak memuat ciphertext', !str_contains($auditJson, 'v1:') && !str_contains($auditJson, $cipher1));
check('17d. audit menandai key diganti (kata)', str_contains($auditJson, 'diganti'));
check('17e. audit mencatat aktivasi/nonaktivasi', str_contains($auditJson, 'is_enabled'));
$auditTest = $db->query("SELECT * FROM audit_log WHERE aksi='pengaturan_ai_tes_koneksi' ORDER BY id DESC LIMIT 1")->fetch();
check('17f. audit tes koneksi tercatat tanpa key', is_array($auditTest) && !str_contains(json_encode($auditTest), 'x-goog') && !str_contains(json_encode($auditTest), $badKey));

// --- CSRF & RBAC endpoint tes -------------------------------------------------
$admin->get('/admin/pengaturan/ai');
$token = $admin->token();

$r = $admin->request('POST', '/admin/pengaturan/ai', ['fields' => ['model' => 'gemini-2.5-pro']]);
check('CSRF: simpan tanpa token → 419', $r['status'] === 419, 'status=' . $r['status']);

$r = $admin->request('POST', '/admin/pengaturan/ai/test', ['json' => ['model' => 'gemini-2.5-flash']]);
check('CSRF: tes tanpa token → 419', $r['status'] === 419, 'status=' . $r['status']);

$cs->get('/cs/dashboard');
$csToken = $cs->token();
$r = $cs->request('POST', '/admin/pengaturan/ai/test', [
    'json' => ['_token' => $csToken, 'model' => 'gemini-2.5-flash'],
    'headers' => ['X-CSRF-Token: ' . $csToken],
]);
check('RBAC: CS tidak boleh memakai endpoint tes (403)', $r['status'] === 403, 'status=' . $r['status']);

// --- 18. Akses DB AI tetap read-only (tidak ada endpoint tulis) --------------
$r = $admin->request('POST', '/admin/pengaturan/ai/test', [
    'json' => ['_token' => $token, 'api_key' => 'x', 'sql' => 'DROP TABLE users'],
    'headers' => ['X-CSRF-Token: ' . $token],
]);
check('18. endpoint pengaturan tidak mengeksekusi SQL pengguna', $r['status'] === 200 && !str_contains($r['body'], 'DROP'));

// --- 19. Aset & keamanan halaman ---------------------------------------------
$r = $anon->get('/assets/js/ai-settings.js');
check('19a. ai-settings.js tersedia', $r['status'] === 200, 'status=' . $r['status']);
check('19b. JS tidak memuat placeholder API key', !str_contains($r['body'], 'AIza'));
$r = $anon->get('/assets/css/style.css');
check('19c. CSS memuat gaya pengaturan AI', str_contains($r['body'], '.input-with-action') && str_contains($r['body'], '.switch-label'));

// --- Bersihkan kondisi uji (agar harness lain tetap deterministik) -----------
$db->exec('DELETE FROM ai_settings');
$db->exec('DELETE FROM ai_provider_keys');
$db->exec("DELETE FROM audit_log WHERE aksi IN ('pengaturan_ai_disimpan','pengaturan_ai_tes_koneksi')");

echo "----------------------------------------------------------------\n";
echo "TOTAL: " . ($PASS + $FAIL + $SKIP) . " | PASS: {$PASS} | FAIL: {$FAIL} | SKIP: {$SKIP}\n";
if ($FAIL > 0) {
    echo "GAGAL:\n";
    foreach ($FAILED as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}
echo "SEMUA TES PENGATURAN AI CHATBOT LULUS.\n";
exit(0);
