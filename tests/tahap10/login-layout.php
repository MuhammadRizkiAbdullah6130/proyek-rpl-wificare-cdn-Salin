<?php
/**
 * WiFiCare CDN — Tahap 10: verifikasi layout login full-screen.
 *
 * Memastikan halaman login memakai layout autentikasi (tanpa sidebar/navbar/
 * footer/AI widget), form lengkap (username, password, show/hide, loading,
 * pesan error, info akun), dan autentikasi tetap berfungsi (CSRF, kredensial,
 * redirect per peran). Dashboard tidak berubah.
 */

declare(strict_types=1);

require_once __DIR__ . '/_config.php';
t10_require_passwords(['T10_PW_ADMIN', 'T10_PW_CS', 'T10_PW_TEK', 'T10_PW_PEL']);
define('BASE', T10_BASE);
define('TMP', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_t10');
if (!is_dir(TMP)) { @mkdir(TMP, 0775, true); }

$PASS = 0;
$FAIL = 0;
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

final class Sess
{
    public string $jar;
    public string $lastBody = '';
    public int $lastStatus = 0;
    public array $lastHeaders = [];

    public function __construct(public string $name)
    {
        $this->jar = TMP . '/login_jar_' . preg_replace('/[^a-z0-9]/i', '_', $name) . '.txt';
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
        $raw = curl_exec($ch);
        if ($raw === false) {
            $this->lastStatus = 0;
            $this->lastBody = 'CURL_ERROR: ' . curl_error($ch);
            curl_close($ch);
            return $this->snapshot();
        }
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $this->lastStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
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
        if (preg_match('/name="_token"\s+value="([a-f0-9]{64})"/', $this->lastBody, $m)) {
            return $m[1];
        }
        return '';
    }

    public function login(string $username, string $password): array
    {
        $this->get('/login');
        $token = $this->token();
        return $this->request('POST', '/login', [
            'fields' => ['_token' => $token, 'username' => $username, 'password' => $password],
        ]);
    }

    public function location(): string
    {
        return $this->lastHeaders['location'] ?? '';
    }
}

echo "==== TAHAP 10 — LOGIN FULL-SCREEN LAYOUT ====\n";

// --- 1. Struktur halaman login ------------------------------------------------
$guest = new Sess('login_guest');
$r = $guest->get('/login');
$body = $r['body'];

check('GET /login 200', $r['status'] === 200, 'status=' . $r['status']);
check('layout autentikasi dipakai', str_contains($body, 'auth-shell') && str_contains($body, 'auth-panel'));
check('tanpa sidebar dashboard', !str_contains($body, 'app-sidebar'));
check('tanpa navbar/header dashboard', !str_contains($body, 'app-header'));
check('tanpa footer dashboard', !str_contains($body, 'app-footer'));
check('tanpa menu Beranda/Tentang/Masuk sidebar', !preg_match('/>\s*(Beranda|Tentang)\s*</', $body));
check('tanpa floating AI widget', !str_contains($body, 'data-ai-widget') && !str_contains($body, 'assets/js/ai-widget.js'));

// --- 2. Isi form --------------------------------------------------------------
check('judul "Selamat Datang"', str_contains($body, 'Selamat Datang'));
check('subtitle', str_contains($body, 'Masuk ke akun WiFiCare CDN Anda'));
check('logo/brand WiFiCare CDN', str_contains($body, 'app-brand-mark') && str_contains($body, 'WiFiCare CDN'));
check('field Username / PPPoE', str_contains($body, 'Username / PPPoE') && str_contains($body, 'name="username"'));
check('field Password WiFiCare', str_contains($body, 'Password WiFiCare') && str_contains($body, 'name="password"'));
check('input password bertipe password', (bool) preg_match('/id="login-password"[^>]*type="password"|type="password"[^>]*id="login-password"/', $body));
check('tombol show/hide password', str_contains($body, 'data-password-toggle="login-password"') && str_contains($body, 'aria-pressed="false"'));
check('tombol Masuk + ikon', str_contains($body, 'auth-submit') && str_contains($body, '>Masuk<'));
check('loading state ada', str_contains($body, 'data-loading') && str_contains($body, 'auth-submit-loading') && str_contains($body, 'auth-spinner'));
check('info akun dibuat Administrator', str_contains($body, 'dibuat oleh Administrator'));
check('tanpa tautan registrasi publik', !preg_match('/href="[^"]*(register|daftar|signup)/i', $body));

// --- 2b. Aset CSS/JS ----------------------------------------------------------
$css = $guest->get('/assets/css/style.css');
check('style.css 200', $css['status'] === 200, 'status=' . $css['status']);
$c = $css['body'];
check('CSS palet: #0B1120 / #172238 / #2563EB', str_contains($c, '#0b1120') && str_contains($c, '#172238') && str_contains($c, '#2563eb'));
check('CSS auth-shell full-screen (100dvh)', str_contains($c, '.auth-shell') && str_contains($c, '100dvh'));
check('CSS card ~420px', str_contains($c, 'max-width: 420px'));
check('CSS responsif <=480px', str_contains($c, '@media (max-width: 480px)'));
check('CSS spinner loading', str_contains($c, '.auth-spinner') && str_contains($c, '@keyframes wc-spin'));
$js = $guest->get('/assets/js/main.js')['body'];
check('JS toggle password & loading state', str_contains($js, 'data-password-toggle') && str_contains($js, 'form[data-loading]'));

// --- 3. CSRF & validasi server tetap berlaku ---------------------------------
$jarToken = $guest->get('/login');
$r = $guest->request('POST', '/login', ['fields' => ['username' => 'admin.budi', 'password' => T10_PW_ADMIN]]);
check('POST /login tanpa CSRF ditolak (302 ke /login)', $r['status'] === 302 && str_contains($guest->location(), '/login'), 'status=' . $r['status'] . ' loc=' . $guest->location());
check('tanpa CSRF tidak masuk (dashboard tetap 302)', $guest->get('/admin/dashboard')['status'] === 302);

// --- 4. Kredensial salah ------------------------------------------------------
$r = $guest->login('admin.budi', 'salah-sekali');
check('kredensial salah -> 302 /login', $r['status'] === 302 && str_contains($guest->location(), '/login'), 'status=' . $r['status']);
$back = $guest->get('/login');
check('kredensial salah -> pesan error', str_contains($back['body'], 'Username atau password salah'));
check('kredensial salah -> tidak masuk', $guest->get('/admin/dashboard')['status'] === 302);

// --- 5. Redirect per peran ----------------------------------------------------
$accounts = [
    ['admin.budi', T10_PW_ADMIN, '/admin/dashboard', 'admin'],
    ['cs.dewi', T10_PW_CS, '/cs/dashboard', 'cs'],
    ['tek.andi', T10_PW_TEK, '/teknisi/dashboard', 'teknisi'],
    ['pppoe.rina', T10_PW_PEL, '/pelanggan/dashboard', 'pelanggan'],
];
foreach ($accounts as [$u, $p, $target, $label]) {
    $s = new Sess('login_' . $label);
    $r = $s->login($u, $p);
    check("login {$label} sukses + redirect {$target}", $r['status'] === 302 && str_contains($s->location(), $target), 'status=' . $r['status'] . ' loc=' . $s->location());

    $dash = $s->get($target);
    check("dashboard {$label} tetap punya sidebar+header", str_contains($dash['body'], 'app-sidebar') && str_contains($dash['body'], 'app-header'));
    check("dashboard {$label} tetap punya floating AI widget", str_contains($dash['body'], 'data-ai-widget'));
}

// --- 6. Pelanggan memakai username PPPoE -------------------------------------
$p = new Sess('login_pppoe');
$r = $p->login('pppoe.rina', T10_PW_PEL);
check('pelanggan login dengan Username PPPoE', $r['status'] === 302 && str_contains($p->location(), '/pelanggan/dashboard'));

echo "----------------------------------------------------------------\n";
echo "TOTAL: " . ($PASS + $FAIL) . " | PASS: {$PASS} | FAIL: {$FAIL}\n";
if ($FAIL > 0) {
    echo "GAGAL:\n";
    foreach ($FAILED as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}
echo "SEMUA TES LOGIN FULL-SCREEN LULUS.\n";
exit(0);
