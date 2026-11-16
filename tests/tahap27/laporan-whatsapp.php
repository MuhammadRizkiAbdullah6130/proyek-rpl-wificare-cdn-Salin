<?php
/**
 * WiFiCare CDN — Tahap 27: Laporan WhatsApp manual (teks).
 *
 * Memeriksa:
 * - tiga jenis laporan, periode, penerima berwenang, dan batas harian;
 * - preview tidak mengirim, token sekali pakai, stale preview, dan data aktual;
 * - pengiriman sukses lewat mock Fonnte, status accepted (bukan delivered);
 * - penolakan CSRF, RBAC, penerima/koneksi curang, dan batas harian;
 * - data pengaduan tidak berubah dan riwayat/audit tidak membocorkan rahasia.
 *
 * Prasyarat:
 *   php -S 127.0.0.1:8099 -t public public/index.php   (DB uji, APP_BASE_PATH=/)
 *   php -S 127.0.0.1:8097 tests/tahap22/mock-fonnte.php
 *
 * Jalankan:
 *   php tests/tahap27/laporan-whatsapp.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../tahap10/_config.php';
t10_require_passwords(['T10_PW_ADMIN', 'T10_PW_CS']);

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

function t27_http(string $method, string $path, ?string $jar = null, array $form = []): array
{
    $ch = curl_init(T10_BASE . $path);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
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
        $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/x-www-form-urlencoded'];
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

function t27_jar(string $tag): string
{
    $jar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_t27_' . $tag . '_' . getmypid() . '.jar';
    @unlink($jar);

    return $jar;
}

function t27_login(string $tag, string $username, string $password): ?string
{
    $jar = t27_jar($tag);
    [$status, $html] = t27_http('GET', '/login', $jar);
    if ($status !== 200 || preg_match('/name="_token"\s+value="([a-f0-9]{64})"/', $html, $m) !== 1) {
        return null;
    }
    [$loginStatus] = t27_http('POST', '/login', $jar, [
        '_token' => $m[1],
        'username' => $username,
        'password' => $password,
    ]);

    return $loginStatus === 302 ? $jar : null;
}

function t27_csrf(string $html): ?string
{
    return preg_match('/name="_token"\s+value="([a-f0-9]{64})"/', $html, $m) === 1 ? $m[1] : null;
}

function t27_preview_token(string $html): ?string
{
    return preg_match('/name="preview_token"\s+value="([a-f0-9]{64})"/', $html, $m) === 1 ? $m[1] : null;
}

function t27_preview_message(string $html): string
{
    if (preg_match('/<pre class="wa-report-preview">(.*?)<\/pre>/s', $html, $m) !== 1) {
        return '';
    }

    return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

function t27_log_lines(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

    return array_values($lines);
}

function t27_max_id(PDO $pdo, string $table): int
{
    return (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM ' . $table)->fetchColumn();
}

function t27_num(int $value): string
{
    return number_format($value, 0, ',', '.');
}

function t27_path(string $location): string
{
    return (string) (parse_url($location, PHP_URL_PATH) ?: $location);
}

function t27_complaint_hash(PDO $pdo): string
{
    $rows = $pdo->query('SELECT id, status, is_cancelled, updated_at FROM pengaduan ORDER BY id')->fetchAll();

    return hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
}

$socket = @fsockopen('127.0.0.1', 8099, $errno, $errstr, 2);
if ($socket === false) {
    echo "BLOCKED  Server aplikasi uji 127.0.0.1:8099 tidak aktif.\n";
    exit(2);
}
fclose($socket);

$mockSocket = @fsockopen('127.0.0.1', 8097, $errno, $errstr, 2);
$mockRunning = $mockSocket !== false;
if ($mockSocket !== false) {
    fclose($mockSocket);
}
$logPath = getenv('FONNTE_MOCK_LOG') ?: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_fonnte_mock.log';

$pdo = new PDO(T10_DB_DSN, T10_DB_USER, T10_DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$adminRow = $pdo->query("SELECT id, username, role FROM users WHERE username = 'admin.budi' LIMIT 1")->fetch();
if (!is_array($adminRow)) {
    echo "BLOCKED  Akun Admin uji tidak ditemukan.\n";
    exit(2);
}
$admin = ['id' => (int) $adminRow['id'], 'role' => (string) $adminRow['role']];

$originalActiveId = $pdo->query(
    "SELECT id FROM api_credentials
     WHERE integration = 'whatsapp' AND is_active = 1 AND revoked_at IS NULL
     ORDER BY id DESC LIMIT 1"
)->fetchColumn();
$originalActiveId = $originalActiveId === false ? null : (int) $originalActiveId;

$snapshotReport = $pdo->query(
    "SELECT * FROM api_integration_settings WHERE feature_key = 'whatsapp_report' LIMIT 1"
)->fetch();
$snapshotReport = is_array($snapshotReport) ? $snapshotReport : null;

$maxOutbox = t27_max_id($pdo, 'whatsapp_outbox');
$maxAudit = t27_max_id($pdo, 'audit_log');
$maxUsage = t27_max_id($pdo, 'api_usage_log');
$maxConfigAudit = t27_max_id($pdo, 'api_config_audit');
$complaintHashBefore = t27_complaint_hash($pdo);
$createdCredentialIds = [];
$testTicketId = 0;
$goodId = 0;
$emptyId = 0;

try {
    section('Konfigurasi dan Ketersediaan');
    $types = WhatsAppSettingsService::reportTypes();
    check('Tiga jenis laporan tersedia', count($types) === 3, json_encode($types));
    check(
        'Jenis laporan sesuai kebutuhan',
        array_keys($types) === ['ringkasan_pengaduan', 'kinerja_teknisi', 'rekap_bulanan'],
        json_encode(array_keys($types))
    );
    check('Rating layanan tidak menjadi opsi laporan', !isset($types['kepuasan_pelanggan']));
    $defaults = ApiFeatureRegistry::defaults(ApiFeatureRegistry::WHATSAPP_REPORT);
    check('Format laporan teks', ($defaults['format'] ?? '') === 'text');
    check(
        'Default bebas scheduler/approval',
        !array_key_exists('schedule', $defaults)
        && !array_key_exists('approved', $defaults)
        && !array_key_exists('is_approved', $defaults)
    );

    section('Fixture Uji Lokal');
    $goodId = ApiCredentialService::saveWhatsApp($admin, [
        'provider' => 'fonnte',
        'name' => 'Uji Laporan Fonnte ' . bin2hex(random_bytes(3)),
        'sender_number' => '6281234567890',
        'base_url' => 'http://127.0.0.1:8097',
        'access_token' => 'test-fonnte-good',
        'webhook_verify_token' => '',
        'webhook_secret' => '',
        'is_active' => '1',
    ]);
    $createdCredentialIds[] = $goodId;
    check('Kredensial Fonnte uji dibuat', $goodId > 0, 'id=' . $goodId);

    if ($mockRunning) {
        $emptyId = ApiCredentialService::saveWhatsApp($admin, [
            'provider' => 'fonnte',
            'name' => 'Uji Laporan Fonnte Tanpa ID ' . bin2hex(random_bytes(3)),
            'sender_number' => '6281234567890',
            'base_url' => 'http://127.0.0.1:8097',
            'access_token' => 'test-fonnte-empty',
            'webhook_verify_token' => '',
            'webhook_secret' => '',
            'is_active' => '0',
        ]);
        $createdCredentialIds[] = $emptyId;
        check('Kredensial Fonnte uji kedua dibuat', $emptyId > 0, 'id=' . $emptyId);
    }

    ApiCredential::setActive($goodId);
    ApiCredential::resetCache();
    ApiIntegrationSetting::resetCache();

    WhatsAppSettingsService::saveReport($admin, [
        'connection_id' => (string) $goodId,
        'recipients' => "Penerima Uji|081234567899\nPimpinan Uji|+62 812 3456 7898",
        'daily_limit' => '10',
    ]);
    ApiIntegrationSetting::resetCache();
    $reportSetting = ApiIntegrationSetting::get(ApiFeatureRegistry::WHATSAPP_REPORT);
    $reportSettings = $reportSetting['settings'];
    check('Penerima laporan tersimpan dari daftar berwenang', count($reportSettings['recipients'] ?? []) === 2);
    check('Nomor penerima dinormalisasi', ($reportSettings['recipients'][0]['number'] ?? '') === '6281234567899');
    check('Laporan manual aktif bila gateway + koneksi + penerima siap', !empty($reportSetting['is_enabled']));
    check('Format tersimpan text', ($reportSettings['format'] ?? '') === 'text');
    check(
        'Setting lama tidak menyimpan scheduler/approval',
        !array_key_exists('schedule', $reportSettings)
        && !array_key_exists('approved', $reportSettings)
        && !array_key_exists('is_approved', $reportSettings)
    );

    section('Halaman Admin dan Aturan UI');
    $adminJar = t27_login('admin', 'admin.budi', T10_PW_ADMIN);
    if ($adminJar === null) {
        blocked('Login Admin', 'Server/DB uji tidak siap.');
        throw new RuntimeException('login_admin_failed');
    }

    $outboxBeforePage = (int) $pdo->query('SELECT COUNT(*) FROM whatsapp_outbox')->fetchColumn();
    [$pageStatus, $pageHtml] = t27_http('GET', '/admin/whatsapp/laporan', $adminJar);
    $outboxAfterPage = (int) $pdo->query('SELECT COUNT(*) FROM whatsapp_outbox')->fetchColumn();
    check('Halaman Laporan WhatsApp 200', $pageStatus === 200, 'status=' . $pageStatus);
    check('Judul halaman tersedia', str_contains($pageHtml, 'Kirim Laporan WhatsApp'));
    check('Form pratinjau tersedia', str_contains($pageHtml, 'name="report_type"') && str_contains($pageHtml, 'Preview Laporan'));
    check('Form pengaturan penerima tersedia', str_contains($pageHtml, 'name="recipients"') && str_contains($pageHtml, 'name="daily_limit"'));
    check('Submenu WhatsApp dan breadcrumb tersedia', str_contains($pageHtml, 'sidebar-submenu-whatsapp') && str_contains($pageHtml, 'app-breadcrumb'));
    check('Mode terang/gelap tersedia', str_contains($pageHtml, 'data-theme') && str_contains($pageHtml, 'theme-toggle'));
    check('Widget AI tetap tersedia', str_contains($pageHtml, 'ai-widget'));
    check('Tidak ada scheduler/rencana/persetujuan', !str_contains($pageHtml, 'name="schedule"') && !str_contains($pageHtml, 'Rencana') && !str_contains($pageHtml, 'Persetujuan'));
    check('Tidak ada format PDF/Excel', !str_contains($pageHtml, 'PDF') && !str_contains($pageHtml, 'Excel'));
    check('Tidak ada token/rahasia di HTML', !str_contains($pageHtml, 'test-fonnte-good') && !str_contains($pageHtml, 'secret_encrypted'));
    check('Membuka halaman tidak mengirim WhatsApp', $outboxBeforePage === $outboxAfterPage, "before={$outboxBeforePage} after={$outboxAfterPage}");

    $csrf = t27_csrf($pageHtml);
    check('CSRF form Laporan tersedia', $csrf !== null);

    $outboxBeforeSave = (int) $pdo->query('SELECT COUNT(*) FROM whatsapp_outbox')->fetchColumn();
    [$saveStatus, , $saveLocation] = t27_http('POST', '/admin/whatsapp/laporan', $adminJar, [
        '_token' => $csrf,
        'recipients' => "Admin Baru|081234567897\nPimpinan Baru|+62 812 3456 7896",
        'daily_limit' => '7',
    ]);
    [$afterSaveStatus, $afterSaveHtml] = t27_http('GET', '/admin/whatsapp/laporan', $adminJar);
    $outboxAfterSave = (int) $pdo->query('SELECT COUNT(*) FROM whatsapp_outbox')->fetchColumn();
    check('Simpan pengaturan menerima redirect', $saveStatus === 302 && t27_path($saveLocation) === '/admin/whatsapp/laporan', "status={$saveStatus} loc={$saveLocation}");
    check('Halaman setelah simpan 200', $afterSaveStatus === 200);
    check('Penerima baru tampil', str_contains($afterSaveHtml, 'Admin Baru'));
    ApiIntegrationSetting::resetCache();
    $savedSettings = ApiIntegrationSetting::get(ApiFeatureRegistry::WHATSAPP_REPORT)['settings'];
    check('Batas harian tersimpan', (int) ($savedSettings['daily_limit'] ?? 0) === 7);
    check('Dua penerima tersimpan setelah simpan', count($savedSettings['recipients'] ?? []) === 2);
    check('Menyimpan pengaturan tidak mengirim WhatsApp', $outboxBeforeSave === $outboxAfterSave, "before={$outboxBeforeSave} after={$outboxAfterSave}");

    $settingsBeforeInvalid = json_encode($savedSettings);
    [$invalidStatus] = t27_http('POST', '/admin/whatsapp/laporan', $adminJar, [
        '_token' => $csrf,
        'recipients' => 'Penerima Curang|abc',
        'daily_limit' => '7',
    ]);
    [$invalidPageStatus, $invalidPageHtml] = t27_http('GET', '/admin/whatsapp/laporan', $adminJar);
    ApiIntegrationSetting::resetCache();
    $settingsAfterInvalid = json_encode(ApiIntegrationSetting::get(ApiFeatureRegistry::WHATSAPP_REPORT)['settings']);
    check('Simpan penerima tidak valid tetap redirect', $invalidStatus === 302);
    check('Penerima tidak valid ditolak dengan pesan jelas', $invalidPageStatus === 200 && str_contains($invalidPageHtml, 'Nomor penerima tidak valid'));
    check('Penerima tidak valid tidak mengubah settings', $settingsBeforeInvalid === $settingsAfterInvalid);
    $activeRecipients = WhatsAppReportService::recipients();
    $expectedTarget = (string) ($activeRecipients[0]['number'] ?? '');
    $expectedMasked = $expectedTarget !== '' ? '****' . substr($expectedTarget, -4) : '';

    section('Validasi Backend dan Data Laporan');
    $blockedError = '';
    try {
        WhatsAppReportService::preview([
            'report_type' => 'ringkasan_pengaduan',
            'rentang' => 'semua',
            'connection_id' => $goodId,
            'recipient_index' => 999,
        ]);
    } catch (ValidationException $exception) {
        $blockedError = $exception->errorsAsString();
    }
    check('Indeks penerima curang ditolak', str_contains($blockedError, 'Penerima'), $blockedError);

    $blockedError = '';
    try {
        WhatsAppReportService::preview([
            'report_type' => 'ringkasan_pengaduan',
            'rentang' => 'semua',
            'connection_id' => 999999,
            'recipient_index' => 0,
        ]);
    } catch (ValidationException $exception) {
        $blockedError = $exception->errorsAsString();
    }
    check('Koneksi curang ditolak', stripos($blockedError, 'koneksi') !== false, $blockedError);

    $invalidPeriod = '';
    try {
        WhatsAppReportService::normalizeFilters(['rentang' => 'ngawur']);
    } catch (ValidationException $exception) {
        $invalidPeriod = $exception->errorsAsString();
    }
    check('Periode tidak valid ditolak', $invalidPeriod !== '', $invalidPeriod);

    $invalidDate = '';
    try {
        WhatsAppReportService::normalizeFilters([
            'rentang' => 'pilih_tanggal',
            'date_from' => '2026-10-31',
            'date_to' => '2026-10-01',
        ]);
    } catch (ValidationException $exception) {
        $invalidDate = $exception->errorsAsString();
    }
    check('Tanggal terbalik ditolak', $invalidDate !== '', $invalidDate);

    $filters = WhatsAppReportService::normalizeFilters(['rentang' => 'bulan_ini']);
    $allFilters = WhatsAppReportService::normalizeFilters(['rentang' => 'semua']);
    $ringkasan = WhatsAppReportService::buildMessage('ringkasan_pengaduan', $filters);
    $kinerja = WhatsAppReportService::buildMessage('kinerja_teknisi', $filters);
    $bulanan = WhatsAppReportService::buildMessage('rekap_bulanan', $filters);
    $semuaPesan = $ringkasan . "\n" . $kinerja . "\n" . $bulanan;

    $summary = Laporan::summary($filters);
    $statusCounts = Laporan::statusDistribution(array_merge($filters, ['cancelled' => 'tidak']));
    check(
        'Jumlah enam status + dibatalkan = total',
        array_sum($statusCounts) + (int) $summary['dibatalkan'] === (int) $summary['total'],
        json_encode($statusCounts) . ' total=' . $summary['total'] . ' cancelled=' . $summary['dibatalkan']
    );
    check('Ringkasan memuat total pengaduan', str_contains($ringkasan, 'Total pengaduan: ' . t27_num((int) $summary['total'])));
    foreach (status_keys() as $key) {
        check(
            'Ringkasan memuat status ' . $key,
            str_contains($ringkasan, status_label($key) . ': ' . t27_num((int) ($statusCounts[$key] ?? 0)))
        );
    }
    if ((int) $summary['dibatalkan'] > 0) {
        check('Dibatalkan dilaporkan terpisah', str_contains($ringkasan, 'Dibatalkan (terpisah, bukan status ketujuh): ' . t27_num((int) $summary['dibatalkan'])));
    }
    check('Periode bulan ini tertera', str_contains($ringkasan, 'Periode:'));
    check('Periode semua waktu berbeda', WhatsAppReportService::periodLabel($allFilters) !== WhatsAppReportService::periodLabel($filters));

    $nonCancelledFilters = array_merge($filters, ['cancelled' => 'tidak']);
    $techRows = Laporan::technicianPerformance($nonCancelledFilters);
    $taskTotal = array_sum(array_map(static fn (array $row): int => (int) ($row['total_tugas'] ?? 0), $techRows));
    check('Kinerja teknisi memuat total penugasan', str_contains($kinerja, 'Total penugasan: ' . t27_num($taskTotal)));
    check('Kinerja teknisi memuat jumlah teknisi', str_contains($kinerja, 'Jumlah teknisi: ' . t27_num(count($techRows))));
    if ($techRows !== []) {
        $firstTech = $techRows[0];
        check(
            'Kinerja teknisi memuat rincian teknisi',
            str_contains($kinerja, (string) ($firstTech['nama'] ?? '') . ': ' . t27_num((int) ($firstTech['total_tugas'] ?? 0)) . ' tugas')
        );
    }

    $statusNonCancelled = Laporan::statusDistribution($nonCancelledFilters);
    $nonCancelled = max(0, (int) $summary['total'] - (int) $summary['dibatalkan']);
    $unfinished = max(0, $nonCancelled - (int) ($statusNonCancelled['selesai'] ?? 0) - (int) ($statusNonCancelled['ditolak'] ?? 0));
    check('Rekap bulanan memuat selesai non-cancelled', str_contains($bulanan, 'Pengaduan selesai: ' . t27_num((int) ($statusNonCancelled['selesai'] ?? 0))));
    check('Rekap bulanan memuat belum selesai non-cancelled', str_contains($bulanan, 'Pengaduan belum selesai: ' . t27_num($unfinished)));
    $categories = Laporan::categoryDistribution($nonCancelledFilters);
    if ($categories !== []) {
        check('Rekap bulanan memuat kategori teratas', str_contains($bulanan, (string) ($categories[0]['nama'] ?? '')));
    }
    check('Pesan berada di bawah batas aman', mb_strlen($semuaPesan, 'UTF-8') < WhatsAppReportService::MAX_MESSAGE_LENGTH);
    check('Pesan tidak membawa token/rahasia', !str_contains($semuaPesan, 'test-fonnte-good') && !str_contains($semuaPesan, 'test-fonnte-empty') && !str_contains($semuaPesan, 'AI_SETTINGS_KEY'));
    $customerName = (string) ($pdo->query("SELECT nama FROM users WHERE role = 'pelanggan' ORDER BY id LIMIT 1")->fetchColumn() ?: '');
    if ($customerName !== '') {
        check('Pesan tidak memuat nama pelanggan', !str_contains($semuaPesan, $customerName), $customerName);
    }

    if (!$mockRunning) {
        blocked('Pengujian preview/kirim live', 'Mock Fonnte 127.0.0.1:8097 tidak aktif.');
        throw new RuntimeException('mock_unavailable');
    }

    section('Pratinjau Tanpa Pengiriman');
    file_put_contents($logPath, '');
    $previewPath = '/admin/whatsapp/laporan?' . http_build_query([
        'preview' => '1',
        'connection_id' => $goodId,
        'report_type' => 'ringkasan_pengaduan',
        'rentang' => 'semua',
        'recipient_index' => '0',
    ]);
    [$previewStatus, $previewHtml] = t27_http('GET', $previewPath, $adminJar);
    $previewToken = t27_preview_token($previewHtml);
    $previewMessage = t27_preview_message($previewHtml);
    $previewLogLines = t27_log_lines($logPath);
    check('Preview 200', $previewStatus === 200, 'status=' . $previewStatus);
    check('Preview menampilkan pesan teks', $previewMessage !== '' && str_contains($previewMessage, 'RINGKASAN PENGADUAN'));
    check('Preview menyimpan token sekali pakai', $previewToken !== null);
    check('Form kirim tersedia pada preview', $previewToken !== null && str_contains($previewHtml, 'action="/admin/whatsapp/laporan/kirim"'));
    check('Preview tidak menghubungi provider', $previewLogLines === [], json_encode($previewLogLines));
    check('Target penerima berasal dari daftar berwenang', $expectedTarget !== '');
    check('Preview memuat penerima tersamarkan', $expectedMasked !== '' && str_contains($previewHtml, $expectedMasked), $expectedMasked);

    section('Pratinjau Kedaluwarsa/Data Berubah');
    $testPelangganId = (int) $pdo->query('SELECT id FROM pelanggan ORDER BY id LIMIT 1')->fetchColumn();
    $testKategoriId = (int) $pdo->query('SELECT id FROM kategori_gangguan ORDER BY id LIMIT 1')->fetchColumn();
    if ($testPelangganId > 0 && $testKategoriId > 0) {
        $nomorTiket = 'WFC-WA27-' . strtoupper(bin2hex(random_bytes(3)));
        $insert = $pdo->prepare(
            "INSERT INTO pengaduan
                (nomor_tiket, pelanggan_id, nama_pelanggan_snapshot, username_pppoe_snapshot,
                 nomor_whatsapp_snapshot, kategori_id, deskripsi, status, is_cancelled, created_at)
             VALUES (?, ?, 'Uji Laporan', 'uji_laporan', '6280000000000', ?, 'Uji stale preview', 'menunggu_verifikasi', 0, NOW())"
        );
        $insert->execute([$nomorTiket, $testPelangganId, $testKategoriId]);
        $testTicketId = (int) $pdo->lastInsertId();
        [$staleSendStatus] = t27_http('POST', '/admin/whatsapp/laporan/kirim', $adminJar, [
            '_token' => $csrf,
            'preview_token' => $previewToken,
        ]);
        [$stalePageStatus, $stalePageHtml] = t27_http('GET', '/admin/whatsapp/laporan', $adminJar);
        $staleLogLines = t27_log_lines($logPath);
        $staleOutbox = (int) $pdo->query("SELECT COUNT(*) FROM whatsapp_outbox WHERE id > {$maxOutbox}")->fetchColumn();
        check('Kirim dengan data berubah menerima redirect', $staleSendStatus === 302);
        check('Data berubah ditolak dengan pesan stale', $stalePageStatus === 200 && str_contains($stalePageHtml, 'Data laporan berubah'));
        check('Data berubah tidak menghubungi provider', $staleLogLines === [], json_encode($staleLogLines));
        check('Data berubah tidak membuat outbox', $staleOutbox === 0);
        $pdo->prepare('DELETE FROM pengaduan WHERE id = ?')->execute([$testTicketId]);
        $testTicketId = 0;
    } else {
        skip('Uji stale preview', 'Fixture pelanggan/kategori tidak tersedia.');
    }

    section('Pengiriman Sukses dan Idempotensi');
    file_put_contents($logPath, '');
    $previewPath = '/admin/whatsapp/laporan?' . http_build_query([
        'preview' => '1',
        'connection_id' => $goodId,
        'report_type' => 'ringkasan_pengaduan',
        'rentang' => 'bulan_ini',
        'recipient_index' => '0',
    ]);
    [$previewStatus, $previewHtml] = t27_http('GET', $previewPath, $adminJar);
    $previewToken = t27_preview_token($previewHtml);
    $previewMessage = t27_preview_message($previewHtml);
    check('Preview kedua 200', $previewStatus === 200 && $previewMessage !== '');
    [$sendStatus, , $sendLocation] = t27_http('POST', '/admin/whatsapp/laporan/kirim', $adminJar, [
        '_token' => $csrf,
        'preview_token' => $previewToken,
    ]);
    [$sentPageStatus, $sentPageHtml] = t27_http('GET', '/admin/whatsapp/laporan', $adminJar);
    $sendLogLines = t27_log_lines($logPath);
    $outboxRow = $pdo->query("SELECT * FROM whatsapp_outbox WHERE id > {$maxOutbox} AND feature_key = 'whatsapp_report' ORDER BY id DESC LIMIT 1")->fetch();
    check('Kirim sukses redirect ke halaman laporan', $sendStatus === 302 && t27_path($sendLocation) === '/admin/whatsapp/laporan', "status={$sendStatus} loc={$sendLocation}");
    check('Halaman setelah kirim 200', $sentPageStatus === 200);
    check('Flash sukses menyebut diterima provider', str_contains($sentPageHtml, 'Permintaan laporan sudah diterima provider'));
    check('Provider menerima tepat satu request', count($sendLogLines) === 1, json_encode($sendLogLines));
    if (is_array($outboxRow)) {
        check('Outbox menyimpan feature laporan', ($outboxRow['feature_key'] ?? '') === 'whatsapp_report');
        check('Outbox menyimpan koneksi terpilih', (int) ($outboxRow['connection_id'] ?? 0) === $goodId);
        check('Outbox berstatus accepted (bukan delivered)', ($outboxRow['status'] ?? '') === 'accepted' && empty($outboxRow['delivered_at']));
        check('Outbox menyimpan jenis laporan', ($outboxRow['template_key'] ?? '') === 'ringkasan_pengaduan');
        check('Outbox menyimpan provider message id', (string) ($outboxRow['provider_message_id'] ?? '') !== '');
        check('Nomor penerima tidak tersimpan plaintext', !str_contains((string) ($outboxRow['recipient_encrypted'] ?? ''), '6281234567899'));
    } else {
        check('Outbox laporan tersimpan', false, 'tidak ditemukan');
    }
    $loggedRequest = $sendLogLines !== [] ? json_decode((string) $sendLogLines[0], true) : null;
    check('Target provider sesuai penerima berwenang', is_array($loggedRequest) && ($loggedRequest['target'] ?? '') === $expectedTarget, json_encode($loggedRequest));
    check('Isi provider sama dengan preview', is_array($loggedRequest) && ($loggedRequest['message'] ?? '') === $previewMessage);
    check('Riwayat menampilkan diterima provider', str_contains($sentPageHtml, 'Diterima provider'));

    $outboxCountAfterSend = (int) $pdo->query("SELECT COUNT(*) FROM whatsapp_outbox WHERE id > {$maxOutbox} AND feature_key = 'whatsapp_report'")->fetchColumn();
    $logCountAfterSend = count(t27_log_lines($logPath));
    [$dupStatus] = t27_http('POST', '/admin/whatsapp/laporan/kirim', $adminJar, [
        '_token' => $csrf,
        'preview_token' => $previewToken,
    ]);
    [$dupPageStatus, $dupPageHtml] = t27_http('GET', '/admin/whatsapp/laporan', $adminJar);
    $outboxCountAfterDup = (int) $pdo->query("SELECT COUNT(*) FROM whatsapp_outbox WHERE id > {$maxOutbox} AND feature_key = 'whatsapp_report'")->fetchColumn();
    $logCountAfterDup = count(t27_log_lines($logPath));
    check('Kirim ulang token yang sama ditolak', $dupStatus === 302 && $dupPageStatus === 200 && str_contains($dupPageHtml, 'Pratinjau ini sudah digunakan'));
    check('Kirim ulang tidak menambah outbox', $outboxCountAfterDup === $outboxCountAfterSend, "before={$outboxCountAfterSend} after={$outboxCountAfterDup}");
    check('Kirim ulang tidak menambah request provider', $logCountAfterDup === $logCountAfterSend, "before={$logCountAfterSend} after={$logCountAfterDup}");

    section('Batas Harian');
    $usedToday = WhatsAppOutbox::countToday(ApiFeatureRegistry::WHATSAPP_REPORT);
    WhatsAppSettingsService::saveReport($admin, [
        'connection_id' => (string) $goodId,
        'recipients' => "Penerima Uji|081234567899\nPimpinan Uji|+62 812 3456 7898",
        'daily_limit' => (string) max(1, $usedToday),
    ]);
    ApiIntegrationSetting::resetCache();
    file_put_contents($logPath, '');
    [$limitPreviewStatus, $limitPreviewHtml] = t27_http('GET', $previewPath, $adminJar);
    $limitToken = t27_preview_token($limitPreviewHtml);
    $limitBeforeOutbox = (int) $pdo->query("SELECT COUNT(*) FROM whatsapp_outbox WHERE id > {$maxOutbox}")->fetchColumn();
    [$limitSendStatus] = t27_http('POST', '/admin/whatsapp/laporan/kirim', $adminJar, [
        '_token' => $csrf,
        'preview_token' => $limitToken,
    ]);
    [$limitPageStatus, $limitPageHtml] = t27_http('GET', '/admin/whatsapp/laporan', $adminJar);
    $limitAfterOutbox = (int) $pdo->query("SELECT COUNT(*) FROM whatsapp_outbox WHERE id > {$maxOutbox}")->fetchColumn();
    check('Preview saat batas tercapai tetap 200', $limitPreviewStatus === 200, 'status=' . $limitPreviewStatus);
    check('Tombol kirim tidak tersedia saat batas tercapai', !str_contains($limitPreviewHtml, 'name="preview_token"'));
    check('Kirim saat batas tercapai ditolak jelas', $limitSendStatus === 302 && $limitPageStatus === 200 && str_contains($limitPageHtml, 'Batas pengiriman laporan harian'));
    check('Batas harian tidak menambah outbox', $limitAfterOutbox === $limitBeforeOutbox);
    check('Batas harian tidak menghubungi provider', t27_log_lines($logPath) === []);

    section('Kegagalan Provider');
    WhatsAppSettingsService::saveReport($admin, [
        'connection_id' => (string) $goodId,
        'recipients' => "Penerima Uji|081234567899\nPimpinan Uji|+62 812 3456 7898",
        'daily_limit' => '10',
    ]);
    ApiIntegrationSetting::resetCache();
    ApiCredential::setActive($emptyId);
    ApiCredential::resetCache();
    file_put_contents($logPath, '');
    $failurePreviewPath = '/admin/whatsapp/laporan?' . http_build_query([
        'preview' => '1',
        'connection_id' => $emptyId,
        'report_type' => 'rekap_bulanan',
        'rentang' => 'bulan_ini',
        'recipient_index' => '0',
    ]);
    [$failurePreviewStatus, $failurePreviewHtml] = t27_http('GET', $failurePreviewPath, $adminJar);
    $failureToken = t27_preview_token($failurePreviewHtml);
    $failureBeforeOutbox = (int) $pdo->query("SELECT COUNT(*) FROM whatsapp_outbox WHERE id > {$maxOutbox}")->fetchColumn();
    [$failureSendStatus] = t27_http('POST', '/admin/whatsapp/laporan/kirim', $adminJar, [
        '_token' => $csrf,
        'preview_token' => $failureToken,
    ]);
    [$failurePageStatus, $failurePageHtml] = t27_http('GET', '/admin/whatsapp/laporan', $adminJar);
    $failureAfterOutbox = (int) $pdo->query("SELECT COUNT(*) FROM whatsapp_outbox WHERE id > {$maxOutbox}")->fetchColumn();
    check('Preview koneksi gagal tetap 200', $failurePreviewStatus === 200, 'status=' . $failurePreviewStatus);
    check('Kegagalan provider ditampilkan jelas', $failureSendStatus === 302 && $failurePageStatus === 200 && (str_contains($failurePageHtml, 'Provider WhatsApp') || str_contains($failurePageHtml, 'Gagal')));
    check('Kegagalan provider tidak membuat outbox accepted', $failureAfterOutbox === $failureBeforeOutbox, "before={$failureBeforeOutbox} after={$failureAfterOutbox}");
    check('Kegagalan provider tidak melakukan retry otomatis', count(t27_log_lines($logPath)) === 1, json_encode(t27_log_lines($logPath)));
    ApiCredential::setActive($goodId);
    ApiCredential::resetCache();

    section('CSRF dan RBAC');
    $csrfBefore = (int) $pdo->query("SELECT COUNT(*) FROM whatsapp_outbox WHERE id > {$maxOutbox}")->fetchColumn();
    [$csrfStatus] = t27_http('POST', '/admin/whatsapp/laporan/kirim', $adminJar, [
        'preview_token' => $previewToken,
    ]);
    $csrfAfter = (int) $pdo->query("SELECT COUNT(*) FROM whatsapp_outbox WHERE id > {$maxOutbox}")->fetchColumn();
    check('POST tanpa CSRF ditolak 419', $csrfStatus === 419, 'status=' . $csrfStatus);
    check('POST tanpa CSRF tidak mengirim', $csrfBefore === $csrfAfter);

    $csJar = t27_login('cs', 'cs.siti', T10_PW_CS);
    if ($csJar !== null) {
        [$csPageStatus] = t27_http('GET', '/admin/whatsapp/laporan', $csJar);
        [$csSendStatus] = t27_http('POST', '/admin/whatsapp/laporan/kirim', $csJar, [
            '_token' => $csrf,
            'preview_token' => $previewToken,
        ]);
        check('CS tidak boleh membuka Laporan WhatsApp', in_array($csPageStatus, [302, 403], true), 'status=' . $csPageStatus);
        check('CS tidak boleh mengirim laporan', in_array($csSendStatus, [302, 403], true), 'status=' . $csSendStatus);
    } else {
        blocked('RBAC CS', 'Login CS gagal.');
    }

    section('Audit, Rahasia, dan Imutabilitas Data');
    $auditRows = $pdo->query("SELECT * FROM audit_log WHERE id > {$maxAudit} AND aksi = 'whatsapp_laporan_dikirim' ORDER BY id")->fetchAll();
    $successAudit = null;
    foreach ($auditRows as $auditCandidate) {
        $metadataCandidate = (string) ($auditCandidate['metadata'] ?? '');
        if (str_contains($metadataCandidate, 'Ringkasan')) {
            $successAudit = $auditCandidate;
            break;
        }
    }
    check('Audit pengiriman laporan tercatat', $auditRows !== []);
    if (is_array($successAudit)) {
        $metadata = (string) ($successAudit['metadata'] ?? '');
        check('Audit tidak memuat nomor lengkap/token', !str_contains($metadata, $expectedTarget) && !str_contains($metadata, 'test-fonnte-good'));
        check('Audit memuat jenis laporan', str_contains($metadata, 'Ringkasan'));
    } else {
        check('Audit sukses memuat jenis laporan', false, 'tidak ditemukan');
    }
    $usageRow = $pdo->query("SELECT * FROM api_usage_log WHERE id > {$maxUsage} AND feature_key = 'whatsapp_report' AND success = 1 ORDER BY id DESC LIMIT 1")->fetch();
    check('Usage log pengiriman sukses tercatat', is_array($usageRow) && ($usageRow['status'] ?? '') === 'accepted');
    $goodSecret = (string) ($pdo->query("SELECT secret_encrypted FROM api_credentials WHERE id = {$goodId}")->fetchColumn() ?: '');
    check('Token provider tetap terenkripsi', $goodSecret !== '' && !str_contains($goodSecret, 'test-fonnte-good'));

    $complaintHashAfter = t27_complaint_hash($pdo);
    check('Data pengaduan tidak berubah', $complaintHashBefore === $complaintHashAfter);
} catch (RuntimeException $exception) {
    if ($exception->getMessage() !== 'mock_unavailable' && $exception->getMessage() !== 'login_admin_failed') {
        echo 'ERROR  ' . $exception->getMessage() . "\n";
        $FAIL++;
    }
} finally {
    if ($testTicketId > 0) {
        try {
            $pdo->prepare('DELETE FROM pengaduan WHERE id = ?')->execute([$testTicketId]);
        } catch (Throwable $ignored) {
        }
    }

    try {
        $pdo->exec("DELETE FROM whatsapp_outbox WHERE id > {$maxOutbox}");
        $pdo->exec("DELETE FROM audit_log WHERE id > {$maxAudit}");
        $pdo->exec("DELETE FROM api_usage_log WHERE id > {$maxUsage}");
        $pdo->exec("DELETE FROM api_config_audit WHERE id > {$maxConfigAudit}");
        $pdo->exec("DELETE FROM api_integration_settings WHERE feature_key = 'whatsapp_report'");
        if (is_array($snapshotReport)) {
            $restore = $pdo->prepare(
                'INSERT INTO api_integration_settings
                    (feature_key, connection_id, is_enabled, settings_json, updated_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $restore->execute([
                $snapshotReport['feature_key'],
                $snapshotReport['connection_id'],
                $snapshotReport['is_enabled'],
                $snapshotReport['settings_json'],
                $snapshotReport['updated_by'],
                $snapshotReport['created_at'],
                $snapshotReport['updated_at'],
            ]);
        }

        if ($originalActiveId !== null) {
            ApiCredential::setActive($originalActiveId);
        } else {
            $pdo->exec("UPDATE api_credentials SET is_active = 0 WHERE integration = 'whatsapp'");
        }
        if ($createdCredentialIds !== []) {
            $placeholders = implode(',', array_fill(0, count($createdCredentialIds), '?'));
            $delete = $pdo->prepare("DELETE FROM api_credentials WHERE id IN ({$placeholders})");
            $delete->execute($createdCredentialIds);
        }
        ApiCredential::resetCache();
        ApiIntegrationSetting::resetCache();
    } catch (Throwable $exception) {
        echo 'CLEANUP ERROR  ' . $exception->getMessage() . "\n";
        $FAIL++;
    }

    @unlink($adminJar ?? '');
    @unlink($csJar ?? '');
}

echo "\n=== Ringkasan Tahap 27 ===\n";
echo "PASS {$PASS} | FAIL {$FAIL} | SKIP {$SKIP} | BLOCKED {$BLOCKED}\n";
exit($FAIL > 0 ? 1 : 0);
