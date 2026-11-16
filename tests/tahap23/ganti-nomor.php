<?php
/**
 * WiFiCare CDN — Tahap 23: Ganti Nomor WhatsApp Pelanggan.
 *
 * Menguji alur aman:
 * - password WiFiCare diverifikasi sebelum OTP diminta;
 * - OTP dikirim ke nomor BARU melalui provider aktif (mock Fonnte);
 * - nomor lama tidak berubah sampai OTP baru benar;
 * - OTP lama tidak berlaku setelah kirim ulang;
 * - nomor baru + status terverifikasi disimpan atomik;
 * - pembatalan menghapus permintaan dan OTP nomor baru;
 * - CSRF, RBAC, rate limit password, audit tanpa nomor/kode/password;
 * - Profil biasa tetap tidak dapat mengedit nomor;
 * - form Buat Pengaduan tetap berfungsi.
 *
 * Prasyarat:
 *   php -S 127.0.0.1:8099 -t public public/index.php   (APP_BASE_PATH=/)
 *   php -S 127.0.0.1:8097 tests/tahap22/mock-fonnte.php
 *
 * Jalankan:
 *   php tests/tahap23/ganti-nomor.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../tahap10/_config.php';

putenv('DB_NAME=wificare_cdn_test');
putenv('APP_BASE_PATH=/');
$_ENV['DB_NAME'] = 'wificare_cdn_test';
$_SERVER['DB_NAME'] = 'wificare_cdn_test';
$_ENV['APP_BASE_PATH'] = '/';
$_SERVER['APP_BASE_PATH'] = '/';

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

function blocked(string $message): void
{
    echo "BLOCKED  {$message}\n";
    exit(2);
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
    if (preg_match('/name="_token"\s+value="([a-f0-9]{64})"/', $html, $m) === 1) {
        return $m[1];
    }
    if (preg_match('/name="csrf-token"\s+content="([a-f0-9]{64})"/', $html, $m) === 1) {
        return $m[1];
    }

    return '';
}

/** @return array{jar:string,ok:bool} */
function login_user(string $tag, string $username, string $password): array
{
    $jar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_t23_' . $tag . '_' . getmypid() . '.jar';
    @unlink($jar);
    $login = http_request('GET', '/login', $jar);
    $token = csrf_from($login['body']);
    if ($login['status'] !== 200 || $token === '') {
        return ['jar' => $jar, 'ok' => false];
    }
    $post = http_request('POST', '/login', $jar, [
        '_token' => $token,
        'username' => $username,
        'password' => $password,
    ]);

    return ['jar' => $jar, 'ok' => $post['status'] === 302];
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
    if (preg_match('/(?<!\d)(\d{6})(?!\d)/', $message, $m) === 1) {
        return $m[1];
    }

    return '';
}

function server_available(string $host, int $port): bool
{
    $socket = @fsockopen($host, $port, $errno, $errstr, 0.5);
    if (is_resource($socket)) {
        fclose($socket);

        return true;
    }

    return false;
}

if (!server_available('127.0.0.1', 8097)) {
    blocked('Mock Fonnte 127.0.0.1:8097 tidak aktif.');
}
if (!server_available('127.0.0.1', 8099)) {
    blocked('Server aplikasi uji 127.0.0.1:8099 tidak aktif.');
}

$mockLog = getenv('FONNTE_MOCK_LOG') ?: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_fonnte_mock.log';
@file_put_contents($mockLog, '');

try {
    $pdo = Database::pdo();
} catch (Throwable $exception) {
    blocked('Database uji tidak dapat dihubungi: ' . $exception->getMessage());
}

$suffix = strtolower(bin2hex(random_bytes(5)));
$password = 'Uji!' . bin2hex(random_bytes(12)) . '9';
$oldPhone = '081200007771';
$newPhone = '081300001234';
$newPhoneNormalized = '6281300001234';
$secondPhone = '081300005678';
$secondPhoneNormalized = '6281300005678';
$userIds = [];
$pelangganIds = [];
$usernames = [];
$credentialId = 0;

$snapshotSettings = $pdo->query(
    "SELECT * FROM api_integration_settings
     WHERE feature_key IN ('whatsapp_gateway','whatsapp_otp')"
)->fetchAll();
$snapshotActive = $pdo->query('SELECT id, is_active FROM api_credentials')->fetchAll();
$max = static function (string $table) use ($pdo): int {
    return (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM ' . $table)->fetchColumn();
};
$maxOutbox = $max('whatsapp_outbox');
$maxOtp = $max('whatsapp_otp');
$maxUsage = $max('api_usage_log');
$maxAuditLog = $max('audit_log');
$maxConfigAudit = $max('api_config_audit');
$maxLoginAttempts = $max('login_attempts');

$cleanup = static function () use (
    $pdo,
    &$userIds,
    &$pelangganIds,
    &$usernames,
    &$credentialId,
    $snapshotSettings,
    $snapshotActive,
    $maxOutbox,
    $maxOtp,
    $maxUsage,
    $maxAuditLog,
    $maxConfigAudit,
    $maxLoginAttempts,
    $mockLog
): void {
    foreach ($userIds as $userId) {
        $pdo->prepare('DELETE FROM notifikasi WHERE user_id = :id')->execute(['id' => $userId]);
    }
    foreach ($pelangganIds as $pelangganId) {
        $pdo->prepare('DELETE FROM pelanggan WHERE id = :id')->execute(['id' => $pelangganId]);
    }
    foreach ($userIds as $userId) {
        $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
    }
    if ($credentialId > 0) {
        $pdo->prepare('DELETE FROM api_credentials WHERE id = :id')->execute(['id' => $credentialId]);
    }

    $pdo->exec("DELETE FROM api_integration_settings WHERE feature_key IN ('whatsapp_gateway','whatsapp_otp')");
    foreach ($snapshotSettings as $row) {
        $pdo->prepare(
            'INSERT INTO api_integration_settings
                (feature_key, connection_id, is_enabled, settings_json, updated_by, updated_at)
             VALUES
                (:feature_key, :connection_id, :is_enabled, :settings_json, :updated_by, :updated_at)'
        )->execute([
            'feature_key' => $row['feature_key'],
            'connection_id' => $row['connection_id'],
            'is_enabled' => $row['is_enabled'],
            'settings_json' => $row['settings_json'],
            'updated_by' => $row['updated_by'],
            'updated_at' => $row['updated_at'],
        ]);
    }

    $pdo->exec('UPDATE api_credentials SET is_active = 0');
    foreach ($snapshotActive as $row) {
        $pdo->prepare('UPDATE api_credentials SET is_active = :active WHERE id = :id')->execute([
            'active' => $row['is_active'],
            'id' => $row['id'],
        ]);
    }

    $pdo->prepare('DELETE FROM whatsapp_otp WHERE id > :id')->execute(['id' => $maxOtp]);
    $pdo->prepare('DELETE FROM whatsapp_outbox WHERE id > :id')->execute(['id' => $maxOutbox]);
    $pdo->prepare('DELETE FROM api_usage_log WHERE id > :id')->execute(['id' => $maxUsage]);
    $pdo->prepare('DELETE FROM audit_log WHERE id > :id')->execute(['id' => $maxAuditLog]);
    $pdo->prepare('DELETE FROM api_config_audit WHERE id > :id')->execute(['id' => $maxConfigAudit]);
    $pdo->prepare('DELETE FROM login_attempts WHERE id > :id')->execute(['id' => $maxLoginAttempts]);
    foreach ($usernames as $username) {
        $pdo->prepare('DELETE FROM login_attempts WHERE username = :username')->execute(['username' => $username]);
    }

    ApiCredential::resetCache();
    ApiIntegrationSetting::resetCache();
    @unlink($mockLog);
};

try {
    $insertUser = $pdo->prepare(
        'INSERT INTO users (username, password_hash, nama, role, is_active)
         VALUES (:username, :password_hash, :nama, :role, 1)'
    );
    $createUser = static function (string $prefix, string $role, string $name) use ($pdo, $insertUser, $suffix, $password, &$usernames): int {
        $username = $prefix . '_' . $suffix;
        $usernames[] = $username;
        $insertUser->execute([
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'nama' => $name,
            'role' => $role,
        ]);

        return (int) $pdo->lastInsertId();
    };

    $adminId = $createUser('t23admin', 'admin', 'Admin Uji Tahap 23');
    $pelangganUserId = $createUser('t23pel', 'pelanggan', 'Pelanggan Uji Tahap 23');
    $userIds = [$adminId, $pelangganUserId];

    $insertPelanggan = $pdo->prepare(
        'INSERT INTO pelanggan (user_id, username_pppoe, nomor_whatsapp, alamat)
         VALUES (:user_id, :pppoe, :phone, :alamat)'
    );
    $insertPelanggan->execute([
        'user_id' => $pelangganUserId,
        'pppoe' => 't23_' . $suffix,
        'phone' => $oldPhone,
        'alamat' => 'Fixture Tahap 23',
    ]);
    $pelangganId = (int) $pdo->lastInsertId();
    $pelangganIds[] = $pelangganId;

    $admin = ['id' => $adminId, 'role' => 'admin', 'username' => 't23admin_' . $suffix];
    $credentialId = ApiCredentialService::saveWhatsApp($admin, [
        'name' => 'Fonnte CDN Uji Tahap 23 ' . $suffix,
        'provider' => 'fonnte',
        'access_token' => 'test-fonnte-token-' . $suffix,
        'sender_number' => '6281200000000',
        'base_url' => 'http://127.0.0.1:8097',
        'is_active' => '1',
    ]);

    WhatsAppSettingsService::saveGateway($admin, [
        'is_enabled' => '1',
        'connection_id' => (string) $credentialId,
        'default_country_code' => '62',
        'daily_limit' => '1000',
        'retry_max' => '3',
        'timeout' => '10',
    ]);
    WhatsAppSettingsService::saveOtp($admin, [
        'is_enabled' => '1',
        'connection_id' => (string) $credentialId,
        'template' => 'Kode OTP WiFiCare CDN Anda: {kode}. Berlaku {masa_berlaku} menit.',
        'ttl_seconds' => '300',
        'max_attempts' => '5',
        'resend_cooldown' => '30',
        'daily_user_limit' => '100',
        'daily_phone_limit' => '100',
        'send_mode' => 'text',
        'template_name' => '',
        'language_code' => 'id',
        'purposes' => ['phone_verification' => '1'],
    ]);
    ApiCredential::resetCache();
    ApiIntegrationSetting::resetCache();

    $login = login_user('pel', 't23pel_' . $suffix, $password);
    check('Login pelanggan fixture berhasil', $login['ok']);
    if (!$login['ok']) {
        throw new RuntimeException('Login fixture gagal.');
    }

    $profile = http_request('GET', '/pelanggan/profil', $login['jar']);
    $html = $profile['body'];
    $token = csrf_from($html);

    check('Profil dapat dibuka', $profile['status'] === 200, 'status=' . $profile['status']);
    check('Tombol Ganti Nomor WhatsApp tampil', str_contains($html, 'Ganti Nomor WhatsApp'));
    check('Modal ganti nomor tersedia', str_contains($html, 'data-modal="profilGantiNomorModal"'));
    check('State ganti nomor siap', str_contains($html, 'data-change-ready="1"'));
    check('Belum ada permintaan ganti nomor', str_contains($html, 'data-change-pending="0"'));
    check('Form minta OTP mengarah ke endpoint ganti', str_contains($html, 'action="' . e(url('/pelanggan/profil/whatsapp/ganti')) . '"'));
    check('Field password WiFiCare tersedia', str_contains($html, 'name="password"') && str_contains($html, 'Password WiFiCare saat ini'));
    check('Field nomor baru tersedia', str_contains($html, 'name="nomor_whatsapp_baru"'));
    check('Input OTP enam digit tersedia', str_contains($html, 'name="kode"') && str_contains($html, 'pattern="[0-9]{6}"'));
    check('CSRF tersedia di halaman', $token !== '');
    check('Script ganti nomor dimuat', str_contains($html, 'pelanggan-profil-ganti-nomor.js'));
    check('Nomor master tetap readonly', preg_match('/id="profil-whatsapp"[^>]*readonly/s', $html) === 1);
    check('Tidak ada input editable nomor_whatsapp di form profil', preg_match('/<input[^>]+name="nomor_whatsapp"/i', $html) !== 1);

    $noCsrf = http_request('POST', '/pelanggan/profil/whatsapp/ganti', $login['jar'], [
        'password' => $password,
        'nomor_whatsapp_baru' => $newPhone,
    ]);
    check('Ganti nomor tanpa CSRF ditolak 419', $noCsrf['status'] === 419, 'status=' . $noCsrf['status']);

    $wrongPassword = http_request('POST', '/pelanggan/profil/whatsapp/ganti', $login['jar'], [
        '_token' => $token,
        'password' => 'PasswordSalah!123',
        'nomor_whatsapp_baru' => $newPhone,
    ]);
    check('Password salah ditolak dengan redirect', $wrongPassword['status'] === 302, 'status=' . $wrongPassword['status']);
    check('Password salah kembali ke modal ganti', str_contains((string) ($wrongPassword['headers']['location'] ?? ''), 'ganti_nomor=1'));
    $afterWrong = http_request('GET', '/pelanggan/profil?ganti_nomor=1', $login['jar']);
    check('Pesan password salah tampil', str_contains($afterWrong['body'], 'Password WiFiCare saat ini tidak sesuai'));
    check('Nomor lama tetap setelah password salah', (string) $pdo->query('SELECT nomor_whatsapp FROM pelanggan WHERE id = ' . $pelangganId)->fetchColumn() === $oldPhone);
    check('Tidak ada OTP terkirim setelah password salah', last_send($mockLog) === []);

    $request = http_request('POST', '/pelanggan/profil/whatsapp/ganti', $login['jar'], [
        '_token' => $token,
        'password' => $password,
        'nomor_whatsapp_baru' => $newPhone,
    ]);
    check('Permintaan OTP nomor baru diterima', $request['status'] === 302, 'status=' . $request['status']);
    check('Permintaan OTP kembali ke modal ganti', str_contains((string) ($request['headers']['location'] ?? ''), 'ganti_nomor=1'));
    check('Nomor lama belum berubah setelah minta OTP', (string) $pdo->query('SELECT nomor_whatsapp FROM pelanggan WHERE id = ' . $pelangganId)->fetchColumn() === $oldPhone);

    $send = last_send($mockLog);
    $code1 = otp_from_message((string) ($send['message'] ?? ''));
    check('Mock Fonnte menerima pengiriman /send', ($send['path'] ?? '') === '/send');
    check('OTP dikirim ke nomor baru', (string) ($send['target'] ?? '') === $newPhoneNormalized, (string) ($send['target'] ?? ''));
    check('countryCode Fonnte 0', (string) ($send['countryCode'] ?? '') === '0');
    check('Pesan OTP memuat kode enam digit', preg_match('/^\d{6}$/', $code1) === 1);
    check('Kode OTP tidak disimpan plaintext di database', !str_contains((string) $pdo->query('SELECT code_hash FROM whatsapp_otp WHERE user_id = ' . $pelangganUserId . ' ORDER BY id DESC LIMIT 1')->fetchColumn(), $code1));

    $otpRow = $pdo->query(
        'SELECT id, send_status, consumed_at FROM whatsapp_otp
         WHERE user_id = ' . $pelangganUserId . ' ORDER BY id DESC LIMIT 1'
    )->fetch();
    check('OTP tersimpan sebagai accepted', is_array($otpRow) && ($otpRow['send_status'] ?? '') === 'accepted');
    check('OTP belum dikonsumsi sebelum verifikasi', is_array($otpRow) && ($otpRow['consumed_at'] ?? null) === null);

    $auditMetadata = (string) $pdo->query(
        "SELECT metadata FROM audit_log
         WHERE actor_id = " . $pelangganUserId . " AND aksi = 'whatsapp_ganti_nomor_diminta'
         ORDER BY id DESC LIMIT 1"
    )->fetchColumn();
    check('Audit permintaan ganti nomor tercatat', $auditMetadata !== '');
    check('Audit memakai nomor tersamarkan', str_contains($auditMetadata, '+6281') && str_contains($auditMetadata, '•'));
    check('Audit tidak memuat nomor baru lengkap', !str_contains($auditMetadata, $newPhoneNormalized));
    check('Audit tidak memuat kode OTP', $code1 !== '' && !str_contains($auditMetadata, $code1));
    check('Audit tidak memuat password', !str_contains($auditMetadata, $password));

    $pending = http_request('GET', '/pelanggan/profil?ganti_nomor=1', $login['jar']);
    $pendingHtml = $pending['body'];
    check('Profil pending ganti dapat dibuka', $pending['status'] === 200, 'status=' . $pending['status']);
    check('Modal ganti auto-open', str_contains($pendingHtml, 'data-change-auto-open="1"'));
    check('Pending ganti terdeteksi', str_contains($pendingHtml, 'data-change-pending="1"'));
    check('OTP nomor baru aktif di UI', str_contains($pendingHtml, 'data-change-has-active="1"'));
    check('Nomor baru ditampilkan tersamarkan', str_contains($pendingHtml, '+6281') && str_contains($pendingHtml, '•'));
    check('Form verifikasi ganti mengarah ke endpoint yang benar', str_contains($pendingHtml, 'action="' . e(url('/pelanggan/profil/whatsapp/ganti/verifikasi')) . '"'));
    check('Form kirim ulang ganti tersedia', str_contains($pendingHtml, 'action="' . e(url('/pelanggan/profil/whatsapp/ganti/kirim-ulang')) . '"'));
    check('Form batal ganti tersedia', str_contains($pendingHtml, 'action="' . e(url('/pelanggan/profil/whatsapp/ganti/batal')) . '"'));
    check('Nomor baru lengkap tidak bocor ke HTML', !str_contains($pendingHtml, $newPhoneNormalized));
    check('Kode OTP tidak bocor ke HTML', $code1 !== '' && !str_contains($pendingHtml, $code1));
    check('Cooldown server diteruskan ke UI', preg_match('/data-change-retry-after="(\d+)"/', $pendingHtml, $m) === 1 && (int) $m[1] > 0);
    check('Teks cooldown tampil', str_contains($pendingHtml, 'Kirim ulang tersedia dalam'));
    check('Nomor lama masih readonly saat pending', preg_match('/id="profil-whatsapp"[^>]*readonly/s', $pendingHtml) === 1);
    check('Nomor lama belum berubah saat pending', str_contains($pendingHtml, 'value="' . $oldPhone . '"'));

    $otpIdBeforeResend = is_array($otpRow) ? (int) $otpRow['id'] : 0;
    $cooldown = http_request('POST', '/pelanggan/profil/whatsapp/ganti/kirim-ulang', $login['jar'], [
        '_token' => $token,
    ]);
    check('Kirim ulang terlalu cepat tetap redirect', $cooldown['status'] === 302, 'status=' . $cooldown['status']);
    $cooldownPage = http_request('GET', '/pelanggan/profil?ganti_nomor=1', $login['jar']);
    check('Pesan cooldown backend tampil', str_contains($cooldownPage['body'], 'Mohon tunggu sebelum meminta kode baru'));
    $otpIdAfterCooldown = (int) $pdo->query('SELECT id FROM whatsapp_otp WHERE user_id = ' . $pelangganUserId . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
    check('Cooldown tidak mengganti OTP aktif', $otpIdAfterCooldown === $otpIdBeforeResend);

    // Longgarkan cooldown hanya untuk menguji kirim ulang sukses dan
    // pembatalan; nilai asli dipulihkan oleh cleanup.
    $otpRowSettings = $pdo->query("SELECT * FROM api_integration_settings WHERE feature_key = 'whatsapp_otp' LIMIT 1")->fetch();
    $otpSettings = json_decode((string) ($otpRowSettings['settings_json'] ?? '{}'), true);
    if (!is_array($otpSettings)) {
        $otpSettings = [];
    }
    WhatsAppSettingsService::saveOtp($admin, [
        'is_enabled' => (string) ($otpRowSettings['is_enabled'] ?? '1'),
        'connection_id' => (string) ($otpRowSettings['connection_id'] ?? $credentialId),
        'template' => (string) ($otpSettings['template'] ?? 'Kode OTP WiFiCare CDN Anda: {kode}. Berlaku {masa_berlaku} menit.'),
        'ttl_seconds' => (string) ($otpSettings['ttl_seconds'] ?? 300),
        'max_attempts' => (string) ($otpSettings['max_attempts'] ?? 5),
        'resend_cooldown' => '0',
        'daily_user_limit' => (string) ($otpSettings['daily_user_limit'] ?? 100),
        'daily_phone_limit' => (string) ($otpSettings['daily_phone_limit'] ?? 100),
        'send_mode' => (string) ($otpSettings['send_mode'] ?? 'text'),
        'template_name' => (string) ($otpSettings['template_name'] ?? ''),
        'language_code' => (string) ($otpSettings['language_code'] ?? 'id'),
        'purposes' => ['phone_verification' => '1'],
    ]);
    ApiIntegrationSetting::resetCache();

    $resend = http_request('POST', '/pelanggan/profil/whatsapp/ganti/kirim-ulang', $login['jar'], [
        '_token' => $token,
    ]);
    check('Kirim ulang OTP sukses redirect', $resend['status'] === 302, 'status=' . $resend['status']);
    $resendSend = last_send($mockLog);
    $code2 = otp_from_message((string) ($resendSend['message'] ?? ''));
    $otpIdAfterResend = (int) $pdo->query('SELECT id FROM whatsapp_otp WHERE user_id = ' . $pelangganUserId . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
    check('Kirim ulang membuat OTP baru', $otpIdAfterResend > $otpIdBeforeResend);
    check('Kode OTP baru diterima mock', preg_match('/^\d{6}$/', $code2) === 1);
    check('Nomor lama tetap setelah kirim ulang', (string) $pdo->query('SELECT nomor_whatsapp FROM pelanggan WHERE id = ' . $pelangganId)->fetchColumn() === $oldPhone);

    if ($code1 !== '' && $code2 !== '' && $code1 !== $code2) {
        $oldCode = http_request('POST', '/pelanggan/profil/whatsapp/ganti/verifikasi', $login['jar'], [
            '_token' => $token,
            'kode' => $code1,
        ]);
        check('Kode OTP lama tidak berlaku setelah kirim ulang', $oldCode['status'] === 302, 'status=' . $oldCode['status']);
        $oldCodePage = http_request('GET', '/pelanggan/profil?ganti_nomor=1', $login['jar']);
        check('Pesan kode lama tidak valid tampil', str_contains($oldCodePage['body'], 'Kode OTP tidak valid'));
        check('Nomor lama tetap setelah kode lama', (string) $pdo->query('SELECT nomor_whatsapp FROM pelanggan WHERE id = ' . $pelangganId)->fetchColumn() === $oldPhone);
    }

    $wrongOtp = http_request('POST', '/pelanggan/profil/whatsapp/ganti/verifikasi', $login['jar'], [
        '_token' => $token,
        'kode' => '000000',
    ]);
    check('Kode OTP salah ditolak dengan redirect', $wrongOtp['status'] === 302, 'status=' . $wrongOtp['status']);
    $wrongOtpPage = http_request('GET', '/pelanggan/profil?ganti_nomor=1', $login['jar']);
    check('Pesan kode OTP salah tampil', str_contains($wrongOtpPage['body'], 'Kode OTP tidak valid'));
    check('Nomor lama tetap setelah kode salah', (string) $pdo->query('SELECT nomor_whatsapp FROM pelanggan WHERE id = ' . $pelangganId)->fetchColumn() === $oldPhone);

    $verify = http_request('POST', '/pelanggan/profil/whatsapp/ganti/verifikasi', $login['jar'], [
        '_token' => $token,
        'kode' => $code2,
    ]);
    check('Verifikasi OTP nomor baru berhasil redirect', $verify['status'] === 302, 'status=' . $verify['status']);
    check('Verifikasi sukses kembali ke profil', str_contains((string) ($verify['headers']['location'] ?? ''), '/pelanggan/profil'));

    $updated = $pdo->query(
        'SELECT nomor_whatsapp, nomor_whatsapp_verified_at, nomor_whatsapp_verified_fingerprint
         FROM pelanggan WHERE id = ' . $pelangganId
    )->fetch();
    check('Nomor baru tersimpan setelah OTP benar', is_array($updated) && (string) ($updated['nomor_whatsapp'] ?? '') === $newPhoneNormalized, (string) ($updated['nomor_whatsapp'] ?? ''));
    check('Nomor baru ditandai terverifikasi', is_array($updated) && ($updated['nomor_whatsapp_verified_at'] ?? null) !== null);
    check('Fingerprint nomor baru tersimpan', is_array($updated) && (string) ($updated['nomor_whatsapp_verified_fingerprint'] ?? '') === Crypto::fingerprint($newPhoneNormalized));
    $consumedAt = $pdo->query('SELECT consumed_at FROM whatsapp_otp WHERE id = ' . $otpIdAfterResend . ' LIMIT 1')->fetchColumn();
    check('OTP dikonsumsi setelah verifikasi', $consumedAt !== null && $consumedAt !== false);

    $doneAudit = (string) $pdo->query(
        "SELECT metadata FROM audit_log
         WHERE actor_id = " . $pelangganUserId . " AND aksi = 'whatsapp_nomor_diganti'
         ORDER BY id DESC LIMIT 1"
    )->fetchColumn();
    check('Audit perubahan nomor tercatat', $doneAudit !== '');
    check('Audit perubahan menandai nomor diubah', str_contains($doneAudit, '"nomor_whatsapp_diubah":true'));
    check('Audit perubahan tidak memuat nomor lengkap', !str_contains($doneAudit, $newPhoneNormalized));
    check('Audit perubahan tidak memuat kode OTP', $code2 !== '' && !str_contains($doneAudit, $code2));

    $profileDone = http_request('GET', '/pelanggan/profil', $login['jar']);
    $doneHtml = $profileDone['body'];
    check('Profil setelah ganti dapat dibuka', $profileDone['status'] === 200, 'status=' . $profileDone['status']);
    check('Profil menampilkan nomor baru', str_contains($doneHtml, 'value="' . $newPhoneNormalized . '"'));
    check('Badge menjadi Terverifikasi', str_contains($doneHtml, 'Terverifikasi'));
    check('Tidak ada pending setelah sukses', str_contains($doneHtml, 'data-change-pending="0"'));
    check('Nomor lama tidak lagi menjadi nomor utama', !str_contains($doneHtml, 'value="' . $oldPhone . '"'));

    $complaint = http_request('GET', '/pelanggan/pengaduan/baru', $login['jar']);
    check('Form Buat Pengaduan tetap berfungsi', $complaint['status'] === 200 && str_contains($complaint['body'], 'Buat Pengaduan'));

    // Pembatalan: minta OTP ke nomor kedua, lalu batalkan.
    $secondRequest = http_request('POST', '/pelanggan/profil/whatsapp/ganti', $login['jar'], [
        '_token' => $token,
        'password' => $password,
        'nomor_whatsapp_baru' => $secondPhone,
    ]);
    check('Permintaan ganti nomor kedua diterima', $secondRequest['status'] === 302, 'status=' . $secondRequest['status']);
    $secondSend = last_send($mockLog);
    check('OTP nomor kedua dikirim ke target baru', (string) ($secondSend['target'] ?? '') === $secondPhoneNormalized);
    $cancel = http_request('POST', '/pelanggan/profil/whatsapp/ganti/batal', $login['jar'], [
        '_token' => $token,
    ]);
    check('Pembatalan ganti nomor redirect', $cancel['status'] === 302, 'status=' . $cancel['status']);
    $afterCancel = http_request('GET', '/pelanggan/profil', $login['jar']);
    check('Tidak ada pending setelah pembatalan', str_contains($afterCancel['body'], 'data-change-pending="0"'));
    check('Nomor utama tetap nomor pertama setelah pembatalan', (string) $pdo->query('SELECT nomor_whatsapp FROM pelanggan WHERE id = ' . $pelangganId)->fetchColumn() === $newPhoneNormalized);
    $secondFingerprint = Crypto::fingerprint($secondPhoneNormalized);
    $secondActive = $pdo->query(
        "SELECT COUNT(*) FROM whatsapp_otp
         WHERE user_id = " . $pelangganUserId . "
           AND phone_fingerprint = '" . $secondFingerprint . "'
           AND consumed_at IS NULL AND expires_at > CURRENT_TIMESTAMP"
    )->fetchColumn();
    check('OTP nomor kedua dibatalkan', (int) $secondActive === 0);
    $cancelAudit = (string) $pdo->query(
        "SELECT metadata FROM audit_log
         WHERE actor_id = " . $pelangganUserId . " AND aksi = 'whatsapp_ganti_nomor_dibatalkan'
         ORDER BY id DESC LIMIT 1"
    )->fetchColumn();
    check('Audit pembatalan tercatat', $cancelAudit !== '');

    // Rate limit password berbasis session.
    for ($i = 0; $i < 5; $i++) {
        http_request('POST', '/pelanggan/profil/whatsapp/ganti', $login['jar'], [
            '_token' => $token,
            'password' => 'SalahTerus!' . $i,
            'nomor_whatsapp_baru' => '081300009999',
        ]);
    }
    $locked = http_request('POST', '/pelanggan/profil/whatsapp/ganti', $login['jar'], [
        '_token' => $token,
        'password' => $password,
        'nomor_whatsapp_baru' => '081300009999',
    ]);
    check('Rate limit password mengunci permintaan', $locked['status'] === 302, 'status=' . $locked['status']);
    $lockedPage = http_request('GET', '/pelanggan/profil?ganti_nomor=1', $login['jar']);
    check('Pesan rate limit password tampil', str_contains($lockedPage['body'], 'Terlalu banyak percobaan password'));
    check('Nomor utama tetap setelah rate limit', (string) $pdo->query('SELECT nomor_whatsapp FROM pelanggan WHERE id = ' . $pelangganId)->fetchColumn() === $newPhoneNormalized);

    // RBAC: Admin tidak boleh membuka profil pelanggan.
    $adminLogin = login_user('admin', 't23admin_' . $suffix, $password);
    check('Login admin fixture berhasil', $adminLogin['ok']);
    if ($adminLogin['ok']) {
        $adminProfile = http_request('GET', '/pelanggan/profil', $adminLogin['jar']);
        check('Admin ditolak membuka profil pelanggan', in_array($adminProfile['status'], [403, 302], true), 'status=' . $adminProfile['status']);
    }
} catch (Throwable $exception) {
    check('Tidak ada exception tak tertangani', false, $exception->getMessage());
} finally {
    $cleanup();
}

echo "\nTOTAL {$PASS} PASS | {$FAIL} FAIL\n";
echo "BLOCKED  Pengujian live Fonnte (token nyata + delivery pelanggan) belum dilakukan.\n";
exit($FAIL > 0 ? 1 : 0);
