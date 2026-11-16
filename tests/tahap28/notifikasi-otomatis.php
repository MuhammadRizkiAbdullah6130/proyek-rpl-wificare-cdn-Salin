<?php
/**
 * WiFiCare CDN — Tahap 28: Notifikasi WhatsApp otomatis setelah commit.
 *
 * Memeriksa:
 * - event tiket memicu pengiriman otomatis tanpa tombol proses antrean;
 * - pembuatan tiket, verifikasi CS, penugasan, mulai penanganan, dan selesai;
 * - notifikasi nonaktif tidak mengirim apa pun;
 * - koneksi/provider gagal tidak membatalkan tiket;
 * - request ganda tidak membuat pesan duplikat;
 * - retry pending diproses otomatis pada request berikutnya;
 * - status hanya accepted sampai webhook valid menyatakan delivered.
 *
 * Prasyarat:
 *   php -S 127.0.0.1:8099 -t public public/index.php   (DB uji, APP_BASE_PATH=/)
 *   php -S 127.0.0.1:8097 tests/tahap22/mock-fonnte.php
 *
 * Jalankan:
 *   php tests/tahap28/notifikasi-otomatis.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../tahap10/_config.php';
t10_require_passwords(['T10_PW_CS', 'T10_PW_TEK', 'T10_PW_PEL']);

putenv('DB_NAME=wificare_cdn_test');
putenv('APP_BASE_PATH=/');
$_ENV['DB_NAME'] = 'wificare_cdn_test';
$_ENV['APP_BASE_PATH'] = '/';
$_SERVER['DB_NAME'] = 'wificare_cdn_test';
$_SERVER['APP_BASE_PATH'] = '/';

require_once __DIR__ . '/../../app/bootstrap.php';

$PASS = 0;
$FAIL = 0;
$SKIP = 0;
$BLOCKED = 0;

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

function blocked(string $name, string $reason): void
{
    global $BLOCKED;
    $BLOCKED++;
    echo "BLOCKED  {$name} :: {$reason}\n";
}

function section(string $title): void
{
    echo "\n=== {$title} ===\n";
}

/** @return array{0:int,1:string,2:string,3:string} status, body, location, error */
function t28_http(string $method, string $path, ?string $jar = null, array $form = []): array
{
    $ch = curl_init(T10_BASE . $path);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
    ];
    if ($jar !== null) {
        $options[CURLOPT_COOKIEJAR] = $jar;
        $options[CURLOPT_COOKIEFILE] = $jar;
    }
    if (strtoupper($method) === 'POST') {
        $options[CURLOPT_POST] = true;
        $hasFile = false;
        foreach ($form as $value) {
            if ($value instanceof CURLFile) {
                $hasFile = true;
                break;
            }
        }
        $options[CURLOPT_POSTFIELDS] = $hasFile ? $form : http_build_query($form);
        if (!$hasFile) {
            $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/x-www-form-urlencoded'];
        }
    }
    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $error !== '') {
        return [0, '', '', $error];
    }

    return [$status, (string) $body, $location, ''];
}

function t28_jar(string $tag): string
{
    $jar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_t28_' . $tag . '_' . getmypid() . '.jar';
    @unlink($jar);

    return $jar;
}

function t28_login(string $tag, string $username, string $password): ?string
{
    $jar = t28_jar($tag);
    [$status, $html] = t28_http('GET', '/login', $jar);
    if ($status !== 200 || preg_match('/name="_token"\s+value="([a-f0-9]{64})"/', $html, $m) !== 1) {
        return null;
    }
    [$loginStatus] = t28_http('POST', '/login', $jar, [
        '_token' => $m[1],
        'username' => $username,
        'password' => $password,
    ]);

    return $loginStatus === 302 ? $jar : null;
}

function t28_csrf(string $html): string
{
    return preg_match('/name="_token"\s+value="([a-f0-9]{64})"/', $html, $m) === 1 ? $m[1] : '';
}

function t28_outbox(PDO $pdo, int $ticketId): array
{
    $statement = $pdo->prepare(
        "SELECT id, event_key, template_key, status, attempts, max_attempts,
                provider_message_id, delivered_at, read_at, last_error_code
         FROM whatsapp_outbox
         WHERE feature_key = 'whatsapp_notification'
           AND event_key LIKE :event_key
         ORDER BY id"
    );
    $statement->execute(['event_key' => 'wa_notif:%:' . $ticketId . ':%']);

    return $statement->fetchAll();
}

function t28_event_status(array $rows, string $eventKey): string
{
    foreach ($rows as $row) {
        if ((string) ($row['template_key'] ?? '') === $eventKey) {
            return (string) ($row['status'] ?? '');
        }
    }

    return '';
}

function t28_notification_settings(int $connectionId, string $retryMax): array
{
    $events = [];
    foreach (ApiFeatureRegistry::notificationEvents() as $key => $meta) {
        $events[$key] = [
            'enabled' => '1',
            'recipient' => (string) $meta['recipient'],
            'custom_number' => '',
            'template' => (string) $meta['template'],
            'send_mode' => 'text',
            'template_name' => '',
            'language_code' => 'id',
        ];
    }

    return [
        'is_enabled' => '1',
        'connection_id' => (string) $connectionId,
        'admin_number' => '6281234567890',
        'daily_limit' => '100',
        'retry_max' => $retryMax,
        'events' => $events,
    ];
}

$socket = @fsockopen('127.0.0.1', 8099, $errno, $errstr, 2);
if ($socket === false) {
    blocked('Server aplikasi uji 127.0.0.1:8099 tidak aktif.', $errstr);
    echo "\nHASIL: {$PASS} PASS, {$FAIL} FAIL, {$SKIP} SKIP, {$BLOCKED} BLOCKED\n";
    exit(1);
}
fclose($socket);

$mockSocket = @fsockopen('127.0.0.1', 8097, $mockErrno, $mockErrstr, 2);
if ($mockSocket === false) {
    blocked('Mock Fonnte 127.0.0.1:8097 tidak aktif.', $mockErrstr);
    echo "\nHASIL: {$PASS} PASS, {$FAIL} FAIL, {$SKIP} SKIP, {$BLOCKED} BLOCKED\n";
    exit(1);
}
fclose($mockSocket);

$pdo = Database::pdo();
check('Database uji terhubung', (string) db_config('database') === 'wificare_cdn_test', (string) db_config('database'));

$admin = $pdo->query("SELECT id, username, nama, role FROM users WHERE username = 'admin.budi' LIMIT 1")->fetch();
$cs = $pdo->query("SELECT id, username, nama, role FROM users WHERE username = 'cs.dewi' LIMIT 1")->fetch();
$teknisi = $pdo->query("SELECT id, username, nama, role FROM users WHERE username = 'tek.andi' LIMIT 1")->fetch();
$categoryId = (int) $pdo->query('SELECT id FROM kategori_gangguan WHERE is_active = 1 ORDER BY id ASC LIMIT 1')->fetchColumn();
check('Akun admin/CS/teknisi uji tersedia', is_array($admin) && is_array($cs) && is_array($teknisi));
check('Kategori aktif tersedia', $categoryId > 0);

if (!is_array($admin) || !is_array($cs) || !is_array($teknisi) || $categoryId <= 0) {
    echo "\nHASIL: {$PASS} PASS, {$FAIL} FAIL, {$SKIP} SKIP, {$BLOCKED} BLOCKED\n";
    exit(1);
}

$suffix = bin2hex(random_bytes(4));
$extraPng = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_t28_' . $suffix . '.png';
file_put_contents(
    $extraPng,
    base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC')
);

$settingsSnapshot = $pdo->query('SELECT * FROM api_integration_settings')->fetchAll();
$credentialActiveSnapshot = $pdo->query('SELECT id, is_active FROM api_credentials')->fetchAll();
$maxOutbox = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM whatsapp_outbox')->fetchColumn();
$maxUsage = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM api_usage_log')->fetchColumn();
$maxConfigAudit = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM api_config_audit')->fetchColumn();
$maxAudit = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM audit_log')->fetchColumn();
$maxTicket = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM pengaduan')->fetchColumn();

$ticketIds = [];
$credentialId = 0;

$cleanup = static function () use (
    $pdo,
    &$ticketIds,
    &$credentialId,
    $settingsSnapshot,
    $credentialActiveSnapshot,
    $maxOutbox,
    $maxUsage,
    $maxConfigAudit,
    $maxAudit,
    $maxTicket,
    $extraPng
): void {
    foreach ($ticketIds as $ticketId) {
        $photos = $pdo->prepare('SELECT file_path FROM pengaduan_foto WHERE pengaduan_id = :id');
        $photos->execute(['id' => $ticketId]);
        foreach ($photos->fetchAll(PDO::FETCH_COLUMN) as $path) {
            Upload::delete((string) $path);
        }

        $reportPhotos = $pdo->prepare(
            'SELECT lf.file_path FROM laporan_foto lf
             INNER JOIN laporan_teknisi lt ON lt.id = lf.laporan_id
             WHERE lt.pengaduan_id = :id'
        );
        $reportPhotos->execute(['id' => $ticketId]);
        foreach ($reportPhotos->fetchAll(PDO::FETCH_COLUMN) as $path) {
            Upload::delete((string) $path);
        }

        foreach ([
            'DELETE lf FROM laporan_foto lf INNER JOIN laporan_teknisi lt ON lt.id = lf.laporan_id WHERE lt.pengaduan_id = :id',
            'DELETE FROM laporan_teknisi WHERE pengaduan_id = :id',
            'DELETE FROM penugasan_teknisi WHERE pengaduan_id = :id',
            'DELETE FROM penyelesaian_cs WHERE pengaduan_id = :id',
            'DELETE FROM catatan_cs WHERE pengaduan_id = :id',
            'DELETE FROM notifikasi WHERE pengaduan_id = :id',
            'DELETE FROM riwayat_status WHERE pengaduan_id = :id',
            'DELETE FROM pengaduan_foto WHERE pengaduan_id = :id',
        ] as $sql) {
            $pdo->prepare($sql)->execute(['id' => $ticketId]);
        }
        $pdo->prepare('DELETE FROM whatsapp_outbox WHERE event_key LIKE :event_key')
            ->execute(['event_key' => 'wa_notif:%:' . $ticketId . ':%']);
        $pdo->prepare('DELETE FROM pengaduan WHERE id = :id')->execute(['id' => $ticketId]);
    }

    $pdo->exec('DELETE FROM whatsapp_outbox WHERE id > ' . $maxOutbox);
    $pdo->exec('DELETE FROM api_usage_log WHERE id > ' . $maxUsage);
    $pdo->exec('DELETE FROM api_config_audit WHERE id > ' . $maxConfigAudit);
    $pdo->exec('DELETE FROM audit_log WHERE id > ' . $maxAudit);
    $pdo->exec('DELETE FROM pengaduan WHERE id > ' . $maxTicket);

    if ($credentialId > 0) {
        $pdo->prepare('DELETE FROM api_credentials WHERE id = :id')->execute(['id' => $credentialId]);
    }

    $restore = $pdo->prepare(
        'UPDATE api_integration_settings
         SET connection_id = :connection_id, is_enabled = :is_enabled,
             settings_json = :settings_json, updated_by = :updated_by,
             updated_at = :updated_at
         WHERE feature_key = :feature_key'
    );
    foreach ($settingsSnapshot as $row) {
        $restore->execute([
            'connection_id' => $row['connection_id'],
            'is_enabled' => $row['is_enabled'],
            'settings_json' => $row['settings_json'],
            'updated_by' => $row['updated_by'],
            'updated_at' => $row['updated_at'],
            'feature_key' => $row['feature_key'],
        ]);
    }

    $restoreCredential = $pdo->prepare('UPDATE api_credentials SET is_active = :is_active WHERE id = :id');
    foreach ($credentialActiveSnapshot as $row) {
        $restoreCredential->execute(['is_active' => $row['is_active'], 'id' => $row['id']]);
    }

    ApiCredential::resetCache();
    ApiIntegrationSetting::resetCache();
    @unlink($extraPng);
};

$customerJar = null;
$csJar = null;
$teknisiJar = null;

try {
    section('Konfigurasi Uji');
    $credentialId = ApiCredentialService::saveWhatsApp($admin, [
        'name' => 'Fonnte Otomatis Uji ' . $suffix,
        'provider' => 'fonnte',
        'access_token' => 'test-fonnte-token-' . $suffix,
        'sender_number' => '081234567890',
        'base_url' => 'http://127.0.0.1:8097',
        'is_active' => '1',
    ]);
    check('Kredensial Fonnte uji dibuat', $credentialId > 0);

    WhatsAppSettingsService::saveGateway($admin, [
        'is_enabled' => '1',
        'connection_id' => (string) $credentialId,
        'default_country_code' => '62',
        'daily_limit' => '1000',
        'retry_max' => '3',
        'timeout' => '20',
    ]);
    WhatsAppSettingsService::saveNotifications($admin, t28_notification_settings($credentialId, '1'));
    check(
        'Notifikasi otomatis aktif dengan koneksi uji',
        ApiIntegrationSetting::isEnabled(ApiFeatureRegistry::WHATSAPP_NOTIFICATION)
            && ApiIntegrationSetting::connectionId(ApiFeatureRegistry::WHATSAPP_NOTIFICATION) === $credentialId
    );

    $customerJar = t28_login('pelanggan', 'pppoe.rina', T10_PW_PEL);
    $csJar = t28_login('cs', 'cs.dewi', T10_PW_CS);
    $teknisiJar = t28_login('teknisi', 'tek.andi', T10_PW_TEK);
    check('Login pelanggan/CS/teknisi uji berhasil', $customerJar !== null && $csJar !== null && $teknisiJar !== null);

    section('A. Pelanggan Membuat Tiket');
    [$formStatus, $formHtml] = t28_http('GET', '/pelanggan/pengaduan/baru', $customerJar);
    $formToken = t28_csrf($formHtml);
    check('Form pengaduan pelanggan tersedia', $formStatus === 200 && $formToken !== '', 'status=' . $formStatus);

    [$createStatus, , $createLocation] = t28_http('POST', '/pelanggan/pengaduan', $customerJar, [
        '_token' => $formToken,
        'nomor_whatsapp' => '081200000001',
        'kategori_id' => (string) $categoryId,
        'deskripsi' => 'Uji notifikasi otomatis setelah tiket berhasil disimpan.',
        'foto[]' => new CURLFile($extraPng, 'image/png', 'bukti.png'),
    ]);
    check('Tiket pelanggan berhasil dibuat', $createStatus === 302, 'status=' . $createStatus . ' ' . $createLocation);

    $created = $pdo->query('SELECT id, status FROM pengaduan WHERE id > ' . $maxTicket . ' ORDER BY id DESC LIMIT 1')->fetch();
    $ticketA = is_array($created) ? (int) $created['id'] : 0;
    if ($ticketA > 0) {
        $ticketIds[] = $ticketA;
    }
    check('Tiket baru tersimpan di database', $ticketA > 0 && (string) ($created['status'] ?? '') === 'menunggu_verifikasi');

    $rowsA = $ticketA > 0 ? t28_outbox($pdo, $ticketA) : [];
    check(
        'A1 Notifikasi pengaduan baru terkirim otomatis',
        t28_event_status($rowsA, 'pengaduan_baru') === 'accepted'
    );
    check(
        'A2 Konfirmasi tiket pelanggan terkirim otomatis',
        t28_event_status($rowsA, 'konfirmasi_tiket') === 'accepted'
    );
    check('A3 Tidak ada pesan duplikat saat pembuatan tiket', count($rowsA) === 2, 'rows=' . count($rowsA));
    check(
        'A4 Status hanya accepted, belum delivered',
        count(array_filter($rowsA, static fn (array $r): bool => ($r['delivered_at'] ?? null) !== null)) === 0
    );

    section('B. CS Memverifikasi Tiket');
    [$detailStatus, $detailHtml] = t28_http('GET', '/cs/pengaduan/' . $ticketA, $csJar);
    $csToken = t28_csrf($detailHtml);
    check('Detail tiket CS tersedia', $detailStatus === 200 && $csToken !== '', 'status=' . $detailStatus);

    [$verifyStatus] = t28_http('POST', '/cs/pengaduan/' . $ticketA . '/terima', $csJar, ['_token' => $csToken]);
    check('CS menerima tiket', $verifyStatus === 302, 'status=' . $verifyStatus);
    $rowsB = t28_outbox($pdo, $ticketA);
    check('B Notifikasi verifikasi terkirim otomatis', t28_event_status($rowsB, 'tiket_diverifikasi') === 'accepted');

    section('H. Request Ganda Tidak Duplikat');
    [$detailStatus, $detailHtml] = t28_http('GET', '/cs/pengaduan/' . $ticketA, $csJar);
    $duplicateToken = t28_csrf($detailHtml);
    $beforeDuplicate = count(t28_outbox($pdo, $ticketA));
    [$duplicateStatus] = t28_http('POST', '/cs/pengaduan/' . $ticketA . '/terima', $csJar, ['_token' => $duplicateToken]);
    $afterDuplicate = count(t28_outbox($pdo, $ticketA));
    check('Request verifikasi ganda ditolak', $duplicateStatus === 302, 'status=' . $duplicateStatus);
    check('Request ganda tidak membuat pesan baru', $beforeDuplicate === $afterDuplicate, "before={$beforeDuplicate} after={$afterDuplicate}");
    check(
        'Status tiket tetap diproses_cs setelah request ganda',
        (string) $pdo->query('SELECT status FROM pengaduan WHERE id = ' . $ticketA)->fetchColumn() === 'diproses_cs'
    );

    section('C. CS Menugaskan Teknisi');
    [$detailStatus, $detailHtml] = t28_http('GET', '/cs/pengaduan/' . $ticketA, $csJar);
    $assignToken = t28_csrf($detailHtml);
    [$assignStatus] = t28_http('POST', '/cs/pengaduan/' . $ticketA . '/tugaskan', $csJar, [
        '_token' => $assignToken,
        'teknisi_id' => (string) $teknisi['id'],
        'catatan' => 'Uji notifikasi penugasan otomatis.',
    ]);
    check('CS menugaskan teknisi', $assignStatus === 302, 'status=' . $assignStatus);
    $rowsC = t28_outbox($pdo, $ticketA);
    check('C Notifikasi penugasan teknisi terkirim otomatis', t28_event_status($rowsC, 'penugasan_teknisi') === 'accepted');

    section('D. Teknisi Memulai Penanganan');
    [$taskStatus, $taskHtml] = t28_http('GET', '/teknisi/tugas/' . $ticketA, $teknisiJar);
    $startToken = t28_csrf($taskHtml);
    check('Halaman tugas teknisi tersedia', $taskStatus === 200 && $startToken !== '', 'status=' . $taskStatus);
    [$startStatus] = t28_http('POST', '/teknisi/tugas/' . $ticketA . '/mulai', $teknisiJar, ['_token' => $startToken]);
    check('Teknisi memulai penanganan', $startStatus === 302, 'status=' . $startStatus);
    $rowsD = t28_outbox($pdo, $ticketA);
    check('D Notifikasi mulai penanganan terkirim otomatis', t28_event_status($rowsD, 'penanganan_dimulai') === 'accepted');

    section('E. Teknisi Menyelesaikan Tiket');
    [$reportStatus, $reportHtml] = t28_http('GET', '/teknisi/tugas/' . $ticketA . '/laporan', $teknisiJar);
    $reportToken = t28_csrf($reportHtml);
    check('Form laporan teknisi tersedia', $reportStatus === 200 && $reportToken !== '', 'status=' . $reportStatus);
    [$finishStatus] = t28_http('POST', '/teknisi/tugas/' . $ticketA . '/laporan', $teknisiJar, [
        '_token' => $reportToken,
        'penyebab_gangguan' => 'Kabel koneksi longgar.',
        'tindakan_perbaikan' => 'Kabel dipasang ulang dan koneksi diuji normal.',
        'catatan' => 'Uji notifikasi otomatis tiket selesai.',
        'foto' => new CURLFile($extraPng, 'image/png', 'hasil.png'),
    ]);
    check('Teknisi menyelesaikan tiket', $finishStatus === 302, 'status=' . $finishStatus);
    check(
        'Status tiket menjadi selesai',
        (string) $pdo->query('SELECT status FROM pengaduan WHERE id = ' . $ticketA)->fetchColumn() === 'selesai'
    );
    $rowsE = t28_outbox($pdo, $ticketA);
    check('E Notifikasi tiket selesai terkirim otomatis', t28_event_status($rowsE, 'tiket_selesai') === 'accepted');
    check(
        'Semua event A-E diterima provider tanpa proses manual',
        count(array_filter($rowsE, static fn (array $r): bool => ($r['status'] ?? '') === 'accepted')) === 6,
        'total=' . count($rowsE)
    );

    section('F. Notifikasi Nonaktif');
    WhatsAppSettingsService::saveNotifications($admin, array_merge(
        t28_notification_settings($credentialId, '1'),
        ['is_enabled' => '']
    ));
    check('Fitur notifikasi dapat dinonaktifkan', !ApiIntegrationSetting::isEnabled(ApiFeatureRegistry::WHATSAPP_NOTIFICATION));

    [$formStatus, $formHtml] = t28_http('GET', '/pelanggan/pengaduan/baru', $customerJar);
    $formToken = t28_csrf($formHtml);
    [$createStatus] = t28_http('POST', '/pelanggan/pengaduan', $customerJar, [
        '_token' => $formToken,
        'nomor_whatsapp' => '081200000001',
        'kategori_id' => (string) $categoryId,
        'deskripsi' => 'Tiket dibuat saat notifikasi WhatsApp dinonaktifkan.',
        'foto[]' => new CURLFile($extraPng, 'image/png', 'bukti-f.png'),
    ]);
    $ticketB = (int) $pdo->query('SELECT id FROM pengaduan WHERE id > ' . $maxTicket . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
    if ($ticketB > 0 && !in_array($ticketB, $ticketIds, true)) {
        $ticketIds[] = $ticketB;
    }
    check('Tiket tetap dibuat saat notifikasi nonaktif', $createStatus === 302 && $ticketB > 0, 'status=' . $createStatus);
    check('F Tidak ada pesan saat notifikasi nonaktif', count(t28_outbox($pdo, $ticketB)) === 0);

    section('G. Koneksi Provider Gagal');
    WhatsAppSettingsService::saveGateway($admin, [
        'is_enabled' => '1',
        'connection_id' => (string) $credentialId,
        'default_country_code' => '62',
        'daily_limit' => '1000',
        'retry_max' => '3',
        'timeout' => '5',
    ]);
    WhatsAppSettingsService::saveNotifications($admin, t28_notification_settings($credentialId, '1'));
    ApiCredentialService::saveWhatsApp($admin, [
        'id' => $credentialId,
        'name' => 'Fonnte Otomatis Uji ' . $suffix,
        'provider' => 'fonnte',
        'access_token' => 'test-fonnte-bad',
        'sender_number' => '081234567890',
        'base_url' => 'http://127.0.0.1:8097',
    ]);

    $flowError = '';
    try {
        PengaduanFlowService::terima($cs, $ticketB);
    } catch (Throwable $exception) {
        $flowError = $exception->getMessage();
    }
    check('G1 Kegagalan provider tidak melempar ke alur tiket', $flowError === '', $flowError);
    check(
        'G2 Tiket tetap tersimpan sebagai diproses_cs',
        (string) $pdo->query('SELECT status FROM pengaduan WHERE id = ' . $ticketB)->fetchColumn() === 'diproses_cs'
    );
    $rowsG = t28_outbox($pdo, $ticketB);
    $failedRow = $rowsG[0] ?? [];
    check(
        'G3 Kegagalan provider dicatat akurat',
        ($failedRow['status'] ?? '') === 'failed' && (string) ($failedRow['last_error_code'] ?? '') !== '',
        json_encode($failedRow)
    );

    section('J. Retry Pending Diproses Otomatis');
    WhatsAppSettingsService::saveNotifications($admin, array_merge(
        t28_notification_settings($credentialId, '1'),
        ['is_enabled' => '']
    ));
    [$formStatus, $formHtml] = t28_http('GET', '/pelanggan/pengaduan/baru', $customerJar);
    $formToken = t28_csrf($formHtml);
    t28_http('POST', '/pelanggan/pengaduan', $customerJar, [
        '_token' => $formToken,
        'nomor_whatsapp' => '081200000001',
        'kategori_id' => (string) $categoryId,
        'deskripsi' => 'Tiket untuk menguji retry otomatis notifikasi pending.',
        'foto[]' => new CURLFile($extraPng, 'image/png', 'bukti-j.png'),
    ]);
    $ticketC = (int) $pdo->query('SELECT id FROM pengaduan WHERE id > ' . $maxTicket . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
    if ($ticketC > 0 && !in_array($ticketC, $ticketIds, true)) {
        $ticketIds[] = $ticketC;
    }

    WhatsAppSettingsService::saveNotifications($admin, t28_notification_settings($credentialId, '2'));
    $flowError = '';
    try {
        PengaduanFlowService::terima($cs, $ticketC);
    } catch (Throwable $exception) {
        $flowError = $exception->getMessage();
    }
    $rowsJ = t28_outbox($pdo, $ticketC);
    check('J1 Percobaan pertama gagal dan tetap pending', $flowError === '' && t28_event_status($rowsJ, 'tiket_diverifikasi') === 'pending', json_encode($rowsJ));

    // Pulihkan koneksi (token yang sama) lalu buat baris retry jatuh tempo.
    ApiCredentialService::saveWhatsApp($admin, [
        'id' => $credentialId,
        'name' => 'Fonnte Otomatis Uji ' . $suffix,
        'provider' => 'fonnte',
        'access_token' => 'test-fonnte-token-' . $suffix,
        'sender_number' => '081234567890',
        'base_url' => 'http://127.0.0.1:8097',
    ]);
    $pdo->prepare(
        "UPDATE whatsapp_outbox
         SET next_attempt_at = DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 SECOND)
         WHERE event_key LIKE :event_key AND status = 'pending'"
    )->execute(['event_key' => 'wa_notif:%:' . $ticketC . ':%']);

    // Request web apa pun memicu retry otomatis di akhir request (shutdown).
    [$probeStatus] = t28_http('GET', '/login');
    check('Request web berikutnya berhasil', $probeStatus === 200, 'status=' . $probeStatus);

    $rowsJAfter = t28_outbox($pdo, $ticketC);
    check(
        'J2 Pending diproses otomatis tanpa tombol manual',
        t28_event_status($rowsJAfter, 'tiket_diverifikasi') === 'accepted',
        json_encode($rowsJAfter)
    );

    section('Keamanan & Status');
    $allTestRows = $pdo->query(
        "SELECT * FROM whatsapp_outbox WHERE event_key LIKE 'wa_notif:%' ORDER BY id"
    )->fetchAll();
    check(
        'Tidak ada status delivered palsu tanpa webhook',
        count(array_filter($allTestRows, static fn (array $r): bool => in_array((string) $r['status'], ['delivered', 'read'], true))) === 0
    );
    $decodedPayloads = '';
    foreach ($allTestRows as $row) {
        $decoded = WhatsAppOutbox::decode($row);
        $decodedPayloads .= json_encode($decoded['payload'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }
    check(
        'Outbox tidak membocorkan token/secret pada isi pesan',
        !str_contains($decodedPayloads, 'test-fonnte-token') && !str_contains($decodedPayloads, 'test-fonnte-bad')
    );
} finally {
    $cleanup();
}

echo "\nHASIL: {$PASS} PASS, {$FAIL} FAIL, {$SKIP} SKIP, {$BLOCKED} BLOCKED\n";
exit($FAIL > 0 ? 1 : 0);
