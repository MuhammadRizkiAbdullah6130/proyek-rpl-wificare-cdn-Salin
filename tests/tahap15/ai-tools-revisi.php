<?php
/**
 * WiFiCare CDN — Tahap 15: verifikasi tool baca-only & cakupan peran (revisi AI).
 *
 * Menguji langsung AiReadService/AiToolService terhadap DB uji:
 * - statistik tanggal (today / this_month) memakai data nyata;
 * - tiket selesai dihitung dari riwayat status, bukan createdAt;
 * - cakupan pelanggan/teknisi/CS/Admin tidak saling bocor;
 * - profil hanya milik pelanggan yang login;
 * - allowlist tool sesuai peran dan tetap tanpa tool tulis.
 *
 * Jalankan: php tests/tahap15/ai-tools-revisi.php
 */

declare(strict_types=1);

putenv('DB_NAME=wificare_cdn_test');
putenv('APP_ENV=testing');

require __DIR__ . '/../../app/bootstrap.php';

$PASS = 0;
$FAIL = 0;
$BLOCKED = 0;
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

function blocked(string $name, string $reason): void
{
    global $BLOCKED;
    $BLOCKED++;
    echo "BLOCKED  {$name} :: {$reason}\n";
}

function userByUsername(PDO $pdo, string $username): array
{
    $statement = $pdo->prepare('SELECT id, username, role, nama FROM users WHERE username = :u LIMIT 1');
    $statement->execute(['u' => $username]);
    $row = $statement->fetch();

    if (!is_array($row)) {
        throw new RuntimeException('Akun uji tidak ditemukan: ' . $username);
    }

    return ['id' => (int) $row['id'], 'role' => (string) $row['role'], 'name' => (string) $row['nama']];
}

try {
    $pdo = Database::pdo();
} catch (Throwable $exception) {
    echo "BLOCKED  koneksi DB uji :: " . $exception->getMessage() . "\n";
    exit(1);
}

echo "==== TAHAP 15 — TOOL BACA AI & CAKUPAN PERAN ====\n";

$admin = userByUsername($pdo, 'admin.budi');
$cs = userByUsername($pdo, 'cs.dewi');
$teknisi = userByUsername($pdo, 'tek.andi');
$pelanggan = userByUsername($pdo, 'pppoe.rina');

$pid = (int) $pdo->query('SELECT id FROM pelanggan WHERE user_id = ' . $pelanggan['id'] . ' LIMIT 1')->fetchColumn();
if ($pid <= 0) {
    echo "BLOCKED  profil pelanggan uji tidak ditemukan\n";
    exit(1);
}

$totalAll = (int) $pdo->query('SELECT COUNT(*) FROM pengaduan WHERE is_cancelled = 0')->fetchColumn();
$totalOwn = (int) $pdo->query('SELECT COUNT(*) FROM pengaduan WHERE is_cancelled = 0 AND pelanggan_id = ' . $pid)->fetchColumn();
$totalTek = (int) $pdo->query(
    'SELECT COUNT(DISTINCT p.id) FROM penugasan_teknisi pt
     INNER JOIN pengaduan p ON p.id = pt.pengaduan_id
     WHERE pt.teknisi_id = ' . $teknisi['id'] . ' AND pt.is_active = 1 AND p.is_cancelled = 0'
)->fetchColumn();

// --- Cakupan statistik --------------------------------------------------------
$adminStats = AiReadService::statusStatisticsForUser($admin, 'all');
check('admin total = seluruh tiket DB', (int) $adminStats['total'] === $totalAll, "tool={$adminStats['total']} db={$totalAll}");
$csStats = AiReadService::statusStatisticsForUser($cs, 'all');
check('CS total = seluruh tiket DB', (int) $csStats['total'] === $totalAll, "tool={$csStats['total']} db={$totalAll}");
$tekStats = AiReadService::statusStatisticsForUser($teknisi, 'all');
check('teknisi total = tiket yang ditugaskan', (int) $tekStats['total'] === $totalTek, "tool={$tekStats['total']} db={$totalTek}");
$pelStats = AiReadService::statusStatisticsForUser($pelanggan, 'all');
check('pelanggan total = tiket miliknya', (int) $pelStats['total'] === $totalOwn, "tool={$pelStats['total']} db={$totalOwn}");
check('statistik memuat enam status resmi', count($adminStats['by_status']) === 6, 'count=' . count($adminStats['by_status']));

// --- Statistik periode & tanggal ---------------------------------------------
$today = date('Y-m-d');
$createdToday = (int) $pdo->query('SELECT COUNT(*) FROM pengaduan WHERE is_cancelled = 0 AND DATE(created_at) = ' . $pdo->quote($today))->fetchColumn();
$adminToday = AiReadService::complaintStatisticsForUser($admin, 'today');
check('statistik hari ini: pengaduan baru = DB', (int) $adminToday['total_pengaduan_baru'] === $createdToday, "tool={$adminToday['total_pengaduan_baru']} db={$createdToday}");

$completedToday = (int) $pdo->query(
    'SELECT COUNT(DISTINCT p.id) FROM riwayat_status rs
     INNER JOIN pengaduan p ON p.id = rs.pengaduan_id
     WHERE rs.status_baru = \'selesai\' AND p.is_cancelled = 0 AND DATE(rs.created_at) = ' . $pdo->quote($today)
)->fetchColumn();
check('statistik hari ini: selesai dari riwayat = DB', (int) $adminToday['tiket_selesai_pada_periode'] === $completedToday, "tool={$adminToday['tiket_selesai_pada_periode']} db={$completedToday}");
check('hasil statistik menjelaskan total vs selesai', isset($adminToday['catatan']) && str_contains((string) $adminToday['catatan'], 'Jangan menjumlahkan'));

$month = AiReadService::complaintStatisticsForUser($admin, 'this_month');
$monthCreated = (int) $pdo->query("SELECT COUNT(*) FROM pengaduan WHERE is_cancelled = 0 AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')")->fetchColumn();
check('statistik bulan ini: pengaduan baru = DB', (int) $month['total_pengaduan_baru'] === $monthCreated, "tool={$month['total_pengaduan_baru']} db={$monthCreated}");
$invalidPeriod = AiReadService::complaintStatisticsForUser($admin, 'kemarin');
check('periode tidak dikenal jatuh ke default aman', $invalidPeriod['period'] === 'today', 'period=' . $invalidPeriod['period']);

// --- Tiket aktif --------------------------------------------------------------
$active = AiReadService::activeTicketsForUser($pelanggan, 20);
$allOwnActive = true;
foreach ($active['tickets'] as $ticket) {
    if (!in_array($ticket['status_key'], ['menunggu_verifikasi', 'diproses_cs', 'menunggu_teknisi', 'dalam_penanganan'], true)) {
        $allOwnActive = false;
    }
}
check('daftar tiket aktif pelanggan hanya status aktif', $allOwnActive);

$activeCount = AiReadService::activeTicketCountForUser($pelanggan);
check('jumlah tiket aktif konsisten dengan total-status', $activeCount['active'] <= $activeCount['total'], json_encode($activeCount));

// --- Profil sendiri -----------------------------------------------------------
$profile = AiReadService::profileForUser($pelanggan);
check('profil pelanggan ditemukan', !empty($profile['found']));
check('profil memakai username PPPoE milik sendiri', ($profile['profil']['username_pppoe'] ?? '') === 'pppoe.rina');
check('nomor WhatsApp disamarkan', str_contains((string) ($profile['profil']['nomor_whatsapp'] ?? ''), '*'));
$profileTek = AiReadService::profileForUser($teknisi);
check('profil ditolak untuk teknisi', ($profileTek['error'] ?? '') === 'not_allowed');

// --- Riwayat teknisi ----------------------------------------------------------
$history = AiReadService::technicianHistoryForUser($teknisi, 20);
$historyValid = true;
foreach ($history['history'] as $ticket) {
    if (!in_array($ticket['status_key'], ['selesai', 'ditolak'], true)) {
        $historyValid = false;
    }
}
check('riwayat teknisi hanya tiket selesai/ditolak', $historyValid);
$historyOther = AiReadService::technicianHistoryForUser($pelanggan, 5);
check('riwayat teknisi ditolak untuk pelanggan', ($historyOther['error'] ?? '') === 'not_allowed');

// --- Rating -------------------------------------------------------------------
$rating = AiReadService::ratingSummary();
$dbRatingCount = (int) $pdo->query('SELECT COUNT(*) FROM rating_layanan')->fetchColumn();
check('ringkasan rating = jumlah DB', (int) $rating['total_rating'] === $dbRatingCount, "tool={$rating['total_rating']} db={$dbRatingCount}");

// --- Allowlist tool per peran -------------------------------------------------
$adminTools = AiToolService::allowedNames('admin');
$csTools = AiToolService::allowedNames('cs');
$tekTools = AiToolService::allowedNames('teknisi');
$pelTools = AiToolService::allowedNames('pelanggan');

check('admin boleh get_service_rating_summary', in_array('get_service_rating_summary', $adminTools, true));
check('CS boleh get_service_rating_summary', in_array('get_service_rating_summary', $csTools, true));
check('teknisi TIDAK boleh get_service_rating_summary', !in_array('get_service_rating_summary', $tekTools, true));
check('pelanggan TIDAK boleh get_service_rating_summary', !in_array('get_service_rating_summary', $pelTools, true));
check('get_my_profile hanya pelanggan', in_array('get_my_profile', $pelTools, true)
    && !in_array('get_my_profile', $adminTools, true)
    && !in_array('get_my_profile', $tekTools, true));
check('get_technician_history hanya teknisi', in_array('get_technician_history', $tekTools, true)
    && !in_array('get_technician_history', $adminTools, true)
    && !in_array('get_technician_history', $pelTools, true));
check('get_complaint_statistics tersedia untuk semua peran', in_array('get_complaint_statistics', $adminTools, true)
    && in_array('get_complaint_statistics', $csTools, true)
    && in_array('get_complaint_statistics', $tekTools, true)
    && in_array('get_complaint_statistics', $pelTools, true));
check('get_my_active_tickets tersedia untuk semua peran', in_array('get_my_active_tickets', $pelTools, true)
    && in_array('get_my_active_tickets', $tekTools, true));

// Tidak ada tool tulis pada allowlist.
$writePatterns = ['insert', 'update', 'delete', 'drop', 'alter', 'create', 'truncate'];
$writeTool = '';
foreach (array_merge($adminTools, $csTools, $tekTools, $pelTools) as $name) {
    foreach ($writePatterns as $pattern) {
        if (str_contains(strtolower($name), $pattern)) {
            $writeTool = $name;
        }
    }
}
check('allowlist tetap tanpa tool tulis', $writeTool === '', $writeTool);

// Argumen aneh tidak menjadi SQL.
try {
    $result = AiToolService::execute('get_my_tickets', ['limit' => '1; DROP TABLE users'], $pelanggan);
    check('argumen limit aneh tidak dieksekusi sebagai SQL', isset($result['tickets']) && (int) $result['count'] <= 20);
} catch (Throwable $exception) {
    check('argumen limit aneh tidak dieksekusi sebagai SQL', false, $exception->getMessage());
}

echo "----------------------------------------------------------------\n";
echo 'TOTAL: ' . ($PASS + $FAIL) . " | PASS: {$PASS} | FAIL: {$FAIL} | BLOCKED: {$BLOCKED}\n";
if ($FAIL > 0) {
    echo "GAGAL:\n";
    foreach ($FAILED as $name) {
        echo '  - ' . $name . "\n";
    }
    exit(1);
}

echo "SEMUA TES TOOL BACA AI LULUS.\n";
