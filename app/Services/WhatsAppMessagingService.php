<?php
/**
 * WhatsApp Messaging Service — satu pintu untuk OTP, notifikasi, dan laporan.
 *
 * Semua fitur memakai adapter yang sama. Tidak ada kode HTTP duplikat.
 * Status yang dikembalikan setelah send adalah "accepted" (provider menerima
 * request), BUKAN "delivered". Delivery hanya dari webhook terverifikasi.
 */

declare(strict_types=1);

final class WhatsAppMessagingService
{
    public static function gatewayEnabled(): bool
    {
        return ApiIntegrationSetting::isEnabled(ApiFeatureRegistry::WHATSAPP_GATEWAY);
    }

    public static function encryptionReady(): bool
    {
        return Crypto::isReady();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function connectionForFeature(string $feature): ?array
    {
        return ApiCredentialService::connectionForFeature($feature);
    }

    /**
     * Tambahkan notifikasi ke antrean idempoten.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     * @return array{id:int,created:bool}
     */
    public static function enqueueEvent(
        string $feature,
        string $eventKey,
        string $recipient,
        array $payload,
        array $options = []
    ): array {
        self::assertGatewayReady();
        if (!ApiIntegrationSetting::isEnabled($feature)) {
            throw new RuntimeException('feature_disabled');
        }

        $connection = self::connectionForFeature($feature);
        if ($connection === null) {
            throw new RuntimeException('connection_unavailable');
        }

        $normalized = self::normalizeRecipient($recipient);
        if ($normalized === null) {
            throw new ValidationException(['nomor' => 'Nomor WhatsApp penerima tidak valid.']);
        }

        self::assertDailyLimit($feature);

        $settings = ApiIntegrationSetting::get($feature)['settings'];
        $maxAttempts = (int) ($options['max_attempts'] ?? ($settings['retry_max'] ?? 3));

        return WhatsAppOutbox::enqueue(
            $feature,
            $eventKey,
            (int) $connection['id'],
            $normalized,
            (string) ($options['send_mode'] ?? 'text'),
            (string) ($options['template_name'] ?? ''),
            (string) ($options['language_code'] ?? ''),
            $payload,
            $maxAttempts,
            (string) ($options['template_key'] ?? '')
        );
    }

    /**
     * Kirim langsung (OTP) dan catat status accepted untuk pelacakan webhook.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     * @return array{ok:bool,status:string,code:string,message:string,provider_message_id:string,duration_ms:int,outbox_id:int}
     */
    public static function sendImmediate(
        string $feature,
        string $eventKey,
        string $recipient,
        array $payload,
        array $options = []
    ): array {
        self::assertGatewayReady();
        $requireFeatureEnabled = !array_key_exists('require_feature_enabled', $options)
            || !empty($options['require_feature_enabled']);
        if ($requireFeatureEnabled && !ApiIntegrationSetting::isEnabled($feature)) {
            return self::failed('feature_disabled', 'Fitur WhatsApp tidak aktif.');
        }

        $connection = null;
        $requestedConnectionId = (int) ($options['connection_id'] ?? 0);
        if ($requestedConnectionId > 0) {
            $candidate = ApiCredentialService::connection($requestedConnectionId);
            if ($candidate !== null && !empty($candidate['is_active'])) {
                $connection = $candidate;
            }
        } else {
            $connection = self::connectionForFeature($feature);
        }
        if ($connection === null) {
            return self::failed('connection_unavailable', 'Koneksi WhatsApp aktif belum tersedia.');
        }

        $normalized = self::normalizeRecipient($recipient);
        if ($normalized === null) {
            return self::failed('invalid_recipient', 'Nomor WhatsApp penerima tidak valid.');
        }

        try {
            self::assertDailyLimit($feature);
        } catch (Throwable $exception) {
            return self::failed('rate_limited', 'Batas pengiriman harian fitur tercapai.');
        }

        $adapter = WhatsAppProviderRegistry::get((string) $connection['provider']);
        if ($adapter === null) {
            return self::failed('provider_unsupported', 'Provider WhatsApp tidak didukung.');
        }

        $timeout = (int) (ApiIntegrationSetting::get(ApiFeatureRegistry::WHATSAPP_GATEWAY)['settings']['timeout'] ?? 20);
        $timeout = max(5, min(60, $timeout));
        $sendMode = (string) ($options['send_mode'] ?? 'text') === 'template' ? 'template' : 'text';
        $templateName = (string) ($options['template_name'] ?? '');
        $body = (string) ($payload['body'] ?? '');

        // Fonnte adalah provider teks. Bila konfigurasi lama masih menyimpan
        // mode template (mis. setelah berganti koneksi), gunakan body teks dan
        // jangan mewajibkan template_name Meta.
        if ($sendMode === 'template' && !$adapter->supportsTemplates()) {
            if (trim($body) === '') {
                return self::failed(
                    'template_not_supported',
                    'Provider ini hanya mendukung pesan teks dan isi pesan belum tersedia.'
                );
            }
            $sendMode = 'text';
            $templateName = '';
        }

        if ($sendMode === 'template' && $templateName === '') {
            return self::failed('template_missing', 'Nama template WhatsApp belum dikonfigurasi.');
        }

        if ($sendMode === 'template') {
            $parameters = array_map(
                static fn (mixed $value): string => (string) $value,
                (array) ($payload['parameters'] ?? [])
            );
            $result = $adapter->sendTemplate(
                $connection,
                $normalized,
                $templateName,
                (string) ($options['language_code'] ?? 'id'),
                $parameters,
                $timeout,
                !empty($options['authentication_template'])
            );
        } else {
            $result = $adapter->sendText(
                $connection,
                $normalized,
                $body,
                $timeout
            );
        }

        $ok = !empty($result['ok']);
        $outboxId = 0;
        if ($ok && (string) ($result['provider_message_id'] ?? '') !== '') {
            try {
                $outboxId = WhatsAppOutbox::recordAccepted(
                    $feature,
                    $eventKey,
                    (int) $connection['id'],
                    $normalized,
                    $sendMode,
                    $templateName,
                    (string) ($options['language_code'] ?? ''),
                    $payload,
                    (string) $result['provider_message_id'],
                    (string) ($options['template_key'] ?? '')
                );
            } catch (Throwable $exception) {
                app_log('Pesan WhatsApp terkirim tetapi gagal dicatat pada outbox.');
            }
        }

        if (($options['log_usage'] ?? true) !== false) {
            ApiUsageLog::record(
                $feature,
                (string) $connection['provider'],
                (int) $connection['id'],
                (string) ($options['event_type'] ?? 'message_send'),
                $ok ? 'accepted' : 'failed',
                $ok,
                (string) ($result['provider_message_id'] ?? ''),
                $ok ? '' : (string) ($result['code'] ?? 'send_failed'),
                (int) ($result['duration_ms'] ?? 0)
            );
        }

        return [
            'ok' => $ok,
            'status' => $ok ? 'accepted' : 'failed',
            'code' => (string) ($result['code'] ?? ''),
            'message' => (string) ($result['message'] ?? ''),
            'provider_message_id' => (string) ($result['provider_message_id'] ?? ''),
            'duration_ms' => (int) ($result['duration_ms'] ?? 0),
            'outbox_id' => $outboxId,
        ];
    }

    /**
     * Proses antrean notifikasi. Kegagalan satu pesan tidak menghentikan yang
     * lain. Tidak pernah mengubah status menjadi delivered.
     *
     * @return array{processed:int,accepted:int,failed:int,error:string}
     */
    public static function processQueue(int $limit = 10, string $feature = '', ?int $timeoutSeconds = null): array
    {
        if (!self::encryptionReady()) {
            return ['processed' => 0, 'accepted' => 0, 'failed' => 0, 'error' => 'encryption_not_ready'];
        }
        if (!self::gatewayEnabled()) {
            return ['processed' => 0, 'accepted' => 0, 'failed' => 0, 'error' => 'gateway_disabled'];
        }
        if ($feature !== '' && !ApiIntegrationSetting::isEnabled($feature)) {
            return ['processed' => 0, 'accepted' => 0, 'failed' => 0, 'error' => 'feature_disabled'];
        }

        $rows = WhatsAppOutbox::claim($limit, $feature);
        $accepted = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $decoded = WhatsAppOutbox::decode($row);
            if ($decoded === null) {
                WhatsAppOutbox::markFailure((int) $row['id'], 'decrypt_failed', 'Pesan tidak dapat didekripsi.');
                $failed++;
                continue;
            }

            // Pengaturan Admin dapat berubah setelah baris masuk antrean.
            // Event yang sudah dinonaktifkan tidak boleh dikirim dan tidak
            // boleh menggantung sebagai pending selamanya.
            $templateKey = (string) ($row['template_key'] ?? '');
            if ($decoded['feature_key'] === ApiFeatureRegistry::WHATSAPP_NOTIFICATION
                && $templateKey !== ''
                && !WhatsAppNotificationService::eventEnabled($templateKey)
            ) {
                WhatsAppOutbox::markSkipped(
                    (int) $row['id'],
                    'event_disabled',
                    'Event notifikasi sudah dinonaktifkan sebelum pengiriman.'
                );
                $failed++;
                continue;
            }

            $connection = $decoded['connection_id'] !== null
                ? ApiCredentialService::connection((int) $decoded['connection_id'])
                : self::connectionForFeature((string) $decoded['feature_key']);

            if ($connection === null) {
                WhatsAppOutbox::markFailure((int) $row['id'], 'connection_unavailable', 'Koneksi WhatsApp tidak tersedia.');
                $failed++;
                continue;
            }

            $adapter = WhatsAppProviderRegistry::get((string) $connection['provider']);
            if ($adapter === null) {
                WhatsAppOutbox::markFailure((int) $row['id'], 'provider_unsupported', 'Provider WhatsApp tidak didukung.');
                $failed++;
                continue;
            }

            $timeout = $timeoutSeconds !== null
                ? (int) $timeoutSeconds
                : (int) (ApiIntegrationSetting::get(ApiFeatureRegistry::WHATSAPP_GATEWAY)['settings']['timeout'] ?? 20);
            // Pengiriman otomatis memakai timeout pendek agar request tiket
            // tidak tertahan lama ketika provider bermasalah.
            $timeout = max(5, min(60, $timeout));
            $payload = is_array($decoded['payload']) ? $decoded['payload'] : [];
            $body = (string) ($payload['body'] ?? '');
            $sendMode = (string) $decoded['send_mode'] === 'template' ? 'template' : 'text';

            // Outbox lama mungkin masih mode template saat koneksi berganti ke
            // Fonnte. Kirim sebagai teks bila body tersedia; jangan fallback ke
            // template Meta yang tidak didukung provider.
            if ($sendMode === 'template' && !$adapter->supportsTemplates()) {
                if (trim($body) === '') {
                    WhatsAppOutbox::markFailure(
                        (int) $row['id'],
                        'template_not_supported',
                        'Provider teks tidak memiliki isi pesan teks.'
                    );
                    $failed++;
                    continue;
                }
                $sendMode = 'text';
            }

            if ($sendMode === 'template') {
                if ($decoded['template_name'] === '') {
                    WhatsAppOutbox::markFailure((int) $row['id'], 'template_missing', 'Nama template belum diisi.');
                    $failed++;
                    continue;
                }
                $parameters = array_map(
                    static fn (mixed $value): string => (string) $value,
                    (array) ($payload['parameters'] ?? [])
                );
                $result = $adapter->sendTemplate(
                    $connection,
                    (string) $decoded['recipient'],
                    (string) $decoded['template_name'],
                    (string) $decoded['language_code'],
                    $parameters,
                    $timeout
                );
            } else {
                if ($body === '') {
                    WhatsAppOutbox::markFailure((int) $row['id'], 'empty_message', 'Isi pesan kosong.');
                    $failed++;
                    continue;
                }
                $result = $adapter->sendText(
                    $connection,
                    (string) $decoded['recipient'],
                    $body,
                    $timeout
                );
            }

            if (!empty($result['ok']) && (string) ($result['provider_message_id'] ?? '') !== '') {
                WhatsAppOutbox::markAccepted((int) $row['id'], (string) $result['provider_message_id']);
                $accepted++;
            } else {
                WhatsAppOutbox::markFailure(
                    (int) $row['id'],
                    (string) ($result['code'] ?? 'send_failed'),
                    (string) ($result['message'] ?? 'Pengiriman gagal.')
                );
                $failed++;
            }

            ApiUsageLog::record(
                (string) $decoded['feature_key'],
                (string) $connection['provider'],
                (int) $connection['id'],
                'message_send',
                !empty($result['ok']) ? 'accepted' : 'failed',
                !empty($result['ok']),
                (string) ($result['provider_message_id'] ?? ''),
                !empty($result['ok']) ? '' : (string) ($result['code'] ?? 'send_failed'),
                (int) ($result['duration_ms'] ?? 0)
            );
        }

        return [
            'processed' => count($rows),
            'accepted' => $accepted,
            'failed' => $failed,
            'error' => '',
        ];
    }

    public static function normalizeRecipient(string $value): ?string
    {
        return whatsapp_number($value);
    }

    /**
     * Provider teks-saja (Fonnte) tidak boleh dipaksa memakai template Meta.
     * Pemanggil tetap harus menyediakan body teks sebagai isi pesan.
     */
    public static function providerSupportsTemplates(string $provider): bool
    {
        $adapter = WhatsAppProviderRegistry::get($provider);

        return $adapter !== null && $adapter->supportsTemplates();
    }

    /**
     * @param array<string, mixed> $connection
     */
    public static function connectionSupportsTemplates(array $connection): bool
    {
        return self::providerSupportsTemplates((string) ($connection['provider'] ?? ''));
    }

    private static function assertGatewayReady(): void
    {
        if (!self::encryptionReady()) {
            throw new RuntimeException('encryption_not_ready');
        }
        if (!self::gatewayEnabled()) {
            throw new RuntimeException('gateway_disabled');
        }
    }

    private static function assertDailyLimit(string $feature): void
    {
        $gateway = ApiIntegrationSetting::get(ApiFeatureRegistry::WHATSAPP_GATEWAY)['settings'];
        $gatewayLimit = (int) ($gateway['daily_limit'] ?? 0);
        if ($gatewayLimit > 0 && WhatsAppOutbox::countToday() >= $gatewayLimit) {
            throw new RuntimeException('gateway_daily_limit');
        }

        $settings = ApiIntegrationSetting::get($feature)['settings'];
        $featureLimit = (int) ($settings['daily_limit'] ?? 0);
        if ($featureLimit > 0 && WhatsAppOutbox::countToday($feature) >= $featureLimit) {
            throw new RuntimeException('feature_daily_limit');
        }
    }

    /**
     * @return array{ok:bool,status:string,code:string,message:string,provider_message_id:string,duration_ms:int,outbox_id:int}
     */
    private static function failed(string $code, string $message): array
    {
        return [
            'ok' => false,
            'status' => 'failed',
            'code' => $code,
            'message' => $message,
            'provider_message_id' => '',
            'duration_ms' => 0,
            'outbox_id' => 0,
        ];
    }
}
