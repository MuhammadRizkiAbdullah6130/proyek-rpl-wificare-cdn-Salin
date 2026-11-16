-- =============================================================================
-- WiFiCare CDN — Migrasi 004: Penyelesaian Langsung oleh Customer Service
-- Engine: InnoDB · Charset: utf8mb4 / utf8mb4_unicode_ci
--
-- Tiket dapat diselesaikan langsung oleh CS tanpa teknisi. Karena laporan
-- teknisi mewajibkan teknisi_id, solusi CS disimpan pada tabel tersendiri.
-- Enam status resmi TIDAK bertambah; penyelesaian ini hanya data pendukung.
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- penyelesaian_cs — solusi/tindakan yang dilakukan CS saat menyelesaikan tiket.
-- Satu tiket hanya boleh memiliki satu penyelesaian (UNIQUE pengaduan_id).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS penyelesaian_cs (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pengaduan_id BIGINT UNSIGNED NOT NULL,
    cs_id        BIGINT UNSIGNED NOT NULL,
    solusi       TEXT            NOT NULL,
    completed_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_penyelesaian_pengaduan (pengaduan_id),
    KEY idx_penyelesaian_cs (cs_id),
    CONSTRAINT fk_penyelesaian_pengaduan FOREIGN KEY (pengaduan_id)
        REFERENCES pengaduan (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_penyelesaian_cs FOREIGN KEY (cs_id)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
