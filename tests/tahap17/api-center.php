<?php
/**
 * WiFiCare CDN — Tahap 17: Pusat Konfigurasi API & Monitoring.
 *
 * Menguji:
 * - enkripsi/replace/revoke kredensial dan larangan plaintext;
 * - pilihan koneksi bersama + mapping per fitur;
 * - OTP sekali pakai, terikat akun/purpose/nomor, batas dan kedaluwarsa;
 * - antrean notifikasi idempoten, accepted ≠ delivered, webhook signature;
 * - monitoring nyata AI + WhatsApp dan filter;
 * - RBAC, CSRF, audit tanpa secret, serta secret tidak muncul di HTML/JSON.
 *
 * Prasyarat HTTP: server uji DB test di http://localhost:8099.
 * Prasyarat mock: php -S 127.0.0.1:8098 tests/tahap17/mock-whatsapp.php
 *
 * Jalankan:
 *   php tests/tahap17/api-center.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../tahap10/_config.php';
t10_require_passwords(['T10_PW_ADMIN', 'T10_PW_CS', 'T10_PW_TEK']);

// Harness ini WAJIB memakai DB uji, bukan DB utama.
putenv('DB_NAME=wificare_cdn_test');
$_ENV['DB_NAME'] = 'wificare_cdn_test';
$_SERVER['DB_NAME'] = 'wificare_cdn_test';

require_once __DIR__ . '/../../app/bootstrap.php';

$PASS = 0;
$FAIL = 0;
$SKIP = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $PASS, $FAIL;
    if ($ok) {
        $PASS++;
        echo "PASS  {$name}\n";

        return;
    }
    $FAIL++;
    echo "FAIL  {$name}" . ($detail !== '' ? " :: {$detail}" : '') . "\n";
}

function skip(string $name, string $reason): void
{
    global $SKIP;
    $SKIP++;
    echo "SKIP  {$name} :: {$reason}\n";
}

function section(string $title): void
{
    echo "\n=== {$title} ===\n";
}

/** @return array{0:int,1:string} status, body */
function http_call(
    string $method,
    string $path,
    ?string $jar = null,
    array $form = [],
    string $rawBody = '',
    array $headers = []
): array {
    $ch = curl_init(T10_BASE . $path);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
    ];
    if ($jar !== null) {
        $options[CURLOPT_COOKIEJAR] = $jar;
        $options[CURLOPT_COOKIEFILE] = $jar;
    }
    if (strtoupper($method) === 'POST') {
        $options[CURLOPT_POST] = true;
        if ($rawBody !== '') {
            $options[CURLOPT_POSTFIELDS] = $rawBody;
        } else {
            $options[CURLOPT_POSTFIELDS] = http_build_query($form);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
    }
    if ($headers !== []) {
        $options[CURLOPT_HTTPHEADER] = $headers;
    }
    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $error !== '') {
        return [0, ''];
    }

    return [$status, (string) $body];
}

function csrf_from(string $html): string
{
    if (preg_match('/name="_token"\s+value="([a-f0-9]{64})"/', $html, $m) === 1) {
        return $m[1];
    }
    if (preg_match('/name="csrf-token"\s+content="([a-f0-9]{64})"/', $html, $m) === 1) {
        return $m[1];
    }

    return '';
}

/** @return array{0:string,1:string} jar, html */
function login(string $tag, string $username, string $password): array
{
    $jar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_t17_' . $tag . '_' . getmypid() . '.jar';
    @unlink($jar);
    [$status, $html] = http_call('GET', '/login', $jar);
    if ($status !== 200) {
        return [$jar, ''];
    }
    $token = csrf_from($html);
    [$postStatus] = http_call('POST', '/login', $jar, [
        '_token' => $token,
        'username' => $username,
        'password' => $password,
    ]);

    return [$jar, $postStatus === 302 ? 'ok' : ''];
}

function exception_message(callable $fn): string
{
    try {
        $fn();

        return '';
    } catch (Throwable $exception) {
        return $exception->getMessage();
    }
}

/** @return array<int, array<string, mixed>> */
function json_lines(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $rows = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $rows[] = $decoded;
        }
    }

    return $rows;
}

function otp_code_from_log(string $path): string
{
    $rows = json_lines($path);
    for ($i = count($rows) - 1; $i >= 0; $i--) {
        $payload = $rows[$i]['payload'] ?? null;
        if (!is_array($payload)) {
            continue;
        }
        $body = (string) ($payload['text']['body'] ?? '');
        if ($body !== '' && preg_match('/\b(\d{6})\b/', $body, $m) === 1) {
            return $m[1];
        }

        $components = $payload['template']['components'] ?? [];
        if (!is_array($components)) {
            continue;
        }
        foreach ($components as $component) {
            if (($component['type'] ?? '') !== 'body') {
                continue;
            }
            $code = (string) ($component['parameters'][0]['text'] ?? '');
            if (preg_match('/^\d{6}$/', $code) === 1) {
                return $code;
            }
        }
    }

    return '';
}

$mockLog = getenv('WA_MOCK_LOG') ?: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_wa_mock.log';
@file_put_contents($mockLog, '');

$pdo = Database::pdo();
check('Database uji terhubung', (string) db_config('database') === 'wificare_cdn_test', (string) db_config('database'));

// Bersihkan HANYA tabel baru milik Tahap 17 di DB uji.
$pdo->exec('DELETE FROM whatsapp_outbox');
$pdo->exec('DELETE FROM whatsapp_otp');
$pdo->exec('DELETE FROM api_usage_log');
$pdo->exec('DELETE FROM api_integration_settings');
$pdo->exec('DELETE FROM api_config_audit');
$pdo->exec('DELETE FROM api_credentials');

$admin = $pdo->query("SELECT id, username, nama, role FROM users WHERE username = 'admin.budi' LIMIT 1")->fetch();
$cs = $pdo->query("SELECT id, username, nama, role FROM users WHERE username = 'cs.dewi' LIMIT 1")->fetch();
$tek = $pdo->query("SELECT id, username, nama, role FROM users WHERE username = 'tek.andi' LIMIT 1")->fetch();
$customer = $pdo->query(
    "SELECT u.id, u.username, u.nama, u.role, p.id AS pelanggan_id, p.username_pppoe
     FROM users u JOIN pelanggan p ON p.user_id = u.id
     WHERE u.username = 'pppoe.rina' LIMIT 1"
)->fetch();
check('Akun uji tersedia', is_array($admin) && is_array($cs) && is_array($tek) && is_array($customer));

section('Enkripsi & Kredensial');
check('libsodium tersedia', Crypto::available());
check('Master key tersedia di environment', Crypto::hasMasterKey());

$token = 'test-token-primer';
$credentialId = ApiCredentialService::saveWhatsApp($admin, [
    'name' => 'Mock Operasional',
    'provider' => 'meta_cloud',
    'access_token' => $token,
    'business_account_id' => 'waba-mock-1',
    'phone_number_id' => '123456789',
    'sender_number' => '6281234567890',
    'base_url' => 'http://127.0.0.1:8098/v20.0',
    'webhook_verify_token' => 'verify-token-123',
    'webhook_secret' => 'secret-token-123',
    'is_active' => '1',
]);
check('Kredensial baru tersimpan', $credentialId > 0);

$credential = ApiCredential::find($credentialId);
$cipher = (string) ($credential['secret_encrypted'] ?? '');
check('Secret tersimpan sebagai ciphertext v1', str_starts_with($cipher, 'v1:'));
check('Secret plaintext tidak ada di database', !str_contains($cipher, $token));
$safeJson = json_encode(ApiCredential::safe($credential));
check('Data aman tidak membawa ciphertext/plaintext', !str_contains((string) $safeJson, 'v1:') && !str_contains((string) $safeJson, $token));

// Simulasi master key hilang: penyimpanan secret baru wajib ditolak.
$savedAiKey = $GLOBALS['app_config']['ai_settings']['encryption_key'] ?? '';
$savedApiKey = $GLOBALS['app_config']['api_credentials']['encryption_key'] ?? '';
$GLOBALS['app_config']['ai_settings']['encryption_key'] = '';
$GLOBALS['app_config']['api_credentials']['encryption_key'] = '';
$before = (int) $pdo->query('SELECT COUNT(*) FROM api_credentials')->fetchColumn();
$error = exception_message(static function () use ($admin): void {
    ApiCredentialService::saveWhatsApp($admin, [
        'name' => 'Tanpa kunci',
        'provider' => 'meta_cloud',
        'access_token' => 'test-token-tanpa-kunci',
        'phone_number_id' => '987654321',
        'base_url' => 'http://127.0.0.1:8098/v20.0',
    ]);
});
$after = (int) $pdo->query('SELECT COUNT(*) FROM api_credentials')->fetchColumn();
check('Simpan secret ditolak saat master key hilang', $error !== '' && $before === $after);
$GLOBALS['app_config']['ai_settings']['encryption_key'] = $savedAiKey;
$GLOBALS['app_config']['api_credentials']['encryption_key'] = $savedApiKey;

$test = ApiCredentialService::testWhatsApp($admin, $credentialId);
check('Tes koneksi nyata ke mock berhasil', !empty($test['ok']));
check('Tes koneksi tidak mengembalikan token', !str_contains(json_encode($test), $token));

// Edit dengan token kosong harus mempertahankan secret lama.
ApiCredentialService::saveWhatsApp($admin, [
    'id' => $credentialId,
    'name' => 'Mock Operasional Diperbarui',
    'provider' => 'meta_cloud',
    'access_token' => '',
    'business_account_id' => 'waba-mock-1',
    'phone_number_id' => '123456789',
    'sender_number' => '6281234567890',
    'base_url' => 'http://127.0.0.1:8098/v20.0',
    'webhook_verify_token' => '',
    'webhook_secret' => '',
]);
$updated = ApiCredential::find($credentialId);
check('Nama kredensial berubah', ($updated['name'] ?? '') === 'Mock Operasional Diperbarui');
check('Input token kosong mempertahankan secret lama', Crypto::decrypt((string) ($updated['secret_encrypted'] ?? '')) === $token);
check('Input webhook kosong mempertahankan verify token', (ApiCredentialService::connection($credentialId)['webhook_verify_token'] ?? '') === 'verify-token-123');

// Ganti token secara aman.
ApiCredentialService::saveWhatsApp($admin, [
    'id' => $credentialId,
    'name' => 'Mock Operasional Diperbarui',
    'provider' => 'meta_cloud',
    'access_token' => 'test-token-rotasi',
    'business_account_id' => 'waba-mock-1',
    'phone_number_id' => '123456789',
    'sender_number' => '6281234567890',
    'base_url' => 'http://127.0.0.1:8098/v20.0',
    'webhook_verify_token' => '',
    'webhook_secret' => '',
]);
$replaced = ApiCredentialService::connection($credentialId);
check('Replace token menghasilkan token baru', ($replaced['token'] ?? '') === 'test-token-rotasi');
check('Replace token tidak menyisakan token lama', !str_contains((string) ApiCredential::find($credentialId)['secret_encrypted'], $token));
// Kembalikan token yang dipakai mock.
ApiCredentialService::saveWhatsApp($admin, [
    'id' => $credentialId,
    'name' => 'Mock Operasional',
    'provider' => 'meta_cloud',
    'access_token' => $token,
    'business_account_id' => 'waba-mock-1',
    'phone_number_id' => '123456789',
    'sender_number' => '6281234567890',
    'base_url' => 'http://127.0.0.1:8098/v20.0',
    'webhook_verify_token' => '',
    'webhook_secret' => '',
]);

$invalid = exception_message(static function () use ($admin): void {
    ApiCredentialService::saveWhatsApp($admin, [
        'name' => 'URL tidak aman',
        'provider' => 'meta_cloud',
        'access_token' => 'test-token-x',
        'phone_number_id' => '111222333',
        'base_url' => 'http://example.com/v20.0',
    ]);
});
check('Base URL HTTP non-localhost ditolak', $invalid !== '');

$badProvider = exception_message(static function () use ($admin): void {
    ApiCredentialService::saveWhatsApp($admin, [
        'name' => 'Provider liar',
        'provider' => 'provider_tidak_ada',
        'access_token' => 'test-token-x',
        'phone_number_id' => '111222333',
        'base_url' => 'http://127.0.0.1:8098/v20.0',
    ]);
});
check('Provider di luar allowlist ditolak', $badProvider !== '');

// Koneksi kedua + aktivasi + pencabutan.
$secondId = ApiCredentialService::saveWhatsApp($admin, [
    'name' => 'Mock Cadangan',
    'provider' => 'meta_cloud',
    'access_token' => 'test-token-cadangan',
    'phone_number_id' => '987654321',
    'sender_number' => '6281234567891',
    'base_url' => 'http://127.0.0.1:8098/v20.0',
    'webhook_verify_token' => 'verify-cadangan-123',
    'webhook_secret' => 'secret-cadangan-123',
]);
ApiCredentialService::setActive($admin, $secondId);
check('Pemilihan koneksi aktif berubah', (int) (ApiCredential::active()['id'] ?? 0) === $secondId);
ApiCredentialService::setActive($admin, $credentialId);
ApiCredentialService::revoke($admin, $secondId);
$revoked = ApiCredential::find($secondId);
check('Revoke menghapus secret', ($revoked['secret_encrypted'] ?? null) === null);
check('Revoke menandai kredensial', ($revoked['revoked_at'] ?? null) !== null);
check('Kredensial dicabut tidak bisa dites', exception_message(static fn () => ApiCredentialService::testWhatsApp($admin, $secondId)) !== '');

section('Pengaturan & Integrasi AI');
$gatewayError = exception_message(static function () use ($admin, $credentialId): void {
    WhatsAppSettingsService::saveGateway($admin, [
        'is_enabled' => '1',
        'connection_id' => (string) $credentialId,
        'default_country_code' => '62',
        'daily_limit' => '100',
        'retry_max' => '3',
        'timeout' => '20',
    ]);
});
check('Gateway dapat diaktifkan dengan kredensial', $gatewayError === '' && ApiIntegrationSetting::isEnabled(ApiFeatureRegistry::WHATSAPP_GATEWAY));

$providers = array_keys((array) (AiSettingsService::viewData()['providers'] ?? []));
check(
    'AI tetap multi-provider (Gemini/DeepSeek/OpenAI + Ollama opsional)',
    array_slice($providers, 0, 3) === ['gemini', 'deepseek', 'openai']
        && in_array('ollama', $providers, true)
);

section('Konfigurasi OTP');
$otpError = exception_message(static function () use ($admin, $credentialId): void {
    WhatsAppSettingsService::saveOtp($admin, [
        'is_enabled' => '1',
        'connection_id' => (string) $credentialId,
        'template' => 'Kode OTP WiFiCare CDN Anda adalah {kode}. Berlaku {masa_berlaku} menit. Jangan bagikan kode ini.',
        'ttl_seconds' => '300',
        'max_attempts' => '5',
        'resend_cooldown' => '0',
        'daily_user_limit' => '100',
        'daily_phone_limit' => '100',
        'send_mode' => 'template',
        'template_name' => 'wificare_otp',
        'language_code' => 'id',
        'purposes' => ['verifikasi_nomor' => '1', 'reset_password' => '1'],
    ]);
});
check('Konfigurasi OTP tersimpan', $otpError === '' && ApiIntegrationSetting::isEnabled(ApiFeatureRegistry::WHATSAPP_OTP));
check('Koneksi OTP mengikuti mapping', ApiIntegrationSetting::connectionId(ApiFeatureRegistry::WHATSAPP_OTP) === $credentialId);

$otpBefore = WhatsAppOtpService::monitoring();
@file_put_contents($mockLog, '');
$request = WhatsAppOtpService::request($admin, 'verifikasi_nomor', '6281234567890');
$code = otp_code_from_log($mockLog);
$otpRow = $pdo->query('SELECT * FROM whatsapp_otp ORDER BY id DESC LIMIT 1')->fetch();
$otpAfter = WhatsAppOtpService::monitoring();
check('Permintaan OTP berhasil dikirim', !empty($request['ok']) && $code !== '');
check('OTP requested bertambah', (int) $otpAfter['requested'] === (int) $otpBefore['requested'] + 1);
check('OTP sent bertambah', (int) $otpAfter['sent'] === (int) $otpBefore['sent'] + 1);
check('Kode OTP tidak disimpan plaintext', ($otpRow['code_hash'] ?? '') !== $code && !str_contains((string) ($otpRow['code_hash'] ?? ''), $code));
check('Nomor OTP tidak disimpan plaintext', !str_contains((string) ($otpRow['phone_fingerprint'] ?? ''), '6281234567890') && strlen((string) ($otpRow['phone_fingerprint'] ?? '')) === 64);

$wrong = WhatsAppOtpService::verify($admin, 'verifikasi_nomor', '6281234567890', '000000');
$good = WhatsAppOtpService::verify($admin, 'verifikasi_nomor', '6281234567890', $code);
$reuse = WhatsAppOtpService::verify($admin, 'verifikasi_nomor', '6281234567890', $code);
check('Kode OTP salah ditolak', empty($wrong['ok']));
check('Kode OTP benar diterima', !empty($good['ok']));
check('OTP sekali pakai (percobaan kedua ditolak)', empty($reuse['ok']));

// OTP terikat nomor tujuan.
@file_put_contents($mockLog, '');
$requestPhone = WhatsAppOtpService::request($admin, 'reset_password', '6281234567892');
$codePhone = otp_code_from_log($mockLog);
$wrongPhone = WhatsAppOtpService::verify($admin, 'reset_password', '6281234567890', $codePhone);
$rightPhone = WhatsAppOtpService::verify($admin, 'reset_password', '6281234567892', $codePhone);
check('OTP terikat nomor tujuan', empty($wrongPhone['ok']) && !empty($rightPhone['ok']));

// OTP terikat purpose.
@file_put_contents($mockLog, '');
WhatsAppOtpService::request($admin, 'reset_password', '6281234567893');
$codePurpose = otp_code_from_log($mockLog);
$wrongPurpose = WhatsAppOtpService::verify($admin, 'verifikasi_nomor', '6281234567893', $codePurpose);
$rightPurpose = WhatsAppOtpService::verify($admin, 'reset_password', '6281234567893', $codePurpose);
check('OTP terikat purpose', empty($wrongPurpose['ok']) && !empty($rightPurpose['ok']));

// Kedaluwarsa.
WhatsAppSettingsService::saveOtp($admin, [
    'is_enabled' => '1', 'connection_id' => (string) $credentialId,
    'template' => 'Kode OTP WiFiCare CDN Anda adalah {kode}. Berlaku {masa_berlaku} menit. Jangan bagikan kode ini.',
    'ttl_seconds' => '60', 'max_attempts' => '5', 'resend_cooldown' => '0',
    'daily_user_limit' => '100', 'daily_phone_limit' => '100',
    'send_mode' => 'template', 'template_name' => 'wificare_otp', 'language_code' => 'id',
    'purposes' => ['verifikasi_nomor' => '1', 'reset_password' => '1'],
]);
@file_put_contents($mockLog, '');
WhatsAppOtpService::request($admin, 'verifikasi_nomor', '6281234567894');
$codeExpired = otp_code_from_log($mockLog);
$pdo->exec("UPDATE whatsapp_otp SET expires_at = DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 SECOND) WHERE id = (SELECT id FROM (SELECT id FROM whatsapp_otp ORDER BY id DESC LIMIT 1) x)");
$expired = WhatsAppOtpService::verify($admin, 'verifikasi_nomor', '6281234567894', $codeExpired);
check('OTP kedaluwarsa ditolak', empty($expired['ok']) && ($expired['code'] ?? '') === 'expired');

// Batas percobaan.
WhatsAppSettingsService::saveOtp($admin, [
    'is_enabled' => '1', 'connection_id' => (string) $credentialId,
    'template' => 'Kode OTP WiFiCare CDN Anda adalah {kode}. Berlaku {masa_berlaku} menit. Jangan bagikan kode ini.',
    'ttl_seconds' => '300', 'max_attempts' => '2', 'resend_cooldown' => '0',
    'daily_user_limit' => '100', 'daily_phone_limit' => '100',
    'send_mode' => 'template', 'template_name' => 'wificare_otp', 'language_code' => 'id',
    'purposes' => ['verifikasi_nomor' => '1', 'reset_password' => '1'],
]);
@file_put_contents($mockLog, '');
WhatsAppOtpService::request($admin, 'verifikasi_nomor', '6281234567895');
$firstWrong = WhatsAppOtpService::verify($admin, 'verifikasi_nomor', '6281234567895', '000000');
$secondWrong = WhatsAppOtpService::verify($admin, 'verifikasi_nomor', '6281234567895', '000000');
check('Batas percobaan OTP ditegakkan', empty($firstWrong['ok']) && empty($secondWrong['ok']) && ($secondWrong['code'] ?? '') === 'max_attempts');

// Batas per nomor/hari.
WhatsAppSettingsService::saveOtp($admin, [
    'is_enabled' => '1', 'connection_id' => (string) $credentialId,
    'template' => 'Kode OTP WiFiCare CDN Anda adalah {kode}. Berlaku {masa_berlaku} menit. Jangan bagikan kode ini.',
    'ttl_seconds' => '300', 'max_attempts' => '5', 'resend_cooldown' => '0',
    'daily_user_limit' => '100', 'daily_phone_limit' => '1',
    'send_mode' => 'template', 'template_name' => 'wificare_otp', 'language_code' => 'id',
    'purposes' => ['verifikasi_nomor' => '1', 'reset_password' => '1'],
]);
$pdo->exec('DELETE FROM whatsapp_otp');
$limitFirst = WhatsAppOtpService::request($admin, 'verifikasi_nomor', '6281234567896');
$limitSecond = WhatsAppOtpService::request($admin, 'verifikasi_nomor', '6281234567896');
check('Batas OTP per nomor/hari ditegakkan', !empty($limitFirst['ok']) && empty($limitSecond['ok']));
$rateStats = WhatsAppOtpService::monitoring();
check('Monitoring OTP mencatat rate limit', (int) $rateStats['rate_limited'] > 0);

// Kembalikan batas normal.
WhatsAppSettingsService::saveOtp($admin, [
    'is_enabled' => '1', 'connection_id' => (string) $credentialId,
    'template' => 'Kode OTP WiFiCare CDN Anda adalah {kode}. Berlaku {masa_berlaku} menit. Jangan bagikan kode ini.',
    'ttl_seconds' => '300', 'max_attempts' => '5', 'resend_cooldown' => '0',
    'daily_user_limit' => '100', 'daily_phone_limit' => '100',
    'send_mode' => 'template', 'template_name' => 'wificare_otp', 'language_code' => 'id',
    'purposes' => ['verifikasi_nomor' => '1', 'reset_password' => '1'],
]);

$plaintextLeak = $pdo->query(
    "SELECT COUNT(*) FROM api_usage_log WHERE event_type LIKE '%otp%' AND (status REGEXP '[0-9]{6}' OR error_code REGEXP '[0-9]{6}')"
)->fetchColumn();
check('Monitoring OTP tidak memuat kode plaintext', (int) $plaintextLeak === 0);

section('Notifikasi & Antrean');
$eventConfig = [];
foreach (ApiFeatureRegistry::notificationEvents() as $key => $meta) {
    $eventConfig[$key] = [
        'enabled' => in_array($key, ['pengaduan_baru', 'konfirmasi_tiket', 'tiket_diverifikasi', 'tiket_selesai'], true) ? '1' : '',
        'recipient' => 'pelanggan',
        'custom_number' => '',
        'template' => $meta['template'],
        'send_mode' => 'text',
        'template_name' => '',
        'language_code' => 'id',
    ];
}
WhatsAppSettingsService::saveNotifications($admin, [
    'is_enabled' => '1',
    'connection_id' => (string) $credentialId,
    'admin_number' => '6281234567890',
    'daily_limit' => '100',
    'retry_max' => '3',
    'events' => $eventConfig,
]);
check('Notifikasi WhatsApp aktif', ApiIntegrationSetting::isEnabled(ApiFeatureRegistry::WHATSAPP_NOTIFICATION));

// Hook nyata: transisi tiket oleh CS membuat event tiket_diverifikasi.
$ticket = $pdo->query(
    "SELECT id, nomor_tiket, status FROM pengaduan
     WHERE status = 'menunggu_verifikasi' AND is_cancelled = 0
     ORDER BY id DESC LIMIT 1"
)->fetch();
check('Tiket uji tersedia untuk hook', is_array($ticket));
$created = ['id' => (int) ($ticket['id'] ?? 0), 'nomor_tiket' => (string) ($ticket['nomor_tiket'] ?? '')];
$flowError = exception_message(static fn () => PengaduanFlowService::terima($cs, (int) $created['id']));
check('Hook transisi tiket berjalan', $flowError === '', $flowError);
$hookRows = $pdo->query(
    "SELECT template_key, status FROM whatsapp_outbox
     WHERE feature_key = 'whatsapp_notification' AND template_key = 'tiket_diverifikasi'
       AND event_key LIKE " . $pdo->quote('wa_notif:%:' . (int) $created['id'] . ':%')
)->fetchAll();
check('Hook transisi membuat notifikasi tiket_diverifikasi', count($hookRows) === 1);
// Kembalikan status agar data uji tetap dapat dipakai ulang.
$pdo->exec('UPDATE pengaduan SET status = ' . $pdo->quote('menunggu_verifikasi') . ' WHERE id = ' . (int) $created['id']);

// Hook pembuatan tiket (dipanggil langsung agar tidak membutuhkan unggahan HTTP).
$pdo->exec("DELETE FROM whatsapp_outbox WHERE feature_key='whatsapp_notification'");
$creationError = exception_message(static fn () => WhatsAppNotificationService::ticketCreated(
    (int) $created['id'],
    ['nomor_tiket' => (string) $created['nomor_tiket'], 'nama_pelanggan' => (string) $customer['nama']]
));
$creationKeys = array_column($pdo->query(
    "SELECT template_key FROM whatsapp_outbox WHERE feature_key='whatsapp_notification'"
)->fetchAll(), 'template_key');
check('Hook pembuatan tiket berjalan', $creationError === '', $creationError);
check('Hook pembuatan membuat pengaduan_baru', in_array('pengaduan_baru', $creationKeys, true));
check('Hook pembuatan membuat konfirmasi_tiket', in_array('konfirmasi_tiket', $creationKeys, true));

// Deduplikasi event.
$beforeDup = (int) $pdo->query("SELECT COUNT(*) FROM whatsapp_outbox WHERE feature_key='whatsapp_notification'")->fetchColumn();
WhatsAppNotificationService::ticketCreated((int) $created['id'], ['nomor_tiket' => (string) $created['nomor_tiket'], 'nama_pelanggan' => (string) $customer['nama']]);
$afterDup = (int) $pdo->query("SELECT COUNT(*) FROM whatsapp_outbox WHERE feature_key='whatsapp_notification'")->fetchColumn();
check('Event notifikasi tidak duplikat', $afterDup === $beforeDup);

// Event individual.
$pdo->exec("DELETE FROM whatsapp_outbox WHERE feature_key='whatsapp_notification'");
WhatsAppNotificationService::ticketEvent('tiket_diverifikasi', (int) $created['id']);
$one = (int) $pdo->query("SELECT COUNT(*) FROM whatsapp_outbox WHERE feature_key='whatsapp_notification'")->fetchColumn();
WhatsAppNotificationService::ticketEvent('tiket_diverifikasi', (int) $created['id']);
$two = (int) $pdo->query("SELECT COUNT(*) FROM whatsapp_outbox WHERE feature_key='whatsapp_notification'")->fetchColumn();
check('Event individual masuk antrean', $one === 1);
check('Event individual idempoten', $two === 1);

$process = WhatsAppMessagingService::processQueue(10, ApiFeatureRegistry::WHATSAPP_NOTIFICATION);
$acceptedRow = $pdo->query("SELECT * FROM whatsapp_outbox WHERE feature_key='whatsapp_notification' ORDER BY id DESC LIMIT 1")->fetch();
check('Antrean diproses ke provider', (int) ($process['accepted'] ?? 0) >= 1);
check('Status setelah send hanya accepted', ($acceptedRow['status'] ?? '') === 'accepted');
check('Delivered belum diklaim sebelum webhook', ($acceptedRow['delivered_at'] ?? null) === null);

$providerId = (string) ($acceptedRow['provider_message_id'] ?? '');
$webhookPayload = json_encode([
    'entry' => [['changes' => [['value' => ['statuses' => [['id' => $providerId, 'status' => 'delivered']]]]]]],
]);
$badWebhook = WhatsAppWebhookService::handle($credentialId, $webhookPayload, 'sha256=deadbeef');
check('Webhook signature salah ditolak', empty($badWebhook['ok']));
$afterBad = $pdo->query("SELECT status FROM whatsapp_outbox WHERE id=" . (int) $acceptedRow['id'])->fetchColumn();
check('Webhook salah tidak mengubah status', $afterBad === 'accepted');

$signature = 'sha256=' . hash_hmac('sha256', $webhookPayload, 'secret-token-123');
$goodWebhook = WhatsAppWebhookService::handle($credentialId, $webhookPayload, $signature);
$afterGood = $pdo->query("SELECT status, delivered_at FROM whatsapp_outbox WHERE id=" . (int) $acceptedRow['id'])->fetch();
check('Webhook valid memproses delivery', !empty($goodWebhook['ok']) && ($afterGood['status'] ?? '') === 'delivered');
check('Delivered baru diisi setelah webhook', ($afterGood['delivered_at'] ?? null) !== null);

$duplicateWebhook = WhatsAppWebhookService::handle($credentialId, $webhookPayload, $signature);
$afterDupWebhook = $pdo->query("SELECT COUNT(*) FROM whatsapp_outbox WHERE id=" . (int) $acceptedRow['id'])->fetchColumn();
check('Webhook duplikat idempoten', !empty($duplicateWebhook['ok']) && (int) $afterDupWebhook === 1);

// Gagal provider tidak boleh mematikan app; retry_max=1.
$eventConfig['tiket_ditolak'] = [
    'enabled' => '1',
    'recipient' => 'custom',
    'custom_number' => '6281200000000',
    'template' => 'Tiket {nomor_tiket} ditolak. Alasan: {alasan}',
    'send_mode' => 'text',
    'template_name' => '',
    'language_code' => 'id',
];
WhatsAppSettingsService::saveNotifications($admin, [
    'is_enabled' => '1',
    'connection_id' => (string) $credentialId,
    'admin_number' => '6281234567890',
    'daily_limit' => '100',
    'retry_max' => '1',
    'events' => $eventConfig,
]);
$pdo->exec("DELETE FROM whatsapp_outbox WHERE feature_key='whatsapp_notification'");
WhatsAppNotificationService::ticketEvent('tiket_ditolak', (int) $created['id'], ['alasan' => 'Uji gagal provider']);
$failedProcess = WhatsAppMessagingService::processQueue(10, ApiFeatureRegistry::WHATSAPP_NOTIFICATION);
$failedRow = $pdo->query("SELECT * FROM whatsapp_outbox WHERE feature_key='whatsapp_notification' ORDER BY id DESC LIMIT 1")->fetch();
check('Kegagalan provider dicatat sebagai failed', (int) ($failedProcess['failed'] ?? 0) >= 1 && ($failedRow['status'] ?? '') === 'failed');
check('Kegagalan provider tidak melempar exception ke app', ($failedRow['last_error_code'] ?? '') !== '');
$otpStill = WhatsAppOtpService::request($admin, 'verifikasi_nomor', '6281234567898');
check('Fitur lain tetap berjalan setelah notifikasi gagal', !empty($otpStill['ok']));

// Independen disable notifikasi.
WhatsAppSettingsService::saveNotifications($admin, [
    'is_enabled' => '',
    'connection_id' => (string) $credentialId,
    'admin_number' => '6281234567890',
    'daily_limit' => '100',
    'retry_max' => '1',
    'events' => $eventConfig,
]);
$beforeDisabled = (int) $pdo->query("SELECT COUNT(*) FROM whatsapp_outbox WHERE feature_key='whatsapp_notification'")->fetchColumn();
WhatsAppNotificationService::ticketEvent('tiket_ditolak', (int) $created['id'], ['alasan' => 'Tidak boleh masuk']);
$afterDisabled = (int) $pdo->query("SELECT COUNT(*) FROM whatsapp_outbox WHERE feature_key='whatsapp_notification'")->fetchColumn();
check('Disable notifikasi menghentikan event', $beforeDisabled === $afterDisabled);
check('Disable notifikasi tidak mematikan OTP', !empty(WhatsAppOtpService::request($admin, 'verifikasi_nomor', '6281234567899')['ok']));

section('Gateway & Batas');
WhatsAppSettingsService::saveGateway($admin, [
    'is_enabled' => '',
    'connection_id' => (string) $credentialId,
    'default_country_code' => '62',
    'daily_limit' => '100',
    'retry_max' => '3',
    'timeout' => '20',
]);
$gatewayOff = WhatsAppOtpService::request($admin, 'verifikasi_nomor', '6281234567000');
check('Gateway nonaktif memblokir pengiriman', empty($gatewayOff['ok']));
WhatsAppSettingsService::saveGateway($admin, [
    'is_enabled' => '1',
    'connection_id' => (string) $credentialId,
    'default_country_code' => '62',
    'daily_limit' => '1',
    'retry_max' => '3',
    'timeout' => '20',
]);
check('Batas harian gateway tersimpan', (int) ApiIntegrationSetting::get(ApiFeatureRegistry::WHATSAPP_GATEWAY)['settings']['daily_limit'] === 1);
WhatsAppSettingsService::saveGateway($admin, [
    'is_enabled' => '1',
    'connection_id' => (string) $credentialId,
    'default_country_code' => '62',
    'daily_limit' => '1000',
    'retry_max' => '3',
    'timeout' => '20',
]);

section('Audit & Kebocoran');
$audits = $pdo->query('SELECT * FROM api_config_audit ORDER BY id DESC')->fetchAll();
$auditJson = json_encode($audits);
check('Audit konfigurasi tercatat per fitur', count($audits) > 0);
check('Audit tidak memuat token/secret', !str_contains((string) $auditJson, $token) && !str_contains((string) $auditJson, 'secret-token-123') && !str_contains((string) $auditJson, 'v1:'));
$generalAudit = $pdo->query("SELECT metadata FROM audit_log WHERE aksi LIKE 'api_%' ORDER BY id DESC LIMIT 50")->fetchAll();
$generalJson = json_encode($generalAudit);
check('Audit log umum tidak memuat token/secret', !str_contains((string) $generalJson, $token) && !str_contains((string) $generalJson, 'secret-token-123'));
$cipherLeak = $pdo->query("SELECT COUNT(*) FROM api_usage_log WHERE external_id LIKE '%test-token%' OR error_code LIKE '%test-token%'")->fetchColumn();
check('Usage log tidak memuat token', (int) $cipherLeak === 0);

section('Monitoring');
$allMonitoring = ApiMonitoringService::pageData('', '', 'bulan_ini');
check('Monitoring berisi data AI', count(array_filter($allMonitoring['rows'], static fn (array $r): bool => $r['feature_key'] === ApiFeatureRegistry::AI)) > 0);
check('Monitoring berisi data WhatsApp', count(array_filter($allMonitoring['rows'], static fn (array $r): bool => $r['feature_key'] !== ApiFeatureRegistry::AI)) > 0);
check('Monitoring memakai data nyata', (int) ($allMonitoring['summary']['month_requests'] ?? 0) > 0);
check('Monitoring tidak mengarang biaya', ($allMonitoring['summary']['estimated_cost'] ?? null) === null && str_contains((string) $allMonitoring['cost_note'], 'Tidak ada angka'));
$aiFiltered = ApiMonitoringService::pageData('ai_chatbot', 'gemini', 'bulan_ini');
check('Filter fitur AI bekerja', count(array_filter($aiFiltered['rows'], static fn (array $r): bool => $r['feature_key'] !== ApiFeatureRegistry::AI)) === 0);
$waFiltered = ApiMonitoringService::pageData('whatsapp_otp', '', 'bulan_ini');
check('Filter fitur WhatsApp bekerja', count($waFiltered['rows']) === 1 && $waFiltered['rows'][0]['feature_key'] === ApiFeatureRegistry::WHATSAPP_OTP);
$allPeriod = ApiMonitoringService::pageData('', '', 'semua');
check('Filter periode semua bekerja', ($allPeriod['range'][0] ?? '') === '' && ($allPeriod['range'][1] ?? '') === '');

section('HTTP, RBAC, & Kebocoran HTML');
[$adminJar, $adminLogin] = login('admin', 'admin.budi', T10_PW_ADMIN);
[$csJar, $csLogin] = login('cs', 'cs.dewi', T10_PW_CS);
[$tekJar, $tekLogin] = login('tek', 'tek.andi', T10_PW_TEK);

if ($adminLogin !== 'ok') {
    skip('Uji HTTP halaman Admin', 'server uji http://localhost:8099 tidak berjalan atau login gagal');
} else {
    [$status, $html] = http_call('GET', '/admin/whatsapp/gateway', $adminJar);
    check('Halaman WhatsApp Admin dapat dibuka', $status === 200 && str_contains($html, 'Pengaturan WhatsApp Gateway'));
    check('Halaman WhatsApp memuat menu baru', str_contains($html, 'Monitoring Penggunaan API'));
    check('Secret tidak muncul di HTML', !str_contains($html, $token) && !str_contains($html, 'secret-token-123') && !str_contains($html, 'v1:'));
    check('Halaman WhatsApp mendukung tema', str_contains($html, 'data-theme="light"') && str_contains($html, 'wificare-theme'));
    [$monStatus, $monHtml] = http_call('GET', '/admin/monitoring-api?fitur=whatsapp_otp&periode=bulan_ini', $adminJar);
    check('Halaman Monitoring API dapat dibuka', $monStatus === 200 && str_contains($monHtml, 'Monitoring Penggunaan API'));
    check('Filter monitoring terlihat di HTML', str_contains($monHtml, 'whatsapp_otp') && str_contains($monHtml, 'Bulan ini'));
    [$dashStatus, $dashHtml] = http_call('GET', '/admin/dashboard', $adminJar);
    check('Floating AI tetap terpasang di dashboard', $dashStatus === 200 && str_contains($dashHtml, 'data-ai-widget'));

    [$csrfStatus, $csrfHtml] = http_call('POST', '/admin/whatsapp/gateway', $adminJar, ['is_enabled' => '1']);
    check('CSRF tetap wajib pada aksi Admin', $csrfStatus === 419 || str_contains($csrfHtml, 'CSRF'));

    if ($csLogin === 'ok' && $tekLogin === 'ok') {
        [$csStatus] = http_call('GET', '/admin/whatsapp/gateway', $csJar);
        [$tekStatus] = http_call('GET', '/admin/monitoring-api', $tekJar);
        check('CS tidak dapat membuka kredensial', $csStatus === 403);
        check('Teknisi tidak dapat membuka kredensial', $tekStatus === 403);
    } else {
        skip('RBAC HTTP CS/Teknisi', 'login peran uji gagal');
    }

    [$verifyStatus, $verifyBody] = http_call('GET', '/webhook/whatsapp/' . $credentialId . '?hub_mode=subscribe&hub_verify_token=verify-token-123&hub_challenge=abc123');
    check('Webhook GET verifikasi token', $verifyStatus === 200 && $verifyBody === 'abc123');
    [$verifyBadStatus] = http_call('GET', '/webhook/whatsapp/' . $credentialId . '?hub_mode=subscribe&hub_verify_token=salah&hub_challenge=abc123');
    check('Webhook GET token salah ditolak', $verifyBadStatus === 403);

    $httpPayload = json_encode([
        'entry' => [['changes' => [['value' => ['statuses' => [['id' => $providerId, 'status' => 'read']]]]]]],
    ]);
    $httpSignature = 'sha256=' . hash_hmac('sha256', $httpPayload, 'secret-token-123');
    [$hookStatus, $hookBody] = http_call('POST', '/webhook/whatsapp/' . $credentialId, null, [], $httpPayload, [
        'Content-Type: application/json',
        'X-Hub-Signature-256: ' . $httpSignature,
    ]);
    check('Webhook POST signature valid', $hookStatus === 200 && str_contains($hookBody, '"ok":true'));
    [$hookBadStatus] = http_call('POST', '/webhook/whatsapp/' . $credentialId, null, [], $httpPayload, [
        'Content-Type: application/json',
        'X-Hub-Signature-256: sha256=deadbeef',
    ]);
    check('Webhook POST signature salah ditolak', $hookBadStatus === 403);
}

section('Menu & Duplikasi');
$adminMenu = role_menu('admin');
$keys = [];
foreach ($adminMenu as $item) {
    if (isset($item['key'])) {
        $keys[] = $item['key'];
    }
}
check('Tidak ada menu Admin duplikat', count($keys) === count(array_unique($keys)));
check('Menu WhatsApp ada tepat satu', count(array_keys($keys, 'whatsapp', true)) === 1);
$whatsappMenu = array_values(array_filter($adminMenu, static fn (array $item): bool => ($item['key'] ?? '') === 'whatsapp'));
$whatsappChildren = is_array($whatsappMenu[0]['children'] ?? null) ? $whatsappMenu[0]['children'] : [];
check(
    'Submenu WhatsApp lengkap dan unik',
    count($whatsappChildren) === 5
    && count(array_unique(array_column($whatsappChildren, 'key'))) === 5
);
check('Menu Monitoring API ada tepat satu', count(array_keys($keys, 'monitoring_api', true)) === 1);

section('Hasil');
echo "PASS={$PASS} FAIL={$FAIL} SKIP={$SKIP}\n";
exit($FAIL > 0 ? 1 : 0);
