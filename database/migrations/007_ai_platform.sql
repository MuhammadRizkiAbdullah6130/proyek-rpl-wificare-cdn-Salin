-- =============================================================================
-- WiFiCare CDN — Tahap 15: Platform AI (Multi-Provider, Monitoring, FAQ)
-- =============================================================================
-- Migrasi TAMBAHAN. Tidak menghapus data lama. Tabel ai_settings tetap dipakai
-- sebagai konfigurasi aktif; data berikut hanya melengkapinya.
--
-- KEAMANAN:
--   * API key pada ai_provider_keys.api_key_encrypted HANYA ciphertext libsodium
--     (format "v1:..."). Master key tetap berada di environment (AI_SETTINGS_KEY).
--   * ai_usage_log TIDAK menyimpan isi percakapan, API key, atau data pribadi.
--   * FAQ adalah data referensi; bukan instruksi yang boleh melewati RBAC.
-- =============================================================================

SET NAMES utf8mb4;

-- Batas request harian per pengguna (server-side) + tampilan monitoring.
ALTER TABLE ai_settings
    ADD COLUMN IF NOT EXISTS daily_limit INT UNSIGNED NOT NULL DEFAULT 100 AFTER rate_window;

-- -----------------------------------------------------------------------------
-- ai_provider_keys — satu baris per provider, masing-masing punya key/model/base.
-- Key Gemini lama (ai_settings.api_key_encrypted) tetap dibaca sebagai fallback.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_provider_keys (
    provider          VARCHAR(32)     NOT NULL,
    api_key_encrypted TEXT            NULL DEFAULT NULL,
    model             VARCHAR(120)    NULL DEFAULT NULL,
    base_url          VARCHAR(255)    NULL DEFAULT NULL,
    updated_by        BIGINT UNSIGNED NULL DEFAULT NULL,
    created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (provider),
    KEY idx_ai_provider_updated_by (updated_by),
    CONSTRAINT fk_ai_provider_updated_by FOREIGN KEY (updated_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- ai_usage_log — metadata monitoring. TIDAK ADA kolom isi pesan/jawaban/key.
-- success: 1 berhasil, 0 gagal. error_code: kode aman (invalid_key, quota, ...).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_usage_log (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id           BIGINT UNSIGNED NULL DEFAULT NULL,
    role              VARCHAR(20)     NULL DEFAULT NULL,
    provider          VARCHAR(32)     NOT NULL,
    model             VARCHAR(120)    NULL DEFAULT NULL,
    request_type      VARCHAR(20)     NOT NULL DEFAULT 'chat',
    success           TINYINT(1)      NOT NULL DEFAULT 0,
    error_code        VARCHAR(64)     NULL DEFAULT NULL,
    duration_ms       INT UNSIGNED    NOT NULL DEFAULT 0,
    prompt_tokens     INT UNSIGNED    NOT NULL DEFAULT 0,
    completion_tokens INT UNSIGNED    NOT NULL DEFAULT 0,
    total_tokens      INT UNSIGNED    NOT NULL DEFAULT 0,
    created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ai_usage_created (created_at),
    KEY idx_ai_usage_user_created (user_id, created_at),
    KEY idx_ai_usage_provider (provider),
    CONSTRAINT fk_ai_usage_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- ai_faq — pengetahuan resmi yang dikelola Admin. Isi FAQ diperlakukan sebagai
-- DATA referensi di prompt, bukan instruksi sistem.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_faq (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pertanyaan VARCHAR(500)    NOT NULL,
    jawaban    TEXT            NOT NULL,
    kategori   VARCHAR(100)    NOT NULL DEFAULT 'Umum',
    is_active  TINYINT(1)      NOT NULL DEFAULT 1,
    urutan     INT             NOT NULL DEFAULT 0,
    updated_by BIGINT UNSIGNED NULL DEFAULT NULL,
    created_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ai_faq_active (is_active, urutan),
    KEY idx_ai_faq_kategori (kategori),
    CONSTRAINT fk_ai_faq_updated_by FOREIGN KEY (updated_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FAQ awal (idempotent: hanya bila tabel masih kosong).
INSERT INTO ai_faq (pertanyaan, jawaban, kategori, is_active, urutan)
SELECT * FROM (
    SELECT 'Bagaimana cara membuat pengaduan?' AS pertanyaan,
           'Buka menu Pengaduan Baru, pilih kategori gangguan, isi deskripsi, unggah foto bila ada, lalu kirim. Anda akan menerima nomor tiket berformat WFC-YYYYMMDD-XXXXXX.' AS jawaban,
           'Pengaduan' AS kategori, 1 AS is_active, 10 AS urutan
    UNION ALL
    SELECT 'Bagaimana cara melihat status tiket?',
           'Buka menu Riwayat Pengaduan. Status tiket mengikuti enam status resmi: Menunggu Verifikasi, Diproses CS, Menunggu Teknisi, Dalam Penanganan, Selesai, dan Ditolak.',
           'Pengaduan', 1, 20
    UNION ALL
    SELECT 'Berapa jam operasional layanan?',
           'Jam operasional Customer Service adalah 08.00–20.00 WIB setiap hari. Gangguan di luar jam tersebut tetap dapat dilaporkan melalui aplikasi.',
           'Layanan', 1, 30
    UNION ALL
    SELECT 'Bagaimana cara restart router dengan aman?',
           'Matikan router/ONT, tunggu sekitar 30 detik, lalu nyalakan kembali. Tunggu 2–3 menit hingga lampu indikator stabil. Jangan melepas kabel atau membuka perangkat.',
           'Troubleshooting', 1, 40
    UNION ALL
    SELECT 'Apakah saya perlu memberikan kata sandi WiFi?',
           'Tidak. WiFiCare tidak pernah meminta kata sandi WiFi, kata sandi akun, atau kode verifikasi melalui chat. Jangan pernah membagikan kredensial kepada siapa pun.',
           'Keamanan', 1, 50
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM ai_faq LIMIT 1);
