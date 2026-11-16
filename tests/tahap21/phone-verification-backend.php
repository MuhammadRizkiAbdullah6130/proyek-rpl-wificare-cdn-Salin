<?php
/**
 * WiFiCare CDN - Tahap 21, Tahap 2: backend verifikasi nomor WhatsApp.
 *
 * Menguji service dan endpoint backend tanpa Meta live:
 * - migration/purpose phone_verification;
 * - OTP HMAC, template Authentication, binding user/nomor/purpose;
 * - percobaan salah, kedaluwarsa, sekali pakai, cooldown, batas harian;
 * - status verifikasi dan penghapusan status saat nomor berubah;
 * - session, CSRF, RBAC endpoint.
 *
 * Prasyarat:
 *   php -S 127.0.0.1:8099 -t public public/index.php   (DB uji)
 *   php -S 127.0.0.1:8098 tests/tahap17/mock-whatsapp.php
 *
 * Jalankan:
 *   php tests/tahap21/phone-verification-backend.php
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
    $jar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_t21_otp_' . $tag . '_' . getmypid() . '.jar';
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
$suffix = strtolower(bin2hex(random_bytes(5)));
$password = 'Uji!' . bin2hex(random_bytes(12)) . '9';
$userIds = [];
$pelangganIds = [];
$credentialId = 0;
$startUsageId = (int) $pdo->query('SELECT COALESCE(MAX(id),0) FROM api_usage_log')->fetchColumn();
$settingsBackup = $pdo->query(
    "SELECT * FROM api_integration_settings
     WHERE feature_key IN ('whatsapp_gateway','whatsapp_otp')"
)->fetchAll();

$cleanup = static function () use (
    &$userIds,
    &$pelangganIds,
    &$credentialId,
    $pdo,
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
        $username = $prefix . '_' . $suffix;
        $insertUser->execute([
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'nama' => $name,
            'role' => $role,
        ]);
        return (int) $pdo->lastInsertId();
    };

    $adminId = $createUser('s2admin', 'admin', 'Admin Uji Tahap 2');
    $userA = $createUser('s2a', 'pelanggan', 'Pelanggan A Tahap 2');
    $userB = $createUser('s2b', 'pelanggan', 'Pelanggan B Tahap 2');
    $userC = $createUser('s2c', 'pelanggan', 'Pelanggan C Tahap 2');
    $userH = $createUser('s2h', 'pelanggan', 'Pelanggan HTTP Tahap 2');
    $userIds = [$adminId, $userA, $userB, $userC, $userH];

    $insertPelanggan = $pdo->prepare(
        'INSERT INTO pelanggan (user_id, username_pppoe, nomor_whatsapp, alamat)
         VALUES (:user_id, :pppoe, :phone, :alamat)'
    );
    $createPelanggan = static function (int $userId, string $prefix, string $phone) use ($pdo, $insertPelanggan, $suffix): int {
        $pppoe = $prefix . '_' . $suffix;
        $insertPelanggan->execute([
            'user_id' => $userId,
            'pppoe' => $pppoe,
            'phone' => $phone,
            'alamat' => 'Fixture Tahap 2',
        ]);
        return (int) $pdo->lastInsertId();
    };

    $pelAId = $createPelanggan($userA, 's2a', '081200009991');
    $pelBId = $createPelanggan($userB, 's2b', '081200009992');
    $pelCId = $createPelanggan($userC, 's2c', '081200009993');
    $pelHId = $createPelanggan($userH, 's2h', '081200009994');
    $pelangganIds = [$pelAId, $pelBId, $pelCId, $pelHId];

    $admin = ['id' => $adminId, 'role' => 'admin', 'username' => 's2admin_' . $suffix];
    $credentialId = ApiCredentialService::saveWhatsApp($admin, [
        'name' => 'Mock Tahap 2 ' . $suffix,
        'provider' => 'meta_cloud',
        'access_token' => 'test-token-stage2-' . $suffix,
        'business_account_id' => 'waba-stage2',
        'phone_number_id' => 'stage2phone',
        'sender_number' => '6281200000000',
        'base_url' => 'http://127.0.0.1:8098/v20.0',
        'webhook_verify_token' => 'verify-stage2',
        'webhook_secret' => 'secret-stage2',
        'is_active' => '1',
    ]);

    $templateText = 'Kode OTP WiFiCare CDN Anda adalah {kode}. Berlaku {masa_berlaku} menit. Jangan bagikan kode ini.';
    $saveOtp = static function (array $overrides = []) use ($admin, $credentialId, $templateText): void {
        WhatsAppSettingsService::saveOtp($admin, array_replace([
            'is_enabled' => '1',
            'connection_id' => (string) $credentialId,
            'template' => $templateText,
            'ttl_seconds' => '300',
            'max_attempts' => '5',
            'resend_cooldown' => '0',
            'daily_user_limit' => '100',
            'daily_phone_limit' => '100',
            'send_mode' => 'template',
            'template_name' => 'wificare_auth_stage2',
            'language_code' => 'id',
            'purposes' => ['phone_verification' => '1', 'reset_password' => '1'],
        ], $overrides));
    };

    WhatsAppSettingsService::saveGateway($admin, [
        'is_enabled' => '1',
        'connection_id' => (string) $credentialId,
        'default_country_code' => '62',
        'daily_limit' => '1000',
        'retry_max' => '3',
        'timeout' => '10',
    ]);
    $saveOtp();
    ApiIntegrationSetting::resetCache();
    ApiCredential::resetCache();

    check('Database uji dan migration 011 siap', (bool) $pdo->query(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pelanggan'
           AND COLUMN_NAME = 'nomor_whatsapp_verified_at' LIMIT 1"
    )->fetchColumn());
    check('Purpose phone_verification tersedia di enum', str_contains(
        (string) $pdo->query(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_otp' AND COLUMN_NAME = 'purpose'"
        )->fetchColumn(),
        'phone_verification'
    ));
    check('Purpose ditampilkan untuk konfigurasi Admin', isset(ApiFeatureRegistry::adminPurposes()['phone_verification']));

    $actorA = ['id' => $userA, 'role' => 'pelanggan', 'username' => 's2a_' . $suffix];
    $actorB = ['id' => $userB, 'role' => 'pelanggan', 'username' => 's2b_' . $suffix];
    $actorC = ['id' => $userC, 'role' => 'pelanggan', 'username' => 's2c_' . $suffix];
    $pelA = Pelanggan::findByUserId($userA);
    $pelB = Pelanggan::findByUserId($userB);
    $pelC = Pelanggan::findByUserId($userC);

    check('Status awal nomor A belum terverifikasi', PelangganWhatsAppVerificationService::status($pelA)['verified'] === false);

    $requestA = PelangganWhatsAppVerificationService::request($actorA, $pelA);
    check('Request OTP A diterima mock provider', !empty($requestA['ok']), (string) ($requestA['code'] ?? ''));
    $payloadA = last_otp_payload($mockLog);
    $codeA = $payloadA['code'];
    check('Kode OTP tersedia dari payload mock', preg_match('/^\d{6}$/', $codeA) === 1);

    $payload = $payloadA['payload'];
    $components = $payload['template']['components'] ?? [];
    check('Pengiriman memakai tipe template', (string) ($payload['type'] ?? '') === 'template');
    check('Nama template berasal dari konfigurasi Admin', (string) ($payload['template']['name'] ?? '') === 'wificare_auth_stage2');
    check('Bahasa template berasal dari konfigurasi Admin', (string) ($payload['template']['language']['code'] ?? '') === 'id');
    check('Body template memuat kode OTP', (string) ($components[0]['parameters'][0]['text'] ?? '') === $codeA);
    check(
        'Komponen tombol Authentication memuat kode yang sama',
        (string) ($components[1]['type'] ?? '') === 'button'
            && (string) ($components[1]['sub_type'] ?? '') === 'url'
            && (string) ($components[1]['parameters'][0]['text'] ?? '') === $codeA
    );

    $otpRow = $pdo->prepare('SELECT * FROM whatsapp_otp WHERE user_id = :id ORDER BY id DESC LIMIT 1');
    $otpRow->execute(['id' => $userA]);
    $otpRow = $otpRow->fetch();
    check('Purpose OTP tersimpan sebagai phone_verification', (string) ($otpRow['purpose'] ?? '') === 'phone_verification');
    check('Kode OTP tidak disimpan plaintext', ($otpRow['code_hash'] ?? '') !== $codeA && !str_contains((string) ($otpRow['code_hash'] ?? ''), $codeA));
    check('Verifier OTP memakai HMAC 64 karakter', strlen((string) ($otpRow['code_hash'] ?? '')) === 64 && ctype_xdigit((string) $otpRow['code_hash']));
    check('Nomor OTP tidak disimpan plaintext', strlen((string) ($otpRow['phone_fingerprint'] ?? '')) === 64);

    $wrongA = PelangganWhatsAppVerificationService::verify($actorA, $pelA, '000000');
    check('OTP salah ditolak', empty($wrongA['ok']));
    check('Nomor tetap belum terverifikasi setelah OTP salah', PelangganWhatsAppVerificationService::status(Pelanggan::findByUserId($userA))['verified'] === false);

    $goodA = PelangganWhatsAppVerificationService::verify($actorA, $pelA, $codeA);
    check('OTP benar diterima', !empty($goodA['ok']), (string) ($goodA['code'] ?? ''));
    $pelA = Pelanggan::findByUserId($userA);
    $statusA = PelangganWhatsAppVerificationService::status($pelA);
    check('Status nomor A menjadi terverifikasi', $statusA['verified'] === true);
    check('Waktu verifikasi tersimpan', !empty($pelA['nomor_whatsapp_verified_at']));
    check('Nomor utama tidak berubah saat verifikasi', (string) $pelA['nomor_whatsapp'] === '081200009991');
    check('OTP A sudah dikonsumsi', (string) ($pdo->query('SELECT consumed_at FROM whatsapp_otp WHERE user_id = ' . $userA . ' ORDER BY id DESC LIMIT 1')->fetchColumn() ?? '') !== '');

    $pdo->prepare('UPDATE pelanggan SET nomor_whatsapp_verified_at = NULL, nomor_whatsapp_verified_fingerprint = NULL WHERE id = :id')
        ->execute(['id' => $pelAId]);
    $pelA = Pelanggan::findByUserId($userA);
    $reuseA = PelangganWhatsAppVerificationService::verify($actorA, $pelA, $codeA);
    check('Kode OTP yang sudah dipakai tidak dapat dipakai ulang', empty($reuseA['ok']));
    Pelanggan::markWhatsappVerified($pelAId, (string) $pelA['nomor_whatsapp'], (string) Crypto::fingerprint((string) whatsapp_number((string) $pelA['nomor_whatsapp'])));

    $requestB = PelangganWhatsAppVerificationService::request($actorB, $pelB);
    $codeB = last_otp_payload($mockLog)['code'];
    check('Request OTP B berhasil', !empty($requestB['ok']) && $codeB !== '');
    $wrongPhoneB = WhatsAppOtpService::verify($actorB, WhatsAppOtpService::PURPOSE_PHONE_VERIFICATION, '6281200009991', $codeB);
    check('OTP terikat pada nomor tujuan', empty($wrongPhoneB['ok']));
    $crossAccount = PelangganWhatsAppVerificationService::verify($actorC, Pelanggan::findByUserId($userC), $codeB);
    check('Akun lain tidak dapat memakai OTP pelanggan B', empty($crossAccount['ok']));
    $goodB = PelangganWhatsAppVerificationService::verify($actorB, $pelB, $codeB);
    check('OTP B yang benar diterima', !empty($goodB['ok']));

    $requestC = PelangganWhatsAppVerificationService::request($actorC, $pelC);
    $codeC = last_otp_payload($mockLog)['code'];
    check('Request OTP C berhasil', !empty($requestC['ok']) && $codeC !== '');
    $wrongPurposeC = WhatsAppOtpService::verify($actorC, 'reset_password', (string) $pelC['nomor_whatsapp'], $codeC);
    check('OTP terikat pada purpose phone_verification', empty($wrongPurposeC['ok']));

    $pdo->prepare('UPDATE whatsapp_otp SET expires_at = DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 SECOND) WHERE user_id = :id')
        ->execute(['id' => $userC]);
    $expiredC = PelangganWhatsAppVerificationService::verify($actorC, $pelC, $codeC);
    check('OTP kedaluwarsa ditolak', empty($expiredC['ok']) && (string) ($expiredC['code'] ?? '') === 'expired');

    $saveOtp(['resend_cooldown' => '120']);
    ApiIntegrationSetting::resetCache();
    $cooldownC = PelangganWhatsAppVerificationService::request($actorC, $pelC);
    check('Cooldown kirim ulang diterapkan', empty($cooldownC['ok']) && (string) ($cooldownC['code'] ?? '') === 'cooldown');
    check('Cooldown memberi retry_after', (int) ($cooldownC['retry_after'] ?? 0) > 0);

    $saveOtp(['resend_cooldown' => '0', 'daily_user_limit' => '1']);
    ApiIntegrationSetting::resetCache();
    $pdo->prepare('DELETE FROM whatsapp_otp WHERE user_id = :id')->execute(['id' => $userC]);
    $limitFirst = PelangganWhatsAppVerificationService::request($actorC, $pelC);
    $limitSecond = PelangganWhatsAppVerificationService::request($actorC, $pelC);
    check('Batas OTP per pengguna/hari diterapkan', !empty($limitFirst['ok']) && empty($limitSecond['ok']) && (string) ($limitSecond['code'] ?? '') === 'daily_user_limit');

    $saveOtp(['resend_cooldown' => '0', 'daily_user_limit' => '100', 'send_mode' => 'text', 'template_name' => '']);
    ApiIntegrationSetting::resetCache();
    $pdo->prepare('DELETE FROM whatsapp_otp WHERE user_id = :id')->execute(['id' => $userC]);
    $textFallback = PelangganWhatsAppVerificationService::request($actorC, $pelC);
    check('Tidak ada fallback teks untuk phone_verification', empty($textFallback['ok']) && (string) ($textFallback['code'] ?? '') === 'template_required');
    $otpCountC = (int) $pdo->query('SELECT COUNT(*) FROM whatsapp_otp WHERE user_id = ' . $userC)->fetchColumn();
    check('Template wajib gagal tanpa membuat OTP', $otpCountC === 0);

    $saveOtp();
    ApiIntegrationSetting::resetCache();
    $adminRole = PelangganWhatsAppVerificationService::request(['id' => $adminId, 'role' => 'admin'], $pelC);
    check('Service menolak aktor non-Pelanggan', empty($adminRole['ok']) && (string) ($adminRole['code'] ?? '') === 'forbidden');
    $otherOwner = PelangganWhatsAppVerificationService::request($actorC, $pelB);
    check('Service menolak baris pelanggan milik akun lain', empty($otherOwner['ok']) && (string) ($otherOwner['code'] ?? '') === 'forbidden');

    // Perubahan nomor oleh Admin harus membatalkan status verifikasi lama.
    Pelanggan::update($pelAId, (string) $pelA['username_pppoe'], '081200008888', 'Alamat berubah');
    $pelAAfter = Pelanggan::findByUserId($userA);
    check('Perubahan nomor membatalkan status verifikasi', PelangganWhatsAppVerificationService::status($pelAAfter)['verified'] === false);
    check('Fingerprint verifikasi lama dibersihkan', ($pelAAfter['nomor_whatsapp_verified_fingerprint'] ?? null) === null);

    // Endpoint HTTP: session, CSRF, RBAC, request + verify.
    $loginH = login_user('h', 's2h_' . $suffix, $password);
    check('Login pelanggan HTTP berhasil', $loginH['ok']);
    $profileH = http_request('GET', '/pelanggan/profil', $loginH['jar']);
    $tokenH = csrf_from($profileH['body']);
    check('Token CSRF Profil tersedia', $profileH['status'] === 200 && $tokenH !== '');

    $noCsrf = http_request('POST', '/pelanggan/profil/whatsapp/otp', $loginH['jar'], []);
    check('POST OTP tanpa CSRF ditolak 419', $noCsrf['status'] === 419, 'status=' . $noCsrf['status']);

    $httpRequest = http_request('POST', '/pelanggan/profil/whatsapp/otp', $loginH['jar'], ['_token' => $tokenH]);
    check('Endpoint request OTP menerima sesi Pelanggan', $httpRequest['status'] === 302, 'status=' . $httpRequest['status']);
    check('Endpoint request mengarah ke state OTP', str_contains((string) ($httpRequest['headers']['location'] ?? ''), '/pelanggan/profil?otp=1'));
    $codeH = last_otp_payload($mockLog)['code'];
    check('Endpoint request benar-benar mengirim ke mock', preg_match('/^\d{6}$/', $codeH) === 1);

    $httpVerify = http_request('POST', '/pelanggan/profil/whatsapp/verifikasi', $loginH['jar'], [
        '_token' => $tokenH,
        'kode' => $codeH,
    ]);
    check('Endpoint verifikasi OTP berhasil redirect', $httpVerify['status'] === 302, 'status=' . $httpVerify['status']);
    $pelH = Pelanggan::findByUserId($userH);
    check('Endpoint HTTP menandai nomor terverifikasi', PelangganWhatsAppVerificationService::status($pelH)['verified'] === true);

    $adminLogin = login_user('admin', 's2admin_' . $suffix, $password);
    check('Login Admin fixture berhasil', $adminLogin['ok']);
    $adminPage = http_request('GET', '/admin/dashboard', $adminLogin['jar']);
    $adminToken = csrf_from($adminPage['body']);
    $adminPost = http_request('POST', '/pelanggan/profil/whatsapp/otp', $adminLogin['jar'], ['_token' => $adminToken]);
    check('RBAC menolak Admin ke endpoint OTP Pelanggan', $adminPost['status'] === 403, 'status=' . $adminPost['status']);
} catch (Throwable $exception) {
    check('Tidak ada exception tak tertangani', false, $exception->getMessage());
} finally {
    $cleanup();
}

echo "\nTOTAL {$PASS} PASS | {$FAIL} FAIL\n";
if ($FAILED !== []) {
    echo "FAILED:\n";
    foreach ($FAILED as $item) {
        echo " - {$item}\n";
    }
}

exit($FAIL === 0 ? 0 : 1);
