-- =============================================================================
-- WiFiCare CDN — Migrasi 001: Autentikasi & Pelanggan
-- Engine: InnoDB · Charset: utf8mb4 / utf8mb4_unicode_ci
-- Aman dijalankan berulang (CREATE TABLE IF NOT EXISTS).
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- users — satu tabel untuk keempat peran (admin, cs, teknisi, pelanggan).
-- Password disimpan sebagai hash bcrypt (password_hash). Tidak ada plaintext.
-- Akun tidak dihapus permanen; gunakan is_active untuk menonaktifkan.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username      VARCHAR(64)     NOT NULL,
    password_hash VARCHAR(255)    NOT NULL,
    nama          VARCHAR(120)    NOT NULL,
    role          ENUM('admin','cs','teknisi','pelanggan') NOT NULL,
    is_active     TINYINT(1)      NOT NULL DEFAULT 1,
    last_login_at TIMESTAMP       NULL DEFAULT NULL,
    created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP       NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    KEY idx_users_role (role),
    KEY idx_users_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- pelanggan — profil pelanggan. `username_pppoe` adalah identitas login pelanggan.
-- Password jaringan PPPoE TIDAK PERNAH disimpan di sini.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pelanggan (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        BIGINT UNSIGNED NOT NULL,
    username_pppoe VARCHAR(64)     NOT NULL,
    nomor_whatsapp VARCHAR(32)     NOT NULL,
    alamat         VARCHAR(255)    NULL DEFAULT NULL,
    created_at     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP       NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pelanggan_user (user_id),
    UNIQUE KEY uq_pelanggan_pppoe (username_pppoe),
    KEY idx_pelanggan_whatsapp (nomor_whatsapp),
    CONSTRAINT fk_pelanggan_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
