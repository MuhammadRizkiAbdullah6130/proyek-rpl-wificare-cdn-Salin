<?php
/**
 * Konfigurasi aplikasi WiFiCare CDN.
 *
 * Nilai dibaca dari environment (.env). Jangan menaruh kredensial di sini.
 */

declare(strict_types=1);

return [
    'app' => [
        'name' => env('APP_NAME', 'WiFiCare CDN'),
        'env' => env('APP_ENV', 'production'),
        'debug' => env_bool('APP_DEBUG', false),
        'url' => env('APP_URL', ''),
        'timezone' => env('APP_TIMEZONE', 'Asia/Jakarta'),
        'locale' => 'id',
    ],

    'session' => [
        'name' => env('SESSION_NAME', 'wificare_session'),
        'lifetime' => (int) env('SESSION_LIFETIME', '7200'),
        // Secure otomatis dipaksa aktif oleh Session saat request HTTPS.
        'cookie_secure' => env_bool('SESSION_COOKIE_SECURE', false),
        'samesite' => env('SESSION_SAMESITE', 'Lax'),
        // Rotasi ID sesi berkala (detik) selama pengguna masih aktif.
        'regenerate_every' => (int) env('SESSION_REGENERATE_EVERY', '1800'),
    ],

    'security' => [
        'login_max_attempts' => (int) env('LOGIN_MAX_ATTEMPTS', '5'),
        'login_window_minutes' => (int) env('LOGIN_ATTEMPT_WINDOW', '15'),
        'login_max_ip_attempts' => (int) env('LOGIN_MAX_IP_ATTEMPTS', '10'),
    ],

    'upload' => [
        'max_size' => 5 * 1024 * 1024,
        'allowed_types' => ['image/jpeg', 'image/png', 'image/webp'],
    ],

    /*
     * AI Assistant (Tahap 9).
     *
     * Kredensial HANYA dibaca dari environment dan tidak pernah dikirim ke
     * frontend. Bila `api_key`/`model` kosong, layanan AI dianggap belum
     * dikonfigurasi dan antarmuka menampilkan pesan bahwa layanan belum aktif.
     *
     * Catatan: aplikasi tetap berjalan di localhost, tetapi API AI eksternal
     * memerlukan koneksi internet.
     */
    'ai' => [
        /*
         * Provider aktif: "gemini", "deepseek", atau "openai".
         * Bila kosong, provider dipilih otomatis: Gemini bila GEMINI_API_KEY
         * terisi, selain itu OpenAI (kompatibel). Admin dapat mengganti provider
         * lewat dashboard; nilai .env hanya fallback saat belum dikonfigurasi.
         */
        'provider' => strtolower(trim((string) env('AI_PROVIDER', ''))),

        // OpenAI-compatible (opsional).
        'api_key' => env('AI_API_KEY', ''),
        'model' => env('AI_MODEL', ''),
        'base_url' => env('AI_API_BASE_URL', 'https://api.openai.com/v1'),

        // DeepSeek (API kompatibel OpenAI Chat Completions).
        'deepseek_api_key' => env('DEEPSEEK_API_KEY', ''),
        'deepseek_model' => env('DEEPSEEK_MODEL', 'deepseek-chat'),
        'deepseek_base_url' => env('DEEPSEEK_API_BASE_URL', 'https://api.deepseek.com/v1'),

        // Google Gemini (generateContent API).
        'gemini_api_key' => env('GEMINI_API_KEY', ''),
        'gemini_model' => env('GEMINI_MODEL', 'gemini-3.5-flash'),
        // Daftar model cadangan (dipisah koma) yang dicoba bila model utama
        // sedang penuh (503/429) atau tidak tersedia (404).
        'gemini_model_fallback' => env('GEMINI_MODEL_FALLBACK', ''),
        'gemini_base_url' => env('GEMINI_API_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        // 0 = nonaktifkan proses berpikir (respons lebih cepat); "" = bawaan model.
        'gemini_thinking_budget' => env('GEMINI_THINKING_BUDGET', '0'),

        /*
         * Ollama lokal (opsional). Tidak wajib terpasang; aplikasi tetap
         * berjalan dengan Local Database Assistant tanpa Ollama. Base URL
         * HTTP hanya diizinkan untuk loopback/localhost.
         */
        'ollama_base_url' => env('OLLAMA_BASE_URL', 'http://127.0.0.1:11434/v1'),
        'ollama_model' => env('OLLAMA_MODEL', 'llama3.2:3b'),
        'ollama_timeout' => (int) env('OLLAMA_TIMEOUT', '30'),

        'timeout' => (int) env('AI_TIMEOUT', '25'),
        // Jumlah percobaan ulang per model untuk kesalahan sementara.
        'max_retries' => (int) env('AI_MAX_RETRIES', '1'),
        'max_message' => (int) env('AI_MAX_MESSAGE', '2000'),
        'rate_limit' => (int) env('AI_RATE_LIMIT', '20'),
        'rate_window' => (int) env('AI_RATE_WINDOW', '60'),
        'max_tool_rounds' => (int) env('AI_MAX_TOOL_ROUNDS', '3'),
    ],

    /*
     * Pengaturan AI Chatbot (Admin) — Tahap 11.
     *
     * Konfigurasi runtime AI (provider, model, limit) dikelola Admin lewat
     * dashboard dan disimpan pada tabel `ai_settings`. API key disimpan
     * TERENKRIPSI (libsodium secretbox).
     *
     * `encryption_key` adalah master key enkripsi dan HANYA dibaca dari
     * environment server (AI_SETTINGS_KEY). Kunci ini TIDAK boleh disimpan di
     * database maupun repositori. Bila kosong, Admin tidak dapat menyimpan API
     * key (sistem menampilkan informasi konfigurasi yang aman).
     */
    'ai_settings' => [
        'encryption_key' => env('AI_SETTINGS_KEY', ''),
    ],

    /*
     * Pusat API & WhatsApp (Tahap 17).
     *
     * `encryption_key` adalah alias master key yang SAMA dengan AI. Bila
     * API_CREDENTIALS_KEY tidak diisi, AI_SETTINGS_KEY dipakai agar kredensial
     * lama tetap dapat didekripsi. Master key tidak pernah disimpan di DB.
     */
    'api_credentials' => [
        'encryption_key' => env('API_CREDENTIALS_KEY', ''),
    ],

    'api_center' => [
        // Nomor Indonesia tanpa awalan 0 akan dinormalisasi ke kode ini.
        'default_country_code' => env('API_DEFAULT_COUNTRY_CODE', '62'),
        'http_timeout' => (int) env('API_HTTP_TIMEOUT', '20'),
        // Versi Graph API yang dipakai adapter WhatsApp Cloud API.
        'whatsapp_api_version' => env('WHATSAPP_API_VERSION', 'v20.0'),
        'whatsapp_default_base' => env('WHATSAPP_API_BASE', 'https://graph.facebook.com'),
        // Base URL API Fonnte. Dapat ditimpa per koneksi untuk pengujian lokal.
        'fonnte_default_base' => env('FONNTE_API_BASE', 'https://api.fonnte.com'),
    ],
];
