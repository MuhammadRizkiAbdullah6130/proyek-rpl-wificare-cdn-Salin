<?php
/**
 * WiFiCare CDN — Tahap 26: Refactor navigasi WhatsApp Gateway.
 *
 * Menguji submenu sidebar, halaman terpisah, active state, breadcrumb,
 * kompatibilitas URL lama, RBAC, CSRF, tema, dan tidak adanya tab lama.
 *
 * Prasyarat:
 *   php -S 127.0.0.1:8099 -t public public/index.php   (DB uji, APP_BASE_PATH=/)
 *
 * Jalankan:
 *   php tests/tahap26/whatsapp-navigation.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../tahap10/_config.php';
t10_require_passwords(['T10_PW_ADMIN', 'T10_PW_CS']);

putenv('DB_NAME=wificare_cdn_test');
putenv('APP_BASE_PATH=/');
$_ENV['DB_NAME'] = 'wificare_cdn_test';
$_ENV['APP_BASE_PATH'] = '/';
$_SERVER['DB_NAME'] = 'wificare_cdn_test';
$_SERVER['APP_BASE_PATH'] = '/';

require_once __DIR__ . '/../../app/bootstrap.php';

$PASS = 0;
$FAIL = 0;
$SKIP = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $PASS, $FAIL;
    if ($ok) {
        $PASS++;
        echo "PASS  {$name}\n";

        return;
    }
    $FAIL++;
    echo "FAIL  {$name}" . ($detail !== '' ? " :: {$detail}" : '') . "\n";
}

function skip(string $name, string $reason): void
{
    global $SKIP;
    $SKIP++;
    echo "SKIP  {$name} :: {$reason}\n";
}

function section(string $title): void
{
    echo "\n=== {$title} ===\n";
}

function nav_http(string $method, string $path, ?string $jar = null, array $form = []): array
{
    $ch = curl_init(T10_BASE . $path);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
    ];
    if ($jar !== null) {
        $options[CURLOPT_COOKIEJAR] = $jar;
        $options[CURLOPT_COOKIEFILE] = $jar;
    }
    if (strtoupper($method) === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($form);
        $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/x-www-form-urlencoded'];
    }
    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $error !== '') {
        return [0, '', '', $error];
    }

    return [$status, (string) $body, $location, ''];
}

function nav_jar(string $tag): string
{
    $jar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_t26_' . $tag . '_' . getmypid() . '.jar';
    @unlink($jar);

    return $jar;
}

function nav_login(string $tag, string $username, string $password): ?string
{
    $jar = nav_jar($tag);
    [$status, $html] = nav_http('GET', '/login', $jar);
    if ($status !== 200) {
        return null;
    }
    if (preg_match('/name="_token"\s+value="([a-f0-9]{64})"/', $html, $matches) !== 1) {
        return null;
    }
    [$loginStatus] = nav_http('POST', '/login', $jar, [
        '_token' => $matches[1],
        'username' => $username,
        'password' => $password,
    ]);
    if ($loginStatus !== 302) {
        return null;
    }

    return $jar;
}

function nav_breadcrumb(string $html): string
{
    if (preg_match('/<nav class="app-breadcrumb".*?<\/nav>/s', $html, $matches) === 1) {
        return preg_replace('/\s+/', ' ', strip_tags($matches[0])) ?? '';
    }

    return '';
}

$socket = @fsockopen('127.0.0.1', 8099, $errno, $errstr, 2);
if ($socket === false) {
    echo "BLOCKED  Server aplikasi uji 127.0.0.1:8099 tidak aktif.\n";
    exit(2);
}
fclose($socket);

$pdo = new PDO(T10_DB_DSN, T10_DB_USER, T10_DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

section('Menu Sidebar');
$adminMenu = role_menu('admin');
$whatsapp = null;
foreach ($adminMenu as $item) {
    if (($item['key'] ?? '') === 'whatsapp') {
        $whatsapp = $item;
        break;
    }
}
$children = is_array($whatsapp['children'] ?? null) ? $whatsapp['children'] : [];
check('Menu induk WhatsApp Gateway ada', $whatsapp !== null && ($whatsapp['label'] ?? '') === 'WhatsApp Gateway');
check('Lima submenu WhatsApp tersedia', count($children) === 5);
check(
    'Submenu memakai route kanonis',
    count(array_filter($children, static fn (array $c): bool => str_contains((string) ($c['url'] ?? ''), '/admin/whatsapp/'))) === 5
);

$adminJar = nav_login('admin', 'admin.budi', T10_PW_ADMIN);
if ($adminJar === null) {
    echo "BLOCKED  Login Admin gagal; server/DB uji tidak siap.\n";
    exit(2);
}

$pages = [
    '/admin/whatsapp/gateway' => [
        'title' => 'Gateway WhatsApp',
        'crumb' => 'Gateway',
        'active' => 'Gateway',
        'marker' => 'name="default_country_code"',
        'absent' => ['name="ttl_seconds"', 'name="events[', 'name="schedule"'],
    ],
    '/admin/whatsapp/otp' => [
        'title' => 'OTP WhatsApp',
        'crumb' => 'OTP WhatsApp',
        'active' => 'OTP WhatsApp',
        'marker' => 'name="ttl_seconds"',
        'absent' => ['name="default_country_code"', 'name="events[', 'name="schedule"'],
    ],
    '/admin/whatsapp/notifikasi' => [
        'title' => 'Notifikasi WhatsApp',
        'crumb' => 'Notifikasi WhatsApp',
        'active' => 'Notifikasi WhatsApp',
        'marker' => 'name="events[',
        'absent' => ['name="ttl_seconds"', 'name="default_country_code"', 'name="schedule"'],
    ],
    '/admin/whatsapp/laporan' => [
        'title' => 'Laporan WhatsApp',
        'crumb' => 'Laporan WhatsApp',
        'active' => 'Laporan WhatsApp',
        'marker' => 'name="report_type"',
        'absent' => ['name="ttl_seconds"', 'name="default_country_code"', 'name="events[', 'name="schedule"'],
    ],
    '/admin/whatsapp/audit' => [
        'title' => 'Audit Konfigurasi',
        'crumb' => 'Audit Konfigurasi',
        'active' => 'Audit Konfigurasi',
        'marker' => 'Audit Konfigurasi WhatsApp',
        'absent' => ['name="ttl_seconds"', 'name="default_country_code"', 'name="events[', 'name="schedule"'],
    ],
];

section('Halaman Submenu');
foreach ($pages as $path => $expect) {
    [$status, $html] = nav_http('GET', $path, $adminJar);
    $titleOk = preg_match('/<title>' . preg_quote($expect['title'], '/') . '/', $html) === 1;
    $crumb = nav_breadcrumb($html);
    $activeOk = preg_match(
        '/class="app-sidebar-sublink is-active"[^>]*>.*?<span>' . preg_quote($expect['active'], '/') . '<\/span>/s',
        $html
    ) === 1;
    $parentOpen = str_contains($html, 'aria-expanded="true"')
        && preg_match('/id="sidebar-submenu-whatsapp"[^>]*data-submenu-panel(?![^>]*hidden)/s', $html) === 1;
    $onlyRelevant = str_contains($html, $expect['marker']);
    foreach ($expect['absent'] as $absent) {
        if (str_contains($html, $absent)) {
            $onlyRelevant = false;
        }
    }

    check("{$path} dapat dibuka", $status === 200, 'status=' . $status);
    check("{$path} judul benar", $titleOk, $expect['title']);
    check("{$path} active state benar", $activeOk, $expect['active']);
    check("{$path} induk submenu otomatis terbuka", $parentOpen);
    check("{$path} breadcrumb benar", str_contains($crumb, 'Admin') && str_contains($crumb, 'WhatsApp Gateway') && str_contains($crumb, $expect['crumb']), $crumb);
    check("{$path} hanya memuat konfigurasi relevan", $onlyRelevant);
    check("{$path} tidak memakai tab horizontal lama", !str_contains($html, 'class="api-tabs"'));
    check("{$path} tidak membocorkan ciphertext", !str_contains($html, 'v1:'));
}

section('Kompatibilitas URL Lama');
[$oldStatus, , $oldLocation] = nav_http('GET', '/admin/pengaturan/whatsapp', $adminJar);
check('URL lama diarahkan ke Gateway', $oldStatus === 302 && str_contains($oldLocation, '/admin/whatsapp/gateway'), $oldLocation);
[$tabStatus, , $tabLocation] = nav_http('GET', '/admin/pengaturan/whatsapp?tab=otp', $adminJar);
check('Parameter tab lama dipetakan ke OTP', $tabStatus === 302 && str_contains($tabLocation, '/admin/whatsapp/otp'), $tabLocation);
[$sectionStatus, , $sectionLocation] = nav_http('GET', '/admin/pengaturan/whatsapp?section=laporan', $adminJar);
check('Parameter section lama dipetakan ke Laporan', $sectionStatus === 302 && str_contains($sectionLocation, '/admin/whatsapp/laporan'), $sectionLocation);

$credentialId = (int) $pdo->query("SELECT id FROM api_credentials WHERE integration = 'whatsapp' ORDER BY id ASC LIMIT 1")->fetchColumn();
if ($credentialId > 0) {
    [$editStatus, , $editLocation] = nav_http('GET', '/admin/pengaturan/whatsapp?edit=' . $credentialId, $adminJar);
    check(
        'Parameter edit lama dipertahankan ke Gateway',
        $editStatus === 302 && str_contains($editLocation, '/admin/whatsapp/gateway?edit=' . $credentialId),
        $editLocation
    );
} else {
    skip('Parameter edit lama dipertahankan ke Gateway', 'Belum ada kredensial WhatsApp di DB uji');
}

section('Form, CSRF, & RBAC');
[$gatewayStatus, $gatewayHtml] = nav_http('GET', '/admin/whatsapp/gateway', $adminJar);
check('Form Simpan Gateway tersedia', str_contains($gatewayHtml, 'action="/admin/whatsapp/gateway"'));
check('Form Tes Koneksi tersedia', str_contains($gatewayHtml, '/admin/whatsapp/kredensial/tes'));
[$otpStatus, $otpHtml] = nav_http('GET', '/admin/whatsapp/otp', $adminJar);
check('Form Simpan OTP tersedia', str_contains($otpHtml, 'action="/admin/whatsapp/otp"'));
[$notifStatus, $notifHtml] = nav_http('GET', '/admin/whatsapp/notifikasi', $adminJar);
check('Form Simpan Notifikasi tersedia', str_contains($notifHtml, 'action="/admin/whatsapp/notifikasi"'));
check('Form Proses Antrean tersedia', str_contains($notifHtml, 'action="/admin/whatsapp/antrean"'));
[$reportStatus, $reportHtml] = nav_http('GET', '/admin/whatsapp/laporan', $adminJar);
check('Form Simpan Laporan tersedia', str_contains($reportHtml, 'action="/admin/whatsapp/laporan"'));

foreach ([
    '/admin/whatsapp/gateway',
    '/admin/whatsapp/otp',
    '/admin/whatsapp/notifikasi',
    '/admin/whatsapp/laporan',
    '/admin/whatsapp/antrean',
    '/admin/pengaturan/whatsapp/gateway',
] as $postPath) {
    [$csrfStatus, $csrfBody] = nav_http('POST', $postPath, $adminJar, ['name' => 'Tanpa CSRF']);
    check("CSRF wajib pada {$postPath}", $csrfStatus === 419 || str_contains($csrfBody, 'CSRF'), 'status=' . $csrfStatus);
}

$csJar = nav_login('cs', 'cs.dewi', T10_PW_CS);
if ($csJar !== null) {
    foreach (array_keys($pages) as $path) {
        [$csStatus] = nav_http('GET', $path, $csJar);
        check("CS ditolak dari {$path}", $csStatus === 403, 'status=' . $csStatus);
    }
} else {
    skip('RBAC CS pada seluruh submenu', 'Login CS gagal');
}

section('Tema & Responsif');
check('Mode gelap/terang tersedia', str_contains($gatewayHtml, 'data-theme="light"') && str_contains($gatewayHtml, 'wificare-theme'));
$css = (string) file_get_contents(__DIR__ . '/../../public/assets/css/style.css');
check('CSS sidebar mobile tetap ada', str_contains($css, '@media (max-width: 992px)') && str_contains($css, '.app-sidebar.is-open'));
check('CSS submenu aktif memakai warna primer', str_contains($css, '.app-sidebar-sublink.is-active'));
$js = (string) file_get_contents(__DIR__ . '/../../public/assets/js/main.js');
check('JavaScript toggle submenu tersedia', str_contains($js, 'initSidebarSubmenus') && str_contains($js, 'data-submenu-toggle'));
check('Pemetaan hash lama tersedia', str_contains($js, 'initLegacyWhatsAppHash') && str_contains($js, "otp: '/admin/whatsapp/otp'"));
check('Ikon parent dan chevron tersedia', str_contains($gatewayHtml, 'app-sidebar-chevron'));

section('Hasil');
echo "PASS={$PASS} FAIL={$FAIL} SKIP={$SKIP}\n";
exit($FAIL > 0 ? 1 : 0);
