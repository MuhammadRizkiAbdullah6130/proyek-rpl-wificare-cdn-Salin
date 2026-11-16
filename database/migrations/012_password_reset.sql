-- =============================================================================
-- WiFiCare CDN - Migrasi 012: Lupa Password melalui OTP WhatsApp
--
-- Perubahan:
-- 1. `users.session_version` untuk mencabut sesi lama setelah reset password.
-- 2. `password_reset_authorizations` menyimpan otorisasi reset berumur pendek
--    dan sekali pakai. Yang disimpan hanya HMAC token, bukan token plaintext.
-- 3. `password_reset_events` untuk rate limit sumber permintaan tanpa
--    menyimpan username, nomor WhatsApp, OTP, atau password.
--
-- Tidak ada password pengguna yang diubah dan tidak ada data yang dihapus.
-- =============================================================================

SET NAMES utf8mb4;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS session_version INT UNSIGNED NOT NULL DEFAULT 0
        AFTER password_hash;

CREATE TABLE IF NOT EXISTS password_reset_authorizations (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    otp_id      BIGINT UNSIGNED NULL DEFAULT NULL,
    token_hash  CHAR(64)        NOT NULL,
    expires_at  DATETIME        NOT NULL,
    consumed_at TIMESTAMP       NULL DEFAULT NULL,
    created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_password_reset_token (token_hash),
    KEY idx_password_reset_user (user_id, consumed_at, expires_at),
    CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_password_reset_otp FOREIGN KEY (otp_id)
        REFERENCES whatsapp_otp (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_reset_events (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    BIGINT UNSIGNED NULL DEFAULT NULL,
    ip_hash    CHAR(64)        NOT NULL,
    event      VARCHAR(40)     NOT NULL,
    success    TINYINT(1)      NOT NULL DEFAULT 0,
    created_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_password_reset_event_ip (ip_hash, event, created_at),
    KEY idx_password_reset_event_user (user_id, event, created_at),
    CONSTRAINT fk_password_reset_event_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
