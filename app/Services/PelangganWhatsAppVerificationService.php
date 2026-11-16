<?php
/**
 * Verifikasi dan perubahan nomor WhatsApp utama pelanggan.
 *
 * Service ini menjembatani data pelanggan yang sedang login dengan
 * WhatsAppOtpService. Nomor lama selalu diambil dari baris pelanggan di
 * database. Nomor baru hanya disimpan setelah OTP ke nomor tersebut berhasil
 * diverifikasi, sehingga nomor lama tetap aktif sampai proses selesai.
 */

declare(strict_types=1);

final class PelangganWhatsAppVerificationService
{
    public const PURPOSE = WhatsAppOtpService::PURPOSE_PHONE_VERIFICATION;

    /**
     * Purpose khusus alur ganti nomor. Bila instalasi lama belum
     * mengaktifkan `phone_change`, alur tetap memakai purpose verifikasi
     * nomor agar konfigurasi existing tidak langsung rusak.
     */
    public const PURPOSE_CHANGE = WhatsAppOtpService::PURPOSE_PHONE_CHANGE;

    private const CHANGE_SESSION_KEY = '_wa_phone_change';
    private const PASSWORD_ATTEMPT_KEY = '_wa_phone_change_password_attempts';
    private const PASSWORD_WINDOW_SECONDS = 900;
    private const PASSWORD_MAX_ATTEMPTS = 5;

    /**
     * Status verifikasi nomor untuk baris pelanggan.
     *
     * Status hanya dianggap valid bila fingerprint nomor saat ini cocok.
     *
     * @param array<string, mixed> $pelanggan
     * @return array{verified:bool,verified_at:?string,normalized_phone:?string,phone:string,provider:string,provider_label:string,delivery_mode:string}
     */
    public static function status(array $pelanggan): array
    {
        $rawPhone = trim((string) ($pelanggan['nomor_whatsapp'] ?? ''));
        $normalized = whatsapp_number($rawPhone);
        $verifiedAt = trim((string) ($pelanggan['nomor_whatsapp_verified_at'] ?? ''));
        $storedFingerprint = trim((string) ($pelanggan['nomor_whatsapp_verified_fingerprint'] ?? ''));
        $currentFingerprint = $normalized === null ? '' : Crypto::fingerprint($normalized);

        $verified = $normalized !== null
            && $verifiedAt !== ''
            && $storedFingerprint !== ''
            && $currentFingerprint !== ''
            && hash_equals($storedFingerprint, $currentFingerprint);

        return [
            'verified' => $verified,
            'verified_at' => $verified ? $verifiedAt : null,
            'normalized_phone' => $normalized,
            'phone' => $rawPhone,
        ];
    }

    /**
     * Data aman untuk panel verifikasi di halaman Profil.
     *
     * Tidak pernah mengembalikan kode OTP atau nomor lengkap. Cooldown dan
     * masa berlaku dihitung dari database, bukan dari timer browser.
     *
     * @param array<string, mixed> $pelanggan
     * @return array{
     *   verified:bool,
     *   verified_at:?string,
     *   phone:string,
     *   masked_phone:string,
     *   ttl_seconds:int,
     *   ttl_minutes:int,
     *   max_attempts:int,
     *   resend_cooldown:int,
     *   resend_after:int,
     *   has_active_code:bool,
     *   code_expires_at:?string,
     *   code_expires_in:int,
     *   otp_enabled:bool,
     *   gateway_enabled:bool,
     *   template_ready:bool,
     *   ready:bool
     * }
     */
    public static function panelState(array $pelanggan): array
    {
        $status = self::status($pelanggan);
        $userId = (int) ($pelanggan['user_id'] ?? 0);
        $settings = ApiIntegrationSetting::get(ApiFeatureRegistry::WHATSAPP_OTP)['settings'];

        $ttl = max(60, min(1800, (int) ($settings['ttl_seconds'] ?? 300)));
        $maxAttempts = max(1, min(10, (int) ($settings['max_attempts'] ?? 5)));
        $cooldown = max(0, (int) ($settings['resend_cooldown'] ?? 60));
        $elapsed = $userId > 0 ? WhatsAppOtp::secondsSinceLatest($userId, self::PURPOSE) : null;
        $resendAfter = 0;
        if ($cooldown > 0 && $elapsed !== null) {
            $resendAfter = max(0, $cooldown - max(0, $elapsed));
        }

        $fingerprint = $status['normalized_phone'] === null
            ? null
            : Crypto::fingerprint($status['normalized_phone']);
        $active = $userId > 0
            && !$status['verified']
            && $fingerprint !== null
            && $fingerprint !== ''
            ? WhatsAppOtp::activeForUser($userId, self::PURPOSE, $fingerprint)
            : null;
        $expiresIn = $active === null ? 0 : max(0, (int) ($active['seconds_remaining'] ?? 0));

        $sendMode = (string) ($settings['send_mode'] ?? 'text') === 'template' ? 'template' : 'text';
        $templateName = trim((string) ($settings['template_name'] ?? ''));
        $connection = WhatsAppMessagingService::connectionForFeature(ApiFeatureRegistry::WHATSAPP_OTP);
        $provider = (string) ($connection['provider'] ?? '');
        $supportsTemplates = $connection !== null && WhatsAppMessagingService::connectionSupportsTemplates($connection);
        $otpEnabled = ApiIntegrationSetting::isEnabled(ApiFeatureRegistry::WHATSAPP_OTP);
        $gatewayEnabled = WhatsAppMessagingService::gatewayEnabled();
        // Provider teks (Fonnte) siap tanpa template Meta. Provider template
        // (Meta) tetap memerlukan mode template + nama template.
        $templateReady = $connection !== null
            && (!$supportsTemplates || ($sendMode === 'template' && $templateName !== ''));

        return [
            'verified' => $status['verified'],
            'verified_at' => $status['verified_at'],
            'phone' => $status['phone'],
            'masked_phone' => self::maskPhone($status['normalized_phone']),
            'provider' => $provider,
            'provider_label' => WhatsAppProviderRegistry::labels()[$provider] ?? $provider,
            'delivery_mode' => $supportsTemplates ? $sendMode : 'text',
            'ttl_seconds' => $ttl,
            'ttl_minutes' => max(1, (int) ceil($ttl / 60)),
            'max_attempts' => $active === null ? $maxAttempts : (int) $active['max_attempts'],
            'resend_cooldown' => $cooldown,
            'resend_after' => $resendAfter,
            'has_active_code' => $active !== null,
            'code_expires_at' => $active === null ? null : (string) $active['expires_at'],
            'code_expires_in' => $expiresIn,
            'otp_enabled' => $otpEnabled,
            'gateway_enabled' => $gatewayEnabled,
            'template_ready' => $templateReady,
            'ready' => $otpEnabled
                && $gatewayEnabled
                && $templateReady
                && $status['normalized_phone'] !== null,
        ];
    }

    /**
     * Purpose server-side untuk alur ganti nomor.
     *
     * Purpose yang sudah tersimpan pada session permintaan selalu dipakai
     * kembali agar permintaan tidak berpindah purpose di tengah alur.
     *
     * @param array<string, mixed>|null $settings
     * @param array<string, mixed>|null $pending
     */
    private static function changePurpose(?array $settings = null, ?array $pending = null): string
    {
        $stored = is_array($pending) ? (string) ($pending['purpose'] ?? '') : '';
        if ($stored === self::PURPOSE_CHANGE || $stored === self::PURPOSE) {
            return $stored;
        }

        $settings ??= ApiIntegrationSetting::get(ApiFeatureRegistry::WHATSAPP_OTP)['settings'];

        return !empty($settings['purposes'][self::PURPOSE_CHANGE])
            ? self::PURPOSE_CHANGE
            : self::PURPOSE;
    }

    /**
     * Data aman untuk panel/modal ganti nomor di halaman Profil.
     *
     * Nomor baru tidak pernah dikembalikan penuh. Permintaan yang OTP-nya
     * sudah tidak aktif otomatis dibuang agar tidak ada state menggantung.
     *
     * @param array<string, mixed> $pelanggan
     * @return array{
     *   pending:bool,
     *   new_phone_masked:string,
     *   has_active_code:bool,
     *   code_expires_in:int,
     *   resend_after:int,
     *   ttl_minutes:int,
     *   max_attempts:int,
     *   ready:bool,
     *   otp_enabled:bool,
     *   gateway_enabled:bool,
     *   template_ready:bool,
     *   provider:string,
     *   provider_label:string,
     *   delivery_mode:string
     * }
     */
    public static function changeState(array $pelanggan): array
    {
        $userId = (int) ($pelanggan['user_id'] ?? 0);
        $settings = ApiIntegrationSetting::get(ApiFeatureRegistry::WHATSAPP_OTP)['settings'];
        $ttl = max(60, min(1800, (int) ($settings['ttl_seconds'] ?? 300)));
        $maxAttempts = max(1, min(10, (int) ($settings['max_attempts'] ?? 5)));
        $cooldown = max(0, (int) ($settings['resend_cooldown'] ?? 60));

        $connection = WhatsAppMessagingService::connectionForFeature(ApiFeatureRegistry::WHATSAPP_OTP);
        $provider = (string) ($connection['provider'] ?? '');
        $supportsTemplates = $connection !== null && WhatsAppMessagingService::connectionSupportsTemplates($connection);
        $sendMode = (string) ($settings['send_mode'] ?? 'text') === 'template' ? 'template' : 'text';
        $templateName = trim((string) ($settings['template_name'] ?? ''));
        $otpEnabled = ApiIntegrationSetting::isEnabled(ApiFeatureRegistry::WHATSAPP_OTP);
        $gatewayEnabled = WhatsAppMessagingService::gatewayEnabled();
        $templateReady = $connection !== null
            && (!$supportsTemplates || ($sendMode === 'template' && $templateName !== ''));

        $pending = self::pendingChange($pelanggan);
        $purpose = self::changePurpose($settings, $pending);
        $purposeReady = !empty($settings['purposes'][$purpose]);
        $active = $pending['active'] ?? null;
        $resendAfter = 0;
        if ($pending !== null) {
            $elapsed = $userId > 0 ? WhatsAppOtp::secondsSinceLatest($userId, $purpose) : null;
            if ($cooldown > 0 && $elapsed !== null) {
                $resendAfter = max(0, $cooldown - max(0, $elapsed));
            }
        }

        return [
            'pending' => $pending !== null,
            'new_phone_masked' => $pending === null ? '' : self::maskPhone((string) $pending['new_phone']),
            'has_active_code' => $active !== null,
            'code_expires_in' => $active === null ? 0 : max(0, (int) ($active['seconds_remaining'] ?? 0)),
            'resend_after' => $resendAfter,
            'ttl_minutes' => max(1, (int) ceil($ttl / 60)),
            'max_attempts' => $active === null ? $maxAttempts : (int) $active['max_attempts'],
            'ready' => $otpEnabled && $gatewayEnabled && $templateReady && $purposeReady,
            'otp_enabled' => $otpEnabled,
            'gateway_enabled' => $gatewayEnabled,
            'template_ready' => $templateReady,
            'provider' => $provider,
            'provider_label' => WhatsAppProviderRegistry::labels()[$provider] ?? $provider,
            'delivery_mode' => $supportsTemplates ? $sendMode : 'text',
        ];
    }

    /**
     * Minta OTP ke nomor WhatsApp BARU setelah password WiFiCare diverifikasi.
     *
     * Nomor lama tidak diubah di sini. Nomor baru hanya disimpan di session
     * server setelah provider menerima permintaan pengiriman OTP.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $pelanggan
     * @return array{ok:bool,code:string,message:string,expires_in:int,retry_after:int}
     */
    public static function requestChange(
        array $actor,
        array $pelanggan,
        string $newPhoneRaw,
        string $password
    ): array {
        $guard = self::guard($actor, $pelanggan);
        if ($guard !== null) {
            return $guard;
        }

        $lockedFor = self::passwordLockedFor();
        if ($lockedFor > 0) {
            return self::result(
                false,
                'password_locked',
                'Terlalu banyak percobaan password. Coba lagi nanti.',
                0,
                $lockedFor
            );
        }

        $userId = (int) $actor['id'];
        $user = User::findById($userId);
        if ($user === null) {
            return self::result(false, 'unauthenticated', 'Sesi tidak valid.');
        }

        $purpose = self::changePurpose();
        if ($password === '' || !password_verify($password, (string) ($user['password_hash'] ?? ''))) {
            self::recordPasswordFailure();
            self::audit($userId, (int) $pelanggan['id'], 'whatsapp_ganti_nomor_ditolak', [
                'purpose' => $purpose,
                'alasan' => 'password_salah',
            ]);

            return self::result(false, 'invalid_password', 'Password WiFiCare saat ini tidak sesuai.');
        }

        self::clearPasswordFailures();

        $currentRaw = (string) ($pelanggan['nomor_whatsapp'] ?? '');
        $current = whatsapp_number($currentRaw);
        $newPhone = whatsapp_number($newPhoneRaw);
        if ($newPhone === null) {
            return self::result(false, 'invalid_recipient', 'Nomor WhatsApp baru tidak valid.');
        }
        if ($current !== null && hash_equals($current, $newPhone)) {
            return self::result(false, 'same_number', 'Nomor baru sama dengan nomor WhatsApp saat ini.');
        }

        $result = WhatsAppOtpService::request($actor, $purpose, $newPhone);
        if (empty($result['ok'])) {
            self::audit($userId, (int) $pelanggan['id'], 'whatsapp_ganti_nomor_gagal', [
                'purpose' => $purpose,
                'alasan' => (string) ($result['code'] ?? 'send_failed'),
            ]);

            return $result;
        }

        Session::set(self::CHANGE_SESSION_KEY, [
            'user_id' => $userId,
            'pelanggan_id' => (int) $pelanggan['id'],
            'old_phone' => $currentRaw,
            'new_phone' => $newPhone,
            'purpose' => $purpose,
            'requested_at' => time(),
        ]);

        self::audit($userId, (int) $pelanggan['id'], 'whatsapp_ganti_nomor_diminta', [
            'purpose' => $purpose,
            'nomor_lama' => self::maskPhone($current),
            'nomor_baru' => self::maskPhone($newPhone),
            'provider_status' => 'accepted',
        ]);

        return $result;
    }

    /**
     * Kirim ulang OTP ke nomor baru yang sudah lolos verifikasi password.
     *
     * Tidak menerima nomor dari browser; nomor baru diambil dari session
     * permintaan yang masih memiliki OTP aktif.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $pelanggan
     * @return array{ok:bool,code:string,message:string,expires_in:int,retry_after:int}
     */
    public static function resendChange(array $actor, array $pelanggan): array
    {
        $guard = self::guard($actor, $pelanggan);
        if ($guard !== null) {
            return $guard;
        }

        $pending = self::pendingChange($pelanggan);
        if ($pending === null) {
            return self::result(
                false,
                'change_expired',
                'Permintaan ganti nomor sudah tidak berlaku. Mulai ulang dari awal.'
            );
        }

        $userId = (int) $actor['id'];
        $newPhone = (string) $pending['new_phone'];
        $purpose = self::changePurpose(null, $pending);
        $result = WhatsAppOtpService::request($actor, $purpose, $newPhone);

        if (!empty($result['ok'])) {
            self::audit($userId, (int) $pelanggan['id'], 'whatsapp_ganti_nomor_otp_dikirim_ulang', [
                'purpose' => $purpose,
                'nomor_baru' => self::maskPhone($newPhone),
                'provider_status' => 'accepted',
            ]);

            return $result;
        }

        // Bila kegagalan membuat OTP aktif hilang, buang juga permintaan
        // session agar pengguna memulai ulang dengan password.
        $fingerprint = Crypto::fingerprint($newPhone);
        $active = $fingerprint === ''
            ? null
            : WhatsAppOtp::activeForUser($userId, $purpose, $fingerprint);
        if ($active === null) {
            Session::forget(self::CHANGE_SESSION_KEY);
        }

        self::audit($userId, (int) $pelanggan['id'], 'whatsapp_ganti_nomor_gagal', [
            'purpose' => $purpose,
            'alasan' => (string) ($result['code'] ?? 'send_failed'),
        ]);

        return $result;
    }

    /**
     * Verifikasi OTP nomor baru lalu ganti nomor utama secara atomik.
     *
     * Konsumsi OTP dan update nomor berjalan dalam transaksi yang sama.
     * Bila update gagal, OTP tidak dianggap terpakai.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $pelanggan
     * @return array{ok:bool,code:string,message:string,expires_in:int,retry_after:int}
     */
    public static function verifyChange(array $actor, array $pelanggan, string $code): array
    {
        $guard = self::guard($actor, $pelanggan);
        if ($guard !== null) {
            return $guard;
        }

        $pending = self::pendingChange($pelanggan);
        if ($pending === null) {
            return self::result(
                false,
                'change_expired',
                'Permintaan ganti nomor sudah tidak berlaku. Mulai ulang dari awal.'
            );
        }

        $userId = (int) $actor['id'];
        $pelangganId = (int) $pelanggan['id'];
        $oldPhone = (string) $pending['old_phone'];
        $newPhone = (string) $pending['new_phone'];
        $purpose = self::changePurpose(null, $pending);
        $fingerprint = Crypto::fingerprint($newPhone);
        if ($fingerprint === '') {
            return self::result(false, 'encryption_not_ready', 'Konfigurasi enkripsi server belum siap.');
        }

        // Cegah update memakai data pelanggan yang sudah basi.
        $fresh = Pelanggan::findByUserId($userId);
        if ($fresh === null || (string) ($fresh['nomor_whatsapp'] ?? '') !== $oldPhone) {
            Session::forget(self::CHANGE_SESSION_KEY);

            return self::result(
                false,
                'change_expired',
                'Nomor utama sudah berubah. Permintaan ganti nomor dibatalkan.'
            );
        }

        $result = WhatsAppOtpService::verify(
            $actor,
            $purpose,
            $newPhone,
            $code,
            static function () use ($pelangganId, $oldPhone, $newPhone, $fingerprint): void {
                if (!Pelanggan::replaceWhatsappVerified($pelangganId, $oldPhone, $newPhone, $fingerprint)) {
                    throw new RuntimeException('phone_changed');
                }
            }
        );

        if (!empty($result['ok'])) {
            Session::forget(self::CHANGE_SESSION_KEY);
            self::audit($userId, $pelangganId, 'whatsapp_nomor_diganti', [
                'purpose' => $purpose,
                'nomor_lama' => self::maskPhone($oldPhone),
                'nomor_baru' => self::maskPhone($newPhone),
                'nomor_whatsapp_diubah' => true,
                'status_verifikasi' => 'verified',
            ]);

            return self::result(
                true,
                'changed',
                'Nomor WhatsApp berhasil diganti dan diverifikasi.'
            );
        }

        // Kode salah masih menyisakan OTP aktif; batas percobaan/kedaluwarsa
        // membuat OTP tidak aktif lagi sehingga session harus dibersihkan.
        $active = WhatsAppOtp::activeForUser($userId, $purpose, $fingerprint);
        if ($active === null) {
            Session::forget(self::CHANGE_SESSION_KEY);
        }

        self::audit($userId, $pelangganId, 'whatsapp_ganti_nomor_gagal', [
            'purpose' => $purpose,
            'alasan' => (string) ($result['code'] ?? 'verification_failed'),
        ]);

        return $result;
    }

    /**
     * Batalkan permintaan ganti nomor dan OTP khusus nomor baru.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $pelanggan
     * @return array{ok:bool,code:string,message:string,expires_in:int,retry_after:int}
     */
    public static function cancelChange(array $actor, array $pelanggan): array
    {
        $guard = self::guard($actor, $pelanggan);
        if ($guard !== null) {
            return $guard;
        }

        $pending = self::pendingChangeRaw($pelanggan);
        if ($pending === null) {
            return self::result(
                true,
                'cancelled',
                'Tidak ada permintaan ganti nomor yang aktif. Nomor lama tetap aktif.'
            );
        }

        $userId = (int) $actor['id'];
        $newPhone = (string) $pending['new_phone'];
        $purpose = self::changePurpose(null, $pending);
        $fingerprint = Crypto::fingerprint($newPhone);
        if ($fingerprint !== '') {
            WhatsAppOtp::invalidateActiveForPhone($userId, $purpose, $fingerprint);
        }
        Session::forget(self::CHANGE_SESSION_KEY);

        self::audit($userId, (int) $pelanggan['id'], 'whatsapp_ganti_nomor_dibatalkan', [
            'purpose' => $purpose,
            'nomor_baru' => self::maskPhone($newPhone),
        ]);

        return self::result(
            true,
            'cancelled',
            'Permintaan ganti nomor dibatalkan. Nomor lama tetap aktif.'
        );
    }

    /**
     * Samarkan nomor untuk kartu verifikasi. Nomor lengkap tetap hanya
     * ditampilkan pada field readonly yang sudah ada.
     */
    private static function maskPhone(?string $normalized): string
    {
        if ($normalized === null || $normalized === '') {
            return '—';
        }

        $digits = preg_replace('/\D+/', '', $normalized) ?? '';
        $length = strlen($digits);
        if ($length <= 6) {
            return '+' . str_repeat('•', max(3, $length));
        }

        return '+' . substr($digits, 0, 4)
            . str_repeat('•', max(3, $length - 7))
            . substr($digits, -3);
    }

    /**
     * Minta OTP untuk nomor utama pelanggan yang sedang login.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $pelanggan
     * @return array{ok:bool,code:string,message:string,expires_in:int,retry_after:int}
     */
    public static function request(array $actor, array $pelanggan): array
    {
        $guard = self::guard($actor, $pelanggan);
        if ($guard !== null) {
            return $guard;
        }

        if (self::status($pelanggan)['verified']) {
            return self::result(false, 'already_verified', 'Nomor WhatsApp sudah terverifikasi.');
        }

        $phone = (string) ($pelanggan['nomor_whatsapp'] ?? '');
        $result = WhatsAppOtpService::request($actor, self::PURPOSE, $phone);

        if (!empty($result['ok'])) {
            self::audit((int) $actor['id'], (int) $pelanggan['id'], 'whatsapp_otp_diminta', [
                'purpose' => self::PURPOSE,
                'provider_status' => 'accepted',
            ]);
        }

        return $result;
    }

    /**
     * Verifikasi OTP milik pelanggan dan tandai nomor sebagai terverifikasi.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $pelanggan
     * @return array{ok:bool,code:string,message:string,expires_in:int,retry_after:int}
     */
    public static function verify(array $actor, array $pelanggan, string $code): array
    {
        $guard = self::guard($actor, $pelanggan);
        if ($guard !== null) {
            return $guard;
        }

        $status = self::status($pelanggan);
        if ($status['verified']) {
            return self::result(false, 'already_verified', 'Nomor WhatsApp sudah terverifikasi.');
        }

        $rawPhone = (string) ($pelanggan['nomor_whatsapp'] ?? '');
        $normalized = $status['normalized_phone'];
        if ($normalized === null) {
            return self::result(false, 'invalid_recipient', 'Nomor WhatsApp tidak valid.');
        }

        $fingerprint = Crypto::fingerprint($normalized);
        if ($fingerprint === '') {
            return self::result(false, 'encryption_not_ready', 'Konfigurasi enkripsi server belum siap.');
        }

        $pelangganId = (int) $pelanggan['id'];
        $result = WhatsAppOtpService::verify(
            $actor,
            self::PURPOSE,
            $rawPhone,
            $code,
            static function () use ($pelangganId, $rawPhone, $fingerprint): void {
                if (!Pelanggan::markWhatsappVerified($pelangganId, $rawPhone, $fingerprint)) {
                    throw new RuntimeException('phone_changed');
                }
            }
        );

        if (!empty($result['ok'])) {
            self::audit((int) $actor['id'], $pelangganId, 'whatsapp_nomor_diverifikasi', [
                'purpose' => self::PURPOSE,
                'nomor_whatsapp_diubah' => false,
            ]);
        }

        return $result;
    }

    /**
     * Ambil permintaan ganti nomor dari session tanpa query OTP.
     *
     * @param array<string, mixed> $pelanggan
     * @return array<string, mixed>|null
     */
    private static function pendingChangeRaw(array $pelanggan): ?array
    {
        $pending = Session::get(self::CHANGE_SESSION_KEY);
        if (!is_array($pending)) {
            return null;
        }

        $userId = (int) ($pelanggan['user_id'] ?? 0);
        $pelangganId = (int) ($pelanggan['id'] ?? 0);
        $oldPhone = (string) ($pelanggan['nomor_whatsapp'] ?? '');
        $storedOldPhone = (string) ($pending['old_phone'] ?? '');
        $newPhone = trim((string) ($pending['new_phone'] ?? ''));

        if ((int) ($pending['user_id'] ?? 0) !== $userId
            || (int) ($pending['pelanggan_id'] ?? 0) !== $pelangganId
            || $newPhone === ''
            || $oldPhone !== $storedOldPhone
        ) {
            Session::forget(self::CHANGE_SESSION_KEY);

            return null;
        }

        return $pending;
    }

    /**
     * Ambil permintaan ganti nomor yang masih memiliki OTP aktif.
     *
     * @param array<string, mixed> $pelanggan
     * @return array<string, mixed>|null
     */
    private static function pendingChange(array $pelanggan): ?array
    {
        $pending = self::pendingChangeRaw($pelanggan);
        if ($pending === null) {
            return null;
        }

        $userId = (int) $pelanggan['user_id'];
        $newPhone = (string) $pending['new_phone'];
        $purpose = self::changePurpose(null, $pending);
        $fingerprint = Crypto::fingerprint($newPhone);
        $active = $fingerprint === ''
            ? null
            : WhatsAppOtp::activeForUser($userId, $purpose, $fingerprint);

        if ($active === null) {
            Session::forget(self::CHANGE_SESSION_KEY);

            return null;
        }

        $pending['active'] = $active;

        return $pending;
    }

    /**
     * Sisa detik sampai percobaan password boleh dicoba lagi.
     */
    private static function passwordLockedFor(): int
    {
        $attempts = self::passwordAttempts();
        if (count($attempts) < self::PASSWORD_MAX_ATTEMPTS) {
            return 0;
        }

        return max(0, self::PASSWORD_WINDOW_SECONDS - (time() - min($attempts)));
    }

    /** @return array<int, int> */
    private static function passwordAttempts(): array
    {
        $raw = Session::get(self::PASSWORD_ATTEMPT_KEY, []);
        if (!is_array($raw)) {
            return [];
        }

        $cutoff = time() - self::PASSWORD_WINDOW_SECONDS;
        $attempts = [];
        foreach ($raw as $timestamp) {
            $value = (int) $timestamp;
            if ($value > $cutoff) {
                $attempts[] = $value;
            }
        }

        return $attempts;
    }

    private static function recordPasswordFailure(): void
    {
        $attempts = self::passwordAttempts();
        $attempts[] = time();
        Session::set(self::PASSWORD_ATTEMPT_KEY, $attempts);
    }

    private static function clearPasswordFailures(): void
    {
        Session::forget(self::PASSWORD_ATTEMPT_KEY);
    }

    /**
     * Pastikan aktor adalah Pelanggan pemilik baris pelanggan.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $pelanggan
     * @return array{ok:bool,code:string,message:string,expires_in:int,retry_after:int}|null
     */
    private static function guard(array $actor, array $pelanggan): ?array
    {
        $userId = (int) ($actor['id'] ?? 0);
        if ($userId <= 0) {
            return self::result(false, 'unauthenticated', 'Sesi tidak valid.');
        }
        if ((string) ($actor['role'] ?? '') !== 'pelanggan') {
            return self::result(false, 'forbidden', 'Fitur ini hanya untuk Pelanggan.');
        }
        if ((int) ($pelanggan['user_id'] ?? 0) !== $userId) {
            return self::result(false, 'forbidden', 'Data pelanggan tidak sesuai dengan sesi.');
        }

        return null;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private static function audit(int $userId, int $pelangganId, string $action, array $metadata): void
    {
        try {
            AuditLog::record($userId, $action, 'pelanggan', $pelangganId, $metadata);
        } catch (Throwable $exception) {
            app_log('Gagal mencatat audit OTP WhatsApp pelanggan ' . $pelangganId . '.');
        }
    }

    /**
     * @return array{ok:bool,code:string,message:string,expires_in:int,retry_after:int}
     */
    private static function result(
        bool $ok,
        string $code,
        string $message,
        int $expiresIn = 0,
        int $retryAfter = 0
    ): array {
        return [
            'ok' => $ok,
            'code' => $code,
            'message' => $message,
            'expires_in' => $expiresIn,
            'retry_after' => $retryAfter,
        ];
    }
}
