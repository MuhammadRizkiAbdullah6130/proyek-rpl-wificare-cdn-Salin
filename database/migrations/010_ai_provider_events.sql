-- WiFiCare CDN - Tahap 20: Monitoring event provider AI dan notifikasi Admin.
--
-- Migrasi ADITIF. TIDAK mengubah atau menghapus data lama.
--
-- Keamanan:
--   * Hanya metadata: provider, model, tipe error, HTTP status, retry-after,
--     kode error aman, dan waktu.
--   * Tidak ada API key, header Authorization, prompt, atau data pelanggan.
--   * dedup_key mencegah notifikasi kuota/rate limit berulang pada jendela
--     waktu yang sama.
--
-- Jalankan pada database aplikasi:
--   mysql -u root wificare_cdn_v1 < database/migrations/010_ai_provider_events.sql
--   mysql -u root wificare_cdn_test < database/migrations/010_ai_provider_events.sql

SET NAMES utf8mb4;

-- Sumber permintaan: local_database, local_faq, atau cloud_ai.
ALTER TABLE ai_usage_log
    ADD COLUMN IF NOT EXISTS source VARCHAR(20) NOT NULL DEFAULT 'cloud_ai' AFTER request_type;

UPDATE ai_usage_log
SET source = CASE
    WHEN provider IN ('local', 'mysql-local', 'ollama') THEN 'local_database'
    WHEN provider = 'faq' OR model LIKE '%faq%' THEN 'local_faq'
    WHEN provider IN ('gemini', 'deepseek', 'openai') THEN 'cloud_ai'
    ELSE 'other'
END;

CREATE TABLE IF NOT EXISTS ai_provider_events (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider            VARCHAR(32)     NOT NULL,
    model               VARCHAR(120)    NOT NULL DEFAULT '',
    event_type          VARCHAR(40)     NOT NULL,
    error_code          VARCHAR(64)     NOT NULL DEFAULT '',
    http_status         SMALLINT UNSIGNED NULL DEFAULT NULL,
    retry_after_seconds INT UNSIGNED    NULL DEFAULT NULL,
    provider_code       VARCHAR(120)    NOT NULL DEFAULT '',
    dedup_key           VARCHAR(191)    NULL DEFAULT NULL,
    notification_sent   TINYINT(1)      NOT NULL DEFAULT 0,
    created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ai_provider_events_dedup (dedup_key),
    KEY idx_ai_provider_events_provider (provider, created_at),
    KEY idx_ai_provider_events_type (event_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
