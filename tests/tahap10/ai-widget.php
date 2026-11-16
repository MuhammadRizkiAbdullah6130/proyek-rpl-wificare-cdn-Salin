<?php
/**
 * WiFiCare CDN — Tahap 10: verifikasi Floating AI Assistant widget.
 *
 * Berjalan terhadap server uji (DB: wificare_cdn_test) di http://localhost:8099.
 * Memverifikasi: menu AI hilang dari sidebar, widget muncul pada semua dashboard
 * peran, markup/CSS/JS tersedia, dan endpoint AI tetap aman (CSRF, RBAC,
 * pesan konfigurasi, tidak ada kunci API yang bocor, tidak ada respons palsu).
 */

declare(strict_types=1);

require_once __DIR__ . '/_config.php';
t10_require_passwords(['T10_PW_ADMIN', 'T10_PW_CS', 'T10_PW_TEK', 'T10_PW_PEL']);
define('BASE', T10_BASE);
define('TMP', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_t10');
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

/** Sesi HTTP dengan cookie jar sendiri (salinan ringan dari harness E2E). */
final class Sess
{
    public string $jar;
    public string $lastBody = '';
    public int $lastStatus = 0;
    public array $lastHeaders = [];
    public string $lastUrl = '';

    public function __construct(public string $name)
    {
        $this->jar = TMP . '/aiw_jar_' . preg_replace('/[^a-z0-9]/i', '_', $name) . '.txt';
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
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        }
        if (isset($opts['fields'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['fields']);
        }
        if (isset($opts['json'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opts['json']));
            curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(
                $opts['headers'] ?? [],
                ['Content-Type: application/json']
            ));
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

echo "==== TAHAP 10 — FLOATING AI WIDGET (DB uji: wificare_cdn_test) ====\n";

$anon = new Sess('aiw_anon');
$admin = new Sess('aiw_admin');
$cs = new Sess('aiw_cs');
$teknisi = new Sess('aiw_teknisi');
$pelanggan = new Sess('aiw_pelanggan');

check('login admin', $admin->login('admin.budi', T10_PW_ADMIN));
check('login cs', $cs->login('cs.dewi', T10_PW_CS));
check('login teknisi', $teknisi->login('tek.andi', T10_PW_TEK));
check('login pelanggan', $pelanggan->login('pppoe.rina', T10_PW_PEL));

$dashboards = [
    'admin' => [$admin, '/admin/dashboard'],
    'cs' => [$cs, '/cs/dashboard'],
    'teknisi' => [$teknisi, '/teknisi/dashboard'],
    'pelanggan' => [$pelanggan, '/pelanggan/dashboard'],
];

// --- 1. Menu AI hilang dari sidebar + widget hadir pada tiap dashboard --------
$widgetConfigured = null;
foreach ($dashboards as $label => [$s, $path]) {
    $r = $s->get($path);
    $body = $r['body'];
    if ($widgetConfigured === null) {
        $widgetConfigured = !str_contains($body, 'Layanan AI belum dikonfigurasi. Silakan hubungi administrator.');
    }
    check("dashboard {$label} 200", $r['status'] === 200, 'status=' . $r['status']);
    check("{$label}: menu 'AI Assistant' hilang dari sidebar", !str_contains($body, 'AI Assistant'));
    check("{$label}: tidak ada tautan nav ke /ai", !str_contains($body, 'href="/ai"'));
    check("{$label}: floating button hadir", str_contains($body, 'data-ai-widget') && str_contains($body, 'aiw-fab'));
    check("{$label}: panel chat hadir", str_contains($body, 'data-aiw-panel') && str_contains($body, 'id="aiw-panel"'));
    check("{$label}: header widget benar", str_contains($body, 'WiFiCare AI') && str_contains($body, 'Asisten Virtual'));
    check("{$label}: tooltip tombol AI", str_contains($body, 'title="Tanya WiFiCare AI"'));
    check("{$label}: script widget dimuat", str_contains($body, 'assets/js/ai-widget.js'));
    if ($widgetConfigured) {
        check("{$label}: sapaan AI + pertanyaan cepat (API aktif)", str_contains($body, 'Saya WiFiCare AI') && str_contains($body, 'data-aiw-quick-list'));
        check("{$label}: input tidak dinonaktifkan saat API aktif", !preg_match('/data-aiw-input[^>]*disabled/', $body));
    } else {
        check("{$label}: pesan konfigurasi (API belum diset)", str_contains($body, 'Layanan AI belum dikonfigurasi. Silakan hubungi administrator.'));
    }
    check("{$label}: form kirim ada", str_contains($body, 'data-aiw-form') && str_contains($body, 'data-aiw-input'));
    check("{$label}: tombol minimize & close ada", str_contains($body, 'data-aiw-minimize') && str_contains($body, 'data-aiw-close'));
}

// --- 2. Widget pada halaman terautentikasi lain -------------------------------
foreach ([
    [$admin, '/admin/laporan'],
    [$admin, '/admin/pengaduan'],
    [$cs, '/cs/pengaduan'],
    [$teknisi, '/teknisi/tugas'],
    [$pelanggan, '/pelanggan/pengaduan'],
] as [$s, $path]) {
    $r = $s->get($path);
    check("widget pada {$path} 200", $r['status'] === 200, 'status=' . $r['status']);
    check("widget pada {$path} hadir", str_contains($r['body'], 'data-ai-widget'));
}

// --- 3. Halaman /ai lama tetap berfungsi (fitur tidak dihapus) -----------------
foreach ($dashboards as $label => [$s, $path]) {
    $r = $s->get('/ai');
    check("/ai {$label} tetap 200", $r['status'] === 200, 'status=' . $r['status']);
    check("/ai {$label} memuat chat & widget", str_contains($r['body'], 'data-ai-form') && str_contains($r['body'], 'data-ai-widget'));
}

// --- 4. Guest tidak mendapat widget ------------------------------------------
$r = $anon->get('/admin/dashboard');
check('guest -> 302 login', $r['status'] === 302 && str_contains($r['headers']['location'] ?? '', '/login'), 'status=' . $r['status']);
check('guest: tidak ada widget', !str_contains($r['body'], 'data-ai-widget'));

// --- 5. Aset statis tersedia --------------------------------------------------
$r = $anon->get('/assets/js/ai-widget.js');
check('ai-widget.js 200', $r['status'] === 200, 'status=' . $r['status']);
check('ai-widget.js berisi logika toggle', str_contains($r['body'], 'data-aiw-toggle') && str_contains($r['body'], 'textContent'));
$r = $anon->get('/assets/css/style.css');
check('style.css memuat gaya .aiw', str_contains($r['body'], '.aiw-fab') && str_contains($r['body'], '.aiw-panel'));

// --- 6. Endpoint AI: CSRF, validasi, konfigurasi, RBAC ------------------------
$admin->get('/admin/dashboard');
$token = $admin->token();

// 6a. Tanpa token -> 419.
$r = $admin->request('POST', '/ai/chat', ['json' => ['message' => 'halo']]);
check('POST /ai/chat tanpa CSRF -> 419', $r['status'] === 419, 'status=' . $r['status']);

// 6b. Token salah -> 419.
$r = $admin->request('POST', '/ai/chat', [
    'json' => ['message' => 'halo', '_token' => str_repeat('0', 64)],
    'headers' => ['X-CSRF-Token: ' . str_repeat('0', 64)],
]);
check('POST /ai/chat token salah -> 419', $r['status'] === 419, 'status=' . $r['status']);

// 6c. Pesan kosong -> 422.
$r = $admin->request('POST', '/ai/chat', [
    'json' => ['message' => '   ', '_token' => $token],
    'headers' => ['X-CSRF-Token: ' . $token],
]);
check('POST /ai/chat pesan kosong -> 422', $r['status'] === 422, 'status=' . $r['status']);

// 6d. Perilaku bergantung konfigurasi provider.
if ($widgetConfigured) {
    // Provider aktif: jalur jawaban nyata diuji di ai-gemini.php (Gemini) / ai-live.php (mock).
    skip('POST /ai/chat (API belum diset) -> ok=false', 'provider aktif; diuji di ai-gemini.php');
} else {
    $r = $admin->request('POST', '/ai/chat', [
        'json' => ['message' => 'Bagaimana cara membuat pengaduan?', '_token' => $token],
        'headers' => ['X-CSRF-Token: ' . $token],
    ]);
    $decoded = json_decode($r['body'], true);
    check('POST /ai/chat (tanpa API) -> 200', $r['status'] === 200, 'status=' . $r['status']);
    check('POST /ai/chat (tanpa API) ok=false', is_array($decoded) && ($decoded['ok'] ?? null) === false, 'body=' . substr($r['body'], 0, 120));
    check('POST /ai/chat (tanpa API) memuat pesan konfigurasi', is_array($decoded) && str_contains((string) ($decoded['error'] ?? ''), 'belum'));
}

// 6e. Guest -> endpoint terlindungi (bukan 200 ok=true).
$r = $anon->request('POST', '/ai/chat', ['json' => ['message' => 'halo']]);
$guestOk = ($r['status'] === 200) && (json_decode($r['body'], true)['ok'] ?? false) === true;
check('guest POST /ai/chat tidak sukses', !$guestOk, 'status=' . $r['status']);

// --- 7. Tidak ada rahasia di output HTML/JS -----------------------------------
$r = $admin->get('/admin/dashboard');
check('HTML tidak memuat nama variabel rahasia', !str_contains($r['body'], 'AI_API_KEY'));
check('HTML tidak memuat header Authorization', !str_contains($r['body'], 'Authorization: Bearer'));

echo "----------------------------------------------------------------\n";
echo "TOTAL: " . ($PASS + $FAIL + $SKIP) . " | PASS: {$PASS} | FAIL: {$FAIL} | SKIP: {$SKIP}\n";
if ($FAIL > 0) {
    echo "GAGAL:\n";
    foreach ($FAILED as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}
echo "SEMUA TES FLOATING AI WIDGET LULUS.\n";
exit(0);
