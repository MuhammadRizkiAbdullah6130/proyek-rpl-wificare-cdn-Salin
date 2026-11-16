<?php
/**
 * WiFiCare CDN - validasi nomor pengirim WhatsApp Cloud API.
 *
 * Fokus:
 * - nomor E.164 internasional (contoh +1 555 168-7957) diterima;
 * - normalisasi +, spasi, kurung, titik, dan tanda hubung;
 * - nomor lokal Indonesia tetap didukung;
 * - huruf, nomor terlalu pendek/panjang, dan input kosong ditolak;
 * - Phone Number ID tetap dipakai untuk tes koneksi, bukan nomor pengirim;
 * - token tetap tersimpan terenkripsi dan tidak berubah saat edit;
 * - kredensial tampil pada daftar Kredensial Tersimpan.
 *
 * Tes koneksi memakai mock lokal. Ini BUKAN klaim bahwa koneksi Meta live
 * berhasil; verifikasi Meta memerlukan token dan Phone Number ID nyata.
 *
 * Jalankan:
 *   php tests/tahap17/whatsapp-sender-validation.php
 */

declare(strict_types=1);

putenv('DB_NAME=wificare_cdn_test');
putenv('APP_ENV=testing');

require_once __DIR__ . '/../../app/bootstrap.php';

$PASS = 0;
$FAIL = 0;
$SKIP = 0;
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

function skip(string $name, string $reason): void
{
    global $SKIP;
    $SKIP++;
    echo "SKIP  {$name} :: {$reason}\n";
}

function exception_message(callable $fn): string
{
    try {
        $fn();
    } catch (ValidationException $exception) {
        return implode(' ', $exception->errors());
    } catch (Throwable $exception) {
        return $exception->getMessage();
    }

    return '';
}

/** @return array{0:int,1:string,2:resource|null} */
function startMockWhatsApp(string $mockLog, string $serverLog): array
{
    $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!is_resource($socket)) {
        return [0, '', null];
    }
    $name = (string) stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int) substr($name, strrpos($name, ':') + 1);
    if ($port < 1) {
        return [0, '', null];
    }

    putenv('WA_MOCK_LOG=' . $mockLog);
    $command = escapeshellarg(PHP_BINARY)
        . ' -S 127.0.0.1:' . $port
        . ' ' . escapeshellarg(__DIR__ . '/mock-whatsapp.php');
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $serverLog, 'a'],
        2 => ['file', $serverLog, 'a'],
    ];
    $process = proc_open($command, $descriptors, $pipes, dirname(__DIR__, 2));
    if (!is_resource($process)) {
        return [0, '', null];
    }

    $ready = false;
    for ($i = 0; $i < 30; $i++) {
        $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if (is_resource($probe)) {
            fclose($probe);
            $ready = true;
            break;
        }
        usleep(100000);
    }

    return $ready ? [$port, '', $process] : [0, '', $process];
}

function stopMockWhatsApp($process): void
{
    if (!is_resource($process)) {
        return;
    }

    $status = proc_get_status($process);
    $processId = (int) ($status['pid'] ?? 0);
    if (DIRECTORY_SEPARATOR === '\\' && $processId > 0) {
        // proc_terminate() hanya menghentikan pembungkus cmd di Windows;
        // /T memastikan proses php -S anak ikut berhenti.
        @exec('taskkill /PID ' . $processId . ' /T /F 2>NUL');
    } else {
        proc_terminate($process);
    }
    proc_close($process);
}

$db = Database::pdo();
$admin = $db->query(
    "SELECT id, username, nama, role FROM users
     WHERE role = 'admin' AND is_active = 1 ORDER BY id ASC LIMIT 1"
)->fetch();
if (!is_array($admin)) {
    echo "Tidak ada akun Admin aktif pada fixture DB uji.\n";
    exit(1);
}

$suffix = strtolower(bin2hex(random_bytes(4)));
$token = 'test-token-sender-' . $suffix;
$credentialId = 0;
$previousActive = (int) (ApiCredential::active(ApiCredentialService::INTEGRATION_WHATSAPP)['id'] ?? 0);
$mockLog = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_wa_sender_mock_' . $suffix . '.log';
$serverLog = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_wa_sender_server_' . $suffix . '.log';
@file_put_contents($mockLog, '');
@file_put_contents($serverLog, '');
$mockProcess = null;
$mockPort = 0;

try {
    // 1. Format yang diminta.
    check('15551687957 valid', whatsapp_number('15551687957') === '15551687957', (string) whatsapp_number('15551687957'));
    check('+15551687957 valid setelah normalisasi', whatsapp_number('+15551687957') === '15551687957', (string) whatsapp_number('+15551687957'));
    check(
        '+1 (555) 168-7957 dinormalisasi ke 15551687957',
        whatsapp_number('+1 (555) 168-7957') === '15551687957',
        (string) whatsapp_number('+1 (555) 168-7957')
    );
    check('6281234567890 tetap valid', whatsapp_number('6281234567890') === '6281234567890', (string) whatsapp_number('6281234567890'));
    check(
        'format Indonesia 0812-3456-7890 dinormalisasi ke 62',
        whatsapp_number('0812-3456-7890') === '6281234567890',
        (string) whatsapp_number('0812-3456-7890')
    );
    check(
        'format internasional 00 dinormalisasi',
        whatsapp_number('0015551687957') === '15551687957',
        (string) whatsapp_number('0015551687957')
    );
    check(
        'format +62 08... dinormalisasi tanpa angka 0 ganda',
        whatsapp_number('+62 0812-3456-7890') === '6281234567890',
        (string) whatsapp_number('+62 0812-3456-7890')
    );
    check(
        'nomor internasional +81 tidak dipaksa menjadi 62',
        whatsapp_number('+81 80-1234-5678') === '818012345678',
        (string) whatsapp_number('+81 80-1234-5678')
    );

    // 2. Penolakan input tidak valid pada helper.
    check('huruf ditolak', whatsapp_number('abc15551687957') === null, (string) whatsapp_number('abc15551687957'));
    check('huruf di tengah nomor ditolak', whatsapp_number('+1 555 ABC 7957') === null, (string) whatsapp_number('+1 555 ABC 7957'));
    check('nomor terlalu pendek ditolak', whatsapp_number('1234567') === null, (string) whatsapp_number('1234567'));
    check('nomor terlalu panjang ditolak', whatsapp_number('1234567890123456') === null, (string) whatsapp_number('1234567890123456'));
    check('nomor kosong ditolak oleh validator', whatsapp_number('') === null && whatsapp_number(null) === null);
    check('tanda plus ganda ditolak', whatsapp_number('++15551687957') === null, (string) whatsapp_number('++15551687957'));

    // 3. Input tidak valid ditolak oleh service penyimpanan.
    $invalidSenders = [
        'abc15551687957',
        '1234567',
        '1234567890123456',
    ];
    foreach ($invalidSenders as $invalidSender) {
        $message = exception_message(static fn () => ApiCredentialService::saveWhatsApp($admin, [
            'name' => 'Uji nomor tidak valid ' . $suffix,
            'provider' => 'meta_cloud',
            'access_token' => $token,
            'phone_number_id' => '123456789',
            'sender_number' => $invalidSender,
        ]));
        check(
            'service menolak nomor tidak valid: ' . $invalidSender,
            str_contains($message, 'Nomor pengirim tidak valid'),
            $message
        );
    }

    // 4. Siapkan mock untuk tes koneksi (bukan Meta live).
    [$mockPort, $mockError, $mockProcess] = startMockWhatsApp($mockLog, $serverLog);
    if ($mockPort < 1) {
        skip('tes koneksi mock WhatsApp', 'mock lokal tidak dapat dimulai' . ($mockError !== '' ? ': ' . $mockError : ''));
    }

    $baseUrl = $mockPort > 0
        ? 'http://127.0.0.1:' . $mockPort . '/v20.0'
        : 'http://127.0.0.1:8098/v20.0';

    // 5. Simpan kredensial dengan format internasional ber-tanda +.
    $credentialId = ApiCredentialService::saveWhatsApp($admin, [
        'name' => 'WA CDN Uji Internasional ' . $suffix,
        'provider' => 'meta_cloud',
        'access_token' => $token,
        'business_account_id' => 'waba-uji-sender',
        'phone_number_id' => '123456789',
        'sender_number' => '+1 (555) 168-7957',
        'base_url' => $baseUrl,
        'webhook_verify_token' => 'verify-sender-123',
        'webhook_secret' => 'secret-sender-123',
        'is_active' => '0',
    ]);
    check('kredensial WA CDN tersimpan', $credentialId > 0);

    $safe = ApiCredentialService::safe($credentialId);
    check(
        'nomor pengirim tersimpan ternormalisasi',
        ($safe['sender_number'] ?? '') === '15551687957',
        (string) ($safe['sender_number'] ?? '')
    );
    check(
        'Phone Number ID tetap tersimpan dan tidak diganti nomor pengirim',
        ($safe['phone_number_id'] ?? '') === '123456789',
        (string) ($safe['phone_number_id'] ?? '')
    );

    $connections = ApiCredentialService::whatsappConnections();
    $found = null;
    foreach ($connections as $connection) {
        if ((int) ($connection['id'] ?? 0) === $credentialId) {
            $found = $connection;
            break;
        }
    }
    check(
        'kredensial tampil di daftar Kredensial Tersimpan',
        is_array($found)
            && ($found['name'] ?? '') === 'WA CDN Uji Internasional ' . $suffix
            && ($found['sender_number'] ?? '') === '15551687957'
    );

    $row = ApiCredential::find($credentialId);
    $cipher = (string) ($row['secret_encrypted'] ?? '');
    check('secret tetap disimpan sebagai ciphertext v1', str_starts_with($cipher, 'v1:'));
    check('token plaintext tidak tersimpan di database', $cipher !== '' && !str_contains($cipher, $token));
    check(
        'token dapat didekripsi kembali tanpa perubahan',
        Crypto::decrypt($cipher) === $token
    );
    check(
        'data safe/daftar tidak membocorkan token atau ciphertext',
        !str_contains(json_encode($safe, JSON_UNESCAPED_UNICODE) ?: '', $token)
            && !str_contains(json_encode($safe, JSON_UNESCAPED_UNICODE) ?: '', 'v1:')
    );

    // 6. Edit tanpa mengisi token: secret lama harus tetap.
    ApiCredentialService::saveWhatsApp($admin, [
        'id' => $credentialId,
        'name' => 'WA CDN Uji Internasional ' . $suffix,
        'provider' => 'meta_cloud',
        'access_token' => '',
        'business_account_id' => 'waba-uji-sender',
        'phone_number_id' => '123456789',
        'sender_number' => '+1 (555) 168-7957',
        'base_url' => $baseUrl,
        'webhook_verify_token' => '',
        'webhook_secret' => '',
        'is_active' => '0',
    ]);
    $updated = ApiCredential::find($credentialId);
    check(
        'edit tanpa token tidak mengubah access token',
        Crypto::decrypt((string) ($updated['secret_encrypted'] ?? '')) === $token
    );
    check(
        'edit tetap mempertahankan nomor pengirim internasional',
        (ApiCredentialService::connection($credentialId)['sender_number'] ?? '') === '15551687957'
    );

    // 7. Tes koneksi memakai token + Phone Number ID (mock lokal).
    if ($mockPort > 0) {
        $test = ApiCredentialService::testWhatsApp($admin, $credentialId);
        check('tes koneksi mock berhasil memakai token dan Phone Number ID', !empty($test['ok']), json_encode($test));
        check('tes koneksi tidak mengembalikan token', !str_contains(json_encode($test) ?: '', $token));

        $lines = is_file($mockLog)
            ? file($mockLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []
            : [];
        $lastRequest = $lines === [] ? [] : (json_decode((string) end($lines), true) ?: []);
        check(
            'mock menerima GET ke Phone Number ID, bukan memakai nomor pengirim sebagai ID',
            ($lastRequest['method'] ?? '') === 'GET'
                && ($lastRequest['phone_id'] ?? '') === '123456789'
                && ($lastRequest['path'] ?? '') === '/v20.0/123456789',
            json_encode($lastRequest)
        );
    } else {
        skip('tes koneksi mock WhatsApp', 'mock lokal tidak aktif');
    }

    // 8. Verifikasi Meta live tidak dapat diklaim dari validasi format.
    $metaToken = trim((string) (getenv('WA_META_TEST_TOKEN') ?: ''));
    $metaPhoneId = trim((string) (getenv('WA_META_TEST_PHONE_NUMBER_ID') ?: ''));
    if ($metaToken === '' || $metaPhoneId === '') {
        skip(
            'tes koneksi Meta live',
            'token/Phone Number ID Meta live tidak tersedia; validasi format bukan bukti koneksi'
        );
    } else {
        $liveId = ApiCredentialService::saveWhatsApp($admin, [
            'name' => 'WA Meta Live Uji ' . $suffix,
            'provider' => 'meta_cloud',
            'access_token' => $metaToken,
            'phone_number_id' => $metaPhoneId,
            'sender_number' => '+1 (555) 168-7957',
            'is_active' => '0',
        ]);
        $live = ApiCredentialService::testWhatsApp($admin, $liveId);
        check('tes koneksi Meta live', !empty($live['ok']), json_encode($live));
        $db->prepare('DELETE FROM api_credentials WHERE id = :id')->execute(['id' => $liveId]);
    }
} finally {
    if ($credentialId > 0) {
        $db->prepare('DELETE FROM api_usage_log WHERE connection_id = :id')->execute(['id' => $credentialId]);
        $db->prepare('DELETE FROM api_credentials WHERE id = :id')->execute(['id' => $credentialId]);
    }
    if ($previousActive > 0) {
        ApiCredential::setActive($previousActive);
    }
    stopMockWhatsApp($mockProcess);
    @unlink($mockLog);
    @unlink($serverLog);
}

echo "\nHASIL VALIDASI NOMOR PENGIRIM WHATSAPP: {$PASS} PASS, {$FAIL} FAIL, {$SKIP} SKIP\n";
if ($FAILED !== []) {
    echo "GAGAL:\n- " . implode("\n- ", $FAILED) . "\n";
    exit(1);
}
