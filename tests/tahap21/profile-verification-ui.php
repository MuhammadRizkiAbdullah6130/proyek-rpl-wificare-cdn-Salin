<?php
/**
 * WiFiCare CDN - Tahap 21, Tahap 2B: integrasi UI verifikasi WhatsApp.
 *
 * Menguji halaman Profil Pelanggan setelah backend OTP terpasang:
 * - card, badge, nomor tersamarkan, dan form mengarah ke endpoint yang benar;
 * - modal OTP enam digit, CSRF, auto-open, serta kode tidak bocor ke HTML;
 * - cooldown ditampilkan dari state server dan tetap ditegakkan endpoint;
 * - nomor master tetap readonly dan tidak punya input editable;
 * - status berubah menjadi Terverifikasi hanya setelah OTP benar;
 * - halaman Buat Pengaduan tetap berfungsi.
 *
 * Prasyarat:
 *   php -S 127.0.0.1:8099 -t public public/index.php   (DB uji)
 *   php -S 127.0.0.1:8098 tests/tahap17/mock-whatsapp.php
 *
 * Jalankan dengan WA_MOCK_LOG yang sama untuk server mock dan pengujian:
 *   php tests/tahap21/profile-verification-ui.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../tahap10/_config.php';

putenv('DB_NAME=wificare_cdn_test');
$_ENV['DB_NAME'] = 'wificare_cdn_test';
$_SERVER['DB_NAME'] = 'wificare_cdn_test';

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
    $jar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_t21_ui_' . $tag . '_' . getmypid() . '.jar';
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

/** @return array{code:string,payload:array<string,mixed>} */
function last_otp_payload(string $logFile): array
{
    $rows = mock_rows($logFile);
    for ($i = count($rows) - 1; $i >= 0; $i--) {
        $payload = $rows[$i]['payload'] ?? null;
        if (!is_array($payload)) {
            continue;
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
                return ['code' => $code, 'payload' => $payload];
            }
        }
    }

    return ['code' => '', 'payload' => []];
}

$mockLog = getenv('WA_MOCK_LOG') ?: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_wa_mock.log';
@file_put_contents($mockLog, '');

try {
    $pdo = Database::pdo();
} catch (Throwable $exception) {
    blocked('Database uji tidak dapat dihubungi: ' . $exception->getMessage());
}
if (@file_get_contents(T10_BASE . '/login') === false) {
    blocked('Server aplikasi uji ' . T10_BASE . ' tidak aktif.');
}

$settingsBackup = $pdo->query(
    "SELECT * FROM api_integration_settings
     WHERE feature_key IN ('whatsapp_gateway','whatsapp_otp')"
)->fetchAll();
$startUsageId = (int) $pdo->query('SELECT COALESCE(MAX(id),0) FROM api_usage_log')->fetchColumn();
$suffix = strtolower(bin2hex(random_bytes(5)));
$password = 'Uji!' . bin2hex(random_bytes(12)) . '9';
$userIds = [];
$pelangganIds = [];
$credentialId = 0;

$cleanup = static function () use (
    $pdo,
    &$userIds,
    &$pelangganIds,
    &$credentialId,
    $settingsBackup,
    $startUsageId,
    $mockLog
): void {
    foreach ($userIds as $userId) {
        $pdo->prepare('DELETE FROM whatsapp_otp WHERE user_id = :id')->execute(['id' => $userId]);
        $pdo->prepare('DELETE FROM audit_log WHERE actor_id = :id')->execute(['id' => $userId]);
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
    foreach ($settingsBackup as $row) {
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

    $pdo->prepare('DELETE FROM api_usage_log WHERE id > :id')->execute(['id' => $startUsageId]);
    $pdo->exec("DELETE FROM whatsapp_outbox WHERE event_key LIKE 'otp:%'");
    $pdo->exec("DELETE FROM api_config_audit WHERE action LIKE 'pengaturan_%' AND created_at >= CURRENT_TIMESTAMP - INTERVAL 30 MINUTE");
    @unlink($mockLog);
};

try {
    $insertUser = $pdo->prepare(
        'INSERT INTO users (username, password_hash, nama, role, is_active)
         VALUES (:username, :password_hash, :nama, :role, 1)'
    );
    $createUser = static function (string $prefix, string $role, string $name) use ($pdo, $insertUser, $suffix, $password): int {
        $insertUser->execute([
            'username' => $prefix . '_' . $suffix,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'nama' => $name,
            'role' => $role,
        ]);
        return (int) $pdo->lastInsertId();
    };

    $adminId = $createUser('s2badmin', 'admin', 'Admin Uji Tahap 2B');
    $userP = $createUser('s2bpel', 'pelanggan', 'Pelanggan Uji Tahap 2B');
    $userIds = [$adminId, $userP];

    $insertPelanggan = $pdo->prepare(
        'INSERT INTO pelanggan (user_id, username_pppoe, nomor_whatsapp, alamat)
         VALUES (:user_id, :pppoe, :phone, :alamat)'
    );
    $insertPelanggan->execute([
        'user_id' => $userP,
        'pppoe' => 's2b_' . $suffix,
        'phone' => '081200009995',
        'alamat' => 'Fixture Tahap 2B',
    ]);
    $pelangganIds[] = (int) $pdo->lastInsertId();

    $admin = ['id' => $adminId, 'role' => 'admin', 'username' => 's2badmin_' . $suffix];
    $credentialId = ApiCredentialService::saveWhatsApp($admin, [
        'name' => 'Mock Tahap 2B ' . $suffix,
        'provider' => 'meta_cloud',
        'access_token' => 'test-token-stage2b-' . $suffix,
        'business_account_id' => 'waba-stage2b',
        'phone_number_id' => 'stage2bphone',
        'sender_number' => '6281200000000',
        'base_url' => 'http://127.0.0.1:8098/v20.0',
        'webhook_verify_token' => 'verify-stage2b',
        'webhook_secret' => 'secret-stage2b',
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
        'template' => 'Kode OTP WiFiCare CDN Anda adalah {kode}. Berlaku {masa_berlaku} menit.',
        'ttl_seconds' => '300',
        'max_attempts' => '5',
        'resend_cooldown' => '30',
        'daily_user_limit' => '100',
        'daily_phone_limit' => '100',
        'send_mode' => 'template',
        'template_name' => 'wificare_auth_stage2b',
        'language_code' => 'id',
        'purposes' => ['phone_verification' => '1', 'reset_password' => '1'],
    ]);
    ApiIntegrationSetting::resetCache();
    ApiCredential::resetCache();

    $login = login_user('pel', 's2bpel_' . $suffix, $password);
    check('Login pelanggan fixture berhasil', $login['ok']);
    if (!$login['ok']) {
        throw new RuntimeException('Login fixture gagal.');
    }

    $profile = http_request('GET', '/pelanggan/profil', $login['jar']);
    $token = csrf_from($profile['body']);
    $html = $profile['body'];

    check('Profil dapat dibuka', $profile['status'] === 200, 'status=' . $profile['status']);
    check('Card verifikasi tampil', str_contains($html, 'Verifikasi Nomor WhatsApp'));
    check('Badge awal Belum Terverifikasi', str_contains($html, 'Belum Terverifikasi'));
    check('Nomor pada card tersamarkan', str_contains($html, '+6281') && str_contains($html, '•'));
    check('State UI OTP siap', str_contains($html, 'data-otp-ready="1"'));
    check('Form kirim mengarah ke endpoint OTP', str_contains($html, 'action="' . e(url('/pelanggan/profil/whatsapp/otp')) . '"'));
    check('Form verifikasi mengarah ke endpoint verifikasi', str_contains($html, 'action="' . e(url('/pelanggan/profil/whatsapp/verifikasi')) . '"'));
    check('Modal OTP tersedia', str_contains($html, 'data-modal="profilOtpModal"'));
    check('Input OTP enam digit tersedia', str_contains($html, 'name="kode"') && str_contains($html, 'pattern="[0-9]{6}"'));
    check('CSRF tersedia di halaman', $token !== '');
    check('Script UI OTP dimuat', str_contains($html, 'pelanggan-profil-otp.js'));
    check('Nomor master tetap readonly', preg_match('/id="profil-whatsapp"[^>]*readonly/s', $html) === 1);
    check('Tidak ada input editable nomor_whatsapp', preg_match('/<input[^>]+name="nomor_whatsapp"/i', $html) !== 1);

    $noCsrf = http_request('POST', '/pelanggan/profil/whatsapp/verifikasi', $login['jar'], ['kode' => '123456']);
    check('Verifikasi tanpa CSRF ditolak 419', $noCsrf['status'] === 419, 'status=' . $noCsrf['status']);

    $send = http_request('POST', '/pelanggan/profil/whatsapp/otp', $login['jar'], ['_token' => $token]);
    check('Kirim OTP diterima endpoint', $send['status'] === 302, 'status=' . $send['status']);
    check('Kirim OTP redirect ke state OTP', str_contains((string) ($send['headers']['location'] ?? ''), '/pelanggan/profil?otp=1'));

    $otp = last_otp_payload($mockLog);
    $code = $otp['code'];
    check('Kode OTP diterima mock', preg_match('/^\d{6}$/', $code) === 1);

    $profileOtp = http_request('GET', '/pelanggan/profil?otp=1', $login['jar']);
    $htmlOtp = $profileOtp['body'];
    check('Profil state OTP dapat dibuka', $profileOtp['status'] === 200, 'status=' . $profileOtp['status']);
    check('Modal auto-open aktif', str_contains($htmlOtp, 'data-otp-auto-open="1"'));
    check('Kode OTP aktif terdeteksi UI', str_contains($htmlOtp, 'data-otp-has-active="1"'));
    check('Cooldown server diteruskan ke UI', preg_match('/data-otp-retry-after="(\d+)"/', $htmlOtp, $m) === 1 && (int) $m[1] > 0);
    check('Teks cooldown tampil', str_contains($htmlOtp, 'Kirim ulang tersedia dalam'));
    check('Kode OTP tidak bocor ke HTML', $code !== '' && !str_contains($htmlOtp, $code));
    check('Kode OTP tidak muncul di payload halaman', !str_contains($htmlOtp, 'code_hash'));

    $cooldown = http_request('POST', '/pelanggan/profil/whatsapp/otp', $login['jar'], ['_token' => $token]);
    check('Kirim ulang terlalu cepat tetap redirect', $cooldown['status'] === 302, 'status=' . $cooldown['status']);
    $profileCooldown = http_request('GET', '/pelanggan/profil?otp=1', $login['jar']);
    check('Pesan cooldown backend tampil', str_contains($profileCooldown['body'], 'Mohon tunggu sebelum meminta kode baru'));

    $verify = http_request('POST', '/pelanggan/profil/whatsapp/verifikasi', $login['jar'], [
        '_token' => $token,
        'kode' => $code,
    ]);
    check('Verifikasi OTP berhasil redirect', $verify['status'] === 302, 'status=' . $verify['status']);

    $profileDone = http_request('GET', '/pelanggan/profil', $login['jar']);
    $htmlDone = $profileDone['body'];
    check('Profil setelah verifikasi dapat dibuka', $profileDone['status'] === 200, 'status=' . $profileDone['status']);
    check('Badge menjadi Terverifikasi', str_contains($htmlDone, 'Terverifikasi'));
    check('Waktu verifikasi tampil', str_contains($htmlDone, 'sudah terverifikasi pada'));
    check('Form kirim OTP hilang setelah terverifikasi', !str_contains($htmlDone, 'id="profilOtpRequestForm"'));
    check('Modal OTP hilang setelah terverifikasi', !str_contains($htmlDone, 'data-modal="profilOtpModal"'));
    check('Nomor master tetap sama', str_contains($htmlDone, 'value="081200009995"'));
    check('Nomor master tetap readonly setelah verifikasi', preg_match('/id="profil-whatsapp"[^>]*readonly/s', $htmlDone) === 1);

    $status = PelangganWhatsAppVerificationService::status(Pelanggan::findByUserId($userP));
    check('Status verifikasi tersimpan di database', $status['verified'] === true);

    $complaint = http_request('GET', '/pelanggan/pengaduan/baru', $login['jar']);
    check('Form Buat Pengaduan tetap berfungsi', $complaint['status'] === 200 && str_contains($complaint['body'], 'Buat Pengaduan'));
} catch (Throwable $exception) {
    check('Tidak ada exception tak tertangani', false, $exception->getMessage());
} finally {
    $cleanup();
}

echo "\nTOTAL {$PASS} PASS | {$FAIL} FAIL\n";
