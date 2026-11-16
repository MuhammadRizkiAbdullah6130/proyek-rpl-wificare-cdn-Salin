<?php
/**
 * Model kunci & konfigurasi per provider AI (`ai_provider_keys`).
 *
 * Nilai `api_key_encrypted` selalu ciphertext libsodium. Model ini tidak pernah
 * mengembalikan plaintext ke tampilan; dekripsi hanya dilakukan di service
 * runtime yang membutuhkan kredensial untuk memanggil provider.
 */

declare(strict_types=1);

final class AiProviderKey
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
            $statement = Database::pdo()->query('SELECT * FROM ai_provider_keys');
            foreach ($statement->fetchAll() as $row) {
                if (is_array($row) && isset($row['provider'])) {
                    self::$rows[(string) $row['provider']] = $row;
                }
            }
        } catch (Throwable $exception) {
            // Migrasi belum dijalankan → anggap belum ada konfigurasi provider.
            app_log('Gagal membaca ai_provider_keys: ' . $exception->getMessage());
        }

        return self::$rows;
    }

    /** @return array<string, mixed>|null */
    public static function get(string $provider): ?array
    {
        return self::all()[$provider] ?? null;
    }

    public static function forget(): void
    {
        self::$rows = null;
    }

    /**
     * Simpan key/model/base satu provider. Key lama dipertahankan bila $encrypted
     * bernilai null (tidak ada key baru). Ciphertext TIDAK pernah di-log.
     */
    public static function save(string $provider, ?string $encrypted, string $model, string $base, ?int $actorId): void
    {
        $sql = 'INSERT INTO ai_provider_keys (provider, api_key_encrypted, model, base_url, updated_by)
                VALUES (:provider, :api_key_encrypted, :model, :base_url, :updated_by)
                ON DUPLICATE KEY UPDATE
                    api_key_encrypted = COALESCE(VALUES(api_key_encrypted), api_key_encrypted),
                    model = VALUES(model),
                    base_url = VALUES(base_url),
                    updated_by = VALUES(updated_by)';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute([
            'provider' => $provider,
            'api_key_encrypted' => $encrypted,
            'model' => $model,
            'base_url' => $base,
            'updated_by' => $actorId,
        ]);

        self::forget();
    }
}
