<?php
/**
 * Model kredensial API (`api_credentials`).
 *
 * Kolom secret SELALU ciphertext libsodium. Metode `safe()` tidak pernah
 * mengembalikan ciphertext/plaintext ke tampilan. Dekripsi hanya dilakukan
 * oleh service internal saat memanggil provider.
 */

declare(strict_types=1);

final class ApiCredential
{
    /** @var array<int, array<string, mixed>>|null */
    private static ?array $rows = null;

    /** @return array<int, array<string, mixed>> */
    public static function all(string $integration = 'whatsapp'): array
    {
        if (self::$rows === null) {
            self::$rows = [];
            try {
                $statement = Database::pdo()->query('SELECT * FROM api_credentials ORDER BY id DESC');
                foreach ($statement->fetchAll() as $row) {
                    if (is_array($row) && isset($row['id'])) {
                        self::$rows[(int) $row['id']] = $row;
                    }
                }
            } catch (Throwable $exception) {
                app_log('Tabel api_credentials belum tersedia.');
            }
        }

        $result = [];
        foreach (self::$rows as $row) {
            if ((string) ($row['integration'] ?? '') !== $integration) {
                continue;
            }
            $result[] = $row;
        }

        return $result;
    }

    public static function resetCache(): void
    {
        self::$rows = null;
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        foreach (self::all() as $row) {
            if ((int) $row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public static function active(string $integration = 'whatsapp'): ?array
    {
        foreach (self::all($integration) as $row) {
            if ((int) ($row['is_active'] ?? 0) === 1 && ($row['revoked_at'] ?? null) === null) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Data aman untuk tampilan (tanpa ciphertext/plaintext).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function safe(array $row): array
    {
        $config = self::config($row);

        return [
            'id' => (int) ($row['id'] ?? 0),
            'integration' => (string) ($row['integration'] ?? ''),
            'provider' => (string) ($row['provider'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'business_account_id' => (string) ($config['business_account_id'] ?? ''),
            'phone_number_id' => (string) ($config['phone_number_id'] ?? ''),
            'sender_number' => (string) ($config['sender_number'] ?? ''),
            'base_url' => (string) ($config['base_url'] ?? ''),
            'has_secret' => trim((string) ($row['secret_encrypted'] ?? '')) !== '',
            'has_webhook_verify_token' => trim((string) ($row['webhook_verify_token_encrypted'] ?? '')) !== '',
            'has_webhook_secret' => trim((string) ($row['webhook_secret_encrypted'] ?? '')) !== '',
            'is_active' => (int) ($row['is_active'] ?? 0) === 1,
            'is_revoked' => ($row['revoked_at'] ?? null) !== null,
            'last_tested_at' => $row['last_tested_at'] ?? null,
            'last_test_status' => (string) ($row['last_test_status'] ?? ''),
            'last_test_message' => (string) ($row['last_test_message'] ?? ''),
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public static function config(array $row): array
    {
        $config = [];
        if (!empty($row['config_json'])) {
            $decoded = json_decode((string) $row['config_json'], true);
            if (is_array($decoded)) {
                $config = $decoded;
            }
        }

        return $config;
    }

    /**
     * Simpan kredensial baru atau perbarui yang ada.
     *
     * Secret null berarti "pertahankan nilai lama" (input kosong saat edit).
     *
     * @param array<string, mixed> $data
     */
    public static function save(array $data): int
    {
        $id = (int) ($data['id'] ?? 0);
        $config = [
            'business_account_id' => (string) ($data['business_account_id'] ?? ''),
            'phone_number_id' => (string) ($data['phone_number_id'] ?? ''),
            'sender_number' => (string) ($data['sender_number'] ?? ''),
            'base_url' => (string) ($data['base_url'] ?? ''),
        ];

        $secret = $data['secret_encrypted'] ?? null;
        $verify = $data['webhook_verify_token_encrypted'] ?? null;
        $webhook = $data['webhook_secret_encrypted'] ?? null;

        if ($id > 0) {
            $statement = Database::pdo()->prepare(
                'UPDATE api_credentials SET
                    provider = :provider,
                    name = :name,
                    config_json = :config_json,
                    secret_encrypted = CASE WHEN :has_secret = 1 THEN :secret_encrypted ELSE secret_encrypted END,
                    webhook_verify_token_encrypted = CASE WHEN :has_verify = 1 THEN :verify_encrypted ELSE webhook_verify_token_encrypted END,
                    webhook_secret_encrypted = CASE WHEN :has_webhook = 1 THEN :webhook_encrypted ELSE webhook_secret_encrypted END,
                    updated_by = :updated_by
                 WHERE id = :id AND revoked_at IS NULL'
            );
            $statement->execute([
                'provider' => (string) ($data['provider'] ?? 'meta_cloud'),
                'name' => (string) ($data['name'] ?? ''),
                'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'has_secret' => $secret !== null && $secret !== '' ? 1 : 0,
                'secret_encrypted' => $secret,
                'has_verify' => $verify !== null && $verify !== '' ? 1 : 0,
                'verify_encrypted' => $verify,
                'has_webhook' => $webhook !== null && $webhook !== '' ? 1 : 0,
                'webhook_encrypted' => $webhook,
                'updated_by' => (int) ($data['actor_id'] ?? 0) ?: null,
                'id' => $id,
            ]);
            self::resetCache();

            return $id;
        }

        $statement = Database::pdo()->prepare(
            'INSERT INTO api_credentials
                (integration, provider, name, config_json, secret_encrypted,
                 webhook_verify_token_encrypted, webhook_secret_encrypted,
                 is_active, created_by, updated_by)
             VALUES
                (:integration, :provider, :name, :config_json, :secret_encrypted,
                 :verify_encrypted, :webhook_encrypted, 0, :created_by, :updated_by)'
        );
        $statement->execute([
            'integration' => (string) ($data['integration'] ?? 'whatsapp'),
            'provider' => (string) ($data['provider'] ?? 'meta_cloud'),
            'name' => (string) ($data['name'] ?? ''),
            'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'secret_encrypted' => $secret,
            'verify_encrypted' => $verify,
            'webhook_encrypted' => $webhook,
            'created_by' => (int) ($data['actor_id'] ?? 0) ?: null,
            'updated_by' => (int) ($data['actor_id'] ?? 0) ?: null,
        ]);

        self::resetCache();

        return (int) Database::pdo()->lastInsertId();
    }

    public static function setActive(int $id): bool
    {
        $row = self::find($id);
        if ($row === null || ($row['revoked_at'] ?? null) !== null) {
            return false;
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->exec("UPDATE api_credentials SET is_active = 0 WHERE integration = 'whatsapp'");
            $statement = $pdo->prepare('UPDATE api_credentials SET is_active = 1 WHERE id = :id AND revoked_at IS NULL');
            $statement->execute(['id' => $id]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        self::resetCache();

        return true;
    }

    /**
     * Cabut kredensial dengan aman: nonaktifkan, hapus secret, simpan jejak.
     */
    public static function revoke(int $id): bool
    {
        $row = self::find($id);
        if ($row === null || ($row['revoked_at'] ?? null) !== null) {
            return false;
        }

        $statement = Database::pdo()->prepare(
            'UPDATE api_credentials SET
                is_active = 0,
                secret_encrypted = NULL,
                webhook_verify_token_encrypted = NULL,
                webhook_secret_encrypted = NULL,
                revoked_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
        self::resetCache();

        return true;
    }

    public static function markTest(int $id, bool $ok, string $status, string $message): void
    {
        $statement = Database::pdo()->prepare(
            'UPDATE api_credentials SET
                last_tested_at = CURRENT_TIMESTAMP,
                last_test_status = :status,
                last_test_message = :message
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $id,
            'status' => mb_substr($status, 0, 20),
            'message' => mb_substr($message, 0, 255),
        ]);
        self::resetCache();
    }
}
