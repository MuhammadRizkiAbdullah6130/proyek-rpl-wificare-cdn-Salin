-- =============================================================================
-- WiFiCare CDN - Migrasi 013: Purpose OTP `phone_change`
--
-- Perubahan:
-- 1. Menambahkan purpose `phone_change` agar OTP alur ganti nomor tidak
--    bercampur dengan OTP verifikasi nomor lama/utama.
-- 2. Nilai purpose lama tetap dipertahankan. Tidak ada baris OTP yang dihapus
--    dan tidak ada data konfigurasi existing yang diubah.
--
-- Template pesan per purpose disimpan sebagai konfigurasi nonrahasia pada
-- `api_integration_settings.settings_json`; field template lama `template`
-- tetap dibaca sebagai fallback oleh aplikasi.
-- =============================================================================

SET NAMES utf8mb4;

ALTER TABLE whatsapp_otp
    MODIFY COLUMN purpose
        ENUM('phone_verification','phone_change','verifikasi_nomor','reset_password') NOT NULL;
