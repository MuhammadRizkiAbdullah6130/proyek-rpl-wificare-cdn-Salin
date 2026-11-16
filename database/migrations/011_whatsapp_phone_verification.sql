-- =============================================================================
-- WiFiCare CDN - Migrasi 011: Verifikasi Nomor WhatsApp Pelanggan
--
-- Perubahan:
-- 1. Status verifikasi nomor pada tabel pelanggan. Fingerprint disimpan agar
--    status otomatis tidak berlaku bila nomor utama diubah oleh Admin.
-- 2. Purpose OTP baru `phone_verification`. Nilai lama dipertahankan agar
--    integrasi Tahap 17 yang sudah ada tetap kompatibel.
--
-- Tidak ada nomor pelanggan yang diubah dan tidak ada data yang dihapus.
-- =============================================================================

SET NAMES utf8mb4;

ALTER TABLE pelanggan
    ADD COLUMN IF NOT EXISTS nomor_whatsapp_verified_at DATETIME NULL DEFAULT NULL
        AFTER nomor_whatsapp,
    ADD COLUMN IF NOT EXISTS nomor_whatsapp_verified_fingerprint CHAR(64) NULL DEFAULT NULL
        AFTER nomor_whatsapp_verified_at;

ALTER TABLE whatsapp_otp
    MODIFY COLUMN purpose
        ENUM('phone_verification','verifikasi_nomor','reset_password') NOT NULL;
