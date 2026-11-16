<?php
/**
 * WiFiCare CDN - Tahap 4: Template pesan OTP per purpose.
 *
 * Cakupan:
 * - default pesan verifikasi nomor, reset password, dan ganti nomor;
 * - pemilihan template berdasarkan purpose server-side;
 * - substitusi {kode}, {masa_berlaku}, dan {nama_aplikasi};
 * - isolasi OTP antar-purpose;
 * - alur ganti nomor memakai purpose `phone_change` bila aktif;
 * - fallback konfigurasi lama `template`;
 * - Meta tidak boleh jatuh ke teks biasa;
 * - tidak ada OTP/token di log aplikasi dan tabel audit.
 *
 * Prasyarat:
 *   php -S 127.0.0.1:8099 -t public public/index.php   (DB uji, APP_BASE_PATH=/)
 *   php -S 127.0.0.1:8097 tests/tahap22/mock-fonnte.php
 *   php -S 127.0.0.1:8098 tests/tahap17/mock-whatsapp.php
 *
 * Jalankan:
 *   php tests/tahap25/otp-templates.php
 */

declare(strict_types=1);

putenv('T10_BASE=http://127.0.0.1:8099');
putenv('DB_NAME=wificare_cdn_test');
putenv('APP_BASE_PATH=/');
$_ENV['T10_BASE'] = 'http://127.0.0.1:8099';
$_ENV['DB_NAME'] = 'wificare_cdn_test';
$_ENV['APP_BASE_PATH'] = '/';
$_SERVER['DB_NAME'] = 'wificare_cdn_test';
$_SERVER['APP_BASE_PATH'] = '/';

require_once __DIR__ . '/../tahap10/_config.php';
t10_require_passwords(['T10_PW_ADMIN']);
require_once __DIR__ . '/../../app/bootstrap.php';

$PASS = 0;
$FAIL = 0;
$FAILED = [];

function check(string $name, bool $ok, string $detail = ''): void
{
    global $PASS, $FAIL, $FAILED;

    if ($ok) {
        $PASS++;
        echo "PASS  {$name}\n";
        return;
    }

    $FAIL++;
    $FAILED[] = $name . ($detail !== '' ? ' :: ' . $detail : '');
    echo "FAIL  {$name}" . ($detail !== '' ? " :: {$detail}" : '') . "\n";
}

function section(string $title): void
{
    echo "\n=== {$title} ===\n";
}

function blocked(string $message): void
{
    echo "BLOCKED  {$message}\n";
    exit(2);
}

function server_available(string $host, int $port): bool
{
    $socket = @fsockopen($host, $port, $errno, $errstr, 1.5);
    if (!is_resource($socket)) {
        return false;
    }
    fclose($socket);

    return true;
}

/** @return array{status:int,body:string,headers:array<string,string>} */
function http_request(string $method, string $path, ?string $jar = null, array $form = []): array
{
    $ch = curl_init(T10_BASE . $path);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
    ];
    if ($jar !== null) {
        $options[CURLOPT_COOKIEJAR] = $jar;
        $options[CURLOPT_COOKIEFILE] = $jar;
    }
    if (strtoupper($method) === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($form);
    }
    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        return ['status' => 0, 'body' => 'CURL_ERROR: ' . $error, 'headers' => []];
    }
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerText = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);
    curl_close($ch);

    $headers = [];
    foreach (explode("\r\n", $headerText) as $line) {
        if (str_contains($line, ':')) {
            [$key, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($key))] = trim($value);
        }
    }

    return ['status' => $status, 'body' => $body, 'headers' => $headers];
}

function csrf_from(string $html): string
{
    if (preg_match('/name="_token"\s+value="([a-f0-9]{64})"/', $html, $match) === 1) {
        return $match[1];
    }
    if (preg_match('/name="csrf-token"\s+content="([a-f0-9]{64})"/', $html, $match) === 1) {
        return $match[1];
    }

    return '';
}

function temp_jar(string $tag): string
{
    $jar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_t25_' . $tag . '_'
        . getmypid() . '_' . bin2hex(random_bytes(3)) . '.jar';
    @unlink($jar);

    return $jar;
}

function admin_login(string $username, string $password, string $tag): ?string
{
    $jar = temp_jar($tag);
    $login = http_request('GET', '/login', $jar);
    $token = csrf_from($login['body']);
    if ($login['status'] !== 200 || $token === '') {
        return null;
    }
    $post = http_request('POST', '/login', $jar, [
        '_token' => $token,
        'username' => $username,
        'password' => $password,
    ]);
    if ($post['status'] !== 302) {
        return null;
    }

    return $jar;
}

/** @return array<int, array<string, mixed>> */
function mock_rows(string $logFile): array
{
    if (!is_file($logFile)) {
        return [];
    }
    $rows = [];
    foreach (file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $rows[] = $decoded;
        }
    }

    return $rows;
}

/** @return array<string, mixed> */
function last_send(string $logFile): array
{
    $rows = mock_rows($logFile);
    for ($i = count($rows) - 1; $i >= 0; $i--) {
        if (($rows[$i]['path'] ?? '') === '/send') {
            return $rows[$i];
        }
    }

    return [];
}

function otp_from_message(string $message): string
{
    if (preg_match('/(?<!\d)(\d{6})(?!\d)/', $message, $match) === 1) {
        return $match[1];
    }

    return '';
}

function unique_phone(string $prefix = '0813'): string
{
    $digits = (string) random_int(10000000, 99999999);

    return $prefix . $digits . (string) random_int(10, 98);
}

if (!server_available('127.0.0.1', 8097)) {
    blocked('Mock Fonnte 127.0.0.1:8097 tidak aktif.');
}
if (!server_available('127.0.0.1', 8098)) {
    blocked('Mock Meta 127.0.0.1:8098 tidak aktif.');
}
if (!server_available('127.0.0.1', 8099)) {
    blocked('Server aplikasi uji 127.0.0.1:8099 tidak aktif.');
}

$mockLog = getenv('FONNTE_MOCK_LOG') ?: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_fonnte_mock.log';
$metaMockLog = getenv('WA_MOCK_LOG') ?: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_wa_mock.log';
@file_put_contents($mockLog, '');
@file_put_contents($metaMockLog, '');

try {
    $pdo = Database::pdo();
} catch (Throwable $exception) {
    blocked('Database uji tidak dapat dihubungi: ' . $exception->getMessage());
}

$suffix = strtolower(bin2hex(random_bytes(4)));
$password = 'Uji!' . bin2hex(random_bytes(12)) . '9';
$createdUserId = 0;
$createdPelangganId = 0;
$createdCredentialIds = [];
$codes = [];
$settingsBackup = $pdo->query(
    "SELECT * FROM api_integration_settings
     WHERE feature_key IN ('whatsapp_gateway','whatsapp_otp')"
)->fetchAll();
$activeBackup = $pdo->query('SELECT id, is_active FROM api_credentials')->fetchAll();
$max = static function (string $table) use ($pdo): int {
    return (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM ' . $table)->fetchColumn();
};
$maxOtp = $max('whatsapp_otp');
$maxOutbox = $max('whatsapp_outbox');
$maxUsage = $max('api_usage_log');
$maxAudit = $max('audit_log');
$maxConfigAudit = $max('api_config_audit');
$appLogPath = __DIR__ . '/../../storage/logs/app.log';

$cleanup = static function () use (
    $pdo,
    $settingsBackup,
    $activeBackup,
    &$createdUserId,
    &$createdPelangganId,
    &$createdCredentialIds,
    $maxOtp,
    $maxOutbox,
    $maxUsage,
    $maxAudit,
    $maxConfigAudit,
    $mockLog,
    $metaMockLog
): void {
    $pdo->exec("DELETE FROM api_integration_settings WHERE feature_key IN ('whatsapp_gateway','whatsapp_otp')");
    $insertSetting = $pdo->prepare(
        'INSERT INTO api_integration_settings
            (feature_key, connection_id, is_enabled, settings_json, updated_by, updated_at)
         VALUES (:feature_key, :connection_id, :is_enabled, :settings_json, :updated_by, :updated_at)'
    );
    foreach ($settingsBackup as $row) {
        $insertSetting->execute([
            'feature_key' => $row['feature_key'],
            'connection_id' => $row['connection_id'],
            'is_enabled' => $row['is_enabled'],
            'settings_json' => $row['settings_json'],
            'updated_by' => $row['updated_by'],
            'updated_at' => $row['updated_at'],
        ]);
    }

    $pdo->exec('UPDATE api_credentials SET is_active = 0');
    $restoreActive = $pdo->prepare('UPDATE api_credentials SET is_active = :active WHERE id = :id');
    foreach ($activeBackup as $row) {
        $restoreActive->execute(['active' => $row['is_active'], 'id' => $row['id']]);
    }

    $pdo->prepare('DELETE FROM whatsapp_otp WHERE id > :id')->execute(['id' => $maxOtp]);
    $pdo->prepare('DELETE FROM whatsapp_outbox WHERE id > :id')->execute(['id' => $maxOutbox]);
    $pdo->prepare('DELETE FROM api_usage_log WHERE id > :id')->execute(['id' => $maxUsage]);
    $pdo->prepare('DELETE FROM audit_log WHERE id > :id')->execute(['id' => $maxAudit]);
    $pdo->prepare('DELETE FROM api_config_audit WHERE id > :id')->execute(['id' => $maxConfigAudit]);

    if ($createdPelangganId > 0) {
        $pdo->prepare('DELETE FROM pelanggan WHERE id = :id')->execute(['id' => $createdPelangganId]);
    }
    if ($createdUserId > 0) {
        $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $createdUserId]);
    }
    foreach ($createdCredentialIds as $credentialId) {
        $pdo->prepare('DELETE FROM api_credentials WHERE id = :id')->execute(['id' => $credentialId]);
    }

    ApiCredential::resetCache();
    ApiIntegrationSetting::resetCache();
    @unlink($mockLog);
    @unlink($metaMockLog);
};

try {
    $admin = User::findById(1);
    if ($admin === null || (string) ($admin['role'] ?? '') !== 'admin') {
        blocked('Akun Admin fixture tidak tersedia.');
    }

    section('Migrasi, default, dan Admin');

    $purposeColumn = $pdo->query("SHOW COLUMNS FROM whatsapp_otp LIKE 'purpose'")->fetch();
    $purposeType = (string) ($purposeColumn['Type'] ?? '');
    check('Purpose phone_change tersedia di enum OTP', str_contains($purposeType, "'phone_change'"));

    $defaults = ApiFeatureRegistry::otpTemplateDefaults();
    check('Default template verifikasi nomor tersedia', isset($defaults['template_phone_verification']));
    check('Default template reset password tersedia', isset($defaults['template_reset_password']));
    check('Default template ganti nomor tersedia', isset($defaults['template_phone_change']));
    check(
        'Setiap default memuat placeholder kode dan masa berlaku',
        count(array_filter($defaults, static fn (string $value): bool => str_contains($value, '{kode}')
            && str_contains($value, '{masa_berlaku}'))) === 3
    );
    check(
        'Default pesan memakai header sesuai purpose',
        str_contains($defaults['template_phone_verification'], 'Verifikasi Nomor WhatsApp')
        && str_contains($defaults['template_reset_password'], 'Reset Password')
        && str_contains($defaults['template_phone_change'], 'Ganti Nomor WhatsApp')
    );

    $adminJar = admin_login('admin.budi', T10_PW_ADMIN, 'admin');
    if ($adminJar === null) {
        check('Login Admin untuk halaman pengaturan OTP', false);
    } else {
        $page = http_request('GET', '/admin/whatsapp/otp', $adminJar);
        check('Halaman pengaturan WhatsApp dapat dibuka', $page['status'] === 200);
        check(
            'Tiga field template per purpose tersedia di Admin',
            str_contains($page['body'], 'name="template_phone_verification"')
            && str_contains($page['body'], 'name="template_reset_password"')
            && str_contains($page['body'], 'name="template_phone_change"')
        );
        check(
            'Label template per purpose tampil',
            str_contains($page['body'], 'Template verifikasi nomor WhatsApp')
            && str_contains($page['body'], 'Template lupa password')
            && str_contains($page['body'], 'Template ganti nomor WhatsApp')
        );
        check(
            'Purpose ganti nomor dapat dipilih Admin',
            str_contains($page['body'], 'name="purposes[phone_change]"')
        );
        check('Field template lama tunggal tidak lagi menjadi input utama', !str_contains($page['body'], 'name="template"'));
    }

    section('Setup provider dan pengguna uji');

    $insertUser = $pdo->prepare(
        'INSERT INTO users (username, password_hash, nama, role, is_active)
         VALUES (:username, :password_hash, :nama, :role, 1)'
    );
    $insertUser->execute([
        'username' => 't25pel_' . $suffix,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'nama' => 'Pelanggan Tahap 4 ' . $suffix,
        'role' => 'pelanggan',
    ]);
    $createdUserId = (int) $pdo->lastInsertId();
    $actor = User::findById($createdUserId);
    if ($actor === null) {
        throw new RuntimeException('Gagal membuat user uji.');
    }

    $oldPhone = unique_phone('0812');
    $insertPelanggan = $pdo->prepare(
        'INSERT INTO pelanggan
            (user_id, username_pppoe, nomor_whatsapp, nomor_whatsapp_verified_at,
             nomor_whatsapp_verified_fingerprint)
         VALUES (:user_id, :username_pppoe, :nomor_whatsapp, CURRENT_TIMESTAMP, :fingerprint)'
    );
    $insertPelanggan->execute([
        'user_id' => $createdUserId,
        'username_pppoe' => 't25pppoe_' . $suffix,
        'nomor_whatsapp' => $oldPhone,
        'fingerprint' => Crypto::fingerprint((string) whatsapp_number($oldPhone)),
    ]);
    $createdPelangganId = (int) $pdo->lastInsertId();
    $pelanggan = Pelanggan::findByUserId($createdUserId);
    if ($pelanggan === null) {
        throw new RuntimeException('Gagal membuat pelanggan uji.');
    }

    $fonnteToken = 'test-fonnte-token-' . $suffix;
    $fonnteCredentialId = ApiCredentialService::saveWhatsApp($admin, [
        'name' => 'Fonnte Template Uji ' . $suffix,
        'provider' => 'fonnte',
        'access_token' => $fonnteToken,
        'sender_number' => '6281200000000',
        'base_url' => 'http://127.0.0.1:8097',
        'is_active' => '1',
    ]);
    $createdCredentialIds[] = $fonnteCredentialId;

    WhatsAppSettingsService::saveGateway($admin, [
        'is_enabled' => '1',
        'connection_id' => (string) $fonnteCredentialId,
        'default_country_code' => '62',
        'daily_limit' => '1000',
        'retry_max' => '3',
        'timeout' => '10',
    ]);

    $templates = [
        'template_phone_verification' => 'VERIFIKASI-NOMOR {kode} | {masa_berlaku} menit | {nama_aplikasi}',
        'template_reset_password' => 'RESET-PASSWORD {kode} | {masa_berlaku} menit | {nama_aplikasi}',
        'template_phone_change' => 'GANTI-NOMOR {kode} | {masa_berlaku} menit | {nama_aplikasi}',
    ];
    WhatsAppSettingsService::saveOtp($admin, array_merge($templates, [
        'is_enabled' => '1',
        'connection_id' => (string) $fonnteCredentialId,
        'template' => 'TEMPLATE-LAMA {kode} berlaku {masa_berlaku} menit',
        'ttl_seconds' => '300',
        'max_attempts' => '5',
        'resend_cooldown' => '0',
        'daily_user_limit' => '100',
        'daily_phone_limit' => '100',
        'send_mode' => 'text',
        'template_name' => '',
        'language_code' => 'id',
        'purposes' => [
            'phone_verification' => '1',
            'reset_password' => '1',
            'phone_change' => '1',
        ],
    ]));
    ApiCredential::resetCache();
    ApiIntegrationSetting::resetCache();

    check(
        'Tiga template per purpose tersimpan tanpa menimpa template lama',
        (string) (ApiIntegrationSetting::get(ApiFeatureRegistry::WHATSAPP_OTP)['settings']['template_phone_verification'] ?? '') === $templates['template_phone_verification']
        && (string) (ApiIntegrationSetting::get(ApiFeatureRegistry::WHATSAPP_OTP)['settings']['template_reset_password'] ?? '') === $templates['template_reset_password']
        && (string) (ApiIntegrationSetting::get(ApiFeatureRegistry::WHATSAPP_OTP)['settings']['template_phone_change'] ?? '') === $templates['template_phone_change']
    );

    section('Pesan per purpose dan substitusi placeholder');

    $markers = [
        'phone_verification' => 'VERIFIKASI-NOMOR',
        'reset_password' => 'RESET-PASSWORD',
        'phone_change' => 'GANTI-NOMOR',
    ];
    foreach ($markers as $purpose => $marker) {
        $phone = unique_phone('0813');
        @file_put_contents($mockLog, '');
        $result = WhatsAppOtpService::request($actor, $purpose, $phone);
        $send = last_send($mockLog);
        $message = (string) ($send['message'] ?? '');
        $code = otp_from_message($message);
        if ($code !== '') {
            $codes[] = $code;
        }
        check("OTP {$purpose} berhasil dikirim melalui Fonnte", !empty($result['ok']) && $message !== '');
        check("OTP {$purpose} memakai template purpose yang benar", str_contains($message, $marker));
        check(
            "Placeholder OTP {$purpose} diganti backend",
            $code !== ''
            && str_contains($message, $code)
            && !str_contains($message, '{kode}')
            && !str_contains($message, '{masa_berlaku}')
            && !str_contains($message, '{nama_aplikasi}')
        );
        check(
            "Masa berlaku {$purpose} diisi 5 menit",
            str_contains($message, '5 menit')
        );
        $storedPurpose = (string) $pdo->query(
            "SELECT purpose FROM whatsapp_otp WHERE user_id = {$createdUserId} ORDER BY id DESC LIMIT 1"
        )->fetchColumn();
        check("Purpose {$purpose} tersimpan pada OTP", $storedPurpose === $purpose);
    }

    section('Isolasi purpose dan alur ganti nomor');

    $isolationPhone = unique_phone('0814');
    @file_put_contents($mockLog, '');
    $resetRequest = WhatsAppOtpService::request($actor, 'reset_password', $isolationPhone);
    $resetCode = otp_from_message((string) (last_send($mockLog)['message'] ?? ''));
    if ($resetCode !== '') {
        $codes[] = $resetCode;
    }
    $crossPurpose = WhatsAppOtpService::verify($actor, 'phone_verification', $isolationPhone, $resetCode);
    $correctPurpose = WhatsAppOtpService::verify($actor, 'reset_password', $isolationPhone, $resetCode);
    check('OTP reset berhasil diminta untuk uji isolasi', !empty($resetRequest['ok']) && $resetCode !== '');
    check('OTP purpose lain ditolak', empty($crossPurpose['ok']));
    check('OTP purpose yang benar diterima', !empty($correctPurpose['ok']));

    $newPhone = unique_phone('0815');
    $_SESSION = [];
    @file_put_contents($mockLog, '');
    $change = PelangganWhatsAppVerificationService::requestChange($actor, $pelanggan, $newPhone, $password);
    $changeMessage = (string) (last_send($mockLog)['message'] ?? '');
    $changeCode = otp_from_message($changeMessage);
    if ($changeCode !== '') {
        $codes[] = $changeCode;
    }
    $changePurpose = (string) $pdo->query(
        "SELECT purpose FROM whatsapp_otp WHERE user_id = {$createdUserId} ORDER BY id DESC LIMIT 1"
    )->fetchColumn();
    check('Alur ganti nomor memakai purpose phone_change', !empty($change['ok']) && $changePurpose === 'phone_change');
    check('Alur ganti nomor memakai pesan ganti nomor', str_contains($changeMessage, 'GANTI-NOMOR'));
    $changeVerify = PelangganWhatsAppVerificationService::verifyChange($actor, $pelanggan, $changeCode);
    $updatedPelanggan = Pelanggan::findByUserId($createdUserId);
    check(
        'OTP phone_change dapat menyelesaikan ganti nomor',
        !empty($changeVerify['ok'])
        && $updatedPelanggan !== null
        && (string) whatsapp_number((string) ($updatedPelanggan['nomor_whatsapp'] ?? '')) === (string) whatsapp_number($newPhone)
    );

    section('Kompatibilitas konfigurasi lama');

    $customSettingsJson = (string) $pdo->query(
        "SELECT settings_json FROM api_integration_settings WHERE feature_key = 'whatsapp_otp' LIMIT 1"
    )->fetchColumn();
    $legacyJson = json_encode([
        'template' => 'TEMPLATE-LAMA {kode} berlaku {masa_berlaku} menit',
        'ttl_seconds' => 300,
        'max_attempts' => 5,
        'resend_cooldown' => 0,
        'daily_user_limit' => 100,
        'daily_phone_limit' => 100,
        'send_mode' => 'text',
        'template_name' => '',
        'language_code' => 'id',
        'purposes' => [
            'phone_verification' => true,
            'reset_password' => false,
            'phone_change' => false,
            'verifikasi_nomor' => false,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $updateOtp = $pdo->prepare(
        "UPDATE api_integration_settings SET settings_json = :json WHERE feature_key = 'whatsapp_otp'"
    );
    $updateOtp->execute(['json' => $legacyJson]);
    ApiIntegrationSetting::resetCache();

    $legacySettings = ApiIntegrationSetting::get(ApiFeatureRegistry::WHATSAPP_OTP)['settings'];
    check(
        'Konfigurasi lama mengisi template purpose yang belum ada',
        (string) ($legacySettings['template_phone_verification'] ?? '') === 'TEMPLATE-LAMA {kode} berlaku {masa_berlaku} menit'
        && (string) ($legacySettings['template_reset_password'] ?? '') === 'TEMPLATE-LAMA {kode} berlaku {masa_berlaku} menit'
    );

    @file_put_contents($mockLog, '');
    $legacyVerifyPhone = unique_phone('0816');
    $legacyResult = WhatsAppOtpService::request($actor, 'phone_verification', $legacyVerifyPhone);
    $legacyMessage = (string) (last_send($mockLog)['message'] ?? '');
    $legacyCode = otp_from_message($legacyMessage);
    if ($legacyCode !== '') {
        $codes[] = $legacyCode;
    }
    check(
        'Konfigurasi lama tetap dipakai sebagai fallback pesan',
        !empty($legacyResult['ok'])
        && str_contains($legacyMessage, 'TEMPLATE-LAMA')
        && !str_contains($legacyMessage, '{kode}')
    );

    $pelangganBeforeFallback = Pelanggan::findByUserId($createdUserId);
    if ($pelangganBeforeFallback !== null) {
        $_SESSION = [];
        @file_put_contents($mockLog, '');
        $fallbackPhone = unique_phone('0817');
        $fallbackChange = PelangganWhatsAppVerificationService::requestChange(
            $actor,
            $pelangganBeforeFallback,
            $fallbackPhone,
            $password
        );
        $fallbackPurpose = (string) $pdo->query(
            "SELECT purpose FROM whatsapp_otp WHERE user_id = {$createdUserId} ORDER BY id DESC LIMIT 1"
        )->fetchColumn();
        check(
            'Ganti nomor tetap kompatibel saat phone_change belum diaktifkan',
            !empty($fallbackChange['ok']) && $fallbackPurpose === 'phone_verification'
        );
    } else {
        check('Ganti nomor tetap kompatibel saat phone_change belum diaktifkan', false);
    }

    $updateOtp->execute(['json' => $customSettingsJson]);
    ApiIntegrationSetting::resetCache();

    section('Kebijakan Meta: template Authentication, bukan teks bebas');

    $metaToken = 'test-token-' . $suffix;
    $metaCredentialId = ApiCredentialService::saveWhatsApp($admin, [
        'name' => 'Meta Template Uji ' . $suffix,
        'provider' => 'meta_cloud',
        'access_token' => $metaToken,
        'phone_number_id' => '1234567890',
        'base_url' => 'http://127.0.0.1:8098/v20.0',
        'is_active' => '1',
    ]);
    $createdCredentialIds[] = $metaCredentialId;
    WhatsAppSettingsService::saveGateway($admin, [
        'is_enabled' => '1',
        'connection_id' => (string) $metaCredentialId,
        'default_country_code' => '62',
        'daily_limit' => '1000',
        'retry_max' => '3',
        'timeout' => '10',
    ]);
    WhatsAppSettingsService::saveOtp($admin, array_merge($templates, [
        'is_enabled' => '1',
        'connection_id' => (string) $metaCredentialId,
        'template' => 'TEMPLATE-LAMA {kode} berlaku {masa_berlaku} menit',
        'ttl_seconds' => '300',
        'max_attempts' => '5',
        'resend_cooldown' => '0',
        'daily_user_limit' => '100',
        'daily_phone_limit' => '100',
        'send_mode' => 'text',
        'template_name' => '',
        'language_code' => 'id',
        'purposes' => [
            'phone_verification' => '1',
            'reset_password' => '1',
            'phone_change' => '1',
        ],
    ]));
    ApiCredential::resetCache();
    ApiIntegrationSetting::resetCache();

    $metaPhone = unique_phone('0818');
    $metaRejected = WhatsAppOtpService::request($actor, 'phone_verification', $metaPhone);
    $metaOtpCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM whatsapp_otp WHERE user_id = {$createdUserId} AND purpose = 'phone_verification'
         AND phone_fingerprint = " . $pdo->quote((string) Crypto::fingerprint((string) whatsapp_number($metaPhone)))
    )->fetchColumn();
    check(
        'Meta menolak mode teks biasa tanpa membuat OTP',
        empty($metaRejected['ok'])
        && (string) ($metaRejected['code'] ?? '') === 'template_required'
        && $metaOtpCount === 0
    );

    @file_put_contents($metaMockLog, '');
    $metaConfigured = WhatsAppSettingsService::saveOtp($admin, array_merge($templates, [
        'is_enabled' => '1',
        'connection_id' => (string) $metaCredentialId,
        'template' => 'TEMPLATE-LAMA {kode} berlaku {masa_berlaku} menit',
        'ttl_seconds' => '300',
        'max_attempts' => '5',
        'resend_cooldown' => '0',
        'daily_user_limit' => '100',
        'daily_phone_limit' => '100',
        'send_mode' => 'template',
        'template_name' => 'wificare_otp',
        'language_code' => 'id',
        'purposes' => [
            'phone_verification' => '1',
            'reset_password' => '1',
            'phone_change' => '1',
        ],
    ]));
    unset($metaConfigured);
    ApiCredential::resetCache();
    ApiIntegrationSetting::resetCache();

    $metaPhoneOk = unique_phone('0819');
    $metaSend = WhatsAppOtpService::request($actor, 'phone_verification', $metaPhoneOk);
    $metaRows = mock_rows($metaMockLog);
    $metaPayload = [];
    foreach ($metaRows as $row) {
        if (is_array($row['payload'] ?? null)) {
            $metaPayload = $row['payload'];
        }
    }
    check(
        'Meta mode template memakai Authentication template',
        !empty($metaSend['ok'])
        && (string) ($metaPayload['type'] ?? '') === 'template'
        && (string) ($metaPayload['template']['name'] ?? '') === 'wificare_otp'
        && !isset($metaPayload['text']),
        json_encode(['send' => $metaSend, 'payload' => $metaPayload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    section('Tidak ada OTP/token di log dan audit');

    $searchCodes = array_values(array_unique(array_filter($codes, static fn (string $code): bool => preg_match('/^\d{6}$/', $code) === 1)));
    $codePattern = $searchCodes === [] ? 'IMPOSSIBLE-NO-CODE' : implode('|', $searchCodes);
    $usageLeak = (int) $pdo->query(
        "SELECT COUNT(*) FROM api_usage_log
         WHERE CONCAT_WS('|', feature_key, provider, event_type, status, external_id, error_code) REGEXP " . $pdo->quote($codePattern)
    )->fetchColumn();
    check('api_usage_log tidak memuat kode OTP', $usageLeak === 0);

    $configLeak = (int) $pdo->query(
        "SELECT COUNT(*) FROM api_config_audit
         WHERE CONCAT_WS('|', action, changed_fields) REGEXP " . $pdo->quote($codePattern)
    )->fetchColumn();
    check('Audit konfigurasi tidak memuat kode OTP', $configLeak === 0);

    $auditLeak = (int) $pdo->query(
        "SELECT COUNT(*) FROM audit_log
         WHERE CONCAT_WS('|', aksi, objek, metadata) REGEXP " . $pdo->quote($codePattern)
    )->fetchColumn();
    check('Audit aplikasi tidak memuat kode OTP', $auditLeak === 0);

    $appLogContent = is_file($appLogPath) ? (string) file_get_contents($appLogPath) : '';
    $appLogLeak = false;
    foreach ($searchCodes as $code) {
        if ($appLogContent !== '' && str_contains($appLogContent, $code)) {
            $appLogLeak = true;
            break;
        }
    }
    check('Log aplikasi tidak memuat kode OTP', !$appLogLeak);

    $hashLeak = $searchCodes === [] ? 0 : (int) $pdo->query(
        'SELECT COUNT(*) FROM whatsapp_otp WHERE code_hash IN (' . implode(',', array_map([$pdo, 'quote'], $searchCodes)) . ')'
    )->fetchColumn();
    check('Kode OTP tidak disimpan plaintext', $hashLeak === 0);

    $tokenLeak = (int) $pdo->query(
        "SELECT COUNT(*) FROM api_usage_log
         WHERE CONCAT_WS('|', feature_key, provider, event_type, status, external_id, error_code) LIKE " . $pdo->quote('%' . $fonnteToken . '%')
    )->fetchColumn();
    check('Token Fonnte tidak tercatat di usage log', $tokenLeak === 0);
    check('Token provider tidak muncul di HTML Admin', $adminJar === null || !str_contains((string) (http_request('GET', '/admin/whatsapp/gateway', $adminJar)['body'] ?? ''), $fonnteToken));
} catch (Throwable $exception) {
    check('Pengujian selesai tanpa exception', false, $exception->getMessage() . ' @ ' . basename($exception->getFile()) . ':' . $exception->getLine());
} finally {
    $cleanup();
}

echo "\nRingkasan Tahap 4 Template OTP: {$PASS} PASS, {$FAIL} FAIL\n";
if ($FAIL > 0) {
    foreach ($FAILED as $failed) {
        echo "  - {$failed}\n";
    }
}
echo "BLOCKED  Pengujian live Fonnte/Meta (token nyata dan delivery ke penerima) belum dilakukan.\n";
exit($FAIL > 0 ? 1 : 0);
