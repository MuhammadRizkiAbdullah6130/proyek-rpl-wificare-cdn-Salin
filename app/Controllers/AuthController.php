<?php
/**
 * Controller autentikasi WiFiCare CDN.
 *
 * Menangani login (GET/POST), logout (POST), dan halaman akses ditolak.
 * Semua input divalidasi di server dan dilindungi CSRF.
 */

declare(strict_types=1);

require_once __DIR__ . '/Controller.php';

final class AuthController extends Controller
{
    /**
     * Halaman login.
     */
    public function login(): void
    {
        $this->renderAuth('login', [
            'title' => 'Masuk — WiFiCare CDN',
            'active' => 'login',
            'error' => flash_get('error'),
            'success' => flash_get('success'),
            'old' => Session::pullInput(),
        ]);
    }

    /**
     * Proses login (POST).
     */
    public function authenticate(): void
    {
        // 1. Validasi CSRF.
        $token = isset($_POST['_token']) && is_string($_POST['_token']) ? $_POST['_token'] : null;
        // Hanya terima skalar; cegah input array (mis. username[]) yang memicu
        // peringatan "Array to string conversion".
        $rawUsername = isset($_POST['username']) && is_string($_POST['username']) ? $_POST['username'] : '';
        if (!csrf_verify($token)) {
            Session::flashInput(['username' => $rawUsername]);
            flash_set('error', 'Sesi formulir telah berakhir. Silakan coba lagi.');
            redirect('/login');
        }

        $username = trim($rawUsername);
        $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';

        // 2. Validasi input dasar.
        $inputError = Auth::credentialInputError($username, $password);
        if ($inputError !== null) {
            Session::flashInput(['username' => $username]);
            flash_set('error', $inputError);
            redirect('/login');
        }

        // 3. Verifikasi kredensial (dengan pembatasan percobaan).
        $result = Auth::attempt($username, $password);

        switch ($result['status']) {
            case 'locked':
                Session::flashInput(['username' => $username]);
                flash_set('error', 'Terlalu banyak percobaan gagal. Silakan coba lagi dalam beberapa menit.');
                redirect('/login');

            case 'inactive':
                Session::flashInput(['username' => $username]);
                flash_set('error', 'Akun Anda dinonaktifkan. Hubungi Administrator.');
                redirect('/login');

            case 'invalid':
                Session::flashInput(['username' => $username]);
                flash_set('error', 'Username atau password salah.');
                redirect('/login');

            case 'ok':
            default:
                Auth::login($result['user']);
                redirect(Auth::dashboardUrl((string) $result['user']['role']));
        }
    }

    /**
     * Proses logout (POST).
     */
    public function logout(): void
    {
        $token = isset($_POST['_token']) && is_string($_POST['_token']) ? $_POST['_token'] : null;

        if (!csrf_verify($token)) {
            render_forbidden('Permintaan logout tidak valid.');
        }

        Auth::logout();
        flash_set('success', 'Anda telah keluar dari sistem.');
        redirect('/login');
    }

    /**
     * Halaman akses ditolak (403).
     */
    public function forbidden(): void
    {
        render_forbidden('Halaman ini tidak dapat dibuka oleh peran Anda.');
    }
}
