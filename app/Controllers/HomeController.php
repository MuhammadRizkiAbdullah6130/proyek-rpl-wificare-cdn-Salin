<?php
/**
 * Controller halaman awal WiFiCare CDN (Revisi 6).
 *
 * Tidak ada lagi halaman publik dengan sidebar (Beranda/Tentang). URL utama
 * langsung mengarahkan pengguna sesuai kondisi session:
 * - belum masuk → halaman Login;
 * - sudah masuk → Dashboard sesuai peran.
 */

declare(strict_types=1);

require_once __DIR__ . '/Controller.php';

final class HomeController extends Controller
{
    public function index(): void
    {
        // Auth::user() memvalidasi akun ke database dan mengakhiri sesi akun
        // nonaktif. Jangan memakai Auth::check() saja: session lama tanpa akun
        // valid tidak boleh menghasilkan redirect berulang ke /login.
        $user = Auth::user();
        $role = Auth::role();

        if ($user !== null && $role !== null) {
            redirect(Auth::dashboardUrl($role));
        }

        // Tidak ada sesi valid (atau peran tidak dikenal): bersihkan sisa
        // session lalu tampilkan login.
        if (Auth::check()) {
            Auth::logout();
        }

        redirect('/login');
    }
}
