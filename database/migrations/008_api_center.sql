-- =============================================================================
-- WiFiCare CDN — Tahap 17: Pusat Konfigurasi API & Monitoring Per Fitur
-- =============================================================================
-- Migrasi TAMBAHAN. Tidak menghapus/mengubah data lama. Tabel `ai_settings`,
-- `ai_provider_keys`, dan `ai_usage_log` tetap dipakai sebagai sumber AI.
--
-- KEAMANAN:
--   * Secret (access token / webhook secret) HANYA disimpan sebagai ciphertext
--     libsodium (`v1:...`). Master key tetap di environment server
--     (AI_SETTINGS_KEY / API_CREDENTIALS_KEY), TIDAK di database.
--   * `whatsapp_otp` tidak menyimpan kode OTP plaintext (hanya password_hash)
--     dan tidak menyimpan nomor tujuan plaintext (hanya HMAC fingerprint).
--   * `api_usage_log`, `whatsapp_outbox`, dan `api_config_audit` tidak boleh
--     menyimpan isi pesan, OTP, token, atau isi laporan sensitif.
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- api_credentials — kredensial provider yang dapat dipakai bersama beberapa
-- fitur (integrasi `whatsapp`). Integrasi AI tetap memakai ai_provider_keys.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS api_credentials (
    id                             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    integration                    VARCHAR(32)     NOT NULL DEFAULT 'whatsapp',
    provider                       VARCHAR(40)     NOT NULL DEFAULT 'meta_cloud',
    name                           VARCHAR(100)    NOT NULL,
    config_json                    JSON            NULL DEFAULT NULL,
    secret_encrypted               TEXT            NULL DEFAULT NULL,
    webhook_verify_token_encrypted TEXT            NULL DEFAULT NULL,
    webhook_secret_encrypted       TEXT            NULL DEFAULT NULL,
    is_active                      TINYINT(1)      NOT NULL DEFAULT 1,
    last_tested_at                 TIMESTAMP       NULL DEFAULT NULL,
    last_test_status               VARCHAR(20)     NULL DEFAULT NULL,
    last_test_message              VARCHAR(255)    NULL DEFAULT NULL,
    created_by                     BIGINT UNSIGNED NULL DEFAULT NULL,
    updated_by                     BIGINT UNSIGNED NULL DEFAULT NULL,
    revoked_at                     TIMESTAMP       NULL DEFAULT NULL,
    created_at                     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                     TIMESTAMP       NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_api_credentials_integration (integration, is_active),
    KEY idx_api_credentials_revoked (revoked_at),
    CONSTRAINT fk_api_credentials_created_by FOREIGN KEY (created_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_api_credentials_updated_by FOREIGN KEY (updated_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- api_integration_settings — satu baris per fitur. `connection_id` opsional:
-- NULL berarti memakai kredensial WhatsApp aktif (berbagi kredensial).
-- settings_json berisi konfigurasi NONRAHASIA per fitur.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS api_integration_settings (
    feature_key   VARCHAR(40)      NOT NULL,
    connection_id BIGINT UNSIGNED  NULL DEFAULT NULL,
    is_enabled    TINYINT(1)       NOT NULL DEFAULT 0,
    settings_json JSON             NULL DEFAULT NULL,
    updated_by    BIGINT UNSIGNED  NULL DEFAULT NULL,
    created_at    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP        NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (feature_key),
    KEY idx_api_integration_connection (connection_id),
    CONSTRAINT fk_api_integration_connection FOREIGN KEY (connection_id)
        REFERENCES api_credentials (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_api_integration_updated_by FOREIGN KEY (updated_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- api_usage_log — monitoring generik fitur non-AI (WhatsApp OTP/notifikasi/
-- laporan + tes koneksi). AI tetap memakai ai_usage_log lalu digabung di UI.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS api_usage_log (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    feature_key       VARCHAR(40)     NOT NULL,
    provider          VARCHAR(40)     NOT NULL DEFAULT '',
    connection_id     BIGINT UNSIGNED NULL DEFAULT NULL,
    event_type        VARCHAR(60)     NOT NULL DEFAULT '',
    status            VARCHAR(20)     NOT NULL DEFAULT '',
    success           TINYINT(1)      NOT NULL DEFAULT 0,
    external_id       VARCHAR(128)    NULL DEFAULT NULL,
    error_code        VARCHAR(64)     NULL DEFAULT NULL,
    duration_ms       INT UNSIGNED    NOT NULL DEFAULT 0,
    prompt_tokens     INT UNSIGNED    NULL DEFAULT NULL,
    completion_tokens INT UNSIGNED    NULL DEFAULT NULL,
    total_tokens      INT UNSIGNED    NULL DEFAULT NULL,
    created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_api_usage_feature_created (feature_key, created_at),
    KEY idx_api_usage_provider (provider),
    KEY idx_api_usage_status (status),
    KEY idx_api_usage_connection (connection_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- whatsapp_outbox — antrean idempoten untuk notifikasi WhatsApp.
-- Status "accepted" berarti provider MENERIMA request, BUKAN terkirim.
-- Status "delivered"/"read" hanya diubah oleh webhook terverifikasi.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS whatsapp_outbox (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    feature_key         VARCHAR(40)     NOT NULL,
    event_key           VARCHAR(150)    NOT NULL,
    connection_id       BIGINT UNSIGNED NULL DEFAULT NULL,
    recipient_encrypted TEXT            NOT NULL,
    template_key        VARCHAR(60)     NULL DEFAULT NULL,
    send_mode           VARCHAR(20)     NOT NULL DEFAULT 'text',
    template_name       VARCHAR(120)    NULL DEFAULT NULL,
    language_code       VARCHAR(20)     NULL DEFAULT NULL,
    payload_json        JSON            NULL DEFAULT NULL,
    payload_encrypted   TEXT            NULL DEFAULT NULL,
    status              ENUM('pending','processing','accepted','delivered','read','failed','cancelled')
                                        NOT NULL DEFAULT 'pending',
    attempts            TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts        TINYINT UNSIGNED NOT NULL DEFAULT 3,
    next_attempt_at     TIMESTAMP       NULL DEFAULT NULL,
    provider_message_id VARCHAR(128)    NULL DEFAULT NULL,
    last_error_code     VARCHAR(64)     NULL DEFAULT NULL,
    last_error_message  VARCHAR(255)    NULL DEFAULT NULL,
    accepted_at         TIMESTAMP       NULL DEFAULT NULL,
    delivered_at        TIMESTAMP       NULL DEFAULT NULL,
    read_at             TIMESTAMP       NULL DEFAULT NULL,
    created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP       NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_whatsapp_outbox_event (event_key),
    KEY idx_whatsapp_outbox_queue (status, next_attempt_at),
    KEY idx_whatsapp_outbox_feature (feature_key, status),
    KEY idx_whatsapp_outbox_provider_msg (provider_message_id),
    KEY idx_whatsapp_outbox_connection (connection_id),
    CONSTRAINT fk_whatsapp_outbox_connection FOREIGN KEY (connection_id)
        REFERENCES api_credentials (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Untuk instalasi yang sempat menjalankan versi awal migrasi ini.
ALTER TABLE whatsapp_outbox
    ADD COLUMN IF NOT EXISTS payload_encrypted TEXT NULL DEFAULT NULL AFTER payload_json;

-- -----------------------------------------------------------------------------
-- whatsapp_otp — OTP sekali pakai. Kode disimpan sebagai password_hash,
-- nomor tujuan sebagai HMAC fingerprint (bukan plaintext).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS whatsapp_otp (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id             BIGINT UNSIGNED NOT NULL,
    purpose             ENUM('verifikasi_nomor','reset_password') NOT NULL,
    phone_fingerprint   CHAR(64)        NOT NULL,
    code_hash           VARCHAR(255)    NOT NULL,
    send_status         VARCHAR(20)     NOT NULL DEFAULT 'pending',
    provider_message_id VARCHAR(128)    NULL DEFAULT NULL,
    expires_at          DATETIME        NOT NULL,
    max_attempts        TINYINT UNSIGNED NOT NULL DEFAULT 5,
    attempts            TINYINT UNSIGNED NOT NULL DEFAULT 0,
    consumed_at         TIMESTAMP       NULL DEFAULT NULL,
    last_sent_at        TIMESTAMP       NULL DEFAULT NULL,
    created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP       NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_whatsapp_otp_user (user_id, purpose, created_at),
    KEY idx_whatsapp_otp_phone (phone_fingerprint, created_at),
    KEY idx_whatsapp_otp_active (user_id, purpose, consumed_at, expires_at),
    CONSTRAINT fk_whatsapp_otp_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MariaDB memberi TIMESTAMP implicit ON UPDATE CURRENT_TIMESTAMP. Expiry OTP
-- harus tetap stabil saat status pengiriman diperbarui, jadi gunakan DATETIME.
ALTER TABLE whatsapp_otp
    MODIFY COLUMN expires_at DATETIME NOT NULL;

-- -----------------------------------------------------------------------------
-- api_config_audit — audit konfigurasi per fitur. TIDAK PERNAH berisi secret.
-- changed_fields hanya nama field yang berubah.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS api_config_audit (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_id      BIGINT UNSIGNED NULL DEFAULT NULL,
    feature_key   VARCHAR(40)     NOT NULL,
    action        VARCHAR(60)     NOT NULL,
    changed_fields JSON           NULL DEFAULT NULL,
    created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_api_config_audit_feature (feature_key, created_at),
    KEY idx_api_config_audit_actor (actor_id),
    CONSTRAINT fk_api_config_audit_actor FOREIGN KEY (actor_id)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Baris awal fitur WhatsApp. Default NONAKTIF sampai Admin mengonfigurasi.
INSERT IGNORE INTO api_integration_settings (feature_key, is_enabled, settings_json) VALUES
    ('whatsapp_gateway', 0, '{}'),
    ('whatsapp_otp', 0, '{}'),
    ('whatsapp_notification', 0, '{}'),
    ('whatsapp_report', 0, '{}');
