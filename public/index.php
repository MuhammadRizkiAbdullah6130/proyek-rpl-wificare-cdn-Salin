<?php
/**
 * Front controller WiFiCare CDN.
 *
 * Titik masuk utama aplikasi. Semua request HTTP melewati file ini.
 * File ini berada di document root (`public/`), sedangkan file
 * konfigurasi berada di luar root publik.
 */

declare(strict_types=1);

// Jangan pernah menampilkan error/stack trace/path server kepada pengguna,
// bahkan bila APP_DEBUG aktif. Detail teknis hanya ditulis ke log aplikasi.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

// Pada server bawaan PHP (php -S), sajikan berkas statis apa adanya
// agar CSS/JS/gambar tidak melewati routing aplikasi.
if (PHP_SAPI === 'cli-server') {
    $staticPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if ($staticPath !== '/' && is_file(__DIR__ . $staticPath)) {
        return false;
    }
}

require_once __DIR__ . '/../app/bootstrap.php';

// Mulai session aman (HttpOnly, SameSite, strict mode).
Session::start();

// Header keamanan untuk SEMUA respons dinamis (aset statis dilayani Apache
// langsung dan tidak melewati front controller).
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header('Cross-Origin-Opener-Policy: same-origin');

// Halaman privat tidak boleh tersimpan di cache browser/proxy. Ini juga
// mencegah tombol Back menampilkan kembali data sensitif setelah logout.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (Session::isHttps()) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

require_once __DIR__ . '/../routes/web.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Buang base path bila aplikasi dipasang pada subfolder.
$basePath = app_base_path();
if ($basePath !== '' && str_starts_with($path, $basePath)) {
    $path = substr($path, strlen($basePath));
    if ($path === '' || $path === false) {
        $path = '/';
    }
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

try {
    route_dispatch($method, $path);
} catch (Throwable $exception) {
    app_log(sprintf(
        'Unhandled error: %s di %s:%d',
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    ));

    // Respons selalu generik — stack trace/path/query SQL tidak pernah
    // dikirim ke browser. Detail lengkap sudah dicatat oleh app_log().
    http_response_code(500);
    try {
        render_error(
            'Terjadi kesalahan.',
            'Maaf, terjadi kesalahan pada server. Silakan coba beberapa saat lagi.',
            500
        );
    } catch (Throwable $renderException) {
        app_log('Gagal merender halaman error: ' . $renderException->getMessage());
        echo 'Terjadi kesalahan pada server.';
    }
}
