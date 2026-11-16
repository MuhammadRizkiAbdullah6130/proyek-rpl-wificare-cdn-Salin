-- =============================================================================
-- WiFiCare CDN — Tahap 11: Pengaturan AI Chatbot (ai_settings)
-- =============================================================================
-- Konfigurasi layanan AI yang dikelola Admin melalui dashboard (tanpa mengedit
-- berkas .env). Tabel ini bersifat TAMBAHAN; tabel `pengaturan` lama tidak diubah
-- dan data sebelumnya tidak dihapus.
--
-- PENTING (KEAMANAN):
--   * Kolom `api_key_encrypted` HANYA menyimpan CIPHERTEXT hasil enkripsi
--     libsodium (secretbox / XSalsa20-Poly1305). API key TIDAK PERNAH disimpan
--     dalam bentuk plaintext.
--   * Format ciphertext: "v1:" + base64( nonce(24 byte) || secretbox ).
--   * Master key enkripsi HANYA berada di environment server (AI_SETTINGS_KEY),
--     TIDAK disimpan di database dan TIDAK masuk repositori.
-- =============================================================================

CREATE TABLE IF NOT EXISTS ai_settings (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider           VARCHAR(32)     NOT NULL DEFAULT 'gemini',
    api_key_encrypted  TEXT            NULL DEFAULT NULL,
    model              VARCHAR(120)    NULL DEFAULT NULL,
    is_enabled         TINYINT(1)      NOT NULL DEFAULT 0,
    max_output_tokens  INT UNSIGNED    NOT NULL DEFAULT 1500,
    timeout_seconds    INT UNSIGNED    NOT NULL DEFAULT 60,
    rate_limit         INT UNSIGNED    NOT NULL DEFAULT 20,
    rate_window        INT UNSIGNED    NOT NULL DEFAULT 60,
    max_message_length INT UNSIGNED    NOT NULL DEFAULT 2000,
    system_instruction TEXT            NULL DEFAULT NULL,
    updated_by         BIGINT UNSIGNED NULL DEFAULT NULL,
    created_at         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ai_settings_updated_by (updated_by),
    CONSTRAINT fk_ai_settings_updated_by FOREIGN KEY (updated_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
