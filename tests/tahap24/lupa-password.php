<?php
/**
 * WiFiCare CDN — Tahap 3: Lupa Password melalui OTP WhatsApp.
 *
 * Menguji alur publik tiga tahap tanpa Meta/Fonnte live:
 * - tautan login, respons netral, hanya nomor terverifikasi yang dikirimi OTP;
 * - purpose reset_password, binding akun/nomor, OTP salah/kedaluwarsa/sekali pakai;
 * - cooldown, batas harian, otorisasi reset sekali pakai, password hash;
 * - pencabutan sesi lama, CSRF, rate limit sumber, RBAC, dan regresi fitur lama.
 *
 * Prasyarat:
 *   php -S 127.0.0.1:8099 -t public public/index.php   (DB uji, APP_BASE_PATH=/)
 *   php -S 127.0.0.1:8097 tests/tahap22/mock-fonnte.php
 *
 * Jalankan:
 *   php tests/tahap24/lupa-password.php
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

function temp_jar(string $tag): string
{
    $jar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_t24_' . $tag . '_' . getmypid() . '_' . bin2hex(random_bytes(3)) . '.jar';
    @unlink($jar);

    return $jar;
}

/** @return array{jar:string,token:string,body:string,status:int} */
function guest_page(string $tag): array
{
    $jar = temp_jar($tag);
    $response = http_request('GET', '/lupa-password', $jar);

    return [
        'jar' => $jar,
        'token' => csrf_from($response['body']),
        'body' => $response['body'],
        'status' => $response['status'],
    ];
}

/** @return array{jar:string,status:int,location:string} */
function login_response(string $tag, string $username, string $password): array
{
    $jar = temp_jar($tag);
    $login = http_request('GET', '/login', $jar);
    $token = csrf_from($login['body']);
    if ($login['status'] !== 200 || $token === '') {
        return ['jar' => $jar, 'status' => 0, 'location' => ''];
    }
    $post = http_request('POST', '/login', $jar, [
        '_token' => $token,
        'username' => $username,
        'password' => $password,
    ]);

    return [
        'jar' => $jar,
        'status' => $post['status'],
        'location' => (string) ($post['headers']['location'] ?? ''),
    ];
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

function send_count(string $logFile): int
{
    $count = 0;
    foreach (mock_rows($logFile) as $row) {
        if (($row['path'] ?? '') === '/send') {
            $count++;
        }
    }

    return $count;
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

$suffix = strtolower(bin2hex(random_bytes(4)));
$passwordOld = 'Lama!Wifi2026';
$passwordNew = 'Baru!Wifi2026';
$createdUserIds = [];
$createdPelangganIds = [];
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
$maxOtp = $max('whatsapp_otp');
$maxOutbox = $max('whatsapp_outbox');
$maxUsage = $max('api_usage_log');
$maxAudit = $max('audit_log');
$maxConfigAudit = $max('api_config_audit');
$maxLoginAttempts = $max('login_attempts');

// Tabel khusus pengujian ini; mulai bersih agar hitungan rate limit deterministik.
$pdo->exec('DELETE FROM password_reset_authorizations');
$pdo->exec('DELETE FROM password_reset_events');

$cleanup = static function () use (
    $pdo,
    &$createdUserIds,
    &$createdPelangganIds,
    &$usernames,
    &$credentialId,
    $snapshotSettings,
    $snapshotActive,
    $maxOtp,
    $maxOutbox,
    $maxUsage,
    $maxAudit,
    $maxConfigAudit,
    $maxLoginAttempts,
    $mockLog
): void {
    $pdo->exec('DELETE FROM password_reset_events');
    $pdo->exec('DELETE FROM password_reset_authorizations');
    foreach ($createdUserIds as $userId) {
        $pdo->prepare('DELETE FROM notifikasi WHERE user_id = :id')->execute(['id' => $userId]);
    }
    foreach ($createdPelangganIds as $pelangganId) {
        $pdo->prepare('DELETE FROM pelanggan WHERE id = :id')->execute(['id' => $pelangganId]);
    }
    foreach ($createdUserIds as $userId) {
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
    $pdo->prepare('DELETE FROM audit_log WHERE id > :id')->execute(['id' => $maxAudit]);
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
    $createUser = static function (string $prefix, string $role, string $name) use ($pdo, $insertUser, $suffix, $passwordOld, &$usernames, &$createdUserIds): int {
        $username = $prefix . '_' . $suffix;
        $usernames[] = $username;
        $insertUser->execute([
            'username' => $username,
            'password_hash' => password_hash($passwordOld, PASSWORD_DEFAULT),
            'nama' => $name,
            'role' => $role,
        ]);
        $id = (int) $pdo->lastInsertId();
        $createdUserIds[] = $id;

        return $id;
    };

    $adminId = $createUser('t24admin', 'admin', 'Admin Uji Tahap 3');
    $staffId = $createUser('t24staff', 'cs', 'CS Uji Tahap 3');
    $userA = $createUser('t24a', 'pelanggan', 'Pelanggan A');
    $userB = $createUser('t24b', 'pelanggan', 'Pelanggan B');
    $userC = $createUser('t24c', 'pelanggan', 'Pelanggan C');
    $userD = $createUser('t24d', 'pelanggan', 'Pelanggan D');
    $userE = $createUser('t24e', 'pelanggan', 'Pelanggan E');
    $userU = $createUser('t24u', 'pelanggan', 'Pelanggan Belum Verifikasi');
    $admin = ['id' => $adminId, 'role' => 'admin', 'username' => 't24admin_' . $suffix];

    $insertPelanggan = $pdo->prepare(
        'INSERT INTO pelanggan
            (user_id, username_pppoe, nomor_whatsapp, nomor_whatsapp_verified_at,
             nomor_whatsapp_verified_fingerprint, alamat)
         VALUES
            (:user_id, :pppoe, :phone, :verified_at, :fingerprint, :alamat)'
    );
    $createPelanggan = static function (
        int $userId,
        string $pppoe,
        string $phone,
        bool $verified
    ) use ($pdo, $insertPelanggan, $suffix, &$createdPelangganIds): int {
        $normalized = whatsapp_number($phone);
        $fingerprint = $verified && $normalized !== null ? Crypto::fingerprint($normalized) : null;
        $insertPelanggan->execute([
            'user_id' => $userId,
            'pppoe' => $pppoe,
            'phone' => $phone,
            'verified_at' => $verified ? date('Y-m-d H:i:s') : null,
            'fingerprint' => $fingerprint,
            'alamat' => 'Fixture Tahap 3',
        ]);
        $id = (int) $pdo->lastInsertId();
        $createdPelangganIds[] = $id;

        return $id;
    };

    $phones = [
        'A' => '081300007701',
        'B' => '081300007702',
        'C' => '081300007703',
        'D' => '081300007704',
        'E' => '081200000000', // mock Fonnte menolak target berakhiran 0000
        'U' => '081300007705',
    ];
    $pppoe = [];
    $pelangganIds = [];
    foreach (['A' => $userA, 'B' => $userB, 'C' => $userC, 'D' => $userD, 'E' => $userE, 'U' => $userU] as $key => $userId) {
        $pppoe[$key] = 't24pppoe' . strtolower($key) . '_' . $suffix;
        $pelangganIds[$key] = $createPelanggan($userId, $pppoe[$key], $phones[$key], $key !== 'U');
    }

    $credentialId = ApiCredentialService::saveWhatsApp($admin, [
        'name' => 'Fonnte CDN Uji Tahap 3 ' . $suffix,
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
        'resend_cooldown' => '3600',
        'daily_user_limit' => '100',
        'daily_phone_limit' => '100',
        'send_mode' => 'text',
        'template_name' => '',
        'language_code' => 'id',
        'purposes' => ['reset_password' => '1', 'phone_verification' => '1'],
    ]);
    ApiCredential::resetCache();
    ApiIntegrationSetting::resetCache();

    $originalOtpRow = $pdo->query("SELECT settings_json FROM api_integration_settings WHERE feature_key = 'whatsapp_otp' LIMIT 1")->fetch();
    $originalOtpJson = (string) ($originalOtpRow['settings_json'] ?? '');
    $updateOtpSettings = static function (array $changes) use ($pdo, $originalOtpJson): void {
        $settings = json_decode($originalOtpJson, true);
        if (!is_array($settings)) {
            $settings = [];
        }
        foreach ($changes as $key => $value) {
            $settings[$key] = $value;
        }
        $pdo->prepare(
            "UPDATE api_integration_settings SET settings_json = :settings_json WHERE feature_key = 'whatsapp_otp'"
        )->execute(['settings_json' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        ApiIntegrationSetting::resetCache();
    };

    // ---------------------------------------------------------------------
    // Audit UI dan migrasi
    // ---------------------------------------------------------------------
    $loginPage = http_request('GET', '/login');
    check('Halaman login tampil', $loginPage['status'] === 200, 'status=' . $loginPage['status']);
    check('Tautan Lupa Password muncul di Login', str_contains($loginPage['body'], url('/lupa-password')));
    check('Login tetap memiliki tautan CSRF dan form masuk', str_contains($loginPage['body'], 'name="_token"') && str_contains($loginPage['body'], 'name="password"'));

    $guest = guest_page('awal');
    check('Halaman lupa password dapat dibuka', $guest['status'] === 200, 'status=' . $guest['status']);
    check('Tahap identifikasi meminta username PPPoE', str_contains($guest['body'], 'name="username"') && str_contains($guest['body'], 'Username PPPoE'));
    check('CSRF tersedia di halaman lupa password', $guest['token'] !== '');
    check('Light Mode default tersedia', str_contains($guest['body'], 'data-theme="light"'));
    check('Tombol Dark/Light tersedia', str_contains($guest['body'], 'data-theme-toggle'));
    check('Layout publik tidak memuat floating AI chatbot', !str_contains($guest['body'], 'ai-chat') && !str_contains($guest['body'], 'ai-widget'));
    check('Script timer lupa password dimuat', str_contains($guest['body'], 'lupa-password.js'));

    $columns = $pdo->query('SHOW COLUMNS FROM users')->fetchAll();
    check('Kolom session_version tersedia', in_array('session_version', array_column($columns, 'Field'), true));
    $authColumns = $pdo->query('SHOW COLUMNS FROM password_reset_authorizations')->fetchAll();
    check('Tabel otorisasi reset tersedia', in_array('token_hash', array_column($authColumns, 'Field'), true));
    $eventColumns = $pdo->query('SHOW COLUMNS FROM password_reset_events')->fetchAll();
    check('Tabel rate limit sumber tersedia', in_array('ip_hash', array_column($eventColumns, 'Field'), true));

    foreach (['/lupa-password', '/lupa-password/verifikasi', '/lupa-password/kirim-ulang', '/lupa-password/simpan', '/lupa-password/batal'] as $path) {
        $noCsrf = http_request('POST', $path, $guest['jar'], ['username' => 'x', 'otp' => '123456']);
        check('CSRF wajib pada ' . $path, $noCsrf['status'] === 419, 'status=' . $noCsrf['status']);
    }

    // ---------------------------------------------------------------------
    // RBAC dan regresi halaman Pelanggan
    // ---------------------------------------------------------------------
    $oldSession = login_response('old', 't24a_' . $suffix, $passwordOld);
    check('Login pelanggan lama berhasil sebelum reset', $oldSession['status'] === 302 && str_contains($oldSession['location'], '/pelanggan/dashboard'), $oldSession['location']);
    $guestWhenLoggedIn = http_request('GET', '/lupa-password', $oldSession['jar']);
    check('Pengguna yang sudah login diarahkan dari halaman lupa password', $guestWhenLoggedIn['status'] === 302 && str_contains((string) ($guestWhenLoggedIn['headers']['location'] ?? ''), '/pelanggan/dashboard'));

    $profile = http_request('GET', '/pelanggan/profil', $oldSession['jar']);
    check('Profil Pelanggan tetap dapat dibuka', $profile['status'] === 200, 'status=' . $profile['status']);
    check('Fitur Ganti Nomor WhatsApp tetap tampil', str_contains($profile['body'], 'Ganti Nomor WhatsApp'));
    $complaint = http_request('GET', '/pelanggan/pengaduan/baru', $oldSession['jar']);
    check('Form Buat Pengaduan tetap dapat dibuka', $complaint['status'] === 200, 'status=' . $complaint['status']);
    check('Form pengaduan tetap memiliki field nomor WhatsApp tersamarkan/readonly', str_contains($complaint['body'], 'whatsapp') || str_contains($complaint['body'], 'WhatsApp'));

    // ---------------------------------------------------------------------
    // Respons netral: staf, username tidak dikenal, nomor belum terverifikasi
    // ---------------------------------------------------------------------
    $sendBeforeStaff = send_count($mockLog);
    $staffGuest = guest_page('staff');
    http_request('POST', '/lupa-password', $staffGuest['jar'], [
        '_token' => $staffGuest['token'],
        'username' => 't24staff_' . $suffix,
    ]);
    $staffPage = http_request('GET', '/lupa-password', $staffGuest['jar']);
    check('Akun staf tidak dapat memulai reset mandiri', str_contains($staffPage['body'], 'Jika akun memenuhi syarat'));
    check('Tidak ada OTP dikirim untuk akun staf', send_count($mockLog) === $sendBeforeStaff);
    check('Tidak ada OTP dibuat untuk akun staf', (int) $pdo->query('SELECT COUNT(*) FROM whatsapp_otp WHERE user_id = ' . $staffId)->fetchColumn() === 0);

    $sendBeforeUnknown = send_count($mockLog);
    $unknownGuest = guest_page('unknown');
    $unknownRequest = http_request('POST', '/lupa-password', $unknownGuest['jar'], [
        '_token' => $unknownGuest['token'],
        'username' => 'tidak_ada_' . $suffix,
    ]);
    $unknownPage = http_request('GET', '/lupa-password', $unknownGuest['jar']);
    check('Username tidak dikenal mendapat redirect netral', $unknownRequest['status'] === 302);
    check('Username tidak dikenal tidak menerima OTP', send_count($mockLog) === $sendBeforeUnknown);
    check('Pesan netral tidak menyatakan akun tidak terdaftar', str_contains($unknownPage['body'], 'Jika akun memenuhi syarat') && !str_contains(strtolower($unknownPage['body']), 'tidak terdaftar'));
    check('Tahap verifikasi netral tampil untuk username tidak dikenal', str_contains($unknownPage['body'], 'name="otp"'));

    $sendBeforeUnverified = send_count($mockLog);
    $unverifiedGuest = guest_page('unverified');
    http_request('POST', '/lupa-password', $unverifiedGuest['jar'], [
        '_token' => $unverifiedGuest['token'],
        'username' => $pppoe['U'],
    ]);
    $unverifiedPage = http_request('GET', '/lupa-password', $unverifiedGuest['jar']);
    check('Nomor belum terverifikasi tidak menerima OTP reset', send_count($mockLog) === $sendBeforeUnverified);
    check('Tidak ada OTP dibuat untuk nomor belum terverifikasi', (int) $pdo->query('SELECT COUNT(*) FROM whatsapp_otp WHERE user_id = ' . $userU)->fetchColumn() === 0);
    check('Respons nomor belum terverifikasi tetap netral', str_contains($unverifiedPage['body'], 'Jika akun memenuhi syarat'));

    // ---------------------------------------------------------------------
    // Kegagalan gateway Fonnte
    // ---------------------------------------------------------------------
    $gatewayGuest = guest_page('gateway');
    http_request('POST', '/lupa-password', $gatewayGuest['jar'], [
        '_token' => $gatewayGuest['token'],
        'username' => $pppoe['E'],
    ]);
    $gatewayPage = http_request('GET', '/lupa-password', $gatewayGuest['jar']);
    $gatewayOtp = $pdo->query(
        'SELECT send_status, consumed_at FROM whatsapp_otp WHERE user_id = ' . $userE . " AND purpose = 'reset_password' ORDER BY id DESC LIMIT 1"
    )->fetch();
    check('Kegagalan provider tidak membocorkan detail ke UI', str_contains($gatewayPage['body'], 'Jika akun memenuhi syarat'));
    check('OTP gagal kirim ditandai failed', is_array($gatewayOtp) && (string) ($gatewayOtp['send_status'] ?? '') === 'failed');
    check('OTP gagal kirim langsung dikonsumsi', is_array($gatewayOtp) && ($gatewayOtp['consumed_at'] ?? null) !== null);
    check('Tidak ada otorisasi reset setelah gateway gagal', (int) $pdo->query('SELECT COUNT(*) FROM password_reset_authorizations WHERE user_id = ' . $userE)->fetchColumn() === 0);

    // ---------------------------------------------------------------------
    // Permintaan OTP ke nomor terverifikasi yang benar
    // ---------------------------------------------------------------------
    $guestA = guest_page('a');
    $requestA = http_request('POST', '/lupa-password', $guestA['jar'], [
        '_token' => $guestA['token'],
        'username' => $pppoe['A'],
    ]);
    $pageA = http_request('GET', '/lupa-password', $guestA['jar']);
    $sendA = last_send($mockLog);
    $codeA = otp_from_message((string) ($sendA['message'] ?? ''));
    $phoneANormalized = (string) whatsapp_number($phones['A']);
    check('Permintaan reset akun terverifikasi diterima', $requestA['status'] === 302);
    check('OTP tahap verifikasi tampil', str_contains($pageA['body'], 'name="otp"') && str_contains($pageA['body'], 'Kirim Ulang'));
    check('OTP dikirim ke nomor WhatsApp terverifikasi yang benar', (string) ($sendA['target'] ?? '') === $phoneANormalized, (string) ($sendA['target'] ?? ''));
    check('Pesan OTP memuat enam digit', preg_match('/^\d{6}$/', $codeA) === 1);
    check('Nomor WhatsApp lengkap tidak tampil di halaman publik', !str_contains($pageA['body'], $phones['A']) && !str_contains($pageA['body'], $phoneANormalized));
    check('Kode OTP tidak tampil di HTML', $codeA !== '' && !str_contains($pageA['body'], $codeA));
    $otpA = $pdo->query(
        'SELECT id, code_hash, send_status, consumed_at FROM whatsapp_otp WHERE user_id = ' . $userA . " AND purpose = 'reset_password' ORDER BY id DESC LIMIT 1"
    )->fetch();
    check('OTP tersimpan accepted', is_array($otpA) && (string) ($otpA['send_status'] ?? '') === 'accepted');
    check('OTP tidak disimpan plaintext', is_array($otpA) && !str_contains((string) ($otpA['code_hash'] ?? ''), $codeA));

    $guestB = guest_page('b');
    http_request('POST', '/lupa-password', $guestB['jar'], [
        '_token' => $guestB['token'],
        'username' => $pppoe['B'],
    ]);
    $sendB = last_send($mockLog);
    $codeB = otp_from_message((string) ($sendB['message'] ?? ''));
    check('OTP akun kedua dikirim ke nomor akun kedua', (string) ($sendB['target'] ?? '') === (string) whatsapp_number($phones['B']));
    check('Kode akun A dan B berbeda', $codeA !== '' && $codeB !== '' && $codeA !== $codeB);

    $wrongAccount = http_request('POST', '/lupa-password/verifikasi', $guestB['jar'], [
        '_token' => $guestB['token'],
        'otp' => $codeA,
    ]);
    $wrongAccountPage = http_request('GET', '/lupa-password', $guestB['jar']);
    check('OTP akun lain ditolak', $wrongAccount['status'] === 302 && str_contains($wrongAccountPage['body'], 'Kode tidak valid atau sudah kedaluwarsa'));
    check('OTP akun lain tidak membuat otorisasi untuk akun B', (int) $pdo->query('SELECT COUNT(*) FROM password_reset_authorizations WHERE user_id = ' . $userB)->fetchColumn() === 0);

    // ---------------------------------------------------------------------
    // Purpose isolation: OTP verifikasi nomor tidak boleh dipakai reset
    // ---------------------------------------------------------------------
    $accountB = PasswordReset::findAccountByUserId($userB);
    $purposeRequest = WhatsAppOtpService::request($accountB ?? [], 'phone_verification', $phones['B']);
    $sendPhone = last_send($mockLog);
    $codePhone = otp_from_message((string) ($sendPhone['message'] ?? ''));
    check('OTP purpose phone_verification dapat dikirim untuk pengujian isolasi', !empty($purposeRequest['ok']) && $codePhone !== '');
    $wrongPurpose = http_request('POST', '/lupa-password/verifikasi', $guestB['jar'], [
        '_token' => $guestB['token'],
        'otp' => $codePhone,
    ]);
    check('OTP phone_verification tidak dapat dipakai reset password', $wrongPurpose['status'] === 302 && (int) $pdo->query('SELECT COUNT(*) FROM password_reset_authorizations WHERE user_id = ' . $userB)->fetchColumn() === 0);

    // ---------------------------------------------------------------------
    // OTP kedaluwarsa
    // ---------------------------------------------------------------------
    $guestC = guest_page('c');
    http_request('POST', '/lupa-password', $guestC['jar'], [
        '_token' => $guestC['token'],
        'username' => $pppoe['C'],
    ]);
    $sendC = last_send($mockLog);
    $codeC = otp_from_message((string) ($sendC['message'] ?? ''));
    $otpC = $pdo->query(
        'SELECT id FROM whatsapp_otp WHERE user_id = ' . $userC . " AND purpose = 'reset_password' ORDER BY id DESC LIMIT 1"
    )->fetch();
    if (is_array($otpC)) {
        $pdo->prepare('UPDATE whatsapp_otp SET expires_at = DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 SECOND) WHERE id = :id')
            ->execute(['id' => (int) $otpC['id']]);
    }
    $expired = http_request('POST', '/lupa-password/verifikasi', $guestC['jar'], [
        '_token' => $guestC['token'],
        'otp' => $codeC,
    ]);
    $expiredPage = http_request('GET', '/lupa-password', $guestC['jar']);
    check('OTP kedaluwarsa ditolak', $expired['status'] === 302 && str_contains($expiredPage['body'], 'Kode tidak valid atau sudah kedaluwarsa'));
    check('OTP kedaluwarsa tidak membuat otorisasi', (int) $pdo->query('SELECT COUNT(*) FROM password_reset_authorizations WHERE user_id = ' . $userC)->fetchColumn() === 0);

    // ---------------------------------------------------------------------
    // Cooldown kirim ulang
    // ---------------------------------------------------------------------
    $sendCountBeforeResend = send_count($mockLog);
    $otpCountABeforeResend = (int) $pdo->query('SELECT COUNT(*) FROM whatsapp_otp WHERE user_id = ' . $userA . " AND purpose = 'reset_password'")->fetchColumn();
    $resend = http_request('POST', '/lupa-password/kirim-ulang', $guestA['jar'], [
        '_token' => $guestA['token'],
    ]);
    check('Kirim ulang saat cooldown tetap redirect', $resend['status'] === 302);
    check('Cooldown tidak mengirim OTP baru', send_count($mockLog) === $sendCountBeforeResend);
    check('Cooldown tidak mengganti OTP aktif', (int) $pdo->query('SELECT COUNT(*) FROM whatsapp_otp WHERE user_id = ' . $userA . " AND purpose = 'reset_password'")->fetchColumn() === $otpCountABeforeResend);

    // ---------------------------------------------------------------------
    // Batas harian per akun
    // ---------------------------------------------------------------------
    $updateOtpSettings(['daily_user_limit' => 1, 'resend_cooldown' => 0]);
    $guestD = guest_page('d');
    http_request('POST', '/lupa-password', $guestD['jar'], [
        '_token' => $guestD['token'],
        'username' => $pppoe['D'],
    ]);
    $sendAfterD1 = send_count($mockLog);
    http_request('POST', '/lupa-password', $guestD['jar'], [
        '_token' => $guestD['token'],
        'username' => $pppoe['D'],
    ]);
    check('Permintaan pertama akun D mengirim OTP', (int) $pdo->query('SELECT COUNT(*) FROM whatsapp_otp WHERE user_id = ' . $userD)->fetchColumn() === 1);
    check('Batas harian mencegah OTP kedua', send_count($mockLog) === $sendAfterD1 && (int) $pdo->query('SELECT COUNT(*) FROM whatsapp_otp WHERE user_id = ' . $userD)->fetchColumn() === 1);
    $updateOtpSettings(['daily_user_limit' => 100, 'resend_cooldown' => 3600]);

    // ---------------------------------------------------------------------
    // OTP salah lalu OTP benar, otorisasi password, dan reset berhasil
    // ---------------------------------------------------------------------
    $wrongCodeA = is_string($codeA) && $codeA !== ''
        ? substr($codeA, 0, 5) . (($codeA[5] === '9') ? '0' : (string) ((int) $codeA[5] + 1))
        : '000000';
    $wrongA = http_request('POST', '/lupa-password/verifikasi', $guestA['jar'], [
        '_token' => $guestA['token'],
        'otp' => $wrongCodeA,
    ]);
    $wrongAPage = http_request('GET', '/lupa-password', $guestA['jar']);
    check('OTP salah ditolak', $wrongA['status'] === 302 && str_contains($wrongAPage['body'], 'Kode tidak valid'));
    check('OTP salah tidak membuat otorisasi', (int) $pdo->query('SELECT COUNT(*) FROM password_reset_authorizations WHERE user_id = ' . $userA)->fetchColumn() === 0);

    $verifyA = http_request('POST', '/lupa-password/verifikasi', $guestA['jar'], [
        '_token' => $guestA['token'],
        'otp' => $codeA,
    ]);
    $passwordPage = http_request('GET', '/lupa-password', $guestA['jar']);
    check('OTP benar diterima', $verifyA['status'] === 302 && str_contains($passwordPage['body'], 'name="password_baru"'));
    check('Otorisasi reset dibuat setelah OTP benar', (int) $pdo->query('SELECT COUNT(*) FROM password_reset_authorizations WHERE user_id = ' . $userA . ' AND consumed_at IS NULL')->fetchColumn() === 1);
    $authRow = $pdo->query(
        'SELECT token_hash, expires_at FROM password_reset_authorizations WHERE user_id = ' . $userA . ' ORDER BY id DESC LIMIT 1'
    )->fetch();
    check('Token otorisasi disimpan sebagai HMAC 64 karakter', is_array($authRow) && preg_match('/^[a-f0-9]{64}$/', (string) ($authRow['token_hash'] ?? '')) === 1);
    check('Token/code tidak tampil di halaman password', !str_contains($passwordPage['body'], $codeA) && !str_contains($passwordPage['body'], (string) ($authRow['token_hash'] ?? '---')));

    $weak = http_request('POST', '/lupa-password/simpan', $guestA['jar'], [
        '_token' => $guestA['token'],
        'password_baru' => 'pendek',
        'password_konfirmasi' => 'pendek',
    ]);
    $weakPage = http_request('GET', '/lupa-password', $guestA['jar']);
    check('Password lemah ditolak', $weak['status'] === 302 && str_contains($weakPage['body'], 'minimal 8 karakter'));
    check('Otorisasi tetap tersedia setelah validasi password gagal', (int) $pdo->query('SELECT COUNT(*) FROM password_reset_authorizations WHERE user_id = ' . $userA . ' AND consumed_at IS NULL')->fetchColumn() === 1);

    $completeA = http_request('POST', '/lupa-password/simpan', $guestA['jar'], [
        '_token' => $guestA['token'],
        'password_baru' => $passwordNew,
        'password_konfirmasi' => $passwordNew,
    ]);
    check('Password baru berhasil disimpan dan diarahkan ke login', $completeA['status'] === 302 && str_contains((string) ($completeA['headers']['location'] ?? ''), '/login'));
    $successPage = http_request('GET', '/login', $guestA['jar']);
    check('Pesan sukses reset tampil di login', str_contains($successPage['body'], 'berhasil direset'));

    $userAfter = $pdo->query('SELECT password_hash, session_version FROM users WHERE id = ' . $userA)->fetch();
    check('Password baru tersimpan sebagai hash', is_array($userAfter) && password_verify($passwordNew, (string) $userAfter['password_hash']));
    check('Password lama tidak lagi cocok', is_array($userAfter) && !password_verify($passwordOld, (string) $userAfter['password_hash']));
    check('Password plaintext tidak tersimpan', is_array($userAfter) && !str_contains((string) $userAfter['password_hash'], $passwordNew));
    check('Session version dinaikkan setelah reset', is_array($userAfter) && (int) $userAfter['session_version'] === 1);
    check('Otorisasi reset dikonsumsi', (int) $pdo->query('SELECT COUNT(*) FROM password_reset_authorizations WHERE user_id = ' . $userA . ' AND consumed_at IS NULL')->fetchColumn() === 0);
    check('OTP reset dikonsumsi', (int) $pdo->query('SELECT COUNT(*) FROM whatsapp_otp WHERE user_id = ' . $userA . " AND purpose = 'reset_password' AND consumed_at IS NULL")->fetchColumn() === 0);

    $pelangganAfter = $pdo->query(
        'SELECT username_pppoe, nomor_whatsapp, nomor_whatsapp_verified_at FROM pelanggan WHERE id = ' . $pelangganIds['A']
    )->fetch();
    check('Username PPPoE tidak berubah', is_array($pelangganAfter) && (string) $pelangganAfter['username_pppoe'] === $pppoe['A']);
    check('Nomor WhatsApp tidak berubah oleh reset password', is_array($pelangganAfter) && (string) $pelangganAfter['nomor_whatsapp'] === $phones['A']);
    check('Status verifikasi nomor tetap ada', is_array($pelangganAfter) && ($pelangganAfter['nomor_whatsapp_verified_at'] ?? null) !== null);

    $oldSessionCheck = http_request('GET', '/pelanggan/dashboard', $oldSession['jar']);
    check('Sesi login lama tidak lagi valid', $oldSessionCheck['status'] === 302 && str_contains((string) ($oldSessionCheck['headers']['location'] ?? ''), '/login'));

    $oldPasswordLogin = login_response('oldpass', 't24a_' . $suffix, $passwordOld);
    check('Password lama tidak dapat dipakai login', $oldPasswordLogin['status'] === 302 && str_ends_with($oldPasswordLogin['location'], '/login'));
    $newPasswordLogin = login_response('newpass', 't24a_' . $suffix, $passwordNew);
    check('Password baru dapat dipakai login', $newPasswordLogin['status'] === 302 && str_contains($newPasswordLogin['location'], '/pelanggan/dashboard'));

    $accountA = PasswordReset::findAccountByUserId($userA);
    $reuse = WhatsAppOtpService::verify($accountA ?? [], 'reset_password', $phones['A'], $codeA);
    check('OTP lama tidak dapat dipakai dua kali', empty($reuse['ok']));

    $auditRows = $pdo->query(
        "SELECT aksi, metadata FROM audit_log WHERE actor_id = " . $userA . " ORDER BY id DESC LIMIT 20"
    )->fetchAll();
    $auditText = json_encode($auditRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    check('Audit reset berhasil tercatat', str_contains($auditText, 'password_reset_berhasil'));
    check('Audit tidak memuat password/OTP/token', !str_contains($auditText, $passwordNew)
        && !str_contains($auditText, $passwordOld)
        && ($codeA === '' || !str_contains($auditText, $codeA))
        && !str_contains($auditText, 'token_hash'));

    // ---------------------------------------------------------------------
    // Rate limit sumber permintaan
    // ---------------------------------------------------------------------
    $sourceHash = Crypto::fingerprint('password-reset-source:127.0.0.1');
    $insertEvent = $pdo->prepare(
        "INSERT INTO password_reset_events (user_id, ip_hash, event, success)
         VALUES (NULL, :ip_hash, 'request', 1)"
    );
    for ($i = 0; $i < 20; $i++) {
        $insertEvent->execute(['ip_hash' => $sourceHash]);
    }
    $sendBeforeRate = send_count($mockLog);
    $rateGuest = guest_page('rate');
    http_request('POST', '/lupa-password', $rateGuest['jar'], [
        '_token' => $rateGuest['token'],
        'username' => $pppoe['A'],
    ]);
    $ratePage = http_request('GET', '/lupa-password', $rateGuest['jar']);
    check('Rate limit sumber menolak permintaan berlebih', str_contains($ratePage['body'], 'Coba lagi nanti') && str_contains($ratePage['body'], 'name="username"'));
    check('Rate limit sumber tidak mengirim OTP', send_count($mockLog) === $sendBeforeRate);
    check('Tabel event tidak menyimpan username/nomor/OTP', in_array('username', array_column($eventColumns, 'Field'), true) === false
        && in_array('phone', array_column($eventColumns, 'Field'), true) === false
        && in_array('code', array_column($eventColumns, 'Field'), true) === false);

    check('Tidak ada kode OTP plaintext di tabel otorisasi', (int) $pdo->query("SELECT COUNT(*) FROM password_reset_authorizations WHERE token_hash LIKE '%" . $codeA . "%'")->fetchColumn() === 0);
} catch (Throwable $exception) {
    check('Pengujian selesai tanpa exception', false, $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine());
} finally {
    $cleanup();
}

echo "\n";
echo "Ringkasan Tahap 3 Lupa Password: {$PASS} PASS, {$FAIL} FAIL\n";
if ($FAILED !== []) {
    echo "Gagal:\n";
    foreach ($FAILED as $failure) {
        echo " - {$failure}\n";
    }
}
echo "BLOCKED  Pengujian live Fonnte/Meta (token nyata, pesan diterima pelanggan, reset end-to-end via WhatsApp) belum dilakukan.\n";
exit($FAIL > 0 ? 1 : 0);
