<?php
/**
 * Layanan OTP WhatsApp.
 *
 * Jaminan:
 * - Kode hanya disimpan sebagai keyed HMAC dan hanya dikirim ke nomor tujuan.
 * - OTP terikat pada akun, purpose, dan fingerprint nomor.
 * - Sekali pakai (consume atomik), kedaluwarsa, dan batas percobaan.
 * - Monitoring tidak pernah berisi kode/nomor plaintext.
 * - Template pesan dipilih server berdasarkan purpose; `phone_change` terpisah
 *   dari `phone_verification`.
 * - Provider berbasis template (Meta) wajib memakai Authentication template;
 *   teks bebas tidak boleh menjadi fallback.
 */

declare(strict_types=1);

final class WhatsAppOtpService
{
    public const PURPOSE_PHONE_VERIFICATION = 'phone_verification';
    public const PURPOSE_RESET_PASSWORD = 'reset_password';
    public const PURPOSE_PHONE_CHANGE = 'phone_change';
    public const PURPOSE_LEGACY_PHONE_VERIFICATION = 'verifikasi_nomor';

    /**
     * Minta OTP baru.
     *
     * @param array<string, mixed> $actor
     * @return array{ok:bool,code:string,message:string,expires_in:int,retry_after:int}
     */
    public static function request(array $actor, string $purpose, string $phone): array
    {
        $userId = (int) ($actor['id'] ?? 0);
        if ($userId <= 0) {
            return self::result(false, 'unauthenticated', 'Sesi tidak valid.');
        }

        if (!ApiIntegrationSetting::isEnabled(ApiFeatureRegistry::WHATSAPP_OTP)) {
            return self::result(false, 'feature_disabled', 'OTP WhatsApp belum diaktifkan Admin.');
        }
        if (!WhatsAppMessagingService::gatewayEnabled()) {
            self::log('otp_requested', 'gateway_disabled', false, 'gateway_disabled');
            return self::result(false, 'gateway_disabled', 'Gateway WhatsApp belum aktif.');
        }

        $settings = ApiIntegrationSetting::get(ApiFeatureRegistry::WHATSAPP_OTP)['settings'];
        if (!self::purposeAllowed($purpose, $settings)) {
            self::log('otp_rejected', 'purpose_disabled', false, 'purpose_disabled');
            return self::result(false, 'purpose_disabled', 'Jenis OTP tidak diizinkan.');
        }

        $normalized = WhatsAppMessagingService::normalizeRecipient($phone);
        if ($normalized === null) {
            self::log('otp_rejected', 'invalid_recipient', false, 'invalid_recipient');
            return self::result(false, 'invalid_recipient', 'Nomor WhatsApp tidak valid.');
        }

        $fingerprint = Crypto::fingerprint($normalized);
        if ($fingerprint === '') {
            return self::result(false, 'encryption_not_ready', 'Konfigurasi enkripsi server belum siap.');
        }

        $cooldown = max(0, (int) ($settings['resend_cooldown'] ?? 60));
        $elapsed = WhatsAppOtp::secondsSinceLatest($userId, $purpose);
        if ($cooldown > 0 && $elapsed !== null) {
            $remaining = $cooldown - max(0, $elapsed);
            if ($remaining > 0) {
                self::log('otp_rate_limited', 'cooldown', false, 'cooldown');
                return self::result(false, 'cooldown', 'Mohon tunggu sebelum meminta kode baru.', 0, $remaining);
            }
        }

        $userLimit = self::limit($settings['daily_user_limit'] ?? 5, 5);
        if (WhatsAppOtp::countForUserToday($userId) >= $userLimit) {
            self::log('otp_rate_limited', 'daily_user_limit', false, 'daily_user_limit');
            return self::result(false, 'daily_user_limit', 'Batas OTP harian akun tercapai.');
        }

        $phoneLimit = self::limit($settings['daily_phone_limit'] ?? 5, 5);
        if (WhatsAppOtp::countForPhoneToday($fingerprint) >= $phoneLimit) {
            self::log('otp_rate_limited', 'daily_phone_limit', false, 'daily_phone_limit');
            return self::result(false, 'daily_phone_limit', 'Batas OTP harian nomor tercapai.');
        }

        $ttl = max(60, min(1800, (int) ($settings['ttl_seconds'] ?? 300)));
        $maxAttempts = max(1, min(10, (int) ($settings['max_attempts'] ?? 5)));

        $connection = WhatsAppMessagingService::connectionForFeature(ApiFeatureRegistry::WHATSAPP_OTP);
        if ($connection === null) {
            self::log('otp_rejected', 'connection_unavailable', false, 'connection_unavailable');
            return self::result(false, 'connection_unavailable', 'Koneksi WhatsApp belum dikonfigurasi.');
        }

        $adapter = WhatsAppProviderRegistry::get((string) $connection['provider']);
        if ($adapter === null) {
            self::log('otp_rejected', 'provider_unsupported', false, 'provider_unsupported');
            return self::result(false, 'provider_unsupported', 'Provider WhatsApp tidak didukung.');
        }

        $sendMode = (string) ($settings['send_mode'] ?? 'text') === 'template' ? 'template' : 'text';
        $templateName = trim((string) ($settings['template_name'] ?? ''));
        $supportsTemplates = $adapter->supportsTemplates();

        // Fonnte adalah provider teks: abaikan mode template lama dan jangan
        // mewajibkan template_name Meta.
        if (!$supportsTemplates) {
            $sendMode = 'text';
            $templateName = '';
        }

        // Provider yang mendukung template (Meta) wajib memakai Authentication
        // template untuk semua OTP. Teks bebas tidak boleh menjadi fallback.
        if ($supportsTemplates && ($sendMode !== 'template' || $templateName === '')) {
            self::log('otp_rejected', 'template_required', false, 'template_required');
            return self::result(
                false,
                'template_required',
                'OTP dengan provider Meta hanya dapat dikirim melalui template Authentication yang sudah disetujui Admin.'
            );
        }

        $code = (string) random_int(100000, 999999);
        $codeHash = self::hashCode($code);
        if ($codeHash === '') {
            return self::result(false, 'encryption_not_ready', 'Konfigurasi enkripsi server belum siap.');
        }

        WhatsAppOtp::invalidateActive($userId, $purpose);
        $otpId = WhatsAppOtp::create($userId, $purpose, $fingerprint, $codeHash, $ttl, $maxAttempts);
        self::log('otp_requested', 'requested', true);

        // Template dipilih dari purpose yang ditentukan alur server. Field
        // template tidak pernah berasal dari input browser.
        $template = self::templateForPurpose($purpose, $settings);
        $body = strtr($template, [
            '{kode}' => $code,
            '{masa_berlaku}' => (string) max(1, (int) ceil($ttl / 60)),
            '{nama_aplikasi}' => (string) (Pengaturan::get('app_name') ?? config('app.name', 'WiFiCare CDN')),
        ]);

        // Body selalu disertakan agar outbox/retry tetap dapat dikirim sebagai
        // teks bila koneksi beralih ke provider teks seperti Fonnte.
        $payload = ['body' => $body];
        if ($sendMode === 'template') {
            $payload['parameters'] = [$code];
        }

        $send = WhatsAppMessagingService::sendImmediate(
            ApiFeatureRegistry::WHATSAPP_OTP,
            'otp:' . $otpId,
            $normalized,
            $payload,
            [
                'send_mode' => $sendMode,
                'template_name' => $templateName,
                'language_code' => (string) ($settings['language_code'] ?? 'id'),
                'authentication_template' => true,
                'event_type' => 'otp_send',
                'log_usage' => false,
            ]
        );

        $connection = WhatsAppMessagingService::connectionForFeature(ApiFeatureRegistry::WHATSAPP_OTP);
        ApiUsageLog::record(
            ApiFeatureRegistry::WHATSAPP_OTP,
            (string) ($connection['provider'] ?? ''),
            $connection === null ? null : (int) $connection['id'],
            'otp_send',
            !empty($send['ok']) ? 'accepted' : 'failed',
            !empty($send['ok']),
            (string) ($send['provider_message_id'] ?? ''),
            !empty($send['ok']) ? '' : (string) ($send['code'] ?? 'send_failed'),
            (int) ($send['duration_ms'] ?? 0)
        );

        if (empty($send['ok'])) {
            WhatsAppOtp::markSendFailed($otpId);
            return self::result(
                false,
                (string) ($send['code'] ?? 'send_failed'),
                'Kode OTP gagal dikirim. Silakan coba lagi.'
            );
        }

        WhatsAppOtp::markSent($otpId, (string) $send['provider_message_id']);

        return self::result(
            true,
            'sent',
            'Permintaan pengiriman OTP diterima provider. Periksa WhatsApp tujuan; status ini belum berarti pesan sudah delivered.',
            $ttl
        );
    }

    /**
     * Verifikasi OTP. Hanya OTP milik akun, purpose, dan nomor yang sama.
     *
     * Callback `$onVerified` dijalankan dalam transaksi yang sama dengan
     * konsumsi OTP. Bila callback gagal, konsumsi dibatalkan.
     *
     * @param array<string, mixed> $actor
     * @param callable():void|null $onVerified
     * @return array{ok:bool,code:string,message:string}
     */
    public static function verify(
        array $actor,
        string $purpose,
        string $phone,
        string $code,
        ?callable $onVerified = null
    ): array {
        $userId = (int) ($actor['id'] ?? 0);
        if ($userId <= 0) {
            return self::result(false, 'unauthenticated', 'Sesi tidak valid.');
        }

        if (!ApiIntegrationSetting::isEnabled(ApiFeatureRegistry::WHATSAPP_OTP)) {
            return self::result(false, 'feature_disabled', 'OTP WhatsApp belum diaktifkan Admin.');
        }

        $settings = ApiIntegrationSetting::get(ApiFeatureRegistry::WHATSAPP_OTP)['settings'];
        if (!self::purposeAllowed($purpose, $settings)) {
            return self::result(false, 'purpose_disabled', 'Jenis OTP tidak diizinkan.');
        }

        $normalized = WhatsAppMessagingService::normalizeRecipient($phone);
        $code = preg_replace('/\D+/', '', $code) ?? '';
        if ($normalized === null || strlen($code) !== 6) {
            return self::result(false, 'invalid', 'Kode OTP tidak valid atau sudah kedaluwarsa.');
        }

        $fingerprint = Crypto::fingerprint($normalized);
        if ($fingerprint === '') {
            return self::result(false, 'encryption_not_ready', 'Konfigurasi enkripsi server belum siap.');
        }

        $row = WhatsAppOtp::latestActive($userId, $purpose, $fingerprint);
        if ($row === null) {
            $latest = WhatsAppOtp::latestFor($userId, $purpose, $fingerprint);
            if ($latest !== null
                && ($latest['consumed_at'] ?? null) === null
                && !empty($latest['is_expired'])
                && WhatsAppOtp::markExpired((int) $latest['id'])) {
                self::log('otp_expired', 'expired', false, 'expired');
                return self::result(false, 'expired', 'Kode OTP sudah kedaluwarsa. Minta kode baru.');
            }

            return self::result(false, 'invalid', 'Kode OTP tidak valid atau sudah digunakan.');
        }

        $attempts = WhatsAppOtp::incrementAttempts((int) $row['id']);
        if ($attempts > (int) $row['max_attempts']) {
            WhatsAppOtp::consume((int) $row['id']);
            self::log('otp_verify_failed', 'max_attempts', false, 'max_attempts');
            return self::result(false, 'max_attempts', 'Batas percobaan tercapai. Minta kode baru.');
        }

        $expectedHash = self::hashCode($code);
        if ($expectedHash === '' || !hash_equals((string) $row['code_hash'], $expectedHash)) {
            $remaining = max(0, (int) $row['max_attempts'] - $attempts);
            if ($remaining === 0) {
                WhatsAppOtp::consume((int) $row['id']);
                self::log('otp_verify_failed', 'max_attempts', false, 'max_attempts');
                return self::result(false, 'max_attempts', 'Batas percobaan tercapai. Minta kode baru.');
            }
            self::log('otp_verify_failed', 'invalid_code', false, 'invalid_code');
            return self::result(false, 'invalid', 'Kode OTP tidak valid.');
        }

        try {
            Database::transaction(static function () use ($row, $onVerified): void {
                if (!WhatsAppOtp::consume((int) $row['id'])) {
                    throw new RuntimeException('otp_used');
                }
                if ($onVerified !== null) {
                    $onVerified();
                }
            });
        } catch (Throwable $exception) {
            if ($exception->getMessage() === 'otp_used') {
                return self::result(false, 'used', 'Kode OTP sudah digunakan.');
            }

            self::log('otp_verify_failed', 'verification_failed', false, 'verification_failed');
            return self::result(
                false,
                'verification_failed',
                'Verifikasi gagal disimpan. Silakan minta kode baru.'
            );
        }

        self::log('otp_verified', 'verified', true);

        return self::result(true, 'verified', 'OTP berhasil diverifikasi.');
    }

    /**
     * Statistik OTP dari data nyata (api_usage_log).
     *
     * @return array<string, int>
     */
    public static function monitoring(string $from = '', string $to = ''): array
    {
        $feature = ApiFeatureRegistry::WHATSAPP_OTP;

        return [
            'requested' => ApiUsageLog::countEvent($feature, 'otp_requested', $from, $to),
            'sent' => ApiUsageLog::countEvent($feature, 'otp_send', $from, $to, true),
            'failed' => ApiUsageLog::countEvent($feature, 'otp_send', $from, $to, false),
            'verified' => ApiUsageLog::countEvent($feature, 'otp_verified', $from, $to, true),
            'expired' => ApiUsageLog::countEvent($feature, 'otp_expired', $from, $to),
            'rate_limited' => ApiUsageLog::countEvent($feature, 'otp_rate_limited', $from, $to)
                + ApiUsageLog::countEvent($feature, 'otp_rejected', $from, $to),
        ];
    }

    /**
     * Verifier OTP memakai HMAC ter-key dari master key server.
     */
    private static function hashCode(string $code): string
    {
        if ($code === '') {
            return '';
        }

        return Crypto::fingerprint('whatsapp-otp-code:' . $code);
    }

    /**
     * Template teks untuk purpose yang sudah divalidasi server.
     *
     * `verifikasi_nomor` adalah purpose legacy dan memakai template
     * verifikasi nomor. Bila template khusus belum ada, fallback ke field
     * `template` lama agar konfigurasi existing tetap kompatibel.
     *
     * @param array<string, mixed> $settings
     */
    private static function templateForPurpose(string $purpose, array $settings): string
    {
        $key = self::templateSettingKey($purpose);
        $template = $key === null ? '' : trim((string) ($settings[$key] ?? ''));
        if ($template === '') {
            $template = trim((string) ($settings['template'] ?? ''));
        }

        return $template !== '' ? $template : 'Kode OTP Anda: {kode}';
    }

    private static function templateSettingKey(string $purpose): ?string
    {
        return match ($purpose) {
            self::PURPOSE_PHONE_VERIFICATION,
            self::PURPOSE_LEGACY_PHONE_VERIFICATION => 'template_phone_verification',
            self::PURPOSE_RESET_PASSWORD => 'template_reset_password',
            self::PURPOSE_PHONE_CHANGE => 'template_phone_change',
            default => null,
        };
    }

    /** @param array<string, mixed> $settings */
    private static function purposeAllowed(string $purpose, array $settings): bool
    {
        if (!isset(ApiFeatureRegistry::purposes()[$purpose])) {
            return false;
        }

        $map = is_array($settings['purposes'] ?? null) ? $settings['purposes'] : [];

        return !empty($map[$purpose]);
    }

    private static function limit(mixed $value, int $default): int
    {
        $limit = (int) $value;

        return $limit > 0 ? min(100, $limit) : $default;
    }

    private static function log(string $event, string $status, bool $success, string $error = ''): void
    {
        $connection = WhatsAppMessagingService::connectionForFeature(ApiFeatureRegistry::WHATSAPP_OTP);
        ApiUsageLog::record(
            ApiFeatureRegistry::WHATSAPP_OTP,
            (string) ($connection['provider'] ?? ''),
            $connection === null ? null : (int) $connection['id'],
            $event,
            $status,
            $success,
            '',
            $error
        );
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
