-- =============================================================================
-- WiFiCare CDN — Migrasi 003: Operasional & Pendukung
-- Engine: InnoDB · Charset: utf8mb4 / utf8mb4_unicode_ci
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- riwayat_status — jejak perubahan status tiket (audit alur pengaduan).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS riwayat_status (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pengaduan_id  BIGINT UNSIGNED NOT NULL,
    status_lama   ENUM('menunggu_verifikasi','diproses_cs','menunggu_teknisi','dalam_penanganan','selesai','ditolak')
                  NULL DEFAULT NULL,
    status_baru   ENUM('menunggu_verifikasi','diproses_cs','menunggu_teknisi','dalam_penanganan','selesai','ditolak')
                  NOT NULL,
    actor_id      BIGINT UNSIGNED NULL DEFAULT NULL,
    catatan       VARCHAR(255)    NULL DEFAULT NULL,
    created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_riwayat_pengaduan (pengaduan_id),
    KEY idx_riwayat_created (created_at),
    CONSTRAINT fk_riwayat_pengaduan FOREIGN KEY (pengaduan_id)
        REFERENCES pengaduan (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_riwayat_actor FOREIGN KEY (actor_id)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- catatan_cs — catatan internal Customer Service pada tiket.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS catatan_cs (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pengaduan_id BIGINT UNSIGNED NOT NULL,
    cs_id        BIGINT UNSIGNED NOT NULL,
    catatan      TEXT            NOT NULL,
    created_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_catatan_cs_pengaduan (pengaduan_id),
    KEY idx_catatan_cs_user (cs_id),
    CONSTRAINT fk_catatan_cs_pengaduan FOREIGN KEY (pengaduan_id)
        REFERENCES pengaduan (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_catatan_cs_user FOREIGN KEY (cs_id)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- notifikasi — pemberitahuan in-app untuk pengguna.
-- Dihapus bersama user (bukan data riwayat penting; audit_log tetap menyimpan jejak).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifikasi (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      BIGINT UNSIGNED NOT NULL,
    pengaduan_id BIGINT UNSIGNED NULL DEFAULT NULL,
    judul        VARCHAR(150)    NOT NULL,
    pesan        TEXT            NOT NULL,
    read_at      TIMESTAMP       NULL DEFAULT NULL,
    created_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notifikasi_user (user_id, read_at),
    KEY idx_notifikasi_pengaduan (pengaduan_id),
    CONSTRAINT fk_notifikasi_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_notifikasi_pengaduan FOREIGN KEY (pengaduan_id)
        REFERENCES pengaduan (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- rating_layanan — satu rating per tiket yang selesai.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rating_layanan (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pengaduan_id BIGINT UNSIGNED NOT NULL,
    pelanggan_id BIGINT UNSIGNED NOT NULL,
    rating       TINYINT UNSIGNED NOT NULL,
    ulasan       TEXT            NULL DEFAULT NULL,
    created_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rating_pengaduan (pengaduan_id),
    KEY idx_rating_pelanggan (pelanggan_id),
    CONSTRAINT chk_rating_nilai CHECK (rating BETWEEN 1 AND 5),
    CONSTRAINT fk_rating_pengaduan FOREIGN KEY (pengaduan_id)
        REFERENCES pengaduan (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_rating_pelanggan FOREIGN KEY (pelanggan_id)
        REFERENCES pelanggan (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- audit_log — jejak audit append-only (login, perubahan penting, dsb).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_id   BIGINT UNSIGNED NULL DEFAULT NULL,
    aksi       VARCHAR(100)    NOT NULL,
    objek      VARCHAR(100)    NULL DEFAULT NULL,
    objek_id   BIGINT UNSIGNED NULL DEFAULT NULL,
    metadata   JSON            NULL DEFAULT NULL,
    ip_address VARCHAR(45)     NULL DEFAULT NULL,
    created_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_actor (actor_id),
    KEY idx_audit_aksi (aksi),
    KEY idx_audit_created (created_at),
    CONSTRAINT fk_audit_actor FOREIGN KEY (actor_id)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- pengaturan — konfigurasi aplikasi berbasis key/value.
-- `key` adalah kata kunci MySQL sehingga selalu menggunakan backtick.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pengaturan (
    `key`      VARCHAR(100) NOT NULL,
    `value`    TEXT         NULL DEFAULT NULL,
    updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- login_attempts — tabel pendukung pembatasan percobaan login (brute force).
-- Bukan bagian relasi data pengaduan; hanya untuk keamanan autentikasi.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username     VARCHAR(64)     NULL DEFAULT NULL,
    ip_address   VARCHAR(45)     NOT NULL,
    success      TINYINT(1)      NOT NULL DEFAULT 0,
    attempted_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_login_username (username, attempted_at),
    KEY idx_login_ip (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
