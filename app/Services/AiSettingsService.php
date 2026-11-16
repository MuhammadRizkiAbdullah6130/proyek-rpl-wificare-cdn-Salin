<?php
/**
 * Service Pengaturan AI Chatbot (Admin) — WiFiCare CDN.
 *
 * Tanggung jawab:
 * - Membaca konfigurasi provider aktif (Gemini/DeepSeek/OpenAI) dari
 *   `ai_settings` + `ai_provider_keys`.
 * - Menyimpan konfigurasi dengan validasi server-side + enkripsi API key per
 *   provider (libsodium) dan audit log TANPA membocorkan rahasia.
 * - Menyediakan data AMAN untuk halaman Admin (tanpa API key / ciphertext).
 *
 * Kompatibilitas: key Gemini lama pada `ai_settings.api_key_encrypted` tetap
 * dibaca sebagai fallback. Bila baris `ai_settings` belum ada (instalasi lama),
 * konfigurasi .env dipakai seperti sebelumnya.
 */

declare(strict_types=1);

final class AiSettingsService
{
    /** Provider default bila .env/DB belum menentukan. */
    public const PROVIDER = 'gemini';

    public const DEFAULT_MODEL = 'gemini-2.5-flash';

    /**
     * Konfigurasi runtime efektif untuk provider aktif.
     *
     * @return array<string, mixed>
     */
    public static function runtime(): array
    {
        $row = AiSetting::get();
        $rowExists = is_array($row);
        $rowProvider = $rowExists ? strtolower(trim((string) ($row['provider'] ?? ''))) : '';

        // Baris dengan provider kosong = mode legacy/.env (dipakai juga oleh
        // harness offline untuk mengarahkan ke mock). Jangan pakai key DB.
        $legacyMode = !$rowExists || $rowProvider === '';

        if (!$legacyMode && AiProviderRegistry::has($rowProvider)) {
            $provider = $rowProvider;
        } else {
            $envProvider = strtolower(trim((string) config('ai.provider', '')));
            if (AiProviderRegistry::has($envProvider)) {
                $provider = $envProvider;
            } elseif (trim((string) config('ai.gemini_api_key', '')) !== '') {
                $provider = 'gemini';
            } elseif (trim((string) config('ai.api_key', '')) !== '') {
                $provider = 'openai';
            } else {
                $provider = self::PROVIDER;
            }
        }

        $config = self::providerConfig($provider, $legacyMode);

        // Status aktif: baris ada → ikuti is_enabled; belum ada → legacy .env.
        $enabled = $rowExists ? ((int) $row['is_enabled'] === 1) : true;

        return [
            'provider' => $provider,
            'enabled' => $enabled,
            'configured_in_db' => $rowExists,
            'legacy_mode' => $legacyMode,
            'hybrid_mode' => $rowExists ? (int) ($row['hybrid_mode'] ?? 1) === 1 : true,
            'local_db_enabled' => $rowExists ? (int) ($row['local_db_enabled'] ?? 1) === 1 : true,
            'prefer_local_db' => $rowExists ? (int) ($row['prefer_local_db'] ?? 1) === 1 : true,
            'fallback_enabled' => $rowExists ? (int) ($row['fallback_enabled'] ?? 1) === 1 : true,
            'ollama_enabled' => $rowExists ? (int) ($row['ollama_enabled'] ?? 0) === 1 : false,
            'ollama_model' => self::ollamaConfig()['model'],
            'ollama_base' => self::ollamaConfig()['base'],
            'ollama_timeout' => self::ollamaConfig()['timeout'],
            'key' => $config['key'],
            'key_source' => $config['key_source'],
            'has_stored_key' => $config['has_stored_key'],
            'model' => $config['model'],
            'base' => $config['base'],
            'max_output_tokens' => $rowExists
                ? self::clamp((int) $row['max_output_tokens'], 64, 8192)
                : 1500,
            'timeout' => $rowExists
                ? self::clamp((int) $row['timeout_seconds'], 5, 120)
                : self::clamp((int) config('ai.timeout', 25), 5, 120),
            'rate_limit' => $rowExists
                ? self::clamp((int) $row['rate_limit'], 1, 1000)
                : self::clamp((int) config('ai.rate_limit', 20), 1, 1000),
            'rate_window' => $rowExists
                ? self::clamp((int) $row['rate_window'], 10, 3600)
                : self::clamp((int) config('ai.rate_window', 60), 10, 3600),
            'daily_limit' => $rowExists
                ? self::clamp((int) ($row['daily_limit'] ?? 100), 1, 10000)
                : 100,
            'max_message' => $rowExists
                ? self::clamp((int) $row['max_message_length'], 100, 10000)
                : self::clamp((int) config('ai.max_message', 2000), 100, 10000),
            'system_instruction' => $rowExists ? trim((string) ($row['system_instruction'] ?? '')) : '',
        ];
    }

    /**
     * Kredensial & model untuk satu provider (TIDAK pernah dicetak ke log).
     *
     * @return array{key: string, model: string, base: string, key_source: string, has_stored_key: bool}
     */
    public static function providerConfig(string $provider, ?bool $legacyMode = null): array
    {
        $provider = AiProviderRegistry::has($provider) ? strtolower($provider) : self::PROVIDER;
        $adapter = AiProviderRegistry::get($provider);

        if ($legacyMode === null) {
            $row = AiSetting::get();
            $rowProvider = is_array($row) ? strtolower(trim((string) ($row['provider'] ?? ''))) : '';
            $legacyMode = !is_array($row) || $rowProvider === '';
        }

        $defaultModel = $adapter?->defaultModel() ?? self::DEFAULT_MODEL;
        $defaultBase = $adapter?->defaultBase() ?? 'https://generativelanguage.googleapis.com/v1beta';
        $row = AiSetting::get();

        $dbKey = '';
        $model = '';
        $base = '';

        if (!$legacyMode) {
            $stored = AiProviderKey::get($provider);
            if (is_array($stored)) {
                $decrypted = Crypto::decrypt(isset($stored['api_key_encrypted']) ? (string) $stored['api_key_encrypted'] : null);
                $dbKey = $decrypted !== null ? trim($decrypted) : '';
                $model = trim((string) ($stored['model'] ?? ''));
                $base = trim((string) ($stored['base_url'] ?? ''));
            }

            // Kompatibilitas key Gemini lama (sebelum multi-provider).
            if ($provider === 'gemini' && $dbKey === '' && is_array($row)) {
                $decrypted = Crypto::decrypt(isset($row['api_key_encrypted']) ? (string) $row['api_key_encrypted'] : null);
                $dbKey = $decrypted !== null ? trim($decrypted) : '';
                if ($model === '') {
                    $model = trim((string) ($row['model'] ?? ''));
                }
            }
        }

        $envKey = '';
        $envModel = '';
        $envBase = '';
        if ($provider === 'gemini') {
            $envKey = trim((string) config('ai.gemini_api_key', ''));
            $envModel = trim((string) config('ai.gemini_model', ''));
            $envBase = trim((string) config('ai.gemini_base_url', ''));
        } elseif ($provider === 'deepseek') {
            $envKey = trim((string) config('ai.deepseek_api_key', ''));
            $envModel = trim((string) config('ai.deepseek_model', ''));
            $envBase = trim((string) config('ai.deepseek_base_url', ''));
        } elseif ($provider === 'ollama') {
            // Ollama lokal tidak membutuhkan API key. Base URL HTTP hanya untuk
            // loopback; validasi dilakukan saat menyimpan pengaturan.
            $envKey = '';
            $envModel = trim((string) config('ai.ollama_model', ''));
            $envBase = trim((string) config('ai.ollama_base_url', ''));
        } else {
            $envKey = trim((string) config('ai.api_key', ''));
            $envModel = trim((string) config('ai.model', ''));
            $envBase = trim((string) config('ai.base_url', ''));
        }

        $key = $dbKey !== '' ? $dbKey : $envKey;

        return [
            'key' => $key,
            'model' => $model !== '' ? $model : ($envModel !== '' ? $envModel : $defaultModel),
            'base' => $base !== '' ? $base : ($envBase !== '' ? $envBase : $defaultBase),
            'key_source' => $dbKey !== '' ? 'database' : ($envKey !== '' ? 'env' : 'none'),
            'has_stored_key' => $dbKey !== '',
        ];
    }

    /**
     * Konfigurasi Ollama lokal (model/base/timeout) dari DB atau .env.
     *
     * @return array{model: string, base: string, timeout: int}
     */
    public static function ollamaConfig(): array
    {
        $config = self::providerConfig('ollama');

        return [
            'model' => (string) $config['model'],
            'base' => (string) $config['base'],
            'timeout' => self::clamp((int) config('ai.ollama_timeout', 30), 5, 120),
        ];
    }

    /**
     * Apakah asisten database lokal diaktifkan oleh Admin.
     */
    public static function isLocalDatabaseEnabled(): bool
    {
        return (bool) (self::runtime()['local_db_enabled'] ?? true);
    }

    /**
     * Apakah Ollama diaktifkan dan konfigurasinya lengkap.
     */
    public static function isOllamaConfigured(): bool
    {
        $runtime = self::runtime();
        $ollama = self::ollamaConfig();

        return (bool) ($runtime['ollama_enabled'] ?? false)
            && trim($ollama['model']) !== ''
            && trim($ollama['base']) !== '';
    }

    /**
     * Ketersediaan asisten tanpa memanggil provider. Ini bukan tes koneksi:
     * status "aktif" hanya berarti jalur jawaban lokal/cloud tersedia secara
     * konfigurasi, bukan bahwa provider sudah diuji.
     */
    public static function isAvailable(): bool
    {
        if (!self::isEnabled()) {
            return false;
        }

        return self::isConfigured() || self::isLocalDatabaseEnabled() || self::isOllamaConfigured();
    }

    /**
     * Status layanan untuk widget floating: active|disabled|unconfigured.
     */
    public static function state(): string
    {
        if (!self::isEnabled()) {
            return 'disabled';
        }

        // Local Database Assistant tetap tersedia tanpa API key cloud. Jangan
        // menampilkan "belum dikonfigurasi" bila jalur lokal sudah cukup.
        return self::isConfigured() || self::isLocalDatabaseEnabled() || self::isOllamaConfigured()
            ? 'active'
            : 'unconfigured';
    }

    public static function isEnabled(): bool
    {
        return self::runtime()['enabled'] === true;
    }

    public static function isConfigured(): bool
    {
        $runtime = self::runtime();

        return trim((string) $runtime['key']) !== ''
            && trim((string) $runtime['model']) !== ''
            && trim((string) $runtime['base']) !== '';
    }

    public static function hasStoredKey(): bool
    {
        return self::runtime()['has_stored_key'] === true;
    }

    public static function systemInstruction(): string
    {
        return (string) self::runtime()['system_instruction'];
    }

    public static function dailyLimit(): int
    {
        return max(1, (int) self::runtime()['daily_limit']);
    }

    /**
     * Data AMAN untuk halaman Admin (TIDAK memuat API key atau ciphertext).
     *
     * @return array<string, mixed>
     */
    public static function viewData(): array
    {
        $row = AiSetting::get();
        $runtime = self::runtime();

        $providers = [];
        foreach (AiProviderRegistry::metadata() as $id => $meta) {
            $config = self::providerConfig($id);
            $providers[$id] = array_merge($meta, [
                'model' => $config['model'],
                'base' => $config['base'],
                'has_stored_key' => $config['has_stored_key'],
                'key_source' => $config['key_source'],
                'configured' => $id === 'ollama'
                    ? ($config['model'] !== '' && $config['base'] !== '')
                    : ($config['key'] !== '' && $config['model'] !== '' && $config['base'] !== ''),
            ]);
        }

        return [
            'provider' => $runtime['provider'],
            'provider_label' => AiProviderRegistry::label((string) $runtime['provider']),
            'providers' => $providers,
            'model' => $runtime['model'],
            'default_model' => self::DEFAULT_MODEL,
            'is_enabled' => $runtime['enabled'],
            'has_stored_key' => $runtime['has_stored_key'],
            'key_source' => $runtime['key_source'],
            'libsodium_available' => Crypto::available(),
            'master_key_present' => Crypto::hasMasterKey(),
            'encryption_ready' => Crypto::isReady(),
            'max_output_tokens' => $runtime['max_output_tokens'],
            'timeout_seconds' => $runtime['timeout'],
            'rate_limit' => $runtime['rate_limit'],
            'rate_window' => $runtime['rate_window'],
            'daily_limit' => $runtime['daily_limit'],
            'max_message_length' => $runtime['max_message'],
            'system_instruction' => $runtime['system_instruction'],
            'hybrid_mode' => $runtime['hybrid_mode'],
            'local_db_enabled' => $runtime['local_db_enabled'],
            'prefer_local_db' => $runtime['prefer_local_db'],
            'fallback_enabled' => $runtime['fallback_enabled'],
            'ollama_enabled' => $runtime['ollama_enabled'],
            'ollama' => [
                'enabled' => $runtime['ollama_enabled'],
                'model' => $runtime['ollama_model'],
                'base' => $runtime['ollama_base'],
                'timeout' => $runtime['ollama_timeout'],
                'configured' => self::isOllamaConfigured(),
                'tested' => self::lastTestStatus('ollama'),
            ],
            'local_available' => self::isLocalDatabaseEnabled(),
            'cloud_configured' => self::isConfigured(),
            'last_tests' => [
                'gemini' => self::lastTestStatus('gemini'),
                'deepseek' => self::lastTestStatus('deepseek'),
                'openai' => self::lastTestStatus('openai'),
                'ollama' => self::lastTestStatus('ollama'),
                'mysql' => self::lastTestStatus('mysql-local'),
            ],
            'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
            'configured_in_db' => $runtime['configured_in_db'],
            'usage' => AiUsage::stats((int) $runtime['daily_limit']),
            'source_usage' => AiDataAccessLog::stats(30),
            'provider_monitor' => AiProviderMonitor::overview(30),
        ];
    }

    /**
     * Daftar model untuk provider aktif (saran datalist).
     *
     * @return array<int, string>
     */
    public static function suggestedModels(?string $provider = null): array
    {
        $provider = $provider !== null && $provider !== ''
            ? $provider
            : (string) self::runtime()['provider'];
        $adapter = AiProviderRegistry::get($provider);
        $models = [];
        foreach (array_merge($adapter?->suggestedModels() ?? [], [$adapter?->defaultModel() ?? self::DEFAULT_MODEL]) as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '' && !in_array($candidate, $models, true)) {
                $models[] = $candidate;
            }
        }

        $configured = trim((string) self::providerConfig($provider)['model']);
        if ($configured !== '' && !in_array($configured, $models, true)) {
            $models[] = $configured;
        }

        return $models;
    }

    /**
     * Validasi & simpan konfigurasi AI. Kunci API baru dienkripsi dan hanya
     * berlaku untuk provider yang dipilih; key provider lain dipertahankan.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input
     */
    public static function save(array $actor, array $input): void
    {
        $existing = AiSetting::get();
        $errors = [];

        $provider = strtolower(trim((string) ($input['provider'] ?? self::PROVIDER)));
        if (!AiProviderRegistry::has($provider)) {
            $errors['provider'] = 'Provider AI tidak dikenal.';
            $provider = self::PROVIDER;
        }

        $adapter = AiProviderRegistry::get($provider);
        $defaultModel = $adapter?->defaultModel() ?? self::DEFAULT_MODEL;
        $defaultBase = $adapter?->defaultBase() ?? 'https://generativelanguage.googleapis.com/v1beta';

        $model = trim((string) ($input['model'] ?? ''));
        if ($model === '') {
            $model = $defaultModel;
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{1,118}$/', $model) !== 1) {
            $errors['model'] = 'Format nama model tidak valid.';
        }

        $existingConfig = self::providerConfig($provider);
        $baseInput = trim((string) ($input['base_url'] ?? ''));
        $base = $baseInput !== '' ? $baseInput : (string) $existingConfig['base'];
        if ($base === '') {
            $base = $defaultBase;
        }
        if (filter_var($base, FILTER_VALIDATE_URL) === false
            || (!preg_match('#^https://#i', $base) && !($provider === 'ollama' && self::isLoopbackUrl($base)))) {
            $errors['base_url'] = $provider === 'ollama'
                ? 'Base URL Ollama harus HTTPS, atau HTTP khusus loopback/localhost.'
                : 'Base URL provider harus berupa URL HTTPS yang valid.';
        }

        $maxOutput = self::intField($input, 'max_output_tokens', 64, 8192);
        if ($maxOutput === null) {
            $errors['max_output_tokens'] = 'Batas output token harus berupa angka 64–8192.';
        }

        $timeout = self::intField($input, 'timeout_seconds', 5, 120);
        if ($timeout === null) {
            $errors['timeout_seconds'] = 'Timeout API harus berupa angka 5–120 detik.';
        }

        $rateLimit = self::intField($input, 'rate_limit', 1, 1000);
        if ($rateLimit === null) {
            $errors['rate_limit'] = 'Batas request per pengguna harus berupa angka 1–1000.';
        }

        $rateWindow = self::intField($input, 'rate_window', 10, 3600);
        if ($rateWindow === null) {
            $errors['rate_window'] = 'Jendela waktu rate limit harus 10–3600 detik.';
        }

        $dailyLimit = self::intField($input, 'daily_limit', 1, 10000);
        if ($dailyLimit === null && (!isset($input['daily_limit']) || (string) $input['daily_limit'] === '')) {
            $dailyLimit = isset($existing['daily_limit']) ? self::clamp((int) $existing['daily_limit'], 1, 10000) : 100;
        }
        if ($dailyLimit === null) {
            $errors['daily_limit'] = 'Batas request harian harus berupa angka 1–10000.';
        }

        $maxMessage = self::intField($input, 'max_message_length', 100, 10000);
        if ($maxMessage === null) {
            $errors['max_message_length'] = 'Batas panjang pesan harus 100–10000 karakter.';
        }

        $systemInstruction = trim((string) ($input['system_instruction'] ?? ''));
        if (mb_strlen($systemInstruction) > 2000) {
            $errors['system_instruction'] = 'System instruction maksimal 2000 karakter.';
        }

        $isEnabled = !empty($input['is_enabled']) ? 1 : 0;
        $hybridMode = self::flag($input, 'hybrid_mode', $existing, 1);
        $localDbEnabled = self::flag($input, 'local_db_enabled', $existing, 1);
        $preferLocalDb = self::flag($input, 'prefer_local_db', $existing, 1);
        $fallbackEnabled = self::flag($input, 'fallback_enabled', $existing, 1);
        $ollamaEnabled = self::flag($input, 'ollama_enabled', $existing, 0);

        // Konfigurasi Ollama terpisah dari provider cloud aktif. Model/base
        // disimpan pada ai_provider_keys[ollama] tanpa API key.
        $ollamaInputModel = trim((string) ($input['ollama_model'] ?? ''));
        $ollamaInputBase = trim((string) ($input['ollama_base_url'] ?? ''));
        $ollamaExisting = self::ollamaConfig();
        $ollamaModel = $ollamaInputModel !== '' ? $ollamaInputModel : $ollamaExisting['model'];
        $ollamaBase = $ollamaInputBase !== '' ? $ollamaInputBase : $ollamaExisting['base'];
        $saveOllama = $ollamaInputModel !== '' || $ollamaInputBase !== '';
        if ($ollamaInputModel !== ''
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{1,118}$/', $ollamaInputModel) !== 1) {
            $errors['ollama_model'] = 'Format nama model Ollama tidak valid.';
        }
        if ($saveOllama
            && (filter_var($ollamaBase, FILTER_VALIDATE_URL) === false
                || (!preg_match('#^https://#i', $ollamaBase) && !self::isLoopbackUrl($ollamaBase)))) {
            $errors['ollama_base_url'] = 'Base URL Ollama harus HTTPS, atau HTTP khusus loopback/localhost.';
        }

        $newKey = trim((string) ($input['api_key'] ?? ''));
        $keyChanged = false;
        if ($newKey !== '') {
            if (!Crypto::available()) {
                $errors['api_key'] = 'Ekstensi enkripsi (libsodium) tidak tersedia di server. API key tidak disimpan.';
            } elseif (!Crypto::hasMasterKey()) {
                $errors['api_key'] = 'Master key enkripsi belum disiapkan di server (AI_SETTINGS_KEY). API key tidak disimpan tanpa enkripsi.';
            } elseif (mb_strlen($newKey) < 10 || mb_strlen($newKey) > 300) {
                $errors['api_key'] = 'Panjang API key tidak wajar (10–300 karakter).';
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $encrypted = null;
        if ($newKey !== '') {
            $encrypted = Crypto::encrypt($newKey);
            $keyChanged = true;
        }

        // Legacy Gemini: pertahankan key lama pada ai_settings bila tidak diganti.
        $legacyKey = $existing['api_key_encrypted'] ?? null;
        if ($provider === 'gemini' && $encrypted !== null) {
            $legacyKey = $encrypted;
        }

        $fields = [
            'provider' => $provider,
            'hybrid_mode' => $hybridMode,
            'local_db_enabled' => $localDbEnabled,
            'prefer_local_db' => $preferLocalDb,
            'fallback_enabled' => $fallbackEnabled,
            'ollama_enabled' => $ollamaEnabled,
            'api_key_encrypted' => $legacyKey,
            'model' => $model,
            'is_enabled' => $isEnabled,
            'max_output_tokens' => (int) $maxOutput,
            'timeout_seconds' => (int) $timeout,
            'rate_limit' => (int) $rateLimit,
            'rate_window' => (int) $rateWindow,
            'daily_limit' => (int) $dailyLimit,
            'max_message_length' => (int) $maxMessage,
            'system_instruction' => $systemInstruction,
            'updated_by' => isset($actor['id']) ? (int) $actor['id'] : null,
        ];

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            AiSetting::save($fields);
            AiProviderKey::save(
                $provider,
                $encrypted,
                $model,
                $base,
                isset($actor['id']) ? (int) $actor['id'] : null
            );
            if ($saveOllama) {
                AiProviderKey::save(
                    'ollama',
                    null,
                    $ollamaModel,
                    $ollamaBase,
                    isset($actor['id']) ? (int) $actor['id'] : null
                );
            }

            $changes = self::diff($existing, $fields, $keyChanged);
            if ($changes !== []) {
                $changes['provider'] = $provider;
                AuditLog::record(
                    isset($actor['id']) ? (int) $actor['id'] : null,
                    'pengaturan_ai_disimpan',
                    'ai_settings',
                    null,
                    ['perubahan' => $changes]
                );
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        } finally {
            if ($newKey !== '') {
                $newKey = '';
            }
            AiSetting::forget();
            AiProviderKey::forget();
        }
    }

    /**
     * Status tes terakhir per provider, dibaca dari log metadata.
     *
     * @return array{status: string, label: string, tested_at: ?string, success: ?bool, code: string}
     */
    public static function lastTestStatus(string $provider): array
    {
        $empty = [
            'status' => 'untested',
            'label' => 'Belum diuji',
            'tested_at' => null,
            'success' => null,
            'code' => '',
        ];

        try {
            $statement = Database::readPdo()->prepare(
                'SELECT success, error_code, created_at
                 FROM ai_usage_log
                 WHERE provider = :provider AND request_type = :request_type
                 ORDER BY id DESC LIMIT 1'
            );
            $statement->execute(['provider' => $provider, 'request_type' => 'test']);
            $row = $statement->fetch();
        } catch (Throwable $exception) {
            return $empty;
        }

        if (!is_array($row)) {
            return $empty;
        }

        $success = (int) ($row['success'] ?? 0) === 1;

        return [
            'status' => $success ? 'success' : 'failed',
            'label' => ($success ? 'Teruji berhasil' : 'Teruji gagal')
                . ' • ' . format_waktu((string) ($row['created_at'] ?? '')),
            'tested_at' => (string) ($row['created_at'] ?? ''),
            'success' => $success,
            'code' => (string) ($row['error_code'] ?? ''),
        ];
    }

    /**
     * Tes nyata koneksi database read-only (SELECT 1). Tidak membaca data
     * pelanggan dan tidak menampilkan kredensial.
     *
     * @return array{ok: bool, code: string, message: string}
     */
    public static function testDatabaseConnection(): array
    {
        $startedAt = microtime(true);
        try {
            $pdo = Database::readPdo();
            $value = $pdo->query('SELECT 1')->fetchColumn();
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            if ((int) $value !== 1) {
                return ['ok' => false, 'code' => 'unexpected', 'message' => 'Koneksi database tidak mengembalikan hasil yang diharapkan.'];
            }

            return [
                'ok' => true,
                'code' => 'ok',
                'message' => 'Koneksi database read-only berhasil (' . $durationMs . ' ms).',
            ];
        } catch (Throwable $exception) {
            app_log('Tes koneksi database AI gagal: ' . $exception->getMessage());

            return [
                'ok' => false,
                'code' => 'database_error',
                'message' => 'Koneksi database read-only gagal. Periksa konfigurasi database.',
            ];
        }
    }

    /**
     * Uji koneksi provider tertentu memakai API sungguhan (bukan simulasi).
     *
     * @return array{ok: bool, code: string, message: string}
     */
    public static function testProvider(string $provider, ?string $overrideKey = null, ?string $overrideModel = null, ?string $overrideBase = null): array
    {
        $provider = AiProviderRegistry::has($provider) ? strtolower($provider) : '';
        $adapter = $provider !== '' ? AiProviderRegistry::get($provider) : null;
        if ($adapter === null) {
            return ['ok' => false, 'code' => 'invalid_provider', 'message' => 'Provider AI tidak dikenal.'];
        }

        $config = self::providerConfig($provider);

        $key = $overrideKey !== null && trim($overrideKey) !== ''
            ? trim($overrideKey)
            : trim((string) $config['key']);
        if ($key === '' && $provider !== 'ollama') {
            return ['ok' => false, 'code' => 'no_key', 'message' => 'Belum ada API key yang dapat diuji. Masukkan atau simpan API key terlebih dahulu.'];
        }

        $model = $overrideModel !== null && trim($overrideModel) !== ''
            ? trim($overrideModel)
            : trim((string) $config['model']);
        $base = $overrideBase !== null && trim($overrideBase) !== ''
            ? trim($overrideBase)
            : trim((string) $config['base']);
        if ($model === '' || $base === '') {
            return ['ok' => false, 'code' => 'incomplete', 'message' => 'Model atau base URL provider belum dikonfigurasi.'];
        }

        $timeout = $provider === 'ollama'
            ? self::clamp((int) self::ollamaConfig()['timeout'], 5, 30)
            : self::clamp((int) self::runtime()['timeout'], 5, 30);

        $result = $adapter->testConnection($key, $model, $base, $timeout);
        if (empty($result['ok'])) {
            AiProviderMonitor::recordFailure($provider, $model, $result);
        }

        return $result;
    }

    /**
     * Kompatibilitas API lama: uji provider aktif (default Gemini bila legacy).
     *
     * @return array{ok: bool, code: string, message: string}
     */
    public static function testConnection(?string $overrideKey = null, ?string $overrideModel = null, ?string $provider = null): array
    {
        $provider = $provider !== null && $provider !== ''
            ? $provider
            : (string) self::runtime()['provider'];

        return self::testProvider($provider, $overrideKey, $overrideModel);
    }

    /**
     * Baca flag checkbox dengan mempertahankan nilai lama bila field tidak
     * dikirim (kompatibilitas form/API lama).
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $existing
     */
    private static function flag(array $input, string $key, ?array $existing, int $default): int
    {
        if (array_key_exists($key, $input)) {
            return !empty($input[$key]) ? 1 : 0;
        }

        return isset($existing[$key]) ? ((int) $existing[$key] === 1 ? 1 : 0) : $default;
    }

    /**
     * HTTP hanya boleh untuk loopback/localhost (Ollama lokal).
     */
    private static function isLoopbackUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'http') {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_starts_with($host, '127.');
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function intField(array $input, string $key, int $min, int $max): ?int
    {
        $raw = $input[$key] ?? null;
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return null;
        }

        $value = (int) $raw;
        if ($value < $min || $value > $max) {
            return null;
        }

        return $value;
    }

    private static function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }

    /**
     * Selisih konfigurasi untuk audit log (TANPA nilai rahasia).
     *
     * @param array<string, mixed>|null $existing
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private static function diff(?array $existing, array $fields, bool $keyChanged): array
    {
        $changes = [];

        if ($keyChanged) {
            $changes['api_key'] = 'diganti';
        }

        $columns = [
            'provider',
            'hybrid_mode',
            'local_db_enabled',
            'prefer_local_db',
            'fallback_enabled',
            'ollama_enabled',
            'model',
            'is_enabled',
            'max_output_tokens',
            'timeout_seconds',
            'rate_limit',
            'rate_window',
            'daily_limit',
            'max_message_length',
            'system_instruction',
        ];

        foreach ($columns as $column) {
            $before = $existing[$column] ?? null;
            $after = $fields[$column] ?? null;
            if ((string) $before !== (string) $after) {
                $changes[$column] = $after;
            }
        }

        return $changes;
    }
}
