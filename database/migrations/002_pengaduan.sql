-- =============================================================================
-- WiFiCare CDN — Migrasi 002: Kategori & Pengaduan
-- Engine: InnoDB · Charset: utf8mb4 / utf8mb4_unicode_ci
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- kategori_gangguan — master kategori pengaduan.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS kategori_gangguan (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nama       VARCHAR(120)    NOT NULL,
    is_active  TINYINT(1)      NOT NULL DEFAULT 1,
    created_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP       NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_kategori_nama (nama)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- pengaduan — tiket pengaduan pelanggan.
-- Enam status resmi. Pembatalan dan pengarsipan adalah atribut terpisah,
-- bukan status tambahan. Data pelanggan disalin (snapshot) agar riwayat stabil.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pengaduan (
    id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nomor_tiket                VARCHAR(32)     NOT NULL,
    pelanggan_id               BIGINT UNSIGNED NOT NULL,
    nama_pelanggan_snapshot    VARCHAR(120)    NOT NULL,
    username_pppoe_snapshot    VARCHAR(64)     NOT NULL,
    nomor_whatsapp_snapshot    VARCHAR(32)     NOT NULL,
    kategori_id                BIGINT UNSIGNED NOT NULL,
    deskripsi                  TEXT            NOT NULL,
    status                     ENUM('menunggu_verifikasi','diproses_cs','menunggu_teknisi','dalam_penanganan','selesai','ditolak')
                               NOT NULL DEFAULT 'menunggu_verifikasi',
    alasan_penolakan           TEXT            NULL DEFAULT NULL,
    is_cancelled               TINYINT(1)      NOT NULL DEFAULT 0,
    cancelled_at               TIMESTAMP       NULL DEFAULT NULL,
    cancelled_by               BIGINT UNSIGNED NULL DEFAULT NULL,
    cancellation_reason        TEXT            NULL DEFAULT NULL,
    archived_at                TIMESTAMP       NULL DEFAULT NULL,
    archived_by                BIGINT UNSIGNED NULL DEFAULT NULL,
    created_at                 TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                 TIMESTAMP       NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pengaduan_tiket (nomor_tiket),
    KEY idx_pengaduan_status (status),
    KEY idx_pengaduan_pelanggan (pelanggan_id),
    KEY idx_pengaduan_kategori (kategori_id),
    KEY idx_pengaduan_created (created_at),
    KEY idx_pengaduan_cancelled (is_cancelled),
    CONSTRAINT fk_pengaduan_pelanggan FOREIGN KEY (pelanggan_id)
        REFERENCES pelanggan (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_pengaduan_kategori FOREIGN KEY (kategori_id)
        REFERENCES kategori_gangguan (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_pengaduan_cancelled_by FOREIGN KEY (cancelled_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_pengaduan_archived_by FOREIGN KEY (archived_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- pengaduan_foto — lampiran foto pengaduan.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pengaduan_foto (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pengaduan_id  BIGINT UNSIGNED NOT NULL,
    file_path     VARCHAR(255)    NOT NULL,
    original_name VARCHAR(255)    NULL DEFAULT NULL,
    mime_type     VARCHAR(100)    NOT NULL,
    file_size     INT UNSIGNED    NOT NULL DEFAULT 0,
    created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pengaduan_foto_pengaduan (pengaduan_id),
    CONSTRAINT fk_pengaduan_foto_pengaduan FOREIGN KEY (pengaduan_id)
        REFERENCES pengaduan (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- penugasan_teknisi — penugasan tiket kepada teknisi (riwayat penugasan).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS penugasan_teknisi (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pengaduan_id     BIGINT UNSIGNED NOT NULL,
    teknisi_id       BIGINT UNSIGNED NOT NULL,
    ditugaskan_oleh  BIGINT UNSIGNED NULL DEFAULT NULL,
    catatan          TEXT            NULL DEFAULT NULL,
    is_active        TINYINT(1)      NOT NULL DEFAULT 1,
    assigned_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at       TIMESTAMP       NULL DEFAULT NULL,
    created_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_penugasan_pengaduan (pengaduan_id),
    KEY idx_penugasan_teknisi (teknisi_id),
    KEY idx_penugasan_active (is_active),
    CONSTRAINT fk_penugasan_pengaduan FOREIGN KEY (pengaduan_id)
        REFERENCES pengaduan (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_penugasan_teknisi FOREIGN KEY (teknisi_id)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_penugasan_oleh FOREIGN KEY (ditugaskan_oleh)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- laporan_teknisi — satu laporan penyelesaian per tiket.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS laporan_teknisi (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pengaduan_id        BIGINT UNSIGNED NOT NULL,
    teknisi_id          BIGINT UNSIGNED NOT NULL,
    penyebab_gangguan   TEXT            NOT NULL,
    tindakan_perbaikan  TEXT            NOT NULL,
    catatan             TEXT            NULL DEFAULT NULL,
    completed_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_laporan_pengaduan (pengaduan_id),
    KEY idx_laporan_teknisi (teknisi_id),
    CONSTRAINT fk_laporan_pengaduan FOREIGN KEY (pengaduan_id)
        REFERENCES pengaduan (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_laporan_teknisi FOREIGN KEY (teknisi_id)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- laporan_foto — lampiran foto laporan perbaikan.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS laporan_foto (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    laporan_id    BIGINT UNSIGNED NOT NULL,
    file_path     VARCHAR(255)    NOT NULL,
    original_name VARCHAR(255)    NULL DEFAULT NULL,
    mime_type     VARCHAR(100)    NOT NULL,
    file_size     INT UNSIGNED    NOT NULL DEFAULT 0,
    created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_laporan_foto_laporan (laporan_id),
    CONSTRAINT fk_laporan_foto_laporan FOREIGN KEY (laporan_id)
        REFERENCES laporan_teknisi (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
