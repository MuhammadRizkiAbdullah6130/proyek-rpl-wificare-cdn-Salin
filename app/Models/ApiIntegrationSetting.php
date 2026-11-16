<?php
/**
 * Model pengaturan per fitur integrasi (`api_integration_settings`).
 *
 * Nilai `settings_json` hanya berisi konfigurasi NONRAHASIA. Secret provider
 * berada di `api_credentials` dalam bentuk ciphertext.
 */

declare(strict_types=1);

final class ApiIntegrationSetting
{
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $rows = null;

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        if (self::$rows !== null) {
            return self::$rows;
        }

        self::$rows = [];
        try {
            $statement = Database::pdo()->query('SELECT * FROM api_integration_settings');
            foreach ($statement->fetchAll() as $row) {
                if (is_array($row) && isset($row['feature_key'])) {
                    self::$rows[(string) $row['feature_key']] = $row;
                }
            }
        } catch (Throwable $exception) {
            app_log('Tabel api_integration_settings belum tersedia.');
        }

        return self::$rows;
    }

    public static function resetCache(): void
    {
        self::$rows = null;
    }

    /** @return array<string, mixed> */
    public static function get(string $feature): array
    {
        $defaults = ApiFeatureRegistry::defaults($feature);
        $row = self::all()[$feature] ?? null;

        if ($row === null) {
            return [
                'feature_key' => $feature,
                'connection_id' => null,
                'is_enabled' => 0,
                'settings' => $defaults,
                'updated_by' => null,
                'updated_at' => null,
            ];
        }

        $decoded = [];
        if (!empty($row['settings_json'])) {
            $parsed = json_decode((string) $row['settings_json'], true);
            if (is_array($parsed)) {
                $decoded = $parsed;
            }
        }

        $settings = array_replace_recursive($defaults, $decoded);

        // Kompatibilitas konfigurasi lama: template tunggal `template` dipakai
        // untuk purpose yang belum memiliki template khusus. Data lama tidak
        // dihapus dan template purpose yang sudah tersimpan tetap menang.
        if ($feature === ApiFeatureRegistry::WHATSAPP_OTP) {
            $legacyTemplate = trim((string) ($decoded['template'] ?? ''));
            if ($legacyTemplate !== '') {
                foreach (ApiFeatureRegistry::otpTemplateDefaults() as $settingKey => $unused) {
                    if (!array_key_exists($settingKey, $decoded)) {
                        $settings[$settingKey] = $legacyTemplate;
                    }
                }
            }
        }

        return [
            'feature_key' => $feature,
            'connection_id' => $row['connection_id'] === null ? null : (int) $row['connection_id'],
            'is_enabled' => (int) ($row['is_enabled'] ?? 0) === 1 ? 1 : 0,
            'settings' => $settings,
            'updated_by' => $row['updated_by'] === null ? null : (int) $row['updated_by'],
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    public static function isEnabled(string $feature): bool
    {
        return (int) self::get($feature)['is_enabled'] === 1;
    }

    public static function connectionId(string $feature): ?int
    {
        $value = self::get($feature)['connection_id'];

        return $value === null || (int) $value <= 0 ? null : (int) $value;
    }

    /**
     * Simpan status & konfigurasi satu fitur.
     *
     * @param array<string, mixed> $settings
     */
    public static function save(
        string $feature,
        int $actorId,
        bool $enabled,
        ?int $connectionId,
        array $settings
    ): void {
        $statement = Database::pdo()->prepare(
            'INSERT INTO api_integration_settings
                (feature_key, connection_id, is_enabled, settings_json, updated_by)
             VALUES (:feature_key, :connection_id, :is_enabled, :settings_json, :updated_by)
             ON DUPLICATE KEY UPDATE
                connection_id = VALUES(connection_id),
                is_enabled = VALUES(is_enabled),
                settings_json = VALUES(settings_json),
                updated_by = VALUES(updated_by)'
        );
        $statement->execute([
            'feature_key' => $feature,
            'connection_id' => $connectionId !== null && $connectionId > 0 ? $connectionId : null,
            'is_enabled' => $enabled ? 1 : 0,
            'settings_json' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_by' => $actorId > 0 ? $actorId : null,
        ]);

        self::resetCache();
    }
}
