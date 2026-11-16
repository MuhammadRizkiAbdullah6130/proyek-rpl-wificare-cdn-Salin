-- =============================================================================
-- WiFiCare CDN — Seed: Kategori Gangguan
-- Idempotent (INSERT IGNORE berdasarkan unique nama).
-- =============================================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO kategori_gangguan (nama, is_active) VALUES
    ('Gangguan Koneksi Internet', 1),
    ('Koneksi Lambat / Tidak Stabil', 1),
    ('Masalah Perangkat / Router', 1),
    ('Tagihan dan Pembayaran', 1),
    ('Lainnya', 1);
