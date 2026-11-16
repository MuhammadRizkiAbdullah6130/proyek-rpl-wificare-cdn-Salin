<?php
/**
 * Model pengaturan aplikasi (key/value).
 *
 * Hanya menyimpan konfigurasi NONRAHASIA (nama aplikasi, kontak, batas upload).
 * Kredensial/API key TIDAK boleh disimpan di sini.
 */

declare(strict_types=1);

final class Pengaturan
{
    /**
     * Seluruh pengaturan sebagai map key => value.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        $rows = Database::pdo()->query('SELECT `key`, `value` FROM pengaturan')->fetchAll();

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['key']] = (string) ($row['value'] ?? '');
        }

        return $map;
    }

    /**
     * Nilai satu pengaturan.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        $statement = Database::pdo()->prepare('SELECT `value` FROM pengaturan WHERE `key` = :key LIMIT 1');
        $statement->execute(['key' => $key]);
        $value = $statement->fetchColumn();

        return $value === false ? $default : (string) $value;
    }

    /**
     * Simpan banyak pengaturan sekaligus (upsert).
     *
     * @param array<string, string> $values
     */
    public static function setMany(array $values): void
    {
        $statement = Database::pdo()->prepare(
            'INSERT INTO pengaturan (`key`, `value`) VALUES (:key, :value)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
        );

        foreach ($values as $key => $value) {
            $statement->execute(['key' => $key, 'value' => $value]);
        }
    }
}
