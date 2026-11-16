<?php
/**
 * Middleware autentikasi dan RBAC WiFiCare CDN.
 *
 * Dipanggil oleh router sebelum controller dijalankan.
 * - guest() : hanya pengunjung (belum login).
 * - auth()  : wajib login.
 * - role()  : wajib login DAN memiliki peran tertentu.
 *
 * Perilaku respons:
 * - Navigasi browser tanpa sesi  → redirect ke /login (HTTP 302).
 * - API/AJAX tanpa sesi          → HTTP 401 JSON.
 * - Login tetapi peran salah     → HTTP 403 (JSON untuk API/AJAX).
 *
 * Peran SELALU dibaca ulang dari database melalui Auth (bukan dari session,
 * URL, atau input pengguna), sehingga akun yang dinonaktifkan/diubah perannya
 * langsung kehilangan akses.
 */

declare(strict_types=1);

final class AuthMiddleware
{
    /**
     * Batasi akses hanya untuk pengunjung yang belum masuk.
     */
    public static function guest(): void
    {
        $user = Auth::user();

        if ($user === null) {
            // Tidak ada sesi, atau sesi milik akun nonaktif yang baru saja
            // diakhiri oleh Auth::user(). Halaman login boleh ditampilkan.
            if (Auth::check()) {
                Auth::logout();
            }

            return;
        }

        $role = (string) ($user['role'] ?? '');
        if (!Auth::isValidRole($role)) {
            // Peran tidak dikenali: jangan pernah memberi akses dashboard.
            Auth::logout();
            return;
        }

        redirect(Auth::dashboardUrl($role));
    }

    /**
     * Wajib sudah masuk; pengunjung diarahkan ke login / menerima 401 JSON.
     */
    public static function auth(): void
    {
        if (!Auth::check()) {
            respond_unauthorized();
        }

        // Sesi masih ada, tetapi akun bisa saja dihapus/dinonaktifkan setelah
        // login. Auth::user() mengakhiri sesi semacam itu; arahkan ke login
        // (bukan 403) agar konsisten dan tidak menyesatkan pengguna.
        $user = Auth::user();
        if ($user === null) {
            respond_unauthorized('Sesi Anda telah berakhir. Silakan masuk kembali.');
        }

        if (!Auth::isValidRole((string) ($user['role'] ?? ''))) {
            respond_forbidden('Peran akun tidak dikenali. Hubungi Administrator.');
        }
    }

    /**
     * Wajib sudah masuk dan memiliki salah satu peran yang diizinkan.
     *
     * @param string|array<int, string> $roles
     */
    public static function role(string|array $roles): void
    {
        self::auth();

        $roles = is_array($roles) ? $roles : [$roles];

        if (!in_array((string) Auth::role(), $roles, true)) {
            respond_forbidden('Anda tidak memiliki hak untuk membuka halaman ini.');
        }
    }
}
