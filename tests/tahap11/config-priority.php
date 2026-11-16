<?php
/**
 * WiFiCare CDN — Tahap 11: uji prioritas konfigurasi AI (CLI).
 *
 * Memverifikasi kebijakan prioritas yang didokumentasikan:
 * - Tanpa baris `ai_settings`: perilaku lama `.env` (AI_PROVIDER) dipertahankan
 *   (kompatibilitas OpenAI-compatible), provider dipilih dari environment.
 * - Baris `ai_settings` ada: `is_enabled` mengontrol status; Nonaktif
 *   mengabaikan `.env`; key tersimpan (terenkripsi) menang atas `.env`.
 *
 * Penggunaan:
 *   $env:DB_NAME='wificare_cdn_test'; $env:APP_ENV='testing'; php tests/tahap11/config-priority.php
 */

declare(strict_types=1);

require __DIR__ . '/../../app/bootstrap.php';

$PASS = 0;
$FAIL = 0;
$FAILED = [];

function check(string $name, bool $ok, string $detail = ''): void
{
    global $PASS, $FAIL, $FAILED;
    if ($ok) {
        $PASS++;
        echo "PASS  {$name}\n";
    } else {
        $FAIL++;
        $FAILED[] = $name . ($detail !== '' ? " :: {$detail}" : '');
        echo "FAIL  {$name}" . ($detail !== '' ? " :: {$detail}" : '') . "\n";
    }
}

echo "==== TAHAP 11 — PRIORITAS KONFIGURASI AI (CLI) ====\n";

// Simpan baris konfigurasi yang ada agar pengujian TIDAK merusak data nyata.
// Uji dijalankan pada kondisi bersih, lalu baris asli dipulihkan saat berakhir
// (termasuk bila uji berhenti karena kegagalan).
$__aiBackup = null;
$__providerKeysBackup = [];
try {
    $__row = Database::pdo()->query('SELECT * FROM ai_settings ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $__aiBackup = is_array($__row) ? $__row : null;
    $__providerKeysBackup = Database::pdo()->query('SELECT * FROM ai_provider_keys')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $__aiBackup = null;
    $__providerKeysBackup = [];
}
register_shutdown_function(static function () use ($__aiBackup, $__providerKeysBackup): void {
    try {
        if (is_array($__aiBackup) && $__aiBackup !== []) {
            Database::pdo()->exec('DELETE FROM ai_settings');
            $cols = array_keys($__aiBackup);
            $quoted = array_map(static fn (string $c): string => '`' . $c . '`', $cols);
            $params = array_map(static fn (string $c): string => ':' . $c, $cols);
            Database::pdo()->prepare('INSERT INTO ai_settings (' . implode(',', $quoted) . ') VALUES (' . implode(',', $params) . ')')
                ->execute($__aiBackup);
        }
        Database::pdo()->exec('DELETE FROM ai_provider_keys');
        if (is_array($__providerKeysBackup) && $__providerKeysBackup !== []) {
            $cols = array_keys($__providerKeysBackup[0]);
            $quoted = array_map(static fn (string $c): string => '`' . $c . '`', $cols);
            $params = array_map(static fn (string $c): string => ':' . $c, $cols);
            $insert = Database::pdo()->prepare(
                'INSERT INTO ai_provider_keys (' . implode(',', $quoted) . ') VALUES (' . implode(',', $params) . ')'
            );
            foreach ($__providerKeysBackup as $providerRow) {
                $insert->execute($providerRow);
            }
        }
        AiSetting::forget();
        AiProviderKey::forget();
    } catch (Throwable $e) {
        // Pemulihan best-effort.
    }
});

// Kondisi awal deterministik: hapus baris konfigurasi dan kunci provider agar
// key legacy pada ai_settings benar-benar diuji tanpa tertutup cache/baris lain.
Database::pdo()->exec('DELETE FROM ai_settings');
Database::pdo()->exec('DELETE FROM ai_provider_keys');
AiSetting::forget();
AiProviderKey::forget();

$originalAi = $GLOBALS['app_config']['ai'] ?? [];

// --- A. Tanpa baris: legacy .env dipertahankan ---------------------------------
$GLOBALS['app_config']['ai'] = array_merge($originalAi, [
    'provider' => 'gemini',
    'gemini_api_key' => 'ENV-GEMINI-KEY-abcdefghijklmnop',
    'gemini_model' => 'gemini-3.5-flash',
    'gemini_base_url' => 'https://generativelanguage.googleapis.com/v1beta',
]);
AiSetting::forget();
check('A1. tanpa baris → provider dari .env (gemini)', AiAssistantService::provider() === 'gemini');
check('A2. tanpa baris → ai_settings belum terkonfigurasi', AiSettingsService::runtime()['configured_in_db'] === false);
check('A3. tanpa baris → sumber kunci = env', AiSettingsService::runtime()['key_source'] === 'env');
check('A4. tanpa baris → layanan aktif (legacy)', AiSettingsService::isEnabled() === true);
check('A5. tanpa baris → state aktif', AiSettingsService::state() === 'active');

// --- B. Tanpa baris: provider openai dihormati (kompatibilitas) ----------------
$GLOBALS['app_config']['ai'] = array_merge($originalAi, [
    'provider' => 'openai',
    'api_key' => 'ENV-OPENAI-KEY-abcdefghijklmnop',
    'model' => 'openai-mock-model',
    'base_url' => 'http://localhost:8097/v1',
]);
AiSetting::forget();
check('B1. tanpa baris → provider openai dihormati', AiAssistantService::provider() === 'openai');
$creds = AiAssistantService::credentials();
check('B2. tanpa baris openai → kredensial dari config openai', $creds['key'] === 'ENV-OPENAI-KEY-abcdefghijklmnop' && $creds['model'] === 'openai-mock-model');
check('B3. tanpa baris openai → terkonfigurasi', AiSettingsService::isConfigured() === true);

// --- C. Baris Nonaktif mengabaikan .env ---------------------------------------
$GLOBALS['app_config']['ai'] = array_merge($originalAi, [
    'provider' => 'gemini',
    'gemini_api_key' => 'ENV-GEMINI-KEY-abcdefghijklmnop',
    'gemini_model' => 'gemini-3.5-flash',
    'gemini_base_url' => 'https://generativelanguage.googleapis.com/v1beta',
]);
AiSetting::save([
    'provider' => 'gemini',
    'api_key_encrypted' => null,
    'model' => 'gemini-2.5-flash',
    'is_enabled' => 0,
    'max_output_tokens' => 1200,
    'timeout_seconds' => 30,
    'rate_limit' => 15,
    'rate_window' => 60,
    'max_message_length' => 1500,
    'system_instruction' => '',
    'updated_by' => 1,
]);
AiSetting::forget();
check('C1. baris ada → configured_in_db true', AiSettingsService::runtime()['configured_in_db'] === true);
check('C2. Nonaktif → layanan nonaktif meski .env berisi key', AiSettingsService::isEnabled() === false);
check('C3. Nonaktif → state=disabled', AiSettingsService::state() === 'disabled');

// --- D. Baris aktif dengan key tersimpan > .env -------------------------------
$stored = 'STORED-KEY-abcdefghijklmnop-0123456789';
AiSetting::save([
    'provider' => 'gemini',
    'api_key_encrypted' => Crypto::encrypt($stored),
    'model' => 'gemini-2.5-flash',
    'is_enabled' => 1,
    'max_output_tokens' => 1200,
    'timeout_seconds' => 30,
    'rate_limit' => 15,
    'rate_window' => 60,
    'max_message_length' => 1500,
    'system_instruction' => '',
    'updated_by' => 1,
]);
AiSetting::forget();
$runtime = AiSettingsService::runtime();
check('D1. Aktif + key tersimpan → status aktif', AiSettingsService::isEnabled() === true);
check('D2. Aktif → sumber kunci = database', $runtime['key_source'] === 'database');
check('D3. Kunci tersimpan menang atas .env', $runtime['key'] === $stored);
check('D4. Model dari tabel dipakai', $runtime['model'] === 'gemini-2.5-flash');
check('D5. Batas dari tabel dipakai', $runtime['rate_limit'] === 15 && $runtime['max_output_tokens'] === 1200);
check('D6. Kredensial layanan memakai key tersimpan', AiAssistantService::credentials()['key'] === $stored);

// --- Bersihkan -----------------------------------------------------------------
Database::pdo()->exec('DELETE FROM ai_settings');
AiSetting::forget();
$GLOBALS['app_config']['ai'] = $originalAi;

echo "----------------------------------------------------------------\n";
echo "TOTAL: " . ($PASS + $FAIL) . " | PASS: {$PASS} | FAIL: {$FAIL}\n";
if ($FAIL > 0) {
    echo "GAGAL:\n";
    foreach ($FAILED as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}
echo "SEMUA TES PRIORITAS KONFIGURASI LULUS.\n";
exit(0);
