<?php
/**
 * Layanan Lupa Password Pelanggan melalui OTP WhatsApp.
 *
 * Alur:
 * - Identifikasi akun memakai username PPPoE / username WiFiCare.
 * - Hanya akun Pelanggan aktif dengan nomor WhatsApp terverifikasi yang
 *   benar-benar dikirimi OTP. Respons publik selalu netral.
 * - OTP memakai WhatsAppOtpService dengan purpose `reset_password`.
 * - Setelah OTP benar, dibuat otorisasi reset berumur pendek dan sekali pakai
 *   yang terikat pada akun serta session server.
 * - Password baru disimpan bersama kenaikan `users.session_version` dalam satu
 *   transaksi, sehingga sesi login lama tidak lagi valid.
 */

declare(strict_types=1);

final class PasswordResetService
{
    public const PURPOSE = WhatsAppOtpService::PURPOSE_RESET_PASSWORD;

    private const SESSION_KEY = '_password_reset';
    private const AUTHORIZATION_TTL = 600;
    private const PENDING_TTL = 1800;

    private const REQUEST_WINDOW_SECONDS = 900;
    private const REQUEST_MAX_IP = 20;
    private const REQUEST_MAX_USER = 5;

    private const VERIFY_WINDOW_SECONDS = 900;
    private const VERIFY_MAX_IP = 30;

    private const COMPLETE_WINDOW_SECONDS = 900;
    private const COMPLETE_MAX_IP = 10;

    private const NEUTRAL_REQUEST = 'Jika akun memenuhi syarat, kode verifikasi akan dikirim ke WhatsApp yang terdaftar. Periksa WhatsApp Anda.';
    private const NEUTRAL_VERIFY = 'Kode tidak valid atau sudah kedaluwarsa. Jika akun memenuhi syarat, Anda dapat meminta kode baru.';
    private const NEUTRAL_RATE = 'Permintaan belum dapat diproses. Coba lagi nanti atau hubungi Admin/CS.';

    /**
     * Permintaan OTP tahap pertama. Respons tidak membedakan akun valid,
     * tidak dikenal, nonaktif, atau belum terverifikasi.
     *
     * @return array{ok:bool,code:string,message:string,stage:string}
     */
    public static function request(string $identifier): array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return self::result(false, 'invalid_input', 'Username PPPoE wajib diisi.', 'identify');
        }
        if (mb_strlen($identifier) > 64) {
            return self::result(false, 'invalid_input', 'Username PPPoE tidak valid.', 'identify');
        }

        if (PasswordReset::countRecentByIp('request', self::REQUEST_WINDOW_SECONDS) >= self::REQUEST_MAX_IP) {
            PasswordReset::recordEvent(null, 'request_blocked', false);
            self::audit(null, 'password_reset_diblokir', ['alasan' => 'rate_limit_sumber']);
            return self::result(false, 'rate_limited', self::NEUTRAL_RATE, 'identify');
        }

        $account = PasswordReset::findEligibleAccount($identifier);
        $eligible = $account !== null && self::isEligible($account);
        $userId = $eligible ? (int) $account['id'] : 0;

        // Batas per akun sebagai lapisan tambahan; OTP service juga menerapkan
        // batas harian per akun dan nomor.
        $accountLimited = $userId > 0
            && PasswordReset::countRecentByUser($userId, 'request', self::REQUEST_WINDOW_SECONDS) >= self::REQUEST_MAX_USER;

        PasswordReset::recordEvent($userId > 0 ? $userId : null, 'request', !$accountLimited);

        $sendResult = null;
        if ($userId > 0 && !$accountLimited) {
            $phone = self::normalizedPhone($account);
            if ($phone !== null) {
                $sendResult = WhatsAppOtpService::request($account, self::PURPOSE, $phone);
            }
        }

        // Stage verifikasi tetap ditampilkan untuk username yang valid secara
        // format, termasuk bila akun tidak memenuhi syarat. Ini mencegah
        // enumerasi akun melalui perbedaan tahap/form.
        self::setPending($userId, 'verify');

        if ($userId > 0) {
            self::audit($userId, 'password_reset_diminta', [
                'metode' => 'otp_whatsapp',
                'hasil' => $sendResult !== null && !empty($sendResult['ok']) ? 'diterima_provider' : 'netral',
                'alasan' => $sendResult === null ? 'akun_tidak_memenuhi_syarat' : (string) ($sendResult['code'] ?? ''),
            ]);
        } else {
            self::audit(null, 'password_reset_diminta', [
                'metode' => 'otp_whatsapp',
                'hasil' => 'netral',
            ]);
        }

        return self::result(true, 'neutral', self::NEUTRAL_REQUEST, 'verify');
    }

    /**
     * Kirim ulang OTP pada tahap verifikasi. Nomor selalu diambil dari
     * database, bukan input browser.
     *
     * @return array{ok:bool,code:string,message:string,stage:string,retry_after:int}
     */
    public static function resend(): array
    {
        $pending = self::pending();
        if ($pending === null || ($pending['stage'] ?? '') !== 'verify') {
            return self::result(false, 'invalid_stage', 'Mulai ulang proses lupa password.', 'identify', 0);
        }

        if (PasswordReset::countRecentByIp('request', self::REQUEST_WINDOW_SECONDS) >= self::REQUEST_MAX_IP) {
            PasswordReset::recordEvent(null, 'request_blocked', false);
            return self::result(false, 'rate_limited', self::NEUTRAL_RATE, 'verify', 0);
        }

        $userId = (int) ($pending['user_id'] ?? 0);
        $accountLimited = $userId > 0
            && PasswordReset::countRecentByUser($userId, 'request', self::REQUEST_WINDOW_SECONDS) >= self::REQUEST_MAX_USER;
        PasswordReset::recordEvent($userId > 0 ? $userId : null, 'request_resend', !$accountLimited);

        $sendResult = null;
        if ($userId > 0 && !$accountLimited) {
            $account = PasswordReset::findAccountByUserId($userId);
            if ($account !== null && self::isEligible($account)) {
                $phone = self::normalizedPhone($account);
                if ($phone !== null) {
                    $sendResult = WhatsAppOtpService::request($account, self::PURPOSE, $phone);
                }
            } else {
                $userId = 0;
            }
        }

        self::setPending($userId, 'verify');
        $retryAfter = self::resendAfter();

        self::audit($userId > 0 ? $userId : null, 'password_reset_otp_dikirim_ulang', [
            'metode' => 'otp_whatsapp',
            'hasil' => $sendResult !== null && !empty($sendResult['ok']) ? 'diterima_provider' : 'netral',
            'alasan' => $sendResult === null ? 'akun_tidak_memenuhi_syarat' : (string) ($sendResult['code'] ?? ''),
        ]);

        return self::result(true, 'neutral', self::NEUTRAL_REQUEST, 'verify', $retryAfter);
    }

    /**
     * Verifikasi OTP. Otorisasi reset dibuat dalam transaksi yang sama dengan
     * konsumsi OTP.
     *
     * @return array{ok:bool,code:string,message:string,stage:string}
     */
    public static function verify(string $code): array
    {
        $pending = self::pending();
        if ($pending === null || ($pending['stage'] ?? '') !== 'verify') {
            return self::result(false, 'invalid_stage', 'Mulai ulang proses lupa password.', 'identify');
        }

        if (PasswordReset::countRecentByIp('verify_attempt', self::VERIFY_WINDOW_SECONDS) >= self::VERIFY_MAX_IP) {
            PasswordReset::recordEvent(null, 'verify_blocked', false);
            self::audit(null, 'password_reset_otp_diblokir', ['alasan' => 'rate_limit_sumber']);
            return self::result(false, 'rate_limited', self::NEUTRAL_RATE, 'verify');
        }

        $userId = (int) ($pending['user_id'] ?? 0);
        PasswordReset::recordEvent($userId > 0 ? $userId : null, 'verify_attempt', false);

        $code = preg_replace('/\D+/', '', $code) ?? '';
        if (strlen($code) !== 6) {
            return self::result(false, 'invalid', self::NEUTRAL_VERIFY, 'verify');
        }

        // Akun tidak dikenal pada tahap identifikasi tidak pernah memiliki
        // kandidat OTP. Pesan tetap sama dengan OTP salah.
        if ($userId <= 0) {
            self::audit(null, 'password_reset_otp_gagal', ['metode' => 'otp_whatsapp', 'alasan' => 'invalid']);
            return self::result(false, 'invalid', self::NEUTRAL_VERIFY, 'verify');
        }

        $account = PasswordReset::findAccountByUserId($userId);
        if ($account === null || !self::isEligible($account)) {
            self::clearPending();
            self::audit($userId, 'password_reset_otp_gagal', ['metode' => 'otp_whatsapp', 'alasan' => 'akun_tidak_memenuhi_syarat']);
            return self::result(false, 'invalid', self::NEUTRAL_VERIFY, 'identify');
        }

        $phone = self::normalizedPhone($account);
        if ($phone === null) {
            return self::result(false, 'invalid', self::NEUTRAL_VERIFY, 'verify');
        }

        $token = bin2hex(random_bytes(32));
        $tokenHash = Crypto::fingerprint('password-reset-token:' . $token);
        if ($tokenHash === '') {
            self::audit($userId, 'password_reset_otp_gagal', ['metode' => 'otp_whatsapp', 'alasan' => 'enkripsi_belum_siap']);
            return self::result(false, 'unavailable', 'Layanan reset password belum siap. Hubungi Admin/CS.', 'identify');
        }

        $result = WhatsAppOtpService::verify(
            $account,
            self::PURPOSE,
            $phone,
            $code,
            static function () use ($userId, $tokenHash): void {
                PasswordReset::createAuthorization(
                    $userId,
                    $tokenHash,
                    null,
                    self::AUTHORIZATION_TTL
                );
            }
        );

        if (empty($result['ok'])) {
            $reason = (string) ($result['code'] ?? 'invalid');
            self::audit($userId, 'password_reset_otp_gagal', [
                'metode' => 'otp_whatsapp',
                'alasan' => $reason,
            ]);

            $message = in_array($reason, ['feature_disabled', 'purpose_disabled', 'gateway_disabled', 'connection_unavailable'], true)
                ? 'Layanan reset password belum tersedia. Hubungi Admin/CS.'
                : self::NEUTRAL_VERIFY;

            return self::result(false, $reason, $message, 'verify');
        }

        self::setPending($userId, 'password', $token);
        self::audit($userId, 'password_reset_otp_terverifikasi', [
            'metode' => 'otp_whatsapp',
        ]);

        return self::result(
            true,
            'verified',
            'Kode OTP berhasil diverifikasi. Buat password baru Anda.',
            'password'
        );
    }

    /**
     * Simpan password baru setelah otorisasi reset diverifikasi.
     *
     * @return array{ok:bool,code:string,message:string,stage:string}
     */
    public static function complete(string $password, string $confirmation): array
    {
        $pending = self::pending();
        if ($pending === null || ($pending['stage'] ?? '') !== 'password') {
            return self::result(false, 'invalid_stage', 'Sesi reset password tidak valid. Mulai ulang.', 'identify');
        }

        $userId = (int) ($pending['user_id'] ?? 0);
        $token = (string) ($pending['token'] ?? '');

        if (PasswordReset::countRecentByIp('complete_attempt', self::COMPLETE_WINDOW_SECONDS) >= self::COMPLETE_MAX_IP) {
            PasswordReset::recordEvent($userId > 0 ? $userId : null, 'complete_blocked', false);
            self::audit($userId > 0 ? $userId : null, 'password_reset_diblokir', ['alasan' => 'rate_limit_sumber']);
            return self::result(false, 'rate_limited', self::NEUTRAL_RATE, 'password');
        }
        PasswordReset::recordEvent($userId > 0 ? $userId : null, 'complete_attempt', false);

        $account = PasswordReset::findAccountByUserId($userId);
        if ($account === null || !self::isEligible($account) || $token === '') {
            self::clearPending();
            PasswordReset::invalidateAuthorizations($userId);
            return self::result(false, 'invalid_authorization', 'Otorisasi reset sudah tidak berlaku. Mulai ulang.', 'identify');
        }

        $errors = self::validatePassword($password, $confirmation, (string) ($account['username'] ?? ''));
        if (!empty($errors)) {
            return self::result(false, 'weak_password', implode(' ', $errors), 'password');
        }

        $tokenHash = Crypto::fingerprint('password-reset-token:' . $token);
        $authorization = $tokenHash === ''
            ? null
            : PasswordReset::validAuthorization($userId, $tokenHash);
        if ($authorization === null) {
            self::clearPending();
            return self::result(false, 'expired_authorization', 'Otorisasi reset sudah kedaluwarsa. Mulai ulang.', 'identify');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($hash) || $hash === '') {
            return self::result(false, 'unavailable', 'Password gagal disimpan. Silakan coba lagi.', 'password');
        }

        try {
            Database::transaction(static function () use ($authorization, $userId, $hash): void {
                if (!PasswordReset::consumeAuthorization((int) $authorization['id'])) {
                    throw new RuntimeException('authorization_used');
                }
                if (!User::resetPasswordAndInvalidateSessions($userId, $hash)) {
                    throw new RuntimeException('password_update_failed');
                }
                PasswordReset::invalidateAuthorizations($userId);
                AuditLog::record($userId, 'password_reset_berhasil', 'users', $userId, [
                    'metode' => 'otp_whatsapp',
                ]);
            });
        } catch (Throwable $exception) {
            $reason = $exception->getMessage() === 'authorization_used'
                ? 'expired_authorization'
                : 'save_failed';
            self::audit($userId, 'password_reset_gagal', ['metode' => 'otp_whatsapp', 'alasan' => $reason]);

            return self::result(
                false,
                $reason,
                $reason === 'expired_authorization'
                    ? 'Otorisasi reset sudah dipakai. Mulai ulang.'
                    : 'Password gagal disimpan. Silakan coba lagi.',
                $reason === 'expired_authorization' ? 'identify' : 'password'
            );
        }

        self::clearPending();
        PasswordReset::invalidateAuthorizations($userId);
        WhatsAppOtp::invalidateActive($userId, self::PURPOSE);
        Csrf::rotate();

        return self::result(
            true,
            'completed',
            'Password WiFiCare berhasil direset. Silakan masuk dengan password baru Anda.',
            'identify'
        );
    }

    /**
     * Batalkan proses dan hapus OTP reset yang masih aktif.
     */
    public static function cancel(): void
    {
        $pending = self::pending();
        $userId = $pending === null ? 0 : (int) ($pending['user_id'] ?? 0);

        if ($userId > 0) {
            PasswordReset::invalidateAuthorizations($userId);
            WhatsAppOtp::invalidateActive($userId, self::PURPOSE);
            self::audit($userId, 'password_reset_dibatalkan', ['metode' => 'otp_whatsapp']);
        }

        self::clearPending();
        Csrf::rotate();
    }

    /**
     * State aman untuk view. Tidak pernah mengembalikan token, OTP, nomor,
     * username, atau hash apa pun.
     *
     * @return array{stage:string,notice:?string,resend_after:int,authorization_expires_in:int}
     */
    public static function state(): array
    {
        $pending = self::pending();
        if ($pending === null) {
            return [
                'stage' => 'identify',
                'notice' => null,
                'resend_after' => 0,
                'authorization_expires_in' => 0,
            ];
        }

        $stage = (string) ($pending['stage'] ?? 'identify');
        $createdAt = (int) ($pending['created_at'] ?? 0);
        if ($stage !== 'password' && $createdAt > 0 && time() - $createdAt > self::PENDING_TTL) {
            self::clearPending();
            return [
                'stage' => 'identify',
                'notice' => 'Sesi permintaan sudah berakhir. Silakan mulai lagi.',
                'resend_after' => 0,
                'authorization_expires_in' => 0,
            ];
        }

        if ($stage === 'password') {
            $userId = (int) ($pending['user_id'] ?? 0);
            $token = (string) ($pending['token'] ?? '');
            $tokenHash = $token === '' ? '' : Crypto::fingerprint('password-reset-token:' . $token);
            $authorization = $tokenHash === '' ? null : PasswordReset::validAuthorization($userId, $tokenHash);
            if ($authorization === null) {
                self::clearPending();
                return [
                    'stage' => 'identify',
                    'notice' => 'Otorisasi reset sudah kedaluwarsa. Silakan mulai lagi.',
                    'resend_after' => 0,
                    'authorization_expires_in' => 0,
                ];
            }

            $expiresAt = strtotime((string) $authorization['expires_at']);

            return [
                'stage' => 'password',
                'notice' => null,
                'resend_after' => 0,
                'authorization_expires_in' => max(0, (int) ($expiresAt - time())),
            ];
        }

        return [
            'stage' => 'verify',
            'notice' => null,
            'resend_after' => self::resendAfter(),
            'authorization_expires_in' => 0,
        ];
    }

    /**
     * Nilai nonrahasia untuk teks bantuan di halaman publik.
     *
     * @return array{ttl_minutes:int,resend_cooldown:int,max_attempts:int}
     */
    public static function uiSettings(): array
    {
        $settings = ApiIntegrationSetting::get(ApiFeatureRegistry::WHATSAPP_OTP)['settings'];
        $ttl = max(60, min(1800, (int) ($settings['ttl_seconds'] ?? 300)));

        return [
            'ttl_minutes' => max(1, (int) ceil($ttl / 60)),
            'resend_cooldown' => max(0, (int) ($settings['resend_cooldown'] ?? 60)),
            'max_attempts' => max(1, min(10, (int) ($settings['max_attempts'] ?? 5))),
        ];
    }

    /**
     * Pastikan baris pelanggan benar-benar memiliki nomor terverifikasi.
     *
     * @param array<string, mixed> $account
     */
    private static function isEligible(array $account): bool
    {
        if (($account['role'] ?? '') !== 'pelanggan' || (int) ($account['is_active'] ?? 0) !== 1) {
            return false;
        }
        if ((int) ($account['pelanggan_id'] ?? 0) <= 0) {
            return false;
        }

        $phone = self::normalizedPhone($account);
        if ($phone === null) {
            return false;
        }

        $verifiedAt = trim((string) ($account['nomor_whatsapp_verified_at'] ?? ''));
        $stored = trim((string) ($account['nomor_whatsapp_verified_fingerprint'] ?? ''));
        $current = Crypto::fingerprint($phone);

        return $verifiedAt !== ''
            && $stored !== ''
            && $current !== ''
            && hash_equals($stored, $current);
    }

    /** @param array<string, mixed> $account */
    public static function normalizedPhone(array $account): ?string
    {
        return whatsapp_number((string) ($account['nomor_whatsapp'] ?? ''));
    }

    /** @return array<string, int> */
    private static function validatePassword(string $password, string $confirmation, string $username): array
    {
        $errors = [];
        $length = mb_strlen($password);

        if ($password === '') {
            $errors['password_baru'] = 'Password baru wajib diisi.';
        } elseif ($length < 8) {
            $errors['password_baru'] = 'Password baru minimal 8 karakter.';
        } elseif ($length > 72) {
            $errors['password_baru'] = 'Password baru maksimal 72 karakter.';
        } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $errors['password_baru'] = 'Password baru harus memuat huruf dan angka.';
        } elseif ($username !== '' && strtolower($password) === strtolower($username)) {
            $errors['password_baru'] = 'Password baru tidak boleh sama dengan username.';
        }

        if ($confirmation === '') {
            $errors['password_konfirmasi'] = 'Konfirmasi password wajib diisi.';
        } elseif ($password !== $confirmation) {
            $errors['password_konfirmasi'] = 'Konfirmasi password tidak sama.';
        }

        return $errors;
    }

    /** @return array<string, mixed>|null */
    private static function pending(): ?array
    {
        $value = Session::get(self::SESSION_KEY);
        if (!is_array($value)) {
            return null;
        }

        $stage = (string) ($value['stage'] ?? '');
        if (!in_array($stage, ['verify', 'password'], true)) {
            return null;
        }

        return $value;
    }

    private static function setPending(int $userId, string $stage, string $token = ''): void
    {
        $now = time();
        $existing = self::pending();
        $createdAt = $existing !== null ? (int) ($existing['created_at'] ?? $now) : $now;

        Session::set(self::SESSION_KEY, [
            'user_id' => $userId,
            'stage' => $stage,
            'token' => $token,
            'created_at' => $createdAt,
            'last_request_at' => $now,
            'verified_at' => $stage === 'password' ? $now : 0,
        ]);
    }

    private static function clearPending(): void
    {
        Session::forget(self::SESSION_KEY);
    }

    private static function resendAfter(): int
    {
        $pending = self::pending();
        if ($pending === null) {
            return 0;
        }

        $cooldown = max(0, (int) (ApiIntegrationSetting::get(ApiFeatureRegistry::WHATSAPP_OTP)['settings']['resend_cooldown'] ?? 60));
        $last = (int) ($pending['last_request_at'] ?? 0);

        return $last <= 0 ? 0 : max(0, $cooldown - (time() - $last));
    }

    private static function audit(?int $userId, string $action, array $metadata): void
    {
        try {
            AuditLog::record($userId, $action, $userId === null ? null : 'users', $userId, $metadata);
        } catch (Throwable $exception) {
            // Audit tidak boleh menghentikan alur reset yang sudah aman.
            app_log('Audit reset password gagal dicatat: ' . $exception->getMessage());
        }
    }

    /**
     * @return array{ok:bool,code:string,message:string,stage:string,retry_after:int}
     */
    private static function result(
        bool $ok,
        string $code,
        string $message,
        string $stage,
        int $retryAfter = 0
    ): array {
        return [
            'ok' => $ok,
            'code' => $code,
            'message' => $message,
            'stage' => $stage,
            'retry_after' => $retryAfter,
        ];
    }
}
