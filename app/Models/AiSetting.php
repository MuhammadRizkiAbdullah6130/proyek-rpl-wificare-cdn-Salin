<?php
/**
 * Model pengaturan AI (tabel `ai_settings`) — WiFiCare CDN Tahap 11.
 *
 * Tabel ini menyimpan SATU baris konfigurasi (id = 1). Nilai API key pada kolom
 * `api_key_encrypted` selalu berupa ciphertext hasil enkripsi (lihat Crypto);
 * model ini tidak pernah menangani plaintext API key kepada tampilan.
 */

declare(strict_types=1);

final class AiSetting
{
    private static bool $loaded = false;
    /** @var array<string, mixed>|null */
    private static ?array $row = null;

    /**
     * Baris konfigurasi aktif (id = 1), atau null bila belum ada.
     *
     * @return array<string, mixed>|null
     */
    public static function get(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            try {
                $statement = Database::pdo()->query('SELECT * FROM ai_settings ORDER BY id ASC LIMIT 1');
                $row = $statement->fetch();
                self::$row = is_array($row) ? $row : null;
            } catch (Throwable $exception) {
                // Tabel belum dimigrasi / DB bermasalah → anggap belum dikonfigurasi
                // dan jangan menampilkan detail teknis kepada pengguna.
                app_log('Gagal membaca konfigurasi AI: ' . $exception->getMessage());
                self::$row = null;
            }
        }

        return self::$row;
    }

    /**
     * Bersihkan cache dalam-request (dipanggil setelah menyimpan).
     */
    public static function forget(): void
    {
        self::$loaded = false;
        self::$row = null;
    }

    /**
     * Simpan/perbarui baris konfigurasi tunggal (id = 1).
     *
     * Kolom yang diberikan akan ditulis seluruhnya; pemanggil bertanggung jawab
     * mempertahankan nilai lama (mis. api_key_encrypted) bila tidak diubah.
     *
     * @param array<string, mixed> $fields
     */
    public static function save(array $fields): void
    {
        $fields = array_merge(self::defaults(), $fields);

        $sql = 'INSERT INTO ai_settings
                    (id, provider, hybrid_mode, local_db_enabled, prefer_local_db,
                     fallback_enabled, ollama_enabled, api_key_encrypted, model, is_enabled,
                     max_output_tokens, timeout_seconds, rate_limit, rate_window, daily_limit,
                     max_message_length, system_instruction, updated_by)
                VALUES
                    (1, :provider, :hybrid_mode, :local_db_enabled, :prefer_local_db,
                     :fallback_enabled, :ollama_enabled, :api_key_encrypted, :model, :is_enabled,
                     :max_output_tokens, :timeout_seconds, :rate_limit, :rate_window, :daily_limit,
                     :max_message_length, :system_instruction, :updated_by)
                ON DUPLICATE KEY UPDATE
                    provider = VALUES(provider),
                    hybrid_mode = VALUES(hybrid_mode),
                    local_db_enabled = VALUES(local_db_enabled),
                    prefer_local_db = VALUES(prefer_local_db),
                    fallback_enabled = VALUES(fallback_enabled),
                    ollama_enabled = VALUES(ollama_enabled),
                    api_key_encrypted = VALUES(api_key_encrypted),
                    model = VALUES(model),
                    is_enabled = VALUES(is_enabled),
                    max_output_tokens = VALUES(max_output_tokens),
                    timeout_seconds = VALUES(timeout_seconds),
                    rate_limit = VALUES(rate_limit),
                    rate_window = VALUES(rate_window),
                    daily_limit = VALUES(daily_limit),
                    max_message_length = VALUES(max_message_length),
                    system_instruction = VALUES(system_instruction),
                    updated_by = VALUES(updated_by)';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute([
            'provider' => (string) $fields['provider'],
            'hybrid_mode' => (int) ($fields['hybrid_mode'] ?? 1),
            'local_db_enabled' => (int) ($fields['local_db_enabled'] ?? 1),
            'prefer_local_db' => (int) ($fields['prefer_local_db'] ?? 1),
            'fallback_enabled' => (int) ($fields['fallback_enabled'] ?? 1),
            'ollama_enabled' => (int) ($fields['ollama_enabled'] ?? 0),
            'api_key_encrypted' => $fields['api_key_encrypted'],
            'model' => (string) $fields['model'],
            'is_enabled' => (int) $fields['is_enabled'],
            'max_output_tokens' => (int) $fields['max_output_tokens'],
            'timeout_seconds' => (int) $fields['timeout_seconds'],
            'rate_limit' => (int) $fields['rate_limit'],
            'rate_window' => (int) $fields['rate_window'],
            'daily_limit' => (int) ($fields['daily_limit'] ?? 100),
            'max_message_length' => (int) $fields['max_message_length'],
            'system_instruction' => (string) $fields['system_instruction'],
            'updated_by' => $fields['updated_by'] !== null ? (int) $fields['updated_by'] : null,
        ]);

        self::forget();
    }

    /**
     * Nilai bawaan kolom (juga dipakai saat menyusun field yang belum diisi).
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'provider' => 'gemini',
            'hybrid_mode' => 1,
            'local_db_enabled' => 1,
            'prefer_local_db' => 1,
            'fallback_enabled' => 1,
            'ollama_enabled' => 0,
            'api_key_encrypted' => null,
            'model' => 'gemini-2.5-flash',
            'is_enabled' => 0,
            'max_output_tokens' => 1500,
            'timeout_seconds' => 60,
            'rate_limit' => 20,
            'rate_window' => 60,
            'daily_limit' => 100,
            'max_message_length' => 2000,
            'system_instruction' => '',
            'updated_by' => null,
        ];
    }
}
