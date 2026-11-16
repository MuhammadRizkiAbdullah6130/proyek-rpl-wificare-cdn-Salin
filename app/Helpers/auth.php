<?php
/**
 * Helper autentikasi, keamanan, dan utilitas umum WiFiCare CDN.
 *
 * Kumpulan fungsi global yang dipakai controller, middleware, dan view:
 * log aplikasi, IP klien, redirect, CSRF, flash message, dan pembungkus
 * singkat untuk Auth.
 */

declare(strict_types=1);

if (!function_exists('app_log')) {
    /**
     * Tulis pesan ke berkas log aplikasi (tanpa menampilkan ke pengguna).
     */
    function app_log(string $message): void
    {
        $logDir = __DIR__ . '/../../storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }

        $line = sprintf('[%s] %s%s', date('Y-m-d H:i:s'), $message, PHP_EOL);
        @file_put_contents($logDir . '/app.log', $line, FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('client_ip')) {
    /**
     * Alamat IP klien (tanpa mempercayai header yang bisa dipalsukan).
     */
    function client_ip(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        return substr((string) $ip, 0, 45);
    }
}

if (!function_exists('redirect')) {
    /**
     * Kirim header Location lalu hentikan eksekusi.
     */
    function redirect(string $path): void
    {
        header('Location: ' . url($path), true, 302);
        exit;
    }
}

if (!function_exists('request_wants_json')) {
    /**
     * Apakah permintaan mengharapkan respons JSON (API/AJAX/fetch).
     *
     * Dipakai middleware agar pengunjung tanpa sesi pada endpoint API/AJAX
     * menerima HTTP 401 JSON, sedangkan navigasi browser tetap diarahkan ke
     * halaman login. Endpoint yang dipanggil dengan fetch() di frontend selalu
     * mengirim X-Requested-With: XMLHttpRequest.
     */
    function request_wants_json(): bool
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        if (str_contains($accept, 'application/json')) {
            return true;
        }

        if (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
            return true;
        }

        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (str_contains($contentType, 'application/json')) {
            return true;
        }

        // Endpoint API yang jelas (tanpa melihat header klien). Halaman HTML
        // `/ai` TIDAK termasuk agar pengunjung tetap diarahkan ke login.
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        foreach (['/ai/chat', '/admin/pengaturan/ai/test', '/api/'] as $apiPath) {
            if (str_contains($path, $apiPath)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('json_response')) {
    /**
     * Kirim respons JSON lalu hentikan eksekusi.
     *
     * @param array<string, mixed> $data
     */
    function json_response(array $data, int $status = 200): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('respond_unauthorized')) {
    /**
     * Tangani permintaan tanpa sesi valid.
     *
     * - API/AJAX → HTTP 401 JSON.
     * - Navigasi browser → redirect ke login (BUKAN 403, karena masalahnya
     *   memang belum terautentikasi).
     */
    function respond_unauthorized(string $message = 'Silakan masuk terlebih dahulu untuk melanjutkan.'): void
    {
        if (request_wants_json()) {
            json_response(['ok' => false, 'error' => $message], 401);
        }

        flash_set('error', $message);
        redirect('/login');
    }
}

if (!function_exists('respond_forbidden')) {
    /**
     * Tangani permintaan yang sudah terautentikasi tetapi tidak berwenang.
     * API/AJAX mendapat HTTP 403 JSON; navigasi browser mendapat halaman 403.
     */
    function respond_forbidden(string $message = 'Anda tidak memiliki hak untuk membuka halaman ini.'): void
    {
        if (request_wants_json()) {
            json_response(['ok' => false, 'error' => $message], 403);
        }

        render_forbidden($message);
    }
}

if (!function_exists('render_forbidden')) {
    /**
     * Tampilkan halaman 403 dengan layout error sederhana (tanpa sidebar publik).
     */
    function render_forbidden(string $message = 'Anda tidak memiliki hak untuk membuka halaman ini.'): void
    {
        http_response_code(403);
        render_view('akses-ditolak', [
            'title' => 'Akses Ditolak — WiFiCare CDN',
            'active' => '',
            'message' => $message,
        ], 'layouts/error');
        exit;
    }
}

if (!function_exists('auth_check')) {
    /**
     * Apakah ada pengguna yang sedang masuk.
     */
    function auth_check(): bool
    {
        return Auth::check();
    }
}

if (!function_exists('auth_user')) {
    /**
     * Data pengguna yang sedang masuk (atau null).
     *
     * @return array<string, mixed>|null
     */
    function auth_user(): ?array
    {
        return Auth::user();
    }
}

if (!function_exists('auth_id')) {
    /**
     * ID pengguna yang sedang masuk (atau null).
     */
    function auth_id(): ?int
    {
        return Auth::id();
    }
}

if (!function_exists('auth_role')) {
    /**
     * Peran pengguna yang sedang masuk (atau null).
     */
    function auth_role(): ?string
    {
        return Auth::role();
    }
}

if (!function_exists('csrf_token')) {
    /**
     * Token CSRF aktif.
     */
    function csrf_token(): string
    {
        return Csrf::token();
    }
}

if (!function_exists('csrf_field')) {
    /**
     * Input tersembunyi berisi token CSRF.
     */
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('csrf_verify')) {
    /**
     * Validasi token CSRF dari permintaan.
     */
    function csrf_verify(?string $token): bool
    {
        return Csrf::verify($token);
    }
}

if (!function_exists('flash_set')) {
    /**
     * Simpan pesan sekali-tampil.
     */
    function flash_set(string $key, string $message): void
    {
        Session::flash($key, $message);
    }
}

if (!function_exists('flash_get')) {
    /**
     * Ambil dan hapus pesan sekali-tampil.
     */
    function flash_get(string $key): ?string
    {
        return Session::pullFlash($key);
    }
}
