<?php
/**
 * Controller Lupa Password Pelanggan (OTP WhatsApp).
 *
 * Semua form publik dilindungi CSRF. Respons identifikasi akun selalu netral
 * agar keberadaan/status akun tidak bocor ke pengguna yang tidak berhak.
 */

declare(strict_types=1);

require_once __DIR__ . '/Controller.php';

final class PasswordResetController extends Controller
{
    public function show(): void
    {
        $state = PasswordResetService::state();
        $ui = PasswordResetService::uiSettings();

        $this->renderAuth('lupa-password', [
            'title' => 'Lupa Password — WiFiCare CDN',
            'stage' => $state['stage'],
            'notice' => $state['notice'],
            'resend_after' => $state['resend_after'],
            'authorization_expires_in' => $state['authorization_expires_in'],
            'ttl_minutes' => $ui['ttl_minutes'],
            'resend_cooldown' => $ui['resend_cooldown'],
            'max_attempts' => $ui['max_attempts'],
            'error' => flash_get('error'),
            'success' => flash_get('success'),
            'info' => flash_get('info'),
            'old' => Session::pullInput(),
        ]);
    }

    public function request(): void
    {
        $this->requireCsrf();

        $identifier = $this->postString('username');
        $result = PasswordResetService::request($identifier);

        if (!empty($result['ok'])) {
            flash_set('info', (string) $result['message']);
        } else {
            if (($result['stage'] ?? '') === 'identify' && $identifier !== '') {
                Session::flashInput(['username' => $identifier]);
            }
            flash_set('error', (string) $result['message']);
        }

        redirect('/lupa-password');
    }

    public function verify(): void
    {
        $this->requireCsrf();

        $result = PasswordResetService::verify($this->postString('otp'));

        if (!empty($result['ok'])) {
            flash_set('success', (string) $result['message']);
        } else {
            flash_set('error', (string) $result['message']);
        }

        redirect('/lupa-password');
    }

    public function resend(): void
    {
        $this->requireCsrf();

        $result = PasswordResetService::resend();
        if (!empty($result['ok'])) {
            flash_set('info', (string) $result['message']);
        } else {
            flash_set('error', (string) $result['message']);
        }

        redirect('/lupa-password');
    }

    public function complete(): void
    {
        $this->requireCsrf();

        $result = PasswordResetService::complete(
            $this->postString('password_baru'),
            $this->postString('password_konfirmasi')
        );

        if (!empty($result['ok'])) {
            flash_set('success', (string) $result['message']);
            redirect('/login');
        }

        flash_set('error', (string) $result['message']);
        redirect('/lupa-password');
    }

    public function cancel(): void
    {
        $this->requireCsrf();
        PasswordResetService::cancel();
        flash_set('info', 'Proses lupa password dibatalkan.');
        redirect('/lupa-password');
    }

    private function requireCsrf(): void
    {
        $token = isset($_POST['_token']) && is_string($_POST['_token']) ? $_POST['_token'] : null;
        if (!csrf_verify($token)) {
            render_error(
                'Sesi formulir tidak valid',
                'Token keamanan (CSRF) tidak cocok. Silakan muat ulang halaman lalu coba lagi.',
                419
            );
            exit;
        }
    }

    private function postString(string $key): string
    {
        return isset($_POST[$key]) && is_string($_POST[$key]) ? $_POST[$key] : '';
    }
}
