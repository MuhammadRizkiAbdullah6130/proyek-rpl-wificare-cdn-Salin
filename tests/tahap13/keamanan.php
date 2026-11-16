<?php
/**
 * WiFiCare CDN — Tahap 13: pengujian keamanan autentikasi & proteksi folder.
 *
 * Pengujian NYATA melalui HTTP:
 * - Apache port 80 (deployment subfolder): proteksi folder internal, .env,
 *   directory listing, traversal, dan autentikasi halaman privat.
 * - Database utama hanya DIBACA (tidak ada INSERT/UPDATE/DELETE).
 *
 * Jalankan (Apache + MySQL aktif):
 *   php tests/tahap13/keamanan.php
 *
 * Override opsional:
 *   SEC_BASE  = http://localhost/aduan-pelanggan/public
 *   SEC_ROOT  = http://localhost/aduan-pelanggan
 *
 * Kredensial akun uji wajib diisi lewat environment T10_PW_ADMIN, T10_PW_CS,
 * T10_PW_TEK, dan T10_PW_PEL (lihat ../tahap10/_config.php). Tanpa itu skrip
 * berhenti dengan status BLOCKED.
 */

declare(strict_types=1);

require_once __DIR__ . '/../tahap10/_config.php';
t10_require_passwords(['T10_PW_ADMIN', 'T10_PW_CS', 'T10_PW_TEK', 'T10_PW_PEL']);

ini_set('display_errors', '1');
error_reporting(E_ALL);

define('SEC_BASE', getenv('SEC_BASE') ?: 'http://localhost/aduan-pelanggan/public');
define('SEC_ROOT', getenv('SEC_ROOT') ?: 'http://localhost/aduan-pelanggan');

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

function skip(string $name, string $reason): void
{
    global $SKIP;
    $SKIP++;
    echo "SKIP  {$name} ({$reason})\n";
}

final class Http
{
    public string $jar;
    public int $status = 0;
    public string $body = '';
    /** @var array<string, string> */
    public array $headers = [];
    public string $location = '';

    public function __construct(public string $name)
    {
        $this->jar = sys_get_temp_dir() . '/wc_sec13_' . preg_replace('/[^a-z0-9]/i', '_', $name) . '.txt';
        @unlink($this->jar);
    }

    /** @param array{fields?: array<string, mixed>, headers?: array<int, string>, json?: array<string, mixed>} $opts */
    public function request(string $method, string $url, array $opts = []): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_PATH_AS_IS => true,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        }
        $headers = $opts['headers'] ?? [];
        if (isset($opts['fields'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['fields']);
        }
        if (isset($opts['json'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string) json_encode($opts['json']));
            $headers[] = 'Content-Type: application/json';
        }
        if ($headers !== []) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $this->status = 0;
            $this->body = 'CURL_ERROR';
            curl_close($ch);
            return [0, '', [], '', ''];
        }

        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $this->status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $rawHeaders = substr($raw, 0, $headerSize);
        $this->body = substr($raw, $headerSize);
        $this->headers = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = explode(':', $line, 2);
            $key = strtolower(trim($key));
            $value = trim($value);
            // Simpan semua Set-Cookie agar validasi atribut cookie akurat.
            if ($key === 'set-cookie') {
                $this->headers[$key] = ($this->headers[$key] ?? '') . ' | ' . $value;
            } else {
                $this->headers[$key] = $value;
            }
        }
        $this->location = $this->headers['location'] ?? '';
        curl_close($ch);

        return [$this->status, $this->body, $this->headers, $this->location, $this->cookieValue()];
    }

    /** @param array<int, string> $headers */
    public function get(string $url, array $headers = []): array
    {
        return $this->request('GET', $url, ['headers' => $headers]);
    }

    public function token(): string
    {
        if (preg_match('/name="_token"\s+value="([a-f0-9]{64})"/', $this->body, $m)) {
            return $m[1];
        }

        return '';
    }

    public function cookieValue(): string
    {
        if (preg_match('/wificare_session=([^;\s]+)/', $this->headers['set-cookie'] ?? '', $m)) {
            return $m[1];
        }

        return '';
    }

    public function login(string $username, string $password): bool
    {
        $this->get(SEC_BASE . '/login');
        $token = $this->token();
        $r = $this->request('POST', SEC_BASE . '/login', [
            'fields' => ['_token' => $token, 'username' => $username, 'password' => $password],
        ]);

        return $r[0] === 302 && !str_contains($r[3], '/login');
    }
}

function appUrl(string $path): string
{
    return SEC_BASE . $path;
}

echo "==== TAHAP 13 — KEAMANAN AUTENTIKASI & FOLDER ====\n";
echo "BASE: " . SEC_BASE . "\nROOT: " . SEC_ROOT . "\n\n";

/* ------------------------------------------------------------------ */
/* A. PROTEKSI FOLDER INTERNAL & FILE SENSITIF (tanpa login)          */
/* ------------------------------------------------------------------ */
echo "--- A. Proteksi folder & file sensitif ---\n";

$anon = new Http('anon');
$sensitive = [
    '/.env' => 'file .env',
    '/.env.example' => 'contoh .env',
    '/app/' => 'folder app',
    '/app/bootstrap.php' => 'bootstrap app',
    '/app/.htaccess' => 'htaccess app',
    '/bin/' => 'folder bin',
    '/config/' => 'folder config',
    '/database/' => 'folder database',
    '/database/.htaccess' => 'htaccess database',
    '/routes/' => 'folder routes',
    '/routes/web.php' => 'routes web',
    '/storage/' => 'folder storage',
    '/storage/logs/app.log' => 'log aplikasi',
    '/storage/uploads/' => 'folder upload',
    '/tests/' => 'folder tests',
    '/tests/tahap13/keamanan.php' => 'berkas tes',
    '/vendor/' => 'folder vendor',
    '/.git/' => 'folder git',
    '/composer.json' => 'composer.json',
    '/composer.lock' => 'composer.lock',
    '/README.md' => 'dokumentasi',
    '/PROGRESS.md' => 'dokumentasi progres',
];

foreach ($sensitive as $path => $label) {
    $r = $anon->get(SEC_ROOT . $path);
    $allowed = in_array($r[0], [403, 404], true);
    $leak = stripos($r[1], 'GEMINI_API_KEY') !== false
        || stripos($r[1], 'AI_SETTINGS_KEY') !== false
        || stripos($r[1], 'DB_PASSWORD') !== false
        || stripos($r[1], 'Index of /') !== false;
    check("tolak {$label} ({$path})", $allowed && !$leak, 'status=' . $r[0] . ' leak=' . var_export($leak, true));
}

$r = $anon->get(SEC_ROOT . '/');
check('akar proyek diarahkan ke public/', in_array($r[0], [301, 302], true) && str_contains($r[3], '/public/'), 'status=' . $r[0] . ' loc=' . $r[3]);

$r = $anon->get(SEC_BASE . '/');
check('URL utama (public/) diarahkan ke login', $r[0] === 302 && str_contains($r[3], '/login'), 'status=' . $r[0] . ' loc=' . $r[3]);

$r = $anon->get(SEC_BASE . '/assets/css/style.css');
check('aset CSS publik tetap 200', $r[0] === 200 && str_contains($r[2]['content-type'] ?? '', 'text/css'), 'status=' . $r[0]);

$r = $anon->get(SEC_BASE . '/assets/js/main.js');
check('aset JS publik tetap 200', $r[0] === 200, 'status=' . $r[0]);

// Path traversal.
$traversal = [
    '/public/../.env',
    '/public/%2e%2e/.env',
    '/public/assets/../../.env',
    '/public/..%2f..%2f.env',
    '/public/%2e%2e%2f%2e%2e%2f.env',
];
foreach ($traversal as $path) {
    $r = $anon->get(SEC_ROOT . $path);
    $blocked = in_array($r[0], [400, 403, 404], true);
    $leak = stripos($r[1], 'GEMINI_API_KEY') !== false || stripos($r[1], 'APP_DEBUG') !== false;
    check("path traversal ditolak ({$path})", $blocked && !$leak, 'status=' . $r[0] . ' leak=' . var_export($leak, true));
}

/* ------------------------------------------------------------------ */
/* B. AUTENTIKASI & RBAC                                              */
/* ------------------------------------------------------------------ */
echo "\n--- B. Autentikasi & RBAC ---\n";

$privatePages = [
    '/admin/dashboard',
    '/admin/pengguna',
    '/admin/pelanggan',
    '/admin/laporan',
    '/admin/audit',
    '/cs/dashboard',
    '/teknisi/dashboard',
    '/pelanggan/dashboard',
    '/pelanggan/profil',
    '/pelanggan/pengaduan',
    '/notifikasi',
    '/ai',
];
foreach ($privatePages as $path) {
    $r = $anon->get(appUrl($path));
    check("anon {$path} -> login", $r[0] === 302 && str_contains($r[3], '/login'), 'status=' . $r[0] . ' loc=' . $r[3]);
}

$r = $anon->request('POST', appUrl('/ai/chat'), [
    'json' => ['message' => 'halo'],
    'headers' => ['X-Requested-With: XMLHttpRequest', 'Accept: application/json'],
]);
$json = json_decode($r[1], true);
check('API /ai/chat anon -> 401 JSON', $r[0] === 401 && is_array($json) && ($json['ok'] ?? true) === false, 'status=' . $r[0] . ' body=' . substr($r[1], 0, 100));
check('401 JSON tidak memuat stack trace/path', !str_contains($r[1], 'C:\\xampp') && !str_contains($r[1], 'Stack trace'));

$r = $anon->request('POST', appUrl('/ai/chat'), ['fields' => ['message' => 'halo']]);
check('API /ai/chat tanpa header khusus tetap 401 JSON', $r[0] === 401 && str_contains($r[2]['content-type'] ?? '', 'application/json'), 'status=' . $r[0] . ' type=' . ($r[2]['content-type'] ?? ''));

$r = $anon->get(appUrl('/pengaduan/foto/1'));
check('foto anon (browser) ditolak', in_array($r[0], [302, 401, 403], true), 'status=' . $r[0]);
$r = $anon->request('GET', appUrl('/pengaduan/foto/1'), ['headers' => ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']]);
check('foto anon (AJAX) -> 401', $r[0] === 401, 'status=' . $r[0]);

// Login empat peran (akun uji database utama, READ ONLY).
$admin = new Http('admin');
$cs = new Http('cs');
$tek = new Http('tek');
$pel = new Http('pel');
$pel2 = new Http('pel2');

check('login Admin berhasil', $admin->login('admin.budi', T10_PW_ADMIN));
check('login CS berhasil', $cs->login('cs.rizki', T10_PW_CS));
check('login Teknisi berhasil', $tek->login('cdn.romiz', T10_PW_TEK));
check('login Pelanggan Rina berhasil', $pel->login('pppoe.rina', T10_PW_PEL));
check('login Pelanggan Agus berhasil', $pel2->login('pppoe.agus', T10_PW_PEL));

$dashboards = [
    'Admin' => [$admin, '/admin/dashboard', '/pelanggan/dashboard'],
    'CS' => [$cs, '/cs/dashboard', '/admin/dashboard'],
    'Teknisi' => [$tek, '/teknisi/dashboard', '/admin/dashboard'],
    'Pelanggan' => [$pel, '/pelanggan/dashboard', '/admin/dashboard'],
];
foreach ($dashboards as $role => [$session, $own, $foreign]) {
    $r = $session->get(appUrl($own));
    check("{$role} membuka dashboard sendiri 200", $r[0] === 200, 'status=' . $r[0]);
    check("{$role} dashboard no-store", stripos($session->headers['cache-control'] ?? '', 'no-store') !== false, $session->headers['cache-control'] ?? '');
    $r = $session->get(appUrl($foreign));
    check("{$role} dashboard peran lain -> 403", $r[0] === 403, 'status=' . $r[0]);
}

$matrix = [
    ['Admin -> Data Pelanggan (admin)', $admin, '/admin/pelanggan', 200],
    ['Admin -> Data Karyawan', $admin, '/admin/pengguna', 200],
    ['Admin -> Monitoring Teknisi', $admin, '/admin/teknisi', 200],
    ['Admin -> Laporan', $admin, '/admin/laporan', 200],
    ['Admin -> Audit', $admin, '/admin/audit', 200],
    ['CS -> Verifikasi', $cs, '/cs/verifikasi', 200],
    ['CS -> Data Pelanggan admin', $cs, '/admin/pelanggan', 403],
    ['CS -> Data Karyawan', $cs, '/admin/pengguna', 403],
    ['Teknisi -> Tugas', $tek, '/teknisi/tugas', 200],
    ['Teknisi -> Verifikasi CS', $tek, '/cs/verifikasi', 403],
    ['Pelanggan -> Pengaduan baru', $pel, '/pelanggan/pengaduan/baru', 200],
    ['Pelanggan -> Data Karyawan', $pel, '/admin/pengguna', 403],
    ['Pelanggan -> Laporan admin', $pel, '/admin/laporan', 403],
    ['Pelanggan -> AI (auth)', $pel, '/ai', 200],
];
foreach ($matrix as [$label, $session, $path, $expected]) {
    $r = $session->get(appUrl($path));
    check("akses {$label} = {$expected}", $r[0] === $expected, 'status=' . $r[0]);
}

// Identitas pelanggan tidak tertukar.
$r = $pel->get(appUrl('/pelanggan/profil'));
check('profil Rina memuat nama sendiri', str_contains($r[1], 'Rina'), 'status=' . $r[0]);
check('profil Rina tidak memuat Dewi', stripos($r[1], 'Dewi') === false);
$r = $pel2->get(appUrl('/pelanggan/profil'));
check('profil Agus tidak memuat nama Rina', stripos($r[1], 'Rina') === false);

// Parameter/header yang mencoba menentukan peran diabaikan.
$r = $pel->get(appUrl('/pelanggan/dashboard?role=admin&user_id=1'), ['X-User-Id: 1', 'X-Role: admin']);
check('parameter/header peran palsu diabaikan', $r[0] === 200 && stripos($r[1], 'Administrator') === false && stripos($r[1], 'Data Karyawan') === false, 'status=' . $r[0]);

/* ------------------------------------------------------------------ */
/* C. SESSION, COOKIE, LOGOUT, CSRF                                   */
/* ------------------------------------------------------------------ */
echo "\n--- C. Session, cookie, logout, CSRF ---\n";

$fresh = new Http('fresh');
$fresh->get(appUrl('/login'));
$cookie = $fresh->headers['set-cookie'] ?? '';
check('cookie HttpOnly', stripos($cookie, 'httponly') !== false, $cookie);
check('cookie SameSite=Lax', stripos($cookie, 'samesite=lax') !== false, $cookie);
check('cookie Secure tidak dipaksa pada HTTP lokal', stripos($cookie, 'secure') === false, $cookie);

$beforeLogin = $fresh->cookieValue();
$fresh->login('pppoe.rina', T10_PW_PEL);
$afterLogin = $fresh->cookieValue();
check('session ID berubah setelah login (anti fixation)', $beforeLogin !== '' && $afterLogin !== '' && $beforeLogin !== $afterLogin, "before={$beforeLogin} after={$afterLogin}");

$dashboard = $fresh->get(appUrl('/pelanggan/dashboard'));
$token = $fresh->token();
$r = $fresh->request('POST', appUrl('/logout'), ['fields' => ['_token' => 'invalid']]);
check('logout tanpa CSRF valid -> 403', $r[0] === 403, 'status=' . $r[0]);
$r = $fresh->get(appUrl('/pelanggan/dashboard'));
check('session tetap aktif setelah logout CSRF gagal', $r[0] === 200, 'status=' . $r[0]);

$r = $fresh->request('POST', appUrl('/logout'), ['fields' => ['_token' => $token]]);
check('logout valid -> redirect login', $r[0] === 302 && str_contains($r[3], '/login'), 'status=' . $r[0] . ' loc=' . $r[3]);
$r = $fresh->get(appUrl('/pelanggan/dashboard'));
check('setelah logout dashboard -> login', $r[0] === 302 && str_contains($r[3], '/login'), 'status=' . $r[0] . ' loc=' . $r[3]);

/* ------------------------------------------------------------------ */
/* D. FOTO PENGADUAN — OTORISASI KEPEMILIKAN                          */
/* ------------------------------------------------------------------ */
echo "\n--- D. Otorisasi foto pengaduan ---\n";

$photoId = (int) (getenv('SEC_PHOTO_ID') ?: 1);
$r = $pel->get(appUrl('/pengaduan/foto/' . $photoId));
check('pemilik tiket dapat membuka foto (200)', $r[0] === 200 && str_starts_with($r[2]['content-type'] ?? '', 'image/'), 'status=' . $r[0] . ' type=' . ($r[2]['content-type'] ?? ''));
$r = $pel2->get(appUrl('/pengaduan/foto/' . $photoId));
check('pelanggan lain ditolak (403)', $r[0] === 403, 'status=' . $r[0]);
$r = $admin->get(appUrl('/pengaduan/foto/' . $photoId));
check('Admin dapat membuka foto (200)', $r[0] === 200, 'status=' . $r[0]);
$r = $cs->get(appUrl('/pengaduan/foto/' . $photoId));
check('CS dapat membuka foto (200)', $r[0] === 200, 'status=' . $r[0]);
$r = $tek->get(appUrl('/pengaduan/foto/' . $photoId));
check('Teknisi tidak ditugaskan ditolak (403)', $r[0] === 403, 'status=' . $r[0]);
$r = $tek->get(appUrl('/pengaduan/foto/999999'));
check('foto tidak ada -> 404', $r[0] === 404, 'status=' . $r[0]);
$r = $pel->get(appUrl('/pengaduan/foto/abc'));
check('ID foto non-numerik -> 404', $r[0] === 404, 'status=' . $r[0]);

/* ------------------------------------------------------------------ */
/* E. ERROR HANDLING                                                  */
/* ------------------------------------------------------------------ */
echo "\n--- E. Error handling ---\n";

$r = $anon->get(appUrl('/halaman-tidak-ada'));
check('404 tidak membocorkan path server', $r[0] === 404 && !str_contains($r[1], 'C:\\xampp') && !str_contains($r[1], 'Stack trace') && !str_contains($r[1], 'Fatal error'), 'status=' . $r[0]);
$r = $anon->get(SEC_ROOT . '/app/bootstrap.php');
check('akses PHP internal tidak mengeksekusi/membocorkan isi', in_array($r[0], [403, 404], true) && !str_contains($r[1], '<?php'), 'status=' . $r[0]);

// Respons privat tidak boleh tersimpan di cache.
$r = $admin->get(appUrl('/admin/dashboard'));
check('halaman privat mengirim Pragma: no-cache', stripos($admin->headers['pragma'] ?? '', 'no-cache') !== false, $admin->headers['pragma'] ?? '');
check('halaman privat mengirim Expires', isset($admin->headers['expires']), $admin->headers['expires'] ?? '');

// Secure cookie pada HTTPS tidak dapat diuji pada instalasi HTTP lokal.
skip('cookie Secure saat HTTPS', 'pengujian memerlukan VirtualHost HTTPS/TLS');

@unlink($anon->jar);
@unlink($admin->jar);
@unlink($cs->jar);
@unlink($tek->jar);
@unlink($pel->jar);
@unlink($pel2->jar);
@unlink($fresh->jar);

echo "\n----------------------------------------------------------------\n";
echo "TOTAL: " . ($PASS + $FAIL + $SKIP) . " | PASS: {$PASS} | FAIL: {$FAIL} | SKIP: {$SKIP}\n";
if ($FAIL > 0) {
    echo "GAGAL:\n";
    foreach ($FAILED as $failure) {
        echo "  - {$failure}\n";
    }
    exit(1);
}

echo "SEMUA TES KEAMANAN TAHAP 13 LULUS.\n";
