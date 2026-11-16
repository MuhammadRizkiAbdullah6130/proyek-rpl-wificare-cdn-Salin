<?php
/**
 * Routing sederhana WiFiCare CDN.
 *
 * Format: METHOD /path → Controller@method
 *
 * Setiap route dapat memiliki penanda middleware:
 * - 'guest' => true : hanya pengunjung yang belum masuk.
 * - 'auth'  => true : wajib sudah masuk.
 * - 'role'  => string|array : wajib salah satu peran yang diizinkan (admin|cs|teknisi|pelanggan).
 */

declare(strict_types=1);

/**
 * Daftar pola routing.
 *
 * @var array<int, array{method: string, pattern: string, handler: string, guest?: bool, auth?: bool, role?: string|array<int, string>}>
 */
$routes = [
    // Halaman awal (Revisi 6): tanpa halaman publik ber-sidebar.
    // `/` mengarah ke login (belum masuk) atau dashboard per peran (sudah masuk).
    ['method' => 'GET', 'pattern' => '/', 'handler' => 'HomeController@index'],
    ['method' => 'GET', 'pattern' => '/akses-ditolak', 'handler' => 'AuthController@forbidden'],

    // Autentikasi
    ['method' => 'GET', 'pattern' => '/login', 'handler' => 'AuthController@login', 'guest' => true],
    ['method' => 'POST', 'pattern' => '/login', 'handler' => 'AuthController@authenticate', 'guest' => true],
    ['method' => 'POST', 'pattern' => '/logout', 'handler' => 'AuthController@logout', 'auth' => true],

    // Lupa password Pelanggan melalui OTP WhatsApp (Tahap 3).
    ['method' => 'GET', 'pattern' => '/lupa-password', 'handler' => 'PasswordResetController@show', 'guest' => true],
    ['method' => 'POST', 'pattern' => '/lupa-password', 'handler' => 'PasswordResetController@request', 'guest' => true],
    ['method' => 'POST', 'pattern' => '/lupa-password/verifikasi', 'handler' => 'PasswordResetController@verify', 'guest' => true],
    ['method' => 'POST', 'pattern' => '/lupa-password/kirim-ulang', 'handler' => 'PasswordResetController@resend', 'guest' => true],
    ['method' => 'POST', 'pattern' => '/lupa-password/simpan', 'handler' => 'PasswordResetController@complete', 'guest' => true],
    ['method' => 'POST', 'pattern' => '/lupa-password/batal', 'handler' => 'PasswordResetController@cancel', 'guest' => true],

    // Admin
    ['method' => 'GET', 'pattern' => '/admin/dashboard', 'handler' => 'AdminController@dashboard', 'role' => 'admin'],

    // Admin — Monitoring pengaduan & pengarsipan
    ['method' => 'GET', 'pattern' => '/admin/pengaduan', 'handler' => 'AdminController@pengaduan', 'role' => 'admin'],
    ['method' => 'GET', 'pattern' => '/admin/pengaduan/{id}', 'handler' => 'AdminController@pengaduanDetail', 'role' => 'admin'],
    ['method' => 'POST', 'pattern' => '/admin/pengaduan/{id}/arsipkan', 'handler' => 'AdminController@arsipkan', 'role' => 'admin'],

    // Admin — Monitoring teknisi
    ['method' => 'GET', 'pattern' => '/admin/teknisi', 'handler' => 'AdminController@teknisi', 'role' => 'admin'],
    ['method' => 'GET', 'pattern' => '/admin/teknisi/{id}', 'handler' => 'AdminController@teknisiDetail', 'role' => 'admin'],

    // Admin — Laporan & Analitik (Tahap 9)
    ['method' => 'GET', 'pattern' => '/admin/laporan', 'handler' => 'AdminLaporanController@index', 'role' => 'admin'],
    ['method' => 'GET', 'pattern' => '/admin/laporan/pdf', 'handler' => 'AdminLaporanController@pdf', 'role' => 'admin'],
    ['method' => 'GET', 'pattern' => '/admin/laporan/excel', 'handler' => 'AdminLaporanController@excel', 'role' => 'admin'],

    // Admin — Manajemen pengguna
    ['method' => 'GET', 'pattern' => '/admin/pengguna', 'handler' => 'AdminUserController@pengguna', 'role' => 'admin'],
    ['method' => 'POST', 'pattern' => '/admin/pengguna', 'handler' => 'AdminUserController@penggunaSimpan', 'role' => 'admin'],
    ['method' => 'POST', 'pattern' => '/admin/pengguna/update', 'handler' => 'AdminUserController@penggunaUpdate', 'role' => 'admin'],
    ['method' => 'POST', 'pattern' => '/admin/pengguna/aktif', 'handler' => 'AdminUserController@penggunaAktif', 'role' => 'admin'],
    ['method' => 'POST', 'pattern' => '/admin/pengguna/reset', 'handler' => 'AdminUserController@penggunaReset', 'role' => 'admin'],

    // Admin — Manajemen data pelanggan
    ['method' => 'GET', 'pattern' => '/admin/pelanggan', 'handler' => 'AdminPelangganController@pelanggan', 'role' => 'admin'],
    ['method' => 'GET', 'pattern' => '/admin/pelanggan/{id}', 'handler' => 'AdminPelangganController@pelangganDetail', 'role' => 'admin'],
    ['method' => 'POST', 'pattern' => '/admin/pelanggan', 'handler' => 'AdminPelangganController@pelangganSimpan', 'role' => 'admin'],
    ['method' => 'POST', 'pattern' => '/admin/pelanggan/update', 'handler' => 'AdminPelangganController@pelangganUpdate', 'role' => 'admin'],
    ['method' => 'POST', 'pattern' => '/admin/pelanggan/aktif', 'handler' => 'AdminPelangganController@pelangganAktif', 'role' => 'admin'],

    // Admin — Manajemen kategori
    ['method' => 'GET', 'pattern' => '/admin/kategori', 'handler' => 'AdminKategoriController@kategori', 'role' => 'admin'],
    ['method' => 'POST', 'pattern' => '/admin/kategori', 'handler' => 'AdminKategoriController@kategoriSimpan', 'role' => 'admin'],
    ['method' => 'POST', 'pattern' => '/admin/kategori/update', 'handler' => 'AdminKategoriController@kategoriUpdate', 'role' => 'admin'],
    ['method' => 'POST', 'pattern' => '/admin/kategori/aktif', 'handler' => 'AdminKategoriController@kategoriAktif', 'role' => 'admin'],

    // Admin — Audit log & pengaturan
    ['method' => 'GET', 'pattern' => '/admin/audit', 'handler' => 'AdminAuditController@audit', 'role' => 'admin'],
    ['method' => 'GET', 'pattern' => '/admin/pengaturan', 'handler' => 'AdminPengaturanController@pengaturan', 'role' => 'admin'],
    ['method' => 'POST', 'pattern' => '/admin/pengaturan', 'handler' => 'AdminPengaturanController@pengaturanSimpan', 'role' => 'admin'],

    // Admin — Pengaturan AI Chatbot (Tahap 11)
    ['method' => 'GET', 'pattern' => '/admin/pengaturan/ai', 'handler' => 'AdminAiSettingsController@index', 'role' => 'admin'],
    ['method' => 'POST', 'pattern' => '/admin/pengaturan/ai', 'handler' => 'AdminAiSettingsController@save', 'role' => 'admin'],
    ['method' => 'POST', 'pattern' => '/admin/pengaturan/ai/test', 'handler' => 'AdminAiSettingsController@test', 'role' => 'admin'],
    ['method' => 'POST', 'pattern' => '/admin/pengaturan/ai/test-database', 'handler' => 'AdminAiSettingsController@testDatabase', 'role' => 'admin'],
    ['method' => 'POST', 'pattern' => '/admin/pengaturan/ai/faq', 'handler' => 'AdminAiSettingsController@faqSave', 'role' => 'admin'],
    ['method' => 'POST', 'pattern' => '/admin/pengaturan/ai/faq/status', 'handler' => 'AdminAiSettingsController@faqToggle', 'role' => 'admin'],
    ['method' => 'POST', 'pattern' => '/admin/pengaturan/ai/faq/hapus', 'handler' => 'AdminAiSettingsController@faqDelete', 'role' => 'admin'],

    // Admin — WhatsApp Gateway (submenu halaman terpisah, refactor navigasi)
    ['method' => 'GET', 'pattern' => '/admin/whatsapp', 'handler' => 'AdminWhatsAppController@index', 'role' => 'admin'],
    ['method' => 'GET', 'pattern' => '/admin/whatsapp/gateway', 'handler' => 'AdminWhatsAppController@gateway', 'role' => 'admin'],
    ['method' => 'GET', 'pattern' => '/admin/whatsapp/otp', 'handler' => 'AdminWhatsAppController@otp', 'role' => 'admin'],
    ['method' => 'GET', 'pattern' => '/admin/whatsapp/notifikasi', 'handler' => 'AdminWhatsAppController@notifications', 'role' => 'admin'],
    ['method' => 'GET', 'pattern' => '/admin/whatsapp/laporan', 'handler' => 'AdminWhatsAppController@report', 'role' => 'admin'],
    ['method' => 'GET', 'pattern' => '/admin/whatsapp/audit', 'handler' => 'AdminWhatsAppController@audit', 'role' => 'admin'],
    ['method' => 'POST', 'pattern' => '/admin/whatsapp/kredensial', 'handler' => 'AdminWhatsAppController@saveCredential', 'role' => 'admin'],
    ['method' => 'POST', 'pattern' => '/admin/whatsapp/kredensial/aktif', 'handler' => 'AdminWhatsAppController@activateCredential', 'role' => 'admin'],
    ['method' => 'POST', 'pattern' => '/admin/whatsapp/kredensial/cabut', 'handler' => 'AdminWhatsAppController@revokeCredential', 'role' => 'admin'],
    ['method' => 'POST', 'pattern' => '/admin/whatsapp/kredensial/tes', 'handler' => 'AdminWhatsAppController@testCredential', 'role' => 'admin'],
    ['method' => 'POST', 'pattern' => '/admin/whatsapp/gateway', 'handler' => 'AdminWhatsAppController@saveGateway', 'role' => 'admin'],
    ['method' => 'POST', 'pattern' => '/admin/whatsapp/otp', 'handler' => 'AdminWhatsAppController@saveOtp', 'role' => 'admin'],
    ['method' => 'POST', 'pattern' => '/admin/whatsapp/notifikasi', 'handler' => 'AdminWhatsAppController@saveNotifications', 'role' => 'admin'],
    ['method' => 'POST', 'pattern' => '/admin/whatsapp/laporan', 'handler' => 'AdminWhatsAppController@saveReport', 'role' => 'admin'],
    ['method' => 'POST', 'pattern' => '/admin/whatsapp/laporan/kirim', 'handler' => 'AdminWhatsAppController@sendReport', 'role' => 'admin'],
    ['method' => 'POST', 'pattern' => '/admin/whatsapp/antrean', 'handler' => 'AdminWhatsAppController@processQueue', 'role' => 'admin'],

    // Kompatibilitas URL lama: GET diarahkan ke submenu kanonis; POST lama tetap
    // diterima agar bookmark/form yang belum diperbarui tidak rusak.
    ['method' => 'GET', 'pattern' => '/admin/pengaturan/whatsapp', 'handler' => 'AdminWhatsAppController@index', 'role' => 'admin'],
    ['method' => 'POST', 'pattern' => '/admin/pengaturan/whatsapp/kredensial', 'handler' => 'AdminWhatsAppController@saveCredential', 'role' => 'admin'],
    ['method' => 'POST', 'pattern' => '/admin/pengaturan/whatsapp/kredensial/aktif', 'handler' => 'AdminWhatsAppController@activateCredential', 'role' => 'admin'],
    ['method' => 'POST', 'pattern' => '/admin/pengaturan/whatsapp/kredensial/cabut', 'handler' => 'AdminWhatsAppController@revokeCredential', 'role' => 'admin'],
    ['method' => 'POST', 'pattern' => '/admin/pengaturan/whatsapp/kredensial/tes', 'handler' => 'AdminWhatsAppController@testCredential', 'role' => 'admin'],
    ['method' => 'POST', 'pattern' => '/admin/pengaturan/whatsapp/gateway', 'handler' => 'AdminWhatsAppController@saveGateway', 'role' => 'admin'],
    ['method' => 'POST', 'pattern' => '/admin/pengaturan/whatsapp/otp', 'handler' => 'AdminWhatsAppController@saveOtp', 'role' => 'admin'],
    ['method' => 'POST', 'pattern' => '/admin/pengaturan/whatsapp/notifikasi', 'handler' => 'AdminWhatsAppController@saveNotifications', 'role' => 'admin'],
    ['method' => 'POST', 'pattern' => '/admin/pengaturan/whatsapp/laporan', 'handler' => 'AdminWhatsAppController@saveReport', 'role' => 'admin'],
    ['method' => 'POST', 'pattern' => '/admin/pengaturan/whatsapp/laporan/kirim', 'handler' => 'AdminWhatsAppController@sendReport', 'role' => 'admin'],
    ['method' => 'POST', 'pattern' => '/admin/pengaturan/whatsapp/antrean', 'handler' => 'AdminWhatsAppController@processQueue', 'role' => 'admin'],

    // Admin — Monitoring penggunaan API terpadu (Tahap 17)
    ['method' => 'GET', 'pattern' => '/admin/monitoring-api', 'handler' => 'AdminApiMonitoringController@index', 'role' => 'admin'],

    // Webhook WhatsApp (publik; verifikasi token/signature di controller).
    ['method' => 'GET', 'pattern' => '/webhook/whatsapp/{id}', 'handler' => 'WhatsAppWebhookController@verify'],
    ['method' => 'POST', 'pattern' => '/webhook/whatsapp/{id}', 'handler' => 'WhatsAppWebhookController@receive'],

    // Customer Service
    ['method' => 'GET', 'pattern' => '/cs/dashboard', 'handler' => 'CsController@dashboard', 'role' => 'cs'],
    ['method' => 'GET', 'pattern' => '/cs/pengaduan', 'handler' => 'CsController@pengaduan', 'role' => 'cs'],
    ['method' => 'GET', 'pattern' => '/cs/verifikasi', 'handler' => 'CsController@verifikasi', 'role' => 'cs'],
    ['method' => 'GET', 'pattern' => '/cs/penugasan', 'handler' => 'CsController@penugasan', 'role' => 'cs'],
    ['method' => 'GET', 'pattern' => '/cs/monitoring', 'handler' => 'CsController@monitoring', 'role' => 'cs'],
    ['method' => 'GET', 'pattern' => '/cs/pelanggan', 'handler' => 'CsController@pelanggan', 'role' => 'cs'],
    ['method' => 'GET', 'pattern' => '/cs/catatan', 'handler' => 'CsController@catatan', 'role' => 'cs'],
    // Rute {id} diletakkan setelah rute literal agar tidak saling menimpa.
    ['method' => 'GET', 'pattern' => '/cs/pengaduan/{id}', 'handler' => 'CsController@detail', 'role' => 'cs'],
    ['method' => 'GET', 'pattern' => '/cs/pelanggan/{id}', 'handler' => 'CsController@pelangganDetail', 'role' => 'cs'],

    // Aksi Customer Service (operasi tulis). Admin juga berwenang atas tiket.
    ['method' => 'POST', 'pattern' => '/cs/pengaduan/{id}/terima', 'handler' => 'CsController@terima', 'role' => ['cs', 'admin']],
    ['method' => 'POST', 'pattern' => '/cs/pengaduan/{id}/tolak', 'handler' => 'CsController@tolak', 'role' => ['cs', 'admin']],
    ['method' => 'POST', 'pattern' => '/cs/pengaduan/{id}/selesaikan', 'handler' => 'CsController@selesaikan', 'role' => ['cs', 'admin']],
    ['method' => 'POST', 'pattern' => '/cs/pengaduan/{id}/tugaskan', 'handler' => 'CsController@tugaskan', 'role' => ['cs', 'admin']],
    ['method' => 'POST', 'pattern' => '/cs/pengaduan/{id}/batalkan', 'handler' => 'CsController@batalkan', 'role' => ['cs', 'admin']],
    ['method' => 'POST', 'pattern' => '/cs/pengaduan/{id}/catatan', 'handler' => 'CsController@catatanSimpan', 'role' => ['cs', 'admin']],

    // Teknisi (Tahap 7)
    ['method' => 'GET', 'pattern' => '/teknisi/dashboard', 'handler' => 'TeknisiController@dashboard', 'role' => 'teknisi'],
    ['method' => 'GET', 'pattern' => '/teknisi/tugas', 'handler' => 'TeknisiController@tugas', 'role' => 'teknisi'],
    // Rute literal lama diletakkan sebelum rute {id} agar tidak saling menimpa.
    ['method' => 'GET', 'pattern' => '/teknisi/tugas/detail', 'handler' => 'TeknisiController@tugasDetailLegacy', 'role' => 'teknisi'],
    ['method' => 'GET', 'pattern' => '/teknisi/tugas/{id}', 'handler' => 'TeknisiController@detail', 'role' => 'teknisi'],
    ['method' => 'GET', 'pattern' => '/teknisi/tugas/{id}/laporan', 'handler' => 'TeknisiController@laporanForm', 'role' => 'teknisi'],
    ['method' => 'POST', 'pattern' => '/teknisi/tugas/{id}/mulai', 'handler' => 'TeknisiController@mulai', 'role' => 'teknisi'],
    ['method' => 'POST', 'pattern' => '/teknisi/tugas/{id}/laporan', 'handler' => 'TeknisiController@laporanSimpan', 'role' => 'teknisi'],
    ['method' => 'GET', 'pattern' => '/teknisi/laporan', 'handler' => 'TeknisiController@laporan', 'role' => 'teknisi'],
    ['method' => 'GET', 'pattern' => '/teknisi/riwayat', 'handler' => 'TeknisiController@riwayat', 'role' => 'teknisi'],

    // Pelanggan
    ['method' => 'GET', 'pattern' => '/pelanggan/dashboard', 'handler' => 'PelangganController@dashboard', 'role' => 'pelanggan'],
    ['method' => 'GET', 'pattern' => '/pelanggan/pengaduan/baru', 'handler' => 'PelangganController@buat', 'role' => 'pelanggan'],
    ['method' => 'POST', 'pattern' => '/pelanggan/pengaduan', 'handler' => 'PelangganController@simpan', 'role' => 'pelanggan'],
    ['method' => 'GET', 'pattern' => '/pelanggan/pengaduan', 'handler' => 'PelangganController@riwayat', 'role' => 'pelanggan'],
    // Rute {id} diletakkan setelah rute literal '/baru' agar tidak saling menimpa.
    ['method' => 'GET', 'pattern' => '/pelanggan/pengaduan/{id}', 'handler' => 'PelangganController@detail', 'role' => 'pelanggan'],
    ['method' => 'GET', 'pattern' => '/pelanggan/tiket', 'handler' => 'PelangganController@tiket', 'role' => 'pelanggan'],
    ['method' => 'GET', 'pattern' => '/pelanggan/profil', 'handler' => 'PelangganController@profil', 'role' => 'pelanggan'],
    ['method' => 'POST', 'pattern' => '/pelanggan/profil', 'handler' => 'PelangganController@profilSimpan', 'role' => 'pelanggan'],
    ['method' => 'POST', 'pattern' => '/pelanggan/profil/whatsapp/otp', 'handler' => 'PelangganController@whatsappOtpKirim', 'role' => 'pelanggan'],
    ['method' => 'POST', 'pattern' => '/pelanggan/profil/whatsapp/verifikasi', 'handler' => 'PelangganController@whatsappOtpVerifikasi', 'role' => 'pelanggan'],
    ['method' => 'POST', 'pattern' => '/pelanggan/profil/whatsapp/ganti', 'handler' => 'PelangganController@whatsappGantiNomorKirim', 'role' => 'pelanggan'],
    ['method' => 'POST', 'pattern' => '/pelanggan/profil/whatsapp/ganti/kirim-ulang', 'handler' => 'PelangganController@whatsappGantiNomorKirimUlang', 'role' => 'pelanggan'],
    ['method' => 'POST', 'pattern' => '/pelanggan/profil/whatsapp/ganti/verifikasi', 'handler' => 'PelangganController@whatsappGantiNomorVerifikasi', 'role' => 'pelanggan'],
    ['method' => 'POST', 'pattern' => '/pelanggan/profil/whatsapp/ganti/batal', 'handler' => 'PelangganController@whatsappGantiNomorBatal', 'role' => 'pelanggan'],
    ['method' => 'POST', 'pattern' => '/pelanggan/profil/password', 'handler' => 'PelangganController@passwordSimpan', 'role' => 'pelanggan'],
    ['method' => 'GET', 'pattern' => '/pelanggan/rating', 'handler' => 'PelangganController@rating', 'role' => 'pelanggan'],
    ['method' => 'POST', 'pattern' => '/pelanggan/pengaduan/{id}/rating', 'handler' => 'PelangganController@ratingSimpan', 'role' => 'pelanggan'],
    ['method' => 'GET', 'pattern' => '/pelanggan/ai', 'handler' => 'PelangganController@ai', 'role' => 'pelanggan'],

    // AI Assistant (semua peran yang sudah masuk) — Tahap 9.
    ['method' => 'GET', 'pattern' => '/ai', 'handler' => 'AiController@index', 'auth' => true],
    ['method' => 'POST', 'pattern' => '/ai/chat', 'handler' => 'AiController@chat', 'auth' => true],
    ['method' => 'POST', 'pattern' => '/ai/clear', 'handler' => 'AiController@clear', 'auth' => true],

    // Notifikasi in-app (semua peran yang sudah masuk).
    ['method' => 'GET', 'pattern' => '/notifikasi', 'handler' => 'NotifikasiController@index', 'auth' => true],
    ['method' => 'POST', 'pattern' => '/notifikasi/baca-semua', 'handler' => 'NotifikasiController@bacaSemua', 'auth' => true],
    ['method' => 'POST', 'pattern' => '/notifikasi/{id}/baca', 'handler' => 'NotifikasiController@baca', 'auth' => true],

    // Foto bukti pengaduan — dilayani PHP dengan pemeriksaan autentikasi & hak akses.
    ['method' => 'GET', 'pattern' => '/pengaduan/foto/{id}', 'handler' => 'PengaduanFotoController@show', 'auth' => true],

    // Foto hasil perbaikan teknisi — dilayani PHP dengan pemeriksaan hak akses.
    ['method' => 'GET', 'pattern' => '/laporan/foto/{id}', 'handler' => 'LaporanFotoController@show', 'auth' => true],
];

/**
 * Dispatcher sederhana.
 */
function route_dispatch(string $method, string $path): void
{
    global $routes;

    $path = rtrim($path, '/');
    if ($path === '') {
        $path = '/';
    }

    foreach ($routes as $route) {
        if ($route['method'] !== $method) {
            continue;
        }
        if (route_matches($path, $route['pattern'])) {
            route_apply_middleware($route);
            [$controller, $action] = explode('@', $route['handler'], 2);
            handle_request($controller, $action, route_extract_params($path, $route['pattern']));
            return;
        }
    }

    http_response_code(404);
    render_error('Halaman tidak ditemukan.', 'Maaf, halaman yang Anda minta tidak tersedia atau sudah dipindahkan.', 404);
}

/**
 * Terapkan middleware autentikasi/RBAC sesuai penanda route.
 *
 * @param array<string, mixed> $route
 */
function route_apply_middleware(array $route): void
{
    if (!empty($route['guest'])) {
        AuthMiddleware::guest();
    }

    if (!empty($route['auth'])) {
        AuthMiddleware::auth();
    }

    if (!empty($route['role'])) {
        $role = $route['role'];
        AuthMiddleware::role(is_array($role) ? $role : (string) $role);
    }
}

/**
 * Cocokkan path request dengan pola route.
 *
 * Pola `/` hanya cocok dengan akar; pola lain dibandingkan per segmen.
 */
function route_matches(string $path, string $pattern): bool
{
    $patternSegments = route_segments($pattern);
    $pathSegments = route_segments($path);

    if (count($pathSegments) !== count($patternSegments)) {
        return false;
    }

    foreach ($patternSegments as $index => $segment) {
        if (route_is_wildcard($segment)) {
            continue;
        }
        if ($segment !== $pathSegments[$index]) {
            return false;
        }
    }

    return true;
}

/**
 * Apakah sebuah segmen pola merupakan wildcard ({nama}).
 */
function route_is_wildcard(string $segment): bool
{
    return str_starts_with($segment, '{') && str_ends_with($segment, '}');
}

/**
 * Ambil parameter dinamis dari path berdasarkan pola (mis. {id} => 12).
 *
 * @return array<string, string>
 */
function route_extract_params(string $path, string $pattern): array
{
    $patternSegments = route_segments($pattern);
    $pathSegments = route_segments($path);
    $params = [];

    foreach ($patternSegments as $index => $segment) {
        if (!route_is_wildcard($segment)) {
            continue;
        }

        $name = trim($segment, '{}');
        if ($name !== '' && isset($pathSegments[$index])) {
            $params[$name] = $pathSegments[$index];
        }
    }

    return $params;
}

/**
 * Pecah URI menjadi segmen path, tanpa elemen kosong.
 *
 * @return array<int, string>
 */
function route_segments(string $uri): array
{
    $uri = ltrim($uri, '/');
    if ($uri === '') {
        return [];
    }

    return explode('/', $uri);
}

/**
 * Terima permintaan HTTP dan dispatch ke controller.
 *
 * @param array<string, string> $params Parameter dinamis dari pola route.
 */
function handle_request(string $controller, string $action, array $params = []): void
{
    // Base controller wajib dimuat sebelum controller turunan.
    $base = __DIR__ . '/../app/Controllers/Controller.php';
    if (is_file($base)) {
        require_once $base;
    }

    $controllerFile = __DIR__ . '/../app/Controllers/' . $controller . '.php';
    if (!is_file($controllerFile)) {
        http_response_code(500);
        render_error('Kontroler tidak tersedia.', 'Terjadi kesalahan pada server.');
        return;
    }
    require_once $controllerFile;

    $controllerClass = $controller;
    if (!class_exists($controllerClass)) {
        http_response_code(500);
        render_error('Kontroler tidak tersedia.', 'Terjadi kesalahan pada server.');
        return;
    }

    $controllerInstance = new $controllerClass();
    if (!method_exists($controllerInstance, $action)) {
        http_response_code(500);
        render_error('Aksi tidak tersedia.', 'Terjadi kesalahan pada server.');
        return;
    }

    // Kirim parameter route hanya bila aksi memang menerimanya.
    $reflection = new ReflectionMethod($controllerInstance, $action);
    if ($reflection->getNumberOfParameters() > 0) {
        $controllerInstance->{$action}($params);
    } else {
        $controllerInstance->{$action}();
    }
}
