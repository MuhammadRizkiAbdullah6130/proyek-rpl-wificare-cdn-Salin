<?php
/**
 * WiFiCare CDN — Bug fix: sidebar/header role konsisten & RBAC (18 Oktober 2026).
 *
 * Latar: dahulu `render_view()` menyatukan lingkup view dan layout, sehingga
 * variabel `$role` yang dibuat di dalam view (loop daftar pengguna) bocor ke
 * layout dan membuat sidebar/header memakai peran yang salah.
 *
 * Uji ini membuktikan:
 *  - Sidebar & header SELALU memakai peran akun yang login (dari database),
 *    pada semua halaman Admin dan pada query `?role=...`.
 *  - Identitas (nama) konsisten dengan akun login; session user_id stabil.
 *  - CS/Teknisi/Pelanggan ditolak HTTP 403 pada Manajemen Pengguna, tanpa
 *    membocorkan data manajemen.
 *  - Logout menghapus session; login ulang peran lain tidak memakai peran lama.
 *  - Dua sesi (tab) berbeda tidak saling mengubah peran.
 *
 * Prasyarat: server uji di http://localhost:8099 (DB uji wificare_cdn_test).
 *
 * Jalankan: php tests/tahap12/role-sidebar.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../tahap10/_config.php';
t10_require_passwords(['T10_PW_ADMIN', 'T10_PW_CS', 'T10_PW_TEK', 'T10_PW_PEL']);

define('BASE', T10_BASE);
define('RS_TMP', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_rs');
if (!is_dir(RS_TMP)) {
    @mkdir(RS_TMP, 0775, true);
}

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
    public int $lastStatus = 0;
    public array $lastHeaders = [];

    public function __construct(public string $name)
    {
        $this->jar = RS_TMP . DIRECTORY_SEPARATOR . 'jar_' . preg_replace('/[^a-z0-9]/i', '_', $name) . '.txt';
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
            curl_close($ch);
            return ['status' => 0, 'body' => ''];
        }
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $this->lastStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $body = substr($raw, $headerSize);
        $this->lastHeaders = [];
        foreach (explode("\r\n", substr($raw, 0, $headerSize)) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $this->lastHeaders[strtolower(trim($k))] = trim($v);
            }
        }
        curl_close($ch);
        return ['status' => $this->lastStatus, 'body' => $body];
    }

    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    public function tokenFrom(string $html): string
    {
        return preg_match('/name="_token"\s+value="([a-f0-9]{64})"/', $html, $m) === 1 ? $m[1] : '';
    }

    public function login(string $username, string $password): array
    {
        $r = $this->get('/login');
        return $this->request('POST', '/login', [
            'fields' => ['_token' => $this->tokenFrom($r['body']), 'username' => $username, 'password' => $password],
        ]);
    }

    public function logout(): array
    {
        $r = $this->get('/admin/dashboard');
        return $this->request('POST', '/logout', ['fields' => ['_token' => $this->tokenFrom($r['body'])]]);
    }

    public function location(): string
    {
        return $this->lastHeaders['location'] ?? '';
    }
}

/** Ambil label peran dari beberapa tempat pada HTML. */
function roleSnap(string $html): array
{
    preg_match('/app-header-eyebrow">([^<]*)</', $html, $a);
    preg_match('/app-sidebar-role">([^<]*)</', $html, $b);
    preg_match('/app-user-role">([^<]*)</', $html, $c);
    preg_match('/app-user-name">([^<]*)</', $html, $d);

    return [
        'eyebrow' => trim($a[1] ?? ''),
        'sidebar' => trim($b[1] ?? ''),
        'chip' => trim($c[1] ?? ''),
        'nama' => trim($d[1] ?? ''),
    ];
}

echo "==== BUGFIX ROLE/SIDEBAR — WiFiCare CDN ====\n";

/* -------------------------------------------------------------------------
 * 1. Admin — semua halaman memakai sidebar/header Administrator
 * ----------------------------------------------------------------------- */
echo "--- Admin ---\n";
$admin = new Sess('rs_admin');
$r = $admin->login('admin.budi', T10_PW_ADMIN);
check('Admin login berhasil (302)', $r['status'] === 302, 'status=' . $r['status']);

$adminPages = [
    '/admin/dashboard',
    '/admin/pengguna',
    '/admin/pelanggan',
    '/admin/pengaduan',
    '/admin/teknisi',
    '/admin/laporan',
    '/admin/kategori',
    '/admin/audit',
    '/admin/pengaturan',
    '/admin/pengaturan/ai',
    '/admin/dashboard', // kembali ke dashboard
];

$allAdminOk = true;
foreach ($adminPages as $path) {
    $res = $admin->get($path);
    $snap = roleSnap($res['body']);
    $ok = $res['status'] === 200
        && $snap['eyebrow'] === 'Administrator'
        && $snap['sidebar'] === 'Administrator'
        && $snap['chip'] === 'Administrator'
        && str_contains($res['body'], '/admin/pengguna')
        && !str_contains($res['body'], '/pelanggan/dashboard');
    if (!$ok) {
        $allAdminOk = false;
        check("Admin {$path} konsisten", false, 'status=' . $res['status'] . ' ' . json_encode($snap));
    }
}
check('Admin: semua halaman memakai sidebar/header Administrator (tanpa tertukar)', $allAdminOk);

// Filter peran pada query tidak boleh mengubah sidebar.
foreach (['pelanggan', 'teknisi', 'cs', 'admin'] as $f) {
    $res = $admin->get('/admin/pengguna?role=' . $f);
    $snap = roleSnap($res['body']);
    check(
        "Admin: ?role={$f} tidak mengubah sidebar",
        $res['status'] === 200 && $snap['sidebar'] === 'Administrator' && $snap['eyebrow'] === 'Administrator',
        json_encode($snap)
    );
}

// Nama akun konsisten.
$res = $admin->get('/admin/pengguna');
$snap = roleSnap($res['body']);
check('Admin: nama pada header = akun login (Budi Santoso)', $snap['nama'] === 'Budi Santoso', $snap['nama']);

// Refresh tidak mengubah peran.
$r1 = roleSnap($admin->get('/admin/pengguna')['body']);
$r2 = roleSnap($admin->get('/admin/pengguna')['body']);
check('Admin: refresh tidak mengubah peran', $r1['sidebar'] === 'Administrator' && $r2['sidebar'] === 'Administrator');

// Session user_id stabil: tetap bisa membuka halaman admin setelah beberapa navigasi.
$res = $admin->get('/admin/audit');
check('Admin: session tetap valid setelah navigasi berulang', $res['status'] === 200 && roleSnap($res['body'])['sidebar'] === 'Administrator');

/* -------------------------------------------------------------------------
 * 2. RBAC — CS/Teknisi/Pelanggan ditolak 403 pada Manajemen Pengguna
 * ----------------------------------------------------------------------- */
echo "--- RBAC ---\n";
$denied = [
    'cs' => ['cs.dewi', T10_PW_CS],
    'teknisi' => ['tek.andi', T10_PW_TEK],
    'pelanggan' => ['pppoe.rina', T10_PW_PEL],
];
foreach ($denied as $roleName => [$username, $password]) {
    $s = new Sess('rs_' . $roleName);
    $s->login($username, $password);
    $res = $s->get('/admin/pengguna');
    $body = $res['body'];
    $noLeak = !str_contains($body, 'Manajemen Pengguna')
        && !str_contains($body, 'admin.budi')
        && !str_contains($body, 'Tambah Pengguna');
    check("RBAC: {$roleName} → /admin/pengguna 403", $res['status'] === 403, 'status=' . $res['status']);
    check("RBAC: {$roleName} tidak menerima data manajemen", $noLeak);

    // Sidebar sesuai peran masing-masing (bukan admin).
    $res2 = $s->get('/' . $roleName . '/dashboard');
    $snap = roleSnap($res2['body']);
    $expected = ['cs' => 'Customer Service', 'teknisi' => 'Teknisi', 'pelanggan' => 'Pelanggan'][$roleName];
    check("RBAC: sidebar {$roleName} = {$expected}", $snap['sidebar'] === $expected && $snap['eyebrow'] === $expected, json_encode($snap));
    check("RBAC: {$roleName} tidak memuat menu admin", !str_contains($res2['body'], '/admin/pengguna'));
}

/* -------------------------------------------------------------------------
 * 3. Logout & login ulang peran berbeda
 * ----------------------------------------------------------------------- */
echo "--- Logout & login ulang ---\n";
$r = $admin->logout();
check('Logout → 302 ke /login', $r['status'] === 302 && str_contains($admin->location(), '/login'));

$res = $admin->get('/admin/dashboard');
check('Setelah logout, /admin/dashboard → 302 login', $res['status'] === 302 && str_contains($admin->location(), '/login'), 'status=' . $res['status']);

// Login ulang sebagai CS pada sesi yang sama — tidak memakai peran Admin lama.
$r = $admin->login('cs.dewi', T10_PW_CS);
check('Login ulang (sesi sama) sebagai CS berhasil', $r['status'] === 302 && str_contains($admin->location(), '/cs/dashboard'), 'loc=' . $admin->location());
$res = $admin->get('/cs/dashboard');
$snap = roleSnap($res['body']);
check('Login ulang tidak memakai peran lama (sidebar CS)', $snap['sidebar'] === 'Customer Service' && $snap['chip'] === 'Customer Service', json_encode($snap));
$res = $admin->get('/admin/pengguna');
check('Setelah ganti peran ke CS, Manajemen Pengguna ditolak 403', $res['status'] === 403, 'status=' . $res['status']);

/* -------------------------------------------------------------------------
 * 4. Dua sesi berbeda tidak saling mengubah peran
 * ----------------------------------------------------------------------- */
echo "--- Sesi paralel ---\n";
$a2 = new Sess('rs_admin2');
$p2 = new Sess('rs_pel2');
$a2->login('admin.budi', T10_PW_ADMIN);
$p2->login('pppoe.rina', T10_PW_PEL);

$aSnap1 = roleSnap($a2->get('/admin/dashboard')['body']);
$pSnap1 = roleSnap($p2->get('/pelanggan/dashboard')['body']);
$aSnap2 = roleSnap($a2->get('/admin/pengguna')['body']);
$pSnap2 = roleSnap($p2->get('/pelanggan/pengaduan')['body']);

check('Sesi paralel: Admin tetap Administrator', $aSnap1['sidebar'] === 'Administrator' && $aSnap2['sidebar'] === 'Administrator');
check('Sesi paralel: Pelanggan tetap Pelanggan', $pSnap1['sidebar'] === 'Pelanggan' && $pSnap2['sidebar'] === 'Pelanggan');

echo "----------------------------------------------------------------\n";
echo 'TOTAL: ' . ($PASS + $FAIL) . " | PASS: {$PASS} | FAIL: {$FAIL}\n";
if ($FAIL > 0) {
    echo "GAGAL:\n";
    foreach ($FAILED as $f) {
        echo '  - ' . $f . "\n";
    }
    exit(1);
}
echo "SEMUA TES ROLE/SIDEBAR LULUS.\n";
