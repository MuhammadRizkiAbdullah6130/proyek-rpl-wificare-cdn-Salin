-- =============================================================================
-- WiFiCare CDN — Seed: Pengaturan Aplikasi
-- Idempotent tanpa menimpa nilai yang sudah diubah Admin (INSERT IGNORE).
-- Kredensial/API key TIDAK pernah disimpan di sini.
-- =============================================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO pengaturan (`key`, `value`) VALUES
    ('app_name', 'WiFiCare CDN'),
    ('isp_name', 'WiFiCare Network'),
    ('contact_email', ''),
    ('contact_phone', ''),
    ('contact_whatsapp', ''),
    ('ticket_prefix', 'WFC'),
    ('upload_max_mb', '5'),
    ('ai_enabled', '0'),
    ('maintenance_mode', '0');
