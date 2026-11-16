<?php
/**
 * WiFiCare CDN — Revisi Final (7 revisi) verifikasi.
 *
 * Menguji perbaikan akhir:
 *  1. Tata letak teks jawaban AI (rata kiri, line-height, tanpa overflow).
 *  2. Tanpa Markdown mentah + kartu tiket terstruktur dari data DB.
 *  3. Jawaban AI ringkas (system instruction) tanpa komentar tambahan.
 *  4. Kebersihan input chat (placeholder, tanpa peringatan, tombol kirim).
 *  5. Tema global Light (default) + Dark di seluruh halaman.
 *  6. Routing root → login/dashboard, tanpa halaman publik ber-sidebar.
 *  7. Scrollbar disembunyikan visual tanpa mematikan gulir.
 *
 * Prasyarat HTTP (DB uji): server uji di http://localhost:8099.
 * Bagian backend memakai mock Gemini lokal (tanpa jaringan) + DB aplikasi
 * (baris `ai_settings` Tahap 11 dinetralkan sementara lalu dipulihkan).
 *
 * Jalankan: php tests/tahap12/revisi-final.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../tahap10/_config.php';
t10_require_passwords(['T10_PW_ADMIN', 'T10_PW_PEL']);

define('BASE', T10_BASE);
define('ROOT', dirname(__DIR__, 2));
define('TMP', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_t12');
if (!is_dir(TMP)) {
    @mkdir(TMP, 0775, true);
}

define('MOCK_HOST', '127.0.0.1');
define('MOCK_PORT', 8097);
define('MOCK_BASE', 'http://' . MOCK_HOST . ':' . MOCK_PORT . '/v1beta');
define('MOCK_LOG', TMP . DIRECTORY_SEPARATOR . 'mock_t12_hits.log');
@unlink(MOCK_LOG);

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

function file_text(string $relative): string
{
    $path = ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    return is_file($path) ? (string) file_get_contents($path) : '';
}

final class Sess
{
    public string $jar;
    public string $lastBody = '';
    public int $lastStatus = 0;
    public array $lastHeaders = [];

    public function __construct(public string $name)
    {
        $this->jar = TMP . DIRECTORY_SEPARATOR . 'jar_' . preg_replace('/[^a-z0-9]/i', '_', $name) . '.txt';
        @unlink($this->jar);
    }

    public function request(string $method, string $path, array $opts = []): array
    {
        $ch = curl_init();
        $url = str_starts_with($path, 'http') ? $path : BASE . $path;
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
        $raw = curl_exec($ch);
        if ($raw === false) {
            $this->lastStatus = 0;
            $this->lastBody = 'CURL_ERROR: ' . curl_error($ch);
            curl_close($ch);
            return ['status' => 0, 'body' => $this->lastBody, 'headers' => []];
        }
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $this->lastStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $this->lastBody = substr($raw, $headerSize);
        $this->lastHeaders = [];
        foreach (explode("\r\n", substr($raw, 0, $headerSize)) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $this->lastHeaders[strtolower(trim($k))] = trim($v);
            }
        }
        curl_close($ch);
        return ['status' => $this->lastStatus, 'body' => $this->lastBody, 'headers' => $this->lastHeaders];
    }

    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    public function token(): string
    {
        return preg_match('/name="_token"\s+value="([a-f0-9]{64})"/', $this->lastBody, $m) === 1 ? $m[1] : '';
    }

    public function login(string $username, string $password): array
    {
        $this->get('/login');
        return $this->request('POST', '/login', [
            'fields' => ['_token' => $this->token(), 'username' => $username, 'password' => $password],
        ]);
    }

    public function location(): string
    {
        return $this->lastHeaders['location'] ?? '';
    }
}

echo "==== REVISI FINAL — 7 PERBAIKAN ====\n";

/* --------------------------------------------------------------------------
 * Revisi 1 & 2 & 4 — Aset AI (tata letak teks, format, input)
 * ------------------------------------------------------------------------ */

$css = file_text('public/assets/css/style.css');

check('R1 CSS: bubble AI rata kiri (text-align: left)',
    (bool) preg_match('/\.chat-bubble-body\s*\{[^}]*text-align:\s*left/s', $css));
check('R1 CSS: line-height 1.6 pada bubble',
    (bool) preg_match('/\.chat-bubble-body\s*\{[^}]*line-height:\s*1\.6/s', $css));
check('R1 CSS: pembungkusan kata aman (overflow-wrap/word-break)',
    (bool) preg_match('/\.chat-bubble-body\s*\{[^}]*overflow-wrap:\s*anywhere/s', $css)
    && (bool) preg_match('/\.chat-bubble-body\s*\{[^}]*word-break:\s*break-word/s', $css));

$render = file_text('public/assets/js/ai-render.js');
check('R2 ai-render.js tersedia', $render !== '');
check('R2 ai-render.js membangun DOM teks kaya (renderRich)', str_contains($render, 'function renderRich'));
check('R2 ai-render.js membuat kartu tiket (renderCards)', str_contains($render, 'function renderCards'));
check('R2 ai-render.js memakai textContent untuk teks AI', str_contains($render, 'textContent'));
check('R2 ai-render.js TIDAK memakai innerHTML (tidak mengeksekusi HTML AI)', !str_contains($render, '.innerHTML'));
check('R2 ai-render.js menghapus penanda bold ** (bukan ditampilkan mentah)', str_contains($render, 'strong'));

check('R1/R2 CSS: gaya kartu tiket tersedia', str_contains($css, '.chat-ticket') && str_contains($css, '.chat-ticket-label'));

$widgetJs = file_text('public/assets/js/ai-widget.js');
check('R2 widget memakai perender aman (WifiCareAiRender)', str_contains($widgetJs, 'WifiCareAiRender'));
check('R2 widget meneruskan data kartu tiket (data.tickets)', str_contains($widgetJs, 'data.tickets'));
check('R4 widget: tombol kirim punya state loading', str_contains($widgetJs, 'is-loading'));
check('R4 widget: auto-grow textarea', str_contains($widgetJs, 'autoResize'));

$aiPageJs = file_text('public/assets/js/ai.js');
check('R2 halaman /ai memakai perender aman', str_contains($aiPageJs, 'WifiCareAiRender'));
check('R2 halaman /ai meneruskan kartu tiket', str_contains($aiPageJs, 'data.tickets'));

$widgetPhp = file_text('app/Views/components/ai-widget.php');
check('R4 widget: placeholder "Tulis pertanyaan Anda"', str_contains($widgetPhp, 'Tulis pertanyaan Anda'));
check('R4 widget: teks peringatan kata sandi DIHAPUS', !str_contains($widgetPhp, 'Jangan pernah membagikan'));
check('R1 widget: sapaan "Halo! Saya WiFiCare AI."', str_contains($widgetPhp, 'Halo! Saya WiFiCare AI.'));
check('R1 widget: deskripsi sapaan', str_contains($widgetPhp, 'Saya siap membantu'));

$aiIndex = file_text('app/Views/ai/index.php');
check('R4 halaman /ai: peringatan kata sandi DIHAPUS', !str_contains($aiIndex, 'Jangan pernah membagikan'));
check('R4 halaman /ai: placeholder "Tulis pertanyaan Anda"', str_contains($aiIndex, 'Tulis pertanyaan Anda'));

/* --------------------------------------------------------------------------
 * Revisi 5 — Tema global (Light default + Dark)
 * ------------------------------------------------------------------------ */

check('R5 CSS: blok dark theme [data-theme="dark"]', str_contains($css, ':root[data-theme="dark"]'));
check('R5 CSS: token Light default (#f8fafc)', str_contains($css, '#f8fafc'));
check('R5 CSS: token Dark (#0b1120 / #172238)', str_contains($css, '#0b1120') && str_contains($css, '#172238'));
check('R5 CSS: gaya tombol tema (.theme-toggle)', str_contains($css, '.theme-toggle'));

$toggle = file_text('app/Views/components/theme-toggle.php');
check('R5 komponen theme-toggle tersedia', str_contains($toggle, 'data-theme-toggle'));
check('R5 komponen theme-toggle punya ikon moon & sun', str_contains($toggle, 'moon') && str_contains($toggle, 'sun'));

$mainJs = file_text('public/assets/js/main.js');
check('R5 main.js: menyimpan preferensi tema (localStorage)', str_contains($mainJs, 'wificare-theme'));
check('R5 main.js: atribut data-theme + data-bs-theme', str_contains($mainJs, "setAttribute('data-theme'") && str_contains($mainJs, "setAttribute('data-bs-theme'"));
check('R5 main.js: sinyal perubahan tema (wificare:themechange)', str_contains($mainJs, 'wificare:themechange'));

$dashJs = file_text('public/assets/js/dashboard.js');
check('R5 dashboard.js: grafik mengikuti tema (wificare:themechange)', str_contains($dashJs, 'wificare:themechange'));
check('R5 dashboard.js: warna grafik dari token CSS', str_contains($dashJs, 'getComputedStyle'));

$appLayout = file_text('app/Views/layouts/app.php');
$authLayout = file_text('app/Views/layouts/auth.php');
$errLayout = file_text('app/Views/layouts/error.php');
foreach (['app' => $appLayout, 'auth' => $authLayout, 'error' => $errLayout] as $name => $layout) {
    check("R5 layout {$name}: anti-flash tema sebelum stylesheet", str_contains($layout, "localStorage.getItem('wificare-theme')"));
    check("R5 layout {$name}: memasang data-theme", str_contains($layout, 'data-theme'));
}

/* --------------------------------------------------------------------------
 * Revisi 6 — Routing root & tanpa halaman publik ber-sidebar
 * ------------------------------------------------------------------------ */

$routes = file_text('routes/web.php');
check('R6 route /about dihapus', !str_contains($routes, "'/about'"));
check('R6 route / tetap ada (HomeController@index)', str_contains($routes, "'pattern' => '/'"));

$home = file_text('app/Controllers/HomeController.php');
check('R6 HomeController mengarahkan ke login bila belum masuk', str_contains($home, "redirect('/login')"));
check('R6 HomeController mengarahkan ke dashboard per peran', str_contains($home, 'Auth::dashboardUrl'));

$menu = file_text('app/Helpers/menu.php');
check('R6 menu publik tidak memuat Beranda/Tentang', !str_contains($menu, "'label' => 'Beranda'") && !str_contains($menu, "'label' => 'Tentang'"));

/* --------------------------------------------------------------------------
 * Revisi 7 — Scrollbar disembunyikan visual, gulir tetap berfungsi
 * ------------------------------------------------------------------------ */

check('R7 CSS: scrollbar-width: none (Firefox)', str_contains($css, 'scrollbar-width: none'));
check('R7 CSS: ::-webkit-scrollbar (WebKit)', str_contains($css, '::-webkit-scrollbar'));
check('R7 CSS: mengatur sidebar & body sebagai area gulir', str_contains($css, '.app-sidebar') && str_contains($css, 'body::-webkit-scrollbar'));
check('R7 CSS: tidak memakai overflow:hidden pada area gulir utama',
    !preg_match('/\.app-sidebar\s*\{[^}]*overflow:\s*hidden/s', $css));

/* --------------------------------------------------------------------------
 * HTTP — routing, tema, widget, login (DB uji)
 * ------------------------------------------------------------------------ */

echo "----------------------------------------------------------------\n";
echo "--- HTTP (DB uji) ---\n";

$anon = new Sess('t12_anon');
$r = $anon->get('/');
check('HTTP: URL root belum masuk → 302 ke /login',
    $r['status'] === 302 && str_contains($anon->location(), '/login'),
    'status=' . $r['status'] . ' loc=' . $anon->location());

$r = $anon->get('/about');
check('HTTP: /about tidak tersedia (404)', $r['status'] === 404, 'status=' . $r['status']);

$r = $anon->get('/login');
$loginHtml = $r['body'];
check('HTTP: halaman login 200', $r['status'] === 200);
check('HTTP: login memuat tombol tema', str_contains($loginHtml, 'data-theme-toggle'));
check('HTTP: login bebas sidebar dashboard', !str_contains($loginHtml, 'app-sidebar'));
check('HTTP: login bebas menu Beranda/Tentang', !preg_match('/>\s*(Beranda|Tentang)\s*</', $loginHtml));
check('HTTP: login memuat anti-flash tema', str_contains($loginHtml, "localStorage.getItem('wificare-theme')"));

$r = $anon->get('/akses-ditolak');
check('HTTP: /akses-ditolak → 403', $r['status'] === 403, 'status=' . $r['status']);
check('HTTP: halaman 403 tanpa sidebar dashboard', !str_contains($r['body'], 'app-sidebar'));
check('HTTP: halaman 403 memuat tombol tema', str_contains($r['body'], 'data-theme-toggle'));

// Pelanggan
$pel = new Sess('t12_pel');
$pel->login('pppoe.rina', T10_PW_PEL);
check('HTTP: login pelanggan berhasil', $pel->lastStatus === 302);

$r = $pel->get('/');
check('HTTP: pelanggan sudah masuk, root → dashboard pelanggan',
    $r['status'] === 302 && str_contains($pel->location(), '/pelanggan/dashboard'),
    'status=' . $r['status'] . ' loc=' . $pel->location());

$r = $pel->get('/pelanggan/dashboard');
$dashHtml = $r['body'];
check('HTTP: dashboard pelanggan 200', $r['status'] === 200);
check('HTTP: dashboard memuat tombol tema', str_contains($dashHtml, 'data-theme-toggle'));
check('HTTP: dashboard memuat floating AI widget', str_contains($dashHtml, 'data-ai-widget'));
check('HTTP: widget tanpa peringatan kata sandi', !str_contains($dashHtml, 'Jangan pernah membagikan'));
check('HTTP: widget sapaan WiFiCare AI', str_contains($dashHtml, 'Saya WiFiCare AI'));
check('HTTP: ai-render.js dimuat', str_contains($dashHtml, 'ai-render.js'));
check('HTTP: floating AI tidak di halaman login', !str_contains($loginHtml, 'data-ai-widget'));

// RBAC: pelanggan ke area admin → 403 dengan layout error
$r = $pel->get('/admin/dashboard');
check('HTTP: pelanggan ke /admin/dashboard → 403', $r['status'] === 403, 'status=' . $r['status']);
check('HTTP: halaman 403 RBAC tanpa sidebar', !str_contains($r['body'], 'app-sidebar'));

// /ai page (antarmuka chat)
$r = $pel->get('/ai');
check('HTTP: halaman /ai 200', $r['status'] === 200, 'status=' . $r['status']);
check('HTTP: halaman /ai tanpa peringatan kata sandi', !str_contains($r['body'], 'Jangan pernah membagikan'));
check('HTTP: halaman /ai memuat placeholder', str_contains($r['body'], 'Tulis pertanyaan Anda'));
check('HTTP: halaman /ai memuat ai-render.js', str_contains($r['body'], 'ai-render.js'));

// Admin
$admin = new Sess('t12_admin');
$admin->login('admin.budi', T10_PW_ADMIN);
$r = $admin->get('/');
check('HTTP: admin sudah masuk, root → dashboard admin',
    $r['status'] === 302 && str_contains($admin->location(), '/admin/dashboard'),
    'status=' . $r['status'] . ' loc=' . $admin->location());

// Logout → login (pakai sesi pelanggan)
$pel->get('/pelanggan/dashboard');
$token = $pel->token();
$r = $pel->request('POST', '/logout', ['fields' => ['_token' => $token]]);
check('HTTP: logout mengarah ke login', $r['status'] === 302 && str_contains($pel->location(), '/login'),
    'status=' . $r['status'] . ' loc=' . $pel->location());

/* --------------------------------------------------------------------------
 * Backend — kartu tiket dari data DB (mock Gemini)
 * ------------------------------------------------------------------------ */

echo "----------------------------------------------------------------\n";
echo "--- Backend (kartu tiket & system instruction) ---\n";

require_once ROOT . '/app/bootstrap.php';

// Netralkan baris ai_settings Tahap 11 agar konfigurasi uji dipakai (non-destruktif).
$__aiBackup = null;
try {
    AiSetting::forget();
    $__pdo = Database::pdo();
    $__row = $__pdo->query('SELECT id, provider, model, api_key_encrypted, is_enabled FROM ai_settings ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (is_array($__row) && $__row !== []) {
        $__aiBackup = $__row;
        $__pdo->exec("UPDATE ai_settings SET provider = '', model = NULL, api_key_encrypted = NULL, is_enabled = 1 WHERE id = " . (int) $__row['id']);
        AiSetting::forget();
    }
} catch (Throwable $e) {
    $__aiBackup = null;
}
register_shutdown_function(static function () use ($__aiBackup): void {
    if (!is_array($__aiBackup) || $__aiBackup === []) {
        return;
    }
    try {
        Database::pdo()->prepare(
            'UPDATE ai_settings SET provider = :provider, model = :model, api_key_encrypted = :api_key_encrypted, is_enabled = :is_enabled WHERE id = :id'
        )->execute([
            'provider' => $__aiBackup['provider'],
            'model' => $__aiBackup['model'],
            'api_key_encrypted' => $__aiBackup['api_key_encrypted'],
            'is_enabled' => $__aiBackup['is_enabled'],
            'id' => $__aiBackup['id'],
        ]);
        AiSetting::forget();
    } catch (Throwable $e) {
        // Pemulihan bersifat best-effort.
    }
});

// System instruction ringkas (Revisi 3) — via reflection.
$concise = false;
try {
    $method = new ReflectionMethod('AiAssistantService', 'systemPrompt');
    $method->setAccessible(true);
    $prompt = (string) $method->invoke(null, 'pelanggan');
    $concise = str_contains($prompt, 'GAYA JAWABAN')
        && str_contains($prompt, 'LANGSUNG')
        && str_contains($prompt, 'JANGAN menambahkan komentar');
} catch (Throwable $e) {
    $concise = false;
}
check('R3 system instruction memuat aturan jawaban ringkas', $concise);

// Jalankan mock Gemini.
$router = ROOT . '/tests/tahap10/mock-gemini.php';
$cmd = escapeshellarg(PHP_BINARY) . ' -S ' . MOCK_HOST . ':' . MOCK_PORT . ' ' . escapeshellarg($router);
$devNull = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$spec = [1 => ['file', $devNull, 'w'], 2 => ['file', $devNull, 'w']];
$mockTicket = (string) Database::pdo()->query(
    "SELECT p.nomor_tiket
     FROM pengaduan p
     INNER JOIN pelanggan pl ON pl.id = p.pelanggan_id
     INNER JOIN users u ON u.id = pl.user_id
     WHERE u.username = 'pppoe.rina'
     ORDER BY p.id DESC LIMIT 1"
)->fetchColumn();
$proc = @proc_open($cmd, $spec, $pipes, ROOT, array_merge(getenv(), [
    'MOCK_GEMINI_LOG' => MOCK_LOG,
    'MOCK_TICKET_NUMBER' => $mockTicket,
]));
$pid = is_resource($proc) ? (int) (proc_get_status($proc)['pid'] ?? 0) : 0;

$mockUp = false;
if (is_resource($proc)) {
    for ($i = 0; $i < 50; $i++) {
        $sock = @fsockopen(MOCK_HOST, MOCK_PORT, $errno, $errstr, 0.2);
        if (is_resource($sock)) {
            fclose($sock);
            $mockUp = true;
            break;
        }
        usleep(100000);
    }
}
check('Backend: server mock Gemini berjalan', $mockUp);

if ($mockUp) {
    $GLOBALS['app_config']['ai']['provider'] = 'gemini';
    $GLOBALS['app_config']['ai']['gemini_api_key'] = 'mock-key-should-never-leak';
    $GLOBALS['app_config']['ai']['gemini_base_url'] = MOCK_BASE;
    $GLOBALS['app_config']['ai']['gemini_model'] = 'mock-tool-status';
    $GLOBALS['app_config']['ai']['gemini_model_fallback'] = '';
    $GLOBALS['app_config']['ai']['max_retries'] = 0;

    try {
        // Jangan bergantung pada ID auto-increment yang bisa bergeser setelah
        // seed karyawan baru; ambil ID pelanggan uji secara eksplisit.
        $rinaId = (int) Database::pdo()
            ->query("SELECT u.id FROM users u WHERE u.username = 'pppoe.rina' LIMIT 1")
            ->fetchColumn();
        if ($rinaId <= 0) {
            $rinaId = 5;
        }
        $pelanggan = ['id' => $rinaId, 'role' => 'pelanggan', 'name' => 'Rina Marlina'];
        $r = AiAssistantService::chat($pelanggan, 'Tolong tampilkan status tiket saya yang terakhir.');
        $tickets = isset($r['tickets']) && is_array($r['tickets']) ? $r['tickets'] : [];
        check('R2 chat() mengembalikan array tickets', array_key_exists('tickets', $r));
        check('R2 kartu tiket berisi data DB (nomor tiket)', $tickets !== [], json_encode($r));
        if ($tickets !== []) {
            $card = $tickets[0];
            check('R2 kartu memuat nomor_tiket', isset($card['nomor_tiket']) && str_starts_with((string) $card['nomor_tiket'], 'WFC-'), json_encode($card));
            check('R2 kartu memuat status_key (6 status resmi)', isset($card['status_key']) && in_array($card['status_key'], array_keys(status_map()), true), json_encode($card));
            check('R2 kartu memuat kategori', isset($card['kategori']) && $card['kategori'] !== '', json_encode($card));
            $tanggalKartu = (string) ($card['dibuat_panjang'] ?? $card['dibuat'] ?? '');
            check('R2 kartu memuat tanggal panjang (Bahasa Indonesia)', $tanggalKartu !== '' && (bool) preg_match('/\d{4}[, ]\s*\d{2}:\d{2}/', $tanggalKartu), json_encode($card));
            check('R2 pelanggan tidak menerima nama_pelanggan orang lain', !isset($card['nama_pelanggan']) || (string) $card['nama_pelanggan'] === 'Rina Marlina', json_encode($card));
        }

        // Admin melihat nama pelanggan + teknisi pada kartu.
        $GLOBALS['app_config']['ai']['gemini_model'] = 'mock-tool-status';
        $admin = ['id' => 1, 'role' => 'admin', 'name' => 'Budi Santoso'];
        $ra = AiAssistantService::chat($admin, 'Tolong tampilkan status tiket terakhir.');
        $cards = isset($ra['tickets']) ? (array) $ra['tickets'] : [];
        check('R2 admin menerima kartu tiket', $cards !== [], json_encode($ra));

        // Kunci API tidak pernah ikut tercatat ke log permintaan mock.
        $mockLog = is_file(MOCK_LOG) ? (string) file_get_contents(MOCK_LOG) : '';
        check('Backend: kunci API tidak bocor ke log mock', !str_contains($mockLog, 'mock-key-should-never-leak'));
    } catch (Throwable $e) {
        check('R2 eksekusi kartu tiket', false, $e->getMessage());
    }
}

if (is_resource($proc)) {
    if ($pid > 0 && PHP_OS_FAMILY === 'Windows') {
        @exec('taskkill /F /T /PID ' . $pid . ' 2>&1');
    }
    @proc_terminate($proc);
    @proc_close($proc);
}

echo "----------------------------------------------------------------\n";
echo 'TOTAL: ' . ($PASS + $FAIL + $SKIP) . " | PASS: {$PASS} | FAIL: {$FAIL} | SKIP: {$SKIP}\n";
if ($FAIL > 0) {
    echo "GAGAL:\n";
    foreach ($FAILED as $f) {
        echo '  - ' . $f . "\n";
    }
    exit(1);
}
echo "SEMUA TES REVISI FINAL LULUS.\n";
