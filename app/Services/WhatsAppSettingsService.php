<?php
/**
 * Validasi & penyimpanan konfigurasi WhatsApp per fitur.
 *
 * Tidak ada secret pada settings_json. Token/webhook secret tetap di
 * `api_credentials` (ciphertext).
 */

declare(strict_types=1);

final class WhatsAppSettingsService
{
    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input
     */
    public static function saveGateway(array $actor, array $input): void
    {
        $feature = ApiFeatureRegistry::WHATSAPP_GATEWAY;
        $enabled = (string) ($input['is_enabled'] ?? '') === '1';
        $connectionId = self::connectionId($input, true);
        $settings = [
            'default_country_code' => trim((string) ($input['default_country_code'] ?? '62')),
            'daily_limit' => self::intRange($input['daily_limit'] ?? 0, 0, 10000),
            'retry_max' => self::intRange($input['retry_max'] ?? 3, 1, 10),
            'timeout' => self::intRange($input['timeout'] ?? 20, 5, 60),
        ];

        $errors = [];
        if (!preg_match('/^[0-9]{1,4}$/', (string) $settings['default_country_code'])) {
            $errors['default_country_code'] = 'Kode negara harus 1–4 digit.';
        }
        if ($enabled && self::selectedConnection($connectionId) === null) {
            $errors['is_enabled'] = 'Tambahkan dan aktifkan kredensial WhatsApp sebelum mengaktifkan gateway.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        ApiIntegrationSetting::save($feature, (int) ($actor['id'] ?? 0), $enabled, $connectionId, $settings);
        self::audit($actor, $feature, 'pengaturan_gateway_disimpan', [
            'is_enabled',
            'connection_id',
            'daily_limit',
            'retry_max',
            'timeout',
        ]);
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input
     */
    public static function saveOtp(array $actor, array $input): void
    {
        $feature = ApiFeatureRegistry::WHATSAPP_OTP;
        $enabled = (string) ($input['is_enabled'] ?? '') === '1';
        $connectionId = self::connectionId($input, true);
        $purposes = [];
        foreach (array_keys(ApiFeatureRegistry::purposes()) as $purpose) {
            $purposes[$purpose] = !empty($input['purposes'][$purpose]);
        }

        $existing = ApiIntegrationSetting::get($feature)['settings'];
        $legacyProvided = array_key_exists('template', $input);
        $legacyInput = trim((string) ($input['template'] ?? ''));
        $legacyTemplate = $legacyProvided
            ? $legacyInput
            : trim((string) ($existing['template'] ?? ''));
        $purposeTemplates = [];
        $purposeTemplateInput = false;
        foreach (ApiFeatureRegistry::otpTemplates() as $meta) {
            if (array_key_exists((string) $meta['setting'], $input)) {
                $purposeTemplateInput = true;
                break;
            }
        }
        foreach (ApiFeatureRegistry::otpTemplates() as $meta) {
            $key = (string) $meta['setting'];
            if (array_key_exists($key, $input)) {
                $value = trim((string) $input[$key]);
            } elseif ($purposeTemplateInput) {
                $value = $legacyInput !== ''
                    ? $legacyInput
                    : trim((string) ($existing[$key] ?? $meta['default']));
            } elseif ($legacyProvided) {
                $value = $legacyInput;
            } else {
                $value = trim((string) ($existing[$key] ?? $meta['default']));
            }
            $purposeTemplates[$key] = $value;
        }

        $settings = array_merge($purposeTemplates, [
            'template' => $legacyTemplate,
            'ttl_seconds' => self::intRange($input['ttl_seconds'] ?? 300, 60, 1800),
            'max_attempts' => self::intRange($input['max_attempts'] ?? 5, 1, 10),
            'resend_cooldown' => self::intRange($input['resend_cooldown'] ?? 60, 0, 3600),
            'daily_user_limit' => self::intRange($input['daily_user_limit'] ?? 5, 1, 100),
            'daily_phone_limit' => self::intRange($input['daily_phone_limit'] ?? 5, 1, 100),
            'send_mode' => (string) ($input['send_mode'] ?? 'text') === 'template' ? 'template' : 'text',
            'template_name' => trim((string) ($input['template_name'] ?? '')),
            'language_code' => trim((string) ($input['language_code'] ?? 'id')) ?: 'id',
            'purposes' => $purposes,
        ]);

        $errors = [];
        $selected = self::selectedConnection($connectionId);
        $provider = strtolower(trim((string) ($selected['provider'] ?? '')));
        $supportsTemplates = $provider !== '' && ApiCredentialService::providerSupportsTemplates($provider);

        foreach (ApiFeatureRegistry::otpTemplates() as $meta) {
            $key = (string) $meta['setting'];
            $template = (string) ($purposeTemplates[$key] ?? '');
            if (mb_strlen($template) < 20) {
                $errors[$key] = $meta['label'] . ' minimal 20 karakter.';
            } elseif (!str_contains($template, '{kode}')) {
                $errors[$key] = $meta['label'] . ' wajib memuat placeholder {kode}.';
            }
        }
        if (!in_array(true, $purposes, true)) {
            $errors['purposes'] = 'Pilih minimal satu jenis penggunaan OTP.';
        }
        // Nama template hanya wajib untuk provider yang benar-benar memakai
        // template Meta. Fonnte selalu mengirim teks dan tidak memerlukannya.
        if ($settings['send_mode'] === 'template'
            && $supportsTemplates
            && $settings['template_name'] === ''
        ) {
            $errors['template_name'] = 'Nama template wajib diisi untuk mode template Meta.';
        }
        if ($enabled) {
            if (!ApiIntegrationSetting::isEnabled(ApiFeatureRegistry::WHATSAPP_GATEWAY)) {
                $errors['is_enabled'] = 'Aktifkan WhatsApp Gateway terlebih dahulu.';
            } elseif ($selected === null) {
                $errors['is_enabled'] = 'Koneksi WhatsApp belum tersedia.';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        ApiIntegrationSetting::save($feature, (int) ($actor['id'] ?? 0), $enabled, $connectionId, $settings);
        self::audit($actor, $feature, 'pengaturan_otp_disimpan', [
            'is_enabled',
            'connection_id',
            'ttl_seconds',
            'max_attempts',
            'resend_cooldown',
            'daily_user_limit',
            'daily_phone_limit',
            'purposes',
            'send_mode',
            'template_phone_verification',
            'template_reset_password',
            'template_phone_change',
        ]);
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input
     */
    public static function saveNotifications(array $actor, array $input): void
    {
        $feature = ApiFeatureRegistry::WHATSAPP_NOTIFICATION;
        $enabled = (string) ($input['is_enabled'] ?? '') === '1';
        $connectionId = self::connectionId($input, true);
        $adminNumberRaw = trim((string) ($input['admin_number'] ?? ''));
        $adminNumber = $adminNumberRaw === '' ? '' : (string) (whatsapp_number($adminNumberRaw) ?? '');
        $eventsInput = is_array($input['events'] ?? null) ? $input['events'] : [];
        $events = [];
        $errors = [];

        if ($adminNumberRaw !== '' && $adminNumber === '') {
            $errors['admin_number'] = 'Nomor operasional Admin tidak valid.';
        }

        foreach (ApiFeatureRegistry::notificationEvents() as $key => $meta) {
            $raw = is_array($eventsInput[$key] ?? null) ? $eventsInput[$key] : [];
            $recipient = (string) ($raw['recipient'] ?? $meta['recipient']);
            if (!isset(ApiFeatureRegistry::recipientOptions()[$recipient])) {
                $recipient = (string) $meta['recipient'];
            }
            $template = trim((string) ($raw['template'] ?? $meta['template']));
            $sendMode = (string) ($raw['send_mode'] ?? 'text') === 'template' ? 'template' : 'text';
            $templateName = trim((string) ($raw['template_name'] ?? ''));
            $customNumberRaw = trim((string) ($raw['custom_number'] ?? ''));
            $customNumber = $customNumberRaw === '' ? '' : (string) (whatsapp_number($customNumberRaw) ?? '');
            $eventEnabled = !empty($raw['enabled']);

            if (mb_strlen($template) < 10) {
                $errors['events_' . $key] = 'Template ' . $meta['label'] . ' minimal 10 karakter.';
            }
            if ($customNumberRaw !== '' && $customNumber === '') {
                $errors['events_' . $key] = 'Nomor tertentu untuk ' . $meta['label'] . ' tidak valid.';
            }
            if ($sendMode === 'template' && $templateName === '') {
                $errors['events_' . $key] = 'Nama template ' . $meta['label'] . ' wajib diisi.';
            }
            if ($eventEnabled && $recipient === 'admin' && $adminNumber === '') {
                $errors['events_' . $key] = 'Isi nomor operasional Admin untuk event ' . $meta['label'] . '.';
            }
            if ($eventEnabled && $recipient === 'custom' && $customNumber === '') {
                $errors['events_' . $key] = 'Isi nomor tertentu untuk event ' . $meta['label'] . '.';
            }

            $events[$key] = [
                'enabled' => $eventEnabled,
                'recipient' => $recipient,
                'custom_number' => $customNumber,
                'template' => $template,
                'send_mode' => $sendMode,
                'template_name' => $templateName,
                'language_code' => trim((string) ($raw['language_code'] ?? 'id')) ?: 'id',
            ];
        }

        if ($enabled) {
            if (!ApiIntegrationSetting::isEnabled(ApiFeatureRegistry::WHATSAPP_GATEWAY)) {
                $errors['is_enabled'] = 'Aktifkan WhatsApp Gateway terlebih dahulu.';
            } elseif (self::selectedConnection($connectionId) === null) {
                $errors['is_enabled'] = 'Koneksi WhatsApp belum tersedia.';
            }
            if (!in_array(true, array_column($events, 'enabled'), true)) {
                $errors['is_enabled'] = 'Aktifkan minimal satu event sebelum mengaktifkan fitur.';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        ApiIntegrationSetting::save(
            $feature,
            (int) ($actor['id'] ?? 0),
            $enabled,
            $connectionId,
            [
                'admin_number' => $adminNumber,
                'daily_limit' => self::intRange($input['daily_limit'] ?? 0, 0, 10000),
                'retry_max' => self::intRange($input['retry_max'] ?? 3, 1, 10),
                'events' => $events,
            ]
        );

        $enabledEvents = array_keys(array_filter($events, static fn (array $e): bool => !empty($e['enabled'])));
        self::audit($actor, $feature, 'pengaturan_notifikasi_disimpan', array_merge(
            ['is_enabled', 'connection_id', 'admin_number', 'daily_limit', 'retry_max'],
            $enabledEvents
        ));
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input
     */
    public static function saveReport(array $actor, array $input): void
    {
        $feature = ApiFeatureRegistry::WHATSAPP_REPORT;
        $connectionId = self::connectionId($input, true);
        $recipients = self::recipients((string) ($input['recipients'] ?? ''));
        // Jenis laporan bersifat tetap: ketiganya selalu tersedia pada form
        // pengiriman manual. Rating layanan tidak lagi menjadi opsi laporan.
        $types = array_keys(self::reportTypes());
        $errors = [];

        if ($recipients === []) {
            $errors['recipients'] = 'Isi minimal satu penerima laporan yang berwenang.';
        }

        $activeConnection = self::selectedConnection($connectionId);
        if ($connectionId !== null && ($activeConnection === null || empty($activeConnection['is_active']))) {
            $errors['connection_id'] = 'Pilih koneksi WhatsApp yang aktif.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        // Tidak ada tombol "aktifkan" pada halaman manual. Status fitur
        // diturunkan dari gateway + koneksi aktif + daftar penerima. Pengiriman
        // manual tetap memvalidasi koneksi pada setiap aksi.
        $enabled = ApiIntegrationSetting::isEnabled(ApiFeatureRegistry::WHATSAPP_GATEWAY)
            && $activeConnection !== null
            && !empty($activeConnection['is_active'])
            && $recipients !== [];

        $settings = [
            'recipients' => $recipients,
            'report_types' => $types,
            'format' => 'text',
            'daily_limit' => self::intRange($input['daily_limit'] ?? 0, 0, 100),
        ];

        ApiIntegrationSetting::save($feature, (int) ($actor['id'] ?? 0), $enabled, $connectionId, $settings);
        self::audit($actor, $feature, 'pengaturan_laporan_disimpan', [
            'is_enabled',
            'connection_id',
            'recipients',
            'report_types',
            'format',
            'daily_limit',
        ]);
    }

    /** @return array<string, string> */
    public static function reportTypes(): array
    {
        return [
            'ringkasan_pengaduan' => 'Ringkasan Pengaduan',
            'kinerja_teknisi' => 'Kinerja Teknisi',
            'rekap_bulanan' => 'Rekap Bulanan',
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function connectionId(array $input, bool $allowEmpty): ?int
    {
        $id = (int) ($input['connection_id'] ?? 0);
        if ($id <= 0) {
            if (!$allowEmpty) {
                throw new ValidationException(['connection_id' => 'Koneksi WhatsApp wajib dipilih.']);
            }

            return null;
        }

        $row = ApiCredential::find($id);
        if ($row === null || ($row['revoked_at'] ?? null) !== null) {
            throw new ValidationException(['connection_id' => 'Koneksi WhatsApp tidak ditemukan atau sudah dicabut.']);
        }

        return $id;
    }

    /** @return array<string, mixed>|null */
    private static function selectedConnection(?int $connectionId): ?array
    {
        if ($connectionId !== null && $connectionId > 0) {
            return ApiCredentialService::connection($connectionId);
        }

        $active = ApiCredential::active(ApiCredentialService::INTEGRATION_WHATSAPP);

        return $active === null ? null : ApiCredentialService::connection((int) $active['id']);
    }

    /**
     * @return array<int, array{name:string,number:string}>
     */
    private static function recipients(string $text): array
    {
        $result = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line, 2));
            $name = count($parts) > 1 ? $parts[0] : '';
            $numberRaw = count($parts) > 1 ? $parts[1] : $parts[0];
            $number = whatsapp_number($numberRaw);
            if ($number === null) {
                throw new ValidationException(['recipients' => 'Nomor penerima tidak valid: ' . mb_substr($numberRaw, 0, 30)]);
            }
            $result[] = [
                'name' => mb_substr($name !== '' ? $name : 'Penerima', 0, 80),
                'number' => $number,
            ];
            if (count($result) > 20) {
                throw new ValidationException(['recipients' => 'Maksimal 20 penerima laporan.']);
            }
        }

        return $result;
    }

    private static function intRange(mixed $value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<int, string> $fields
     */
    private static function audit(array $actor, string $feature, string $action, array $fields): void
    {
        ApiConfigAudit::record((int) ($actor['id'] ?? 0), $feature, $action, $fields);
        AuditLog::record(
            (int) ($actor['id'] ?? 0),
            'api_' . $action,
            'api_integration_settings',
            null,
            ['feature' => $feature, 'fields' => array_values(array_unique($fields))]
        );
    }
}
