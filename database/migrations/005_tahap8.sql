-- =============================================================================
-- WiFiCare CDN — Migrasi 005: Tahap 8 (Admin, Notifikasi, Rating, Audit)
-- Engine: InnoDB · Charset: utf8mb4 / utf8mb4_unicode_ci
--
-- Tabel inti Tahap 8 (notifikasi, rating_layanan, audit_log, pengaturan) sudah
-- dibuat pada migrasi 003. Migrasi ini hanya melengkapi kolom & indeks yang
-- dibutuhkan modul admin.
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- kategori_gangguan: tambah deskripsi (opsional) untuk manajemen kategori.
-- -----------------------------------------------------------------------------
ALTER TABLE kategori_gangguan
    ADD COLUMN IF NOT EXISTS deskripsi VARCHAR(255) NULL DEFAULT NULL AFTER nama;

-- -----------------------------------------------------------------------------
-- pengaduan: indeks untuk penyaringan tiket yang diarsipkan.
-- Pembatalan & pengarsipan tetap berupa atribut, bukan status ketujuh.
-- -----------------------------------------------------------------------------
ALTER TABLE pengaduan
    ADD INDEX IF NOT EXISTS idx_pengaduan_archived (archived_at);
