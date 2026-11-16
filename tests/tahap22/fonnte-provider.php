<?php
/**
 * WiFiCare CDN — Tahap 22: Integrasi Provider Fonnte.
 *
 * Menguji:
 * - provider Fonnte terdaftar tanpa menghapus Meta;
 * - kredensial Fonnte tanpa field khusus Meta + token terenkripsi;
 * - adapter /device dan /send sesuai kontrak resmi Fonnte;
 * - normalisasi nomor, timeout/HTTP error, respons provider gagal;
 * - pemilihan koneksi default;
 * - OTP provider-aware (Fonnte teks, Meta tetap template);
 * - idempotensi antrean;
 * - UI Admin, CSRF, RBAC, Dark/Light, dan tidak ada token di HTML.
 *
 * Prasyarat HTTP: server uji DB test di http://localhost:8099.
 * Prasyarat mock Fonnte: php -S 127.0.0.1:8097 tests/tahap22/mock-fonnte.php
 * Mock Meta (opsional): php -S 127.0.0.1:8098 tests/tahap17/mock-whatsapp.php
 *
 * Jalankan:
 *   php tests/tahap22/fonnte-provider.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../tahap10/_config.php';
t10_require_passwords(['T10_PW_ADMIN', 'T10_PW_CS']);

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

function exception_message(callable $fn): string
{
    try {
        $fn();

        return '';
    } catch (Throwable $exception) {
        return $exception->getMessage();
    }
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

function app_path(string $path): string
{
    $resolved = url($path);
    $parsed = parse_url($resolved, PHP_URL_PATH);

    return is_string($parsed) && $parsed !== '' ? $parsed : $path;
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

/** @return array{0:string,1:string} jar, login status */
function login(string $tag, string $username, string $password): array
{
    $jar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_t22_' . $tag . '_' . getmypid() . '.jar';
    @unlink($jar);
    [$status, $html] = http_call('GET', app_path('/login'), $jar);
    if ($status !== 200) {
        return [$jar, ''];
    }
    $token = csrf_from($html);
    [$postStatus] = http_call('POST', app_path('/login'), $jar, [
        '_token' => $token,
        'username' => $username,
        'password' => $password,
    ]);

    return [$jar, $postStatus === 302 ? 'ok' : ''];
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

function last_send_log(string $path): array
{
    $rows = json_lines($path);
    for ($i = count($rows) - 1; $i >= 0; $i--) {
        if (($rows[$i]['path'] ?? '') === '/send') {
            return $rows[$i];
        }
    }

    return [];
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

$pdo = Database::pdo();
check('Database uji terhubung', (string) db_config('database') === 'wificare_cdn_test', (string) db_config('database'));

$admin = $pdo->query("SELECT id, username, nama, role FROM users WHERE username = 'admin.budi' LIMIT 1")->fetch();
check('Akun admin uji tersedia', is_array($admin));
if (!is_array($admin)) {
    exit(1);
}

$mockLog = getenv('FONNTE_MOCK_LOG') ?: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_fonnte_mock.log';
@file_put_contents($mockLog, '');
$metaMockLog = getenv('WA_MOCK_LOG') ?: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_wa_mock.log';

$suffix = bin2hex(random_bytes(4));
$createdCredentialIds = [];
$tempUserId = 0;

// Snapshot data yang akan diubah agar DB uji dapat dipakai ulang.
$snapshotSettings = $pdo->query('SELECT * FROM api_integration_settings')->fetchAll();
$snapshotActive = $pdo->query('SELECT id, is_active FROM api_credentials')->fetchAll();
$max = static function (string $table) use ($pdo): int {
    return (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM ' . $table)->fetchColumn();
};
$maxOutbox = $max('whatsapp_outbox');
$maxOtp = $max('whatsapp_otp');
$maxUsage = $max('api_usage_log');
$maxConfigAudit = $max('api_config_audit');
$maxAuditLog = $max('audit_log');

ApiCredential::resetCache();
ApiIntegrationSetting::resetCache();

try {
    section('Registry Provider');
    $labels = WhatsAppProviderRegistry::labels();
    check('Provider Fonnte muncul di daftar provider', isset($labels['fonnte']) && str_contains($labels['fonnte'], 'Fonnte'));
    check('Provider Meta tetap tersedia', isset($labels['meta_cloud']) && str_contains($labels['meta_cloud'], 'Meta'));
    $fonnte = WhatsAppProviderRegistry::get('fonnte');
    $meta = WhatsAppProviderRegistry::get('meta_cloud');
    check('Adapter Fonnte terdaftar', $fonnte instanceof WhatsAppFonnteProvider);
    check('Fonnte adalah provider teks (tanpa template Meta)', $fonnte !== null && !$fonnte->supportsTemplates());
    check('Meta tetap mendukung template', $meta !== null && $meta->supportsTemplates());
    check('Base URL default Fonnte resmi', $fonnte !== null && $fonnte->defaultBase() === 'https://api.fonnte.com');

    section('Kredensial Fonnte');
    $fonnteToken = 'test-fonnte-token-' . $suffix;
    $fonnteId = ApiCredentialService::saveWhatsApp($admin, [
        'name' => 'Fonnte CDN Uji ' . $suffix,
        'provider' => 'fonnte',
        'access_token' => $fonnteToken,
        'sender_number' => '0812-3456-7890',
        'base_url' => 'http://127.0.0.1:8097',
        'is_active' => '1',
    ]);
    $createdCredentialIds[] = $fonnteId;
    check('Kredensial Fonnte tersimpan tanpa WABA/Phone Number ID', $fonnteId > 0);

    $fonnteRow = ApiCredential::find($fonnteId);
    $fonnteSafe = $fonnteRow === null ? [] : ApiCredential::safe($fonnteRow);
    check('Provider tersimpan sebagai fonnte', ($fonnteRow['provider'] ?? '') === 'fonnte');
    check('Phone Number ID Meta tidak diwajibkan', ($fonnteSafe['phone_number_id'] ?? 'x') === '');
    check('Nomor pengirim dinormalisasi', ($fonnteSafe['sender_number'] ?? '') === '6281234567890');
    check('Token Fonnte disimpan sebagai ciphertext', str_starts_with((string) ($fonnteRow['secret_encrypted'] ?? ''), 'v1:'));
    check('Token plaintext tidak ada di database', !str_contains((string) ($fonnteRow['secret_encrypted'] ?? ''), $fonnteToken));
    check('Data aman tidak membawa ciphertext/plaintext', !str_contains(json_encode($fonnteSafe), 'v1:') && !str_contains(json_encode($fonnteSafe), $fonnteToken));
    check('Token Fonnte dapat didekripsi adapter', (ApiCredentialService::connection($fonnteId)['token'] ?? '') === $fonnteToken);

    $metaMissingPhone = exception_message(static function () use ($admin): void {
        ApiCredentialService::saveWhatsApp($admin, [
            'name' => 'Meta tanpa phone id',
            'provider' => 'meta_cloud',
            'access_token' => 'test-token-meta-tanpa-phone',
        ]);
    });
    check('Meta tetap mewajibkan Phone Number ID', $metaMissingPhone !== '');

    $metaId = ApiCredentialService::saveWhatsApp($admin, [
        'name' => 'Meta Regresi Uji ' . $suffix,
        'provider' => 'meta_cloud',
        'access_token' => 'test-token-meta-regression-' . $suffix,
        'business_account_id' => 'waba-regression',
        'phone_number_id' => 'meta' . $suffix,
        'sender_number' => '6281200000000',
        'base_url' => 'http://127.0.0.1:8098/v20.0',
        'webhook_verify_token' => 'verify-regression',
        'webhook_secret' => 'secret-regression',
    ]);
    $createdCredentialIds[] = $metaId;
    check('Kredensial Meta tetap dapat disimpan', $metaId > 0);
    check('Kredensial Meta tidak rusak oleh penambahan Fonnte', ApiCredentialService::providerForConnection($metaId) === 'meta_cloud');

    section('Adapter Fonnte');
    $test = ApiCredentialService::testWhatsApp($admin, $fonnteId);
    check('Tes koneksi Fonnte /device berhasil', !empty($test['ok']), json_encode($test));
    check('Tes koneksi tidak membocorkan token', !str_contains(json_encode($test), $fonnteToken));
    check('Status tes terakhir dicatat terhubung', (ApiCredential::find($fonnteId)['last_test_status'] ?? '') === 'connected');

    $badDevice = $fonnte === null ? [] : $fonnte->testConnection([
        'token' => 'test-fonnte-disconnect',
        'base_url' => 'http://127.0.0.1:8097',
    ], 5);
    check('Device Fonnte disconnect ditolak', empty($badDevice['ok']) && ($badDevice['code'] ?? '') === 'device_disconnected');

    $send = $fonnte === null ? [] : $fonnte->sendText(
        ApiCredentialService::connection($fonnteId),
        '+62 812-3456-7890',
        'Tes pengiriman Fonnte ' . $suffix,
        10
    );
    check('Kirim teks Fonnte accepted', !empty($send['ok']) && ($send['status'] ?? '') === 'accepted', json_encode($send));
    check('ID pesan Fonnte tersimpan di hasil', (string) ($send['provider_message_id'] ?? '') !== '');
    check('Hasil kirim tidak membocorkan token', !str_contains(json_encode($send), $fonnteToken));
    $sendLog = last_send_log($mockLog);
    check('Target Fonnte memakai nomor E.164', ($sendLog['target'] ?? '') === '6281234567890', json_encode($sendLog));
    check('countryCode 0 mencegah normalisasi ganda', ($sendLog['countryCode'] ?? '') === '0');
    check('Pesan teks terkirim ke mock', str_contains((string) ($sendLog['message'] ?? ''), 'Tes pengiriman Fonnte'));
    check('Log mock tidak memuat token', !str_contains(json_encode($sendLog), $fonnteToken));

    $badSend = $fonnte === null ? [] : $fonnte->sendText([
        'token' => 'test-fonnte-bad',
        'base_url' => 'http://127.0.0.1:8097',
    ], '6281234567890', 'Tes token salah', 5);
    check('Respons status false ditangani', empty($badSend['ok']) && ($badSend['code'] ?? '') === 'provider_rejected');
    check('Error provider tidak membocorkan token', !str_contains((string) ($badSend['message'] ?? ''), 'test-fonnte-bad'));

    $httpError = $fonnte === null ? [] : $fonnte->sendText([
        'token' => 'test-fonnte-http',
        'base_url' => 'http://127.0.0.1:8097',
    ], '6281234567890', 'Tes HTTP error', 5);
    check('HTTP error provider ditangani', empty($httpError['ok']) && ($httpError['code'] ?? '') === 'http_500');

    $emptyResponse = $fonnte === null ? [] : $fonnte->sendText([
        'token' => 'test-fonnte-empty',
        'base_url' => 'http://127.0.0.1:8097',
    ], '6281234567890', 'Tes respons tanpa id', 5);
    check('Respons sukses tanpa ID ditolak', empty($emptyResponse['ok']) && ($emptyResponse['code'] ?? '') === 'invalid_response');

    $badTarget = $fonnte === null ? [] : $fonnte->sendText(
        ApiCredentialService::connection($fonnteId),
        '6281200000000',
        'Tes target invalid',
        5
    );
    check('Target invalid dari provider ditangani', empty($badTarget['ok']) && ($badTarget['code'] ?? '') === 'provider_rejected');

    $normalized = WhatsAppMessagingService::normalizeRecipient('0812 3456 7890');
    check('Normalisasi nomor Indonesia', $normalized === '6281234567890');

    section('Koneksi Default');
    ApiCredentialService::setActive($admin, $fonnteId);
    $active = ApiCredential::active();
    check('Fonnte dapat dijadikan koneksi default', (int) ($active['id'] ?? 0) === $fonnteId && ($active['provider'] ?? '') === 'fonnte');
    $options = ApiCredentialService::connectionOptions();
    check('Fonnte muncul di pilihan koneksi', isset($options[(string) $fonnteId]) && str_contains($options[(string) $fonnteId], 'Fonnte CDN'));

    section('OTP Provider-Aware');
    WhatsAppSettingsService::saveGateway($admin, [
        'is_enabled' => '1',
        'connection_id' => (string) $fonnteId,
        'default_country_code' => '62',
        'daily_limit' => '1000',
        'retry_max' => '3',
        'timeout' => '10',
    ]);
    check('Gateway aktif dengan koneksi Fonnte', ApiIntegrationSetting::isEnabled(ApiFeatureRegistry::WHATSAPP_GATEWAY));

    $otpSaveError = exception_message(static function () use ($admin, $fonnteId): void {
        WhatsAppSettingsService::saveOtp($admin, [
            'is_enabled' => '1',
            'connection_id' => (string) $fonnteId,
            'template' => 'Kode verifikasi WiFiCare CDN Anda adalah {kode}. Berlaku {masa_berlaku} menit. Jangan berikan kode ini kepada siapa pun.',
            'ttl_seconds' => '300',
            'max_attempts' => '5',
            'resend_cooldown' => '0',
            'daily_user_limit' => '100',
            'daily_phone_limit' => '100',
            'send_mode' => 'template',
            'template_name' => '',
            'language_code' => 'id',
            'purposes' => ['phone_verification' => '1', 'reset_password' => '1'],
        ]);
    });
    check('OTP Fonnte tidak mewajibkan nama template Meta', $otpSaveError === '', $otpSaveError);

    $insertUser = $pdo->prepare(
        'INSERT INTO users (username, password_hash, nama, role, is_active)
         VALUES (:username, :password_hash, :nama, :role, 1)'
    );
    $insertUser->execute([
        'username' => 't22_' . $suffix,
        'password_hash' => password_hash('T22!UjiFonnte', PASSWORD_DEFAULT),
        'nama' => 'Uji OTP Fonnte',
        'role' => 'pelanggan',
    ]);
    $tempUserId = (int) $pdo->lastInsertId();
    $phone = '081234567890';
    $actor = ['id' => $tempUserId, 'role' => 'pelanggan', 'username' => 't22_' . $suffix];

    $panel = PelangganWhatsAppVerificationService::panelState([
        'id' => 0,
        'user_id' => $tempUserId,
        'nomor_whatsapp' => $phone,
    ]);
    check('Panel verifikasi siap dengan Fonnte', !empty($panel['ready']) && ($panel['provider'] ?? '') === 'fonnte');
    check('Panel memakai mode teks Fonnte', ($panel['delivery_mode'] ?? '') === 'text');

    @file_put_contents($mockLog, '');
    $otpRequest = WhatsAppOtpService::request($actor, 'phone_verification', $phone);
    $otpLog = last_send_log($mockLog);
    $otpMessage = (string) ($otpLog['message'] ?? '');
    preg_match('/\b(\d{6})\b/', $otpMessage, $codeMatch);
    $otpCode = (string) ($codeMatch[1] ?? '');
    check('OTP Fonnte berhasil diminta', !empty($otpRequest['ok']), json_encode($otpRequest));
    check('OTP Fonnte dikirim sebagai teks', $otpCode !== '' && str_contains($otpMessage, 'Kode verifikasi'));
    check('OTP tidak memakai template Meta', !str_contains($otpMessage, 'wificare_auth'));
    check('OTP plaintext tidak disimpan di database', $otpCode !== '' && !str_contains((string) $pdo->query('SELECT code_hash FROM whatsapp_otp WHERE user_id=' . $tempUserId . ' ORDER BY id DESC LIMIT 1')->fetchColumn(), $otpCode));
    $otpVerify = WhatsAppOtpService::verify($actor, 'phone_verification', $phone, $otpCode);
    check('OTP Fonnte dapat diverifikasi', !empty($otpVerify['ok']), json_encode($otpVerify));
    $otpReuse = WhatsAppOtpService::verify($actor, 'phone_verification', $phone, $otpCode);
    check('OTP Fonnte sekali pakai', empty($otpReuse['ok']));

    // Regresi aturan Meta: phone_verification tetap wajib template, tidak fallback teks.
    WhatsAppSettingsService::saveOtp($admin, [
        'is_enabled' => '1',
        'connection_id' => (string) $metaId,
        'template' => 'Kode verifikasi WiFiCare CDN Anda adalah {kode}. Berlaku {masa_berlaku} menit. Jangan berikan kode ini kepada siapa pun.',
        'ttl_seconds' => '300',
        'max_attempts' => '5',
        'resend_cooldown' => '0',
        'daily_user_limit' => '100',
        'daily_phone_limit' => '100',
        'send_mode' => 'text',
        'template_name' => '',
        'language_code' => 'id',
        'purposes' => ['phone_verification' => '1', 'reset_password' => '1'],
    ]);
    $metaFallback = WhatsAppOtpService::request($actor, 'phone_verification', '081234567891');
    check('Meta tetap menolak fallback teks untuk phone_verification', empty($metaFallback['ok']) && ($metaFallback['code'] ?? '') === 'template_required');

    section('Idempotensi Antrean');
    $events = [];
    foreach (ApiFeatureRegistry::notificationEvents() as $key => $metaEvent) {
        $events[$key] = [
            'enabled' => $key === 'tiket_selesai' ? '1' : '',
            'recipient' => 'custom',
            'custom_number' => '6281234567891',
            'template' => 'Tes notifikasi Fonnte {nomor_tiket}',
            'send_mode' => 'text',
            'template_name' => '',
            'language_code' => 'id',
        ];
    }
    WhatsAppSettingsService::saveNotifications($admin, [
        'is_enabled' => '1',
        'connection_id' => (string) $fonnteId,
        'admin_number' => '6281234567890',
        'daily_limit' => '100',
        'retry_max' => '3',
        'events' => $events,
    ]);
    $eventKey = 'wa_notif:uji_fonnte:' . $suffix;
    $first = WhatsAppMessagingService::enqueueEvent(
        ApiFeatureRegistry::WHATSAPP_NOTIFICATION,
        $eventKey,
        '6281234567891',
        ['body' => 'Tes idempoten Fonnte ' . $suffix],
        ['send_mode' => 'text']
    );
    $second = WhatsAppMessagingService::enqueueEvent(
        ApiFeatureRegistry::WHATSAPP_NOTIFICATION,
        $eventKey,
        '6281234567891',
        ['body' => 'Tes idempoten Fonnte ' . $suffix],
        ['send_mode' => 'text']
    );
    check('Event antrean pertama dibuat', !empty($first['created']));
    check('Event antrean duplikat tidak dibuat ulang', empty($second['created']) && (int) $second['id'] === (int) $first['id']);
    $queueResult = WhatsAppMessagingService::processQueue(10, ApiFeatureRegistry::WHATSAPP_NOTIFICATION);
    $outboxCount = (int) $pdo->query('SELECT COUNT(*) FROM whatsapp_outbox WHERE event_key = ' . $pdo->quote($eventKey))->fetchColumn();
    $sentLogs = array_values(array_filter(json_lines($mockLog), static fn (array $row): bool => str_contains((string) ($row['message'] ?? ''), 'Tes idempoten Fonnte')));
    check('Antrean Fonnte diproses', (int) ($queueResult['accepted'] ?? 0) >= 1);
    check('Retry tidak membuat pesan bisnis ganda', $outboxCount === 1 && count($sentLogs) === 1);

    section('HTTP Admin, CSRF, RBAC, Tema');
    [$adminJar, $adminLogin] = login('admin', 'admin.budi', T10_PW_ADMIN);
    if ($adminLogin !== 'ok') {
        skip('Uji HTTP halaman Admin', 'server uji http://localhost:8099 tidak berjalan atau login gagal');
    } else {
        [$status, $html] = http_call('GET', app_path('/admin/whatsapp/gateway'), $adminJar);
        check('Halaman WhatsApp Admin dapat dibuka', $status === 200 && str_contains($html, 'Pengaturan WhatsApp Gateway'));
        check('Pilihan provider Fonnte tampil', str_contains($html, 'Fonnte WhatsApp API') && str_contains($html, 'value="fonnte"'));
        check('Field kondisional Fonnte tersedia', str_contains($html, 'data-wa-provider-fields="fonnte"'));
        check('Script field provider dimuat', str_contains($html, 'admin-whatsapp-provider.js'));
        check('Koneksi Fonnte CDN tampil', str_contains($html, 'Fonnte CDN Uji ' . $suffix));
        check('Token Fonnte tidak muncul di HTML', !str_contains($html, $fonnteToken) && !str_contains($html, 'v1:'));
        check('Dark/Light mode tetap tersedia', str_contains($html, 'data-theme="light"') && str_contains($html, 'wificare-theme'));

        [$otpStatus, $otpHtml] = http_call('GET', app_path('/admin/whatsapp/otp'), $adminJar);
        check('Halaman OTP WhatsApp Admin dapat dibuka', $otpStatus === 200);
        check('Catatan provider OTP tersedia', str_contains($otpHtml, 'Provider OTP saat ini'));

        [$editStatus, $editHtml] = http_call('GET', app_path('/admin/whatsapp/gateway') . '?edit=' . $fonnteId, $adminJar);
        check('Edit kredensial Fonnte dapat dibuka', $editStatus === 200 && str_contains($editHtml, 'Fonnte CDN Uji ' . $suffix));
        check('Field Meta tidak diwajibkan pada edit Fonnte', str_contains($editHtml, 'data-wa-provider-fields="meta_cloud" hidden'));

        [$csrfStatus, $csrfBody] = http_call('POST', app_path('/admin/whatsapp/kredensial'), $adminJar, ['name' => 'Tanpa CSRF']);
        check('CSRF tetap wajib pada aksi kredensial', $csrfStatus === 419 || str_contains($csrfBody, 'CSRF'));

        [$csJar, $csLogin] = login('cs', 'cs.dewi', T10_PW_CS);
        if ($csLogin === 'ok') {
            [$csStatus] = http_call('GET', app_path('/admin/whatsapp/gateway'), $csJar);
            check('CS tidak dapat membuka kredensial WhatsApp', $csStatus === 403);
        } else {
            skip('RBAC CS', 'login peran uji gagal');
        }
    }

    if (server_available('127.0.0.1', 8098)) {
        $metaTest = ApiCredentialService::testWhatsApp($admin, $metaId);
        check('Koneksi Meta existing tetap berfungsi', !empty($metaTest['ok']), json_encode($metaTest));
    } else {
        skip('Koneksi Meta existing', 'mock Meta 127.0.0.1:8098 tidak berjalan');
    }
} finally {
    // Kembalikan konfigurasi dan status kredensial yang sudah ada.
    $pdo->exec('DELETE FROM whatsapp_outbox WHERE id > ' . $maxOutbox);
    $pdo->exec('DELETE FROM whatsapp_otp WHERE id > ' . $maxOtp);
    $pdo->exec('DELETE FROM api_usage_log WHERE id > ' . $maxUsage);
    $pdo->exec('DELETE FROM api_config_audit WHERE id > ' . $maxConfigAudit);
    $pdo->exec('DELETE FROM audit_log WHERE id > ' . $maxAuditLog);
    if ($tempUserId > 0) {
        $pdo->exec('DELETE FROM users WHERE id = ' . $tempUserId);
    }
    if ($createdCredentialIds !== []) {
        $pdo->exec('DELETE FROM api_credentials WHERE id IN (' . implode(',', array_map('intval', $createdCredentialIds)) . ')');
    }

    $pdo->exec('DELETE FROM api_integration_settings');
    if ($snapshotSettings !== []) {
        $insertSetting = $pdo->prepare(
            'INSERT INTO api_integration_settings
                (feature_key, connection_id, is_enabled, settings_json, updated_by, created_at, updated_at)
             VALUES
                (:feature_key, :connection_id, :is_enabled, :settings_json, :updated_by, :created_at, :updated_at)'
        );
        foreach ($snapshotSettings as $row) {
            $insertSetting->execute([
                'feature_key' => $row['feature_key'],
                'connection_id' => $row['connection_id'],
                'is_enabled' => $row['is_enabled'],
                'settings_json' => $row['settings_json'],
                'updated_by' => $row['updated_by'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ]);
        }
    }
    $restoreActive = $pdo->prepare('UPDATE api_credentials SET is_active = :is_active WHERE id = :id');
    foreach ($snapshotActive as $row) {
        $restoreActive->execute(['is_active' => (int) $row['is_active'], 'id' => (int) $row['id']]);
    }

    ApiCredential::resetCache();
    ApiIntegrationSetting::resetCache();
    @unlink(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_t22_admin_' . getmypid() . '.jar');
    @unlink(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_t22_cs_' . getmypid() . '.jar');
}

echo "\nRingkasan: PASS={$PASS} FAIL={$FAIL} SKIP={$SKIP}\n";
exit($FAIL > 0 ? 1 : 0);
