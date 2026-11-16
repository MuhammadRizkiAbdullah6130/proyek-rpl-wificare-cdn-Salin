-- =============================================================================
-- WiFiCare CDN — contoh akun MySQL read-only untuk WiFiCare AI
-- =============================================================================
-- Tujuan: memastikan jalur baca AI (AiReadService) TIDAK PERNAH memakai akun
-- aplikasi yang memiliki hak tulis. Akun ini hanya boleh SELECT pada tabel
-- yang memang dibaca oleh tool AI.
--
-- Ini BUKAN migrasi. Jalankan manual sebagai root/DBA, lalu isi .env:
--   DB_RO_USER=wificare_ai_ro
--   DB_RO_PASSWORD=<kata-sandi-kuat>
--
-- Ganti 'GANTI_DENGAN_SANDI_KUAT' sebelum dijalankan. Jangan commit sandi asli.
-- Catatan: host 'localhost' cukup untuk XAMPP. Gunakan '%' hanya bila server
-- aplikasi dan MySQL berada di mesin berbeda, dan batasi firewall/allowlist.
-- =============================================================================

CREATE USER IF NOT EXISTS 'wificare_ai_ro'@'localhost'
    IDENTIFIED BY 'GANTI_DENGAN_SANDI_KUAT';

-- Hanya tabel yang dibaca AiReadService. Tidak ada INSERT/UPDATE/DELETE.
GRANT SELECT ON `wificare_cdn_v1`.`users`              TO 'wificare_ai_ro'@'localhost';
GRANT SELECT ON `wificare_cdn_v1`.`pelanggan`          TO 'wificare_ai_ro'@'localhost';
GRANT SELECT ON `wificare_cdn_v1`.`pengaduan`          TO 'wificare_ai_ro'@'localhost';
GRANT SELECT ON `wificare_cdn_v1`.`penugasan_teknisi`  TO 'wificare_ai_ro'@'localhost';
GRANT SELECT ON `wificare_cdn_v1`.`riwayat_status`     TO 'wificare_ai_ro'@'localhost';
GRANT SELECT ON `wificare_cdn_v1`.`kategori_gangguan`  TO 'wificare_ai_ro'@'localhost';
GRANT SELECT ON `wificare_cdn_v1`.`rating_layanan`     TO 'wificare_ai_ro'@'localhost';

FLUSH PRIVILEGES;

-- Verifikasi (harus hanya SELECT):
-- SHOW GRANTS FOR 'wificare_ai_ro'@'localhost';
--
-- Uji negatif (harus ERROR 1142 / denied):
-- mysql -u wificare_ai_ro -p wificare_cdn_v1 -e "UPDATE pengaduan SET status='selesai' LIMIT 1;"
