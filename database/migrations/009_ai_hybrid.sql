-- WiFiCare CDN — Tahap 18: Hybrid AI, Local Database Assistant, Ollama.
--
-- Migrasi ini bersifat ADITIF:
-- - menambah pengaturan hybrid/fallback pada ai_settings;
-- - menambah log akses data AI yang tidak menyimpan isi percakapan/filter mentah;
-- - TIDAK menghapus atau mengubah data lama.
--
-- Konfigurasi model/base Ollama disimpan pada ai_provider_keys (tanpa secret).

ALTER TABLE ai_settings
    ADD COLUMN IF NOT EXISTS hybrid_mode TINYINT(1) NOT NULL DEFAULT 1 AFTER provider,
    ADD COLUMN IF NOT EXISTS local_db_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER hybrid_mode,
    ADD COLUMN IF NOT EXISTS prefer_local_db TINYINT(1) NOT NULL DEFAULT 1 AFTER local_db_enabled,
    ADD COLUMN IF NOT EXISTS fallback_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER prefer_local_db,
    ADD COLUMN IF NOT EXISTS ollama_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER fallback_enabled;

CREATE TABLE IF NOT EXISTS ai_data_access_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NULL,
    role VARCHAR(20) NULL,
    source VARCHAR(20) NOT NULL DEFAULT 'local',
    intent VARCHAR(80) NOT NULL,
    dataset VARCHAR(80) NOT NULL,
    result_count INT UNSIGNED NOT NULL DEFAULT 0,
    success TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ai_access_created (created_at),
    KEY idx_ai_access_user_created (user_id, created_at),
    KEY idx_ai_access_intent (intent),
    CONSTRAINT fk_ai_access_user FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
