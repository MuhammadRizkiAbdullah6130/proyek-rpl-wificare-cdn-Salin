<?php
/**
 * WiFiCare CDN - Tahap 20: ekstraksi nama pelanggan dan query lokal.
 *
 * Fokus:
 * - frasa pengantar "atas nama", "bernama", "yang namanya", "dengan nama"
 *   tidak boleh ikut menjadi nama;
 * - pencarian nama/PPPoE memakai tabel pelanggan + users, bukan snapshot tiket;
 * - intent daftar, jumlah, pencarian, pelanggan terbaru, dan pembuat pengaduan
 *   terbaru tidak saling tertukar;
 * - nama duplikat meminta klarifikasi;
 * - pelanggan tanpa riwayat tetap ditemukan;
 * - RBAC dan pesan tidak ditemukan tetap aman.
 *
 * Fixture tambahan hanya dibuat di database uji dan selalu dihapus.
 *
 * Jalankan:
 *   php tests/tahap20/customer-name-search.php
 */

declare(strict_types=1);

putenv('DB_NAME=wificare_cdn_test');
putenv('APP_ENV=testing');

require __DIR__ . '/../../app/bootstrap.php';

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

/** @return array<string, mixed>|null */
function firstUserByRole(PDO $db, string $role): ?array
{
    $statement = $db->prepare(
        'SELECT id, nama, username, role FROM users WHERE role = :role AND is_active = 1 ORDER BY id ASC LIMIT 1'
    );
    $statement->execute(['role' => $role]);
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

function scalar(PDO $db, string $sql, array $params = []): int
{
    $statement = $db->prepare($sql);
    $statement->execute($params);

    return (int) $statement->fetchColumn();
}

/**
 * @return array{user_id: int, pelanggan_id: int}
 */
function createTempCustomer(PDO $db, string $name, string $suffix): array
{
    $username = 'fixture.' . $suffix;
    $pppoe = 'fixture.' . $suffix;
    $insertUser = $db->prepare(
        'INSERT INTO users (username, password_hash, nama, role, is_active)
         VALUES (:username, :password_hash, :nama, :role, 1)'
    );
    $insertUser->execute([
        'username' => $username,
        'password_hash' => password_hash('Fixture!' . $suffix, PASSWORD_DEFAULT),
        'nama' => $name,
        'role' => 'pelanggan',
    ]);
    $userId = (int) $db->lastInsertId();

    $insertCustomer = $db->prepare(
        'INSERT INTO pelanggan (user_id, username_pppoe, nomor_whatsapp, alamat)
         VALUES (:user_id, :username_pppoe, :nomor_whatsapp, :alamat)'
    );
    $insertCustomer->execute([
        'user_id' => $userId,
        'username_pppoe' => $pppoe,
        'nomor_whatsapp' => '0812000099' . substr($suffix, -2),
        'alamat' => 'Alamat fixture uji',
    ]);

    return ['user_id' => $userId, 'pelanggan_id' => (int) $db->lastInsertId()];
}

function removeTempCustomer(PDO $db, int $userId): void
{
    if ($userId <= 0) {
        return;
    }
    $db->prepare('DELETE FROM pelanggan WHERE user_id = :user_id')->execute(['user_id' => $userId]);
    $db->prepare('DELETE FROM users WHERE id = :id AND role = :role')->execute([
        'id' => $userId,
        'role' => 'pelanggan',
    ]);
}

/** @return array<int, array<string, mixed>> */
function resultRows(array $result): array
{
    $rows = [];
    foreach ((array) ($result['tables'] ?? []) as $table) {
        foreach ((array) ($table['rows'] ?? []) as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
    }

    return $rows;
}

/** @return array<int, string> */
function resultColumns(array $result): array
{
    $columns = [];
    foreach ((array) ($result['tables'] ?? []) as $table) {
        foreach ((array) ($table['columns'] ?? []) as $column) {
            $key = (string) ($column['key'] ?? '');
            if ($key !== '') {
                $columns[] = $key;
            }
        }
    }

    return $columns;
}

$db = Database::pdo();
$admin = firstUserByRole($db, 'admin');
$cs = firstUserByRole($db, 'cs');
$teknisi = firstUserByRole($db, 'teknisi');
$pelangganUser = firstUserByRole($db, 'pelanggan');

if ($admin === null || $cs === null || $teknisi === null || $pelangganUser === null) {
    echo "Tidak dapat melanjutkan tanpa akun uji lengkap.\n";
    exit(1);
}

$suffix = strtolower(bin2hex(random_bytes(4)));
$tempUserIds = [];
$agusCreated = false;
$fixtureAgus = null;
$fixtureDuplicate = null;
$fixtureNoHistory = null;

$resetContext = static function (array $users): void {
    foreach ($users as $user) {
        AiContext::clear((int) ($user['id'] ?? 0));
    }
};
$users = [$admin, $cs, $teknisi, $pelangganUser];

try {
    $resetContext($users);

    $agus = $db->query(
        "SELECT p.id, p.user_id, u.nama, p.username_pppoe
         FROM pelanggan p INNER JOIN users u ON u.id = p.user_id
         WHERE u.nama LIKE '%Agus%' AND u.is_active = 1
         ORDER BY p.id ASC LIMIT 1"
    )->fetch();

    if (!is_array($agus)) {
        $fixtureAgus = createTempCustomer($db, 'Agus Setiawan', 'agus.' . $suffix);
        $tempUserIds[] = $fixtureAgus['user_id'];
        $agusCreated = true;
        $agus = $db->query(
            "SELECT p.id, p.user_id, u.nama, p.username_pppoe
             FROM pelanggan p INNER JOIN users u ON u.id = p.user_id
             WHERE p.id = " . (int) $fixtureAgus['pelanggan_id']
        )->fetch();
    }

    if (!is_array($agus)) {
        throw new RuntimeException('Fixture pelanggan Agus gagal dibuat/ditemukan.');
    }

    $agusName = (string) $agus['nama'];
    $agusPppoe = (string) $agus['username_pppoe'];
    $agusId = (int) $agus['id'];
    $agusComplaints = scalar(
        $db,
        'SELECT COUNT(*) FROM pengaduan WHERE pelanggan_id = :id AND is_cancelled = 0',
        ['id' => $agusId]
    );
    $totalCustomers = scalar(
        $db,
        'SELECT COUNT(*) FROM pelanggan p INNER JOIN users u ON u.id = p.user_id'
    );
    $latestCustomer = $db->query(
        'SELECT u.nama FROM pelanggan p INNER JOIN users u ON u.id = p.user_id
         ORDER BY p.created_at DESC, p.id DESC LIMIT 1'
    )->fetchColumn();
    $latestComplaint = $db->query(
        'SELECT p.nama_pelanggan_snapshot, p.nomor_tiket FROM pengaduan p
         ORDER BY p.created_at DESC, p.id DESC LIMIT 1'
    )->fetch();
    $expectedTechnicians = scalar($db, "SELECT COUNT(*) FROM users WHERE role = 'teknisi' AND is_active = 1");

    $isAgus = static function (array $result) use ($agusName): bool {
        foreach (resultRows($result) as $row) {
            if (strcasecmp((string) ($row['nama'] ?? ''), $agusName) === 0) {
                return true;
            }
        }

        return false;
    };

    // 1. Frasa "atas nama" tidak boleh menjadi bagian nama.
    $result = LocalDatabaseAssistant::answer($admin, 'apakah ada pelanggan atas nama agus');
    check(
        '1. "atas nama agus" -> customers.search + nama Agus',
        ($result['intent'] ?? '') === 'customers.search'
            && ($result['reply'] ?? '') === 'Pelanggan bernama ' . $agusName . ' ditemukan.'
            && $isAgus($result),
        json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    // 2.
    $result = LocalDatabaseAssistant::answer($admin, 'cari pelanggan agus');
    check(
        '2. "cari pelanggan agus" menemukan pelanggan yang sama',
        ($result['intent'] ?? '') === 'customers.search' && $isAgus($result),
        json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    // 3.
    $result = LocalDatabaseAssistant::answer($admin, 'ada pelanggan bernama agus?');
    check(
        '3. "bernama agus" tidak menyertakan kata pengantar',
        ($result['intent'] ?? '') === 'customers.search'
            && str_contains((string) ($result['reply'] ?? ''), $agusName)
            && $isAgus($result),
        (string) ($result['reply'] ?? '')
    );

    // 4.
    $result = LocalDatabaseAssistant::answer($admin, 'pelanggan atas nama agus setiawan');
    $rows = resultRows($result);
    check(
        '4. nama lengkap "Agus Setiawan" dipertahankan',
        ($result['intent'] ?? '') === 'customers.search'
            && count($rows) >= 1
            && strcasecmp((string) ($rows[0]['nama'] ?? ''), $agusName) === 0,
        json_encode($rows, JSON_UNESCAPED_UNICODE)
    );

    // 5.
    $result = LocalDatabaseAssistant::answer($admin, 'apakah agus terdaftar sebagai pelanggan?');
    check(
        '5. "agus terdaftar sebagai pelanggan" -> pencarian, bukan daftar',
        ($result['intent'] ?? '') === 'customers.search' && $isAgus($result),
        json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    // 6.
    $result = LocalDatabaseAssistant::answer($admin, 'pelanggan siapa aja');
    $rows = resultRows($result);
    check(
        '6. "pelanggan siapa aja" tetap customers.list',
        ($result['intent'] ?? '') === 'customers.list'
            && count($rows) === min(20, $totalCustomers),
        json_encode($result, JSON_UNESCAPED_UNICODE)
    );

    // 7.
    $result = LocalDatabaseAssistant::answer($admin, 'berapa jumlah pelanggan');
    check(
        '7. "berapa jumlah pelanggan" tetap customers.count',
        ($result['intent'] ?? '') === 'customers.count'
            && str_contains((string) ($result['reply'] ?? ''), 'Total pelanggan: ' . $totalCustomers),
        (string) ($result['reply'] ?? '')
    );

    // 8.
    $result = LocalDatabaseAssistant::answer($admin, 'siapa pelanggan terbaru');
    check(
        '8. "siapa pelanggan terbaru" memakai customers.latest',
        ($result['intent'] ?? '') === 'customers.latest'
            && is_string($latestCustomer)
            && str_contains((string) ($result['reply'] ?? ''), (string) $latestCustomer),
        (string) ($result['reply'] ?? '')
    );

    // 9.
    $result = LocalDatabaseAssistant::answer($admin, 'siapa yang terakhir membuat pengaduan');
    check(
        '9. pembuat pengaduan terbaru tetap complaints.latest_customer',
        ($result['intent'] ?? '') === 'complaints.latest_customer'
            && is_array($latestComplaint)
            && str_contains((string) ($result['reply'] ?? ''), (string) ($latestComplaint['nama_pelanggan_snapshot'] ?? ''))
            && str_contains((string) ($result['reply'] ?? ''), (string) ($latestComplaint['nomor_tiket'] ?? '')),
        (string) ($result['reply'] ?? '')
    );

    // 10. Nama yang tidak ada.
    $missingName = 'Zzz Tidak Ada ' . $suffix;
    $missingDisplay = mb_convert_case($missingName, MB_CASE_TITLE, 'UTF-8');
    $result = LocalDatabaseAssistant::answer($admin, 'apakah ada pelanggan atas nama ' . $missingName);
    check(
        '10. nama tidak ada dijawab jujur tanpa frasa pengantar',
        ($result['intent'] ?? '') === 'customers.search'
            && str_contains((string) ($result['reply'] ?? ''), 'Pelanggan bernama ' . $missingDisplay . ' tidak ditemukan.')
            && !str_contains((string) ($result['reply'] ?? ''), 'atas nama')
            && resultRows($result) === [],
        (string) ($result['reply'] ?? '')
    );

    // 11. Beberapa pelanggan dengan nama sama.
    $fixtureDuplicate = createTempCustomer($db, $agusName, 'dup.' . $suffix);
    $tempUserIds[] = $fixtureDuplicate['user_id'];
    $result = LocalDatabaseAssistant::answer($admin, 'cari pelanggan agus setiawan');
    $rows = resultRows($result);
    check(
        '11. beberapa nama mirip/sama meminta klarifikasi',
        ($result['intent'] ?? '') === 'customers.search'
            && count($rows) > 1
            && str_contains((string) ($result['reply'] ?? ''), 'Ditemukan ' . count($rows) . ' pelanggan'),
        (string) ($result['reply'] ?? '')
    );
    removeTempCustomer($db, (int) $fixtureDuplicate['user_id']);
    $tempUserIds = array_values(array_filter(
        $tempUserIds,
        static fn (int $id): bool => $id !== (int) ($fixtureDuplicate['user_id'] ?? 0)
    ));
    $fixtureDuplicate = null;

    // 12. Kapitalisasi dan spasi berbeda.
    $result = LocalDatabaseAssistant::answer($admin, 'cari pelanggan   aGuS   sEtIaWaN');
    check(
        '12. kapitalisasi/spasi berbeda tetap cocok',
        ($result['intent'] ?? '') === 'customers.search' && $isAgus($result),
        json_encode($result, JSON_UNESCAPED_UNICODE)
    );

    // 13. Pelanggan tanpa riwayat pengaduan.
    $fixtureNoHistory = createTempCustomer($db, 'Tanpa Riwayat ' . $suffix, 'nohist.' . $suffix);
    $tempUserIds[] = $fixtureNoHistory['user_id'];
    $historyCount = scalar(
        $db,
        'SELECT COUNT(*) FROM pengaduan WHERE pelanggan_id = :id',
        ['id' => $fixtureNoHistory['pelanggan_id']]
    );
    $result = LocalDatabaseAssistant::answer($admin, 'cari pelanggan tanpa riwayat ' . $suffix);
    $rows = resultRows($result);
    check(
        '13. pelanggan tanpa riwayat pengaduan tetap ditemukan',
        $historyCount === 0
            && ($result['intent'] ?? '') === 'customers.search'
            && count($rows) === 1
            && str_contains((string) ($rows[0]['nama'] ?? ''), 'Tanpa Riwayat ' . $suffix),
        json_encode($result, JSON_UNESCAPED_UNICODE)
    );
    removeTempCustomer($db, (int) $fixtureNoHistory['user_id']);
    $tempUserIds = array_values(array_filter(
        $tempUserIds,
        static fn (int $id): bool => $id !== (int) ($fixtureNoHistory['user_id'] ?? 0)
    ));
    $fixtureNoHistory = null;

    // 14-15. RBAC.
    $resetContext($users);
    $adminResult = LocalDatabaseAssistant::answer($admin, 'apakah ada pelanggan atas nama agus');
    $csResult = LocalDatabaseAssistant::answer($cs, 'apakah ada pelanggan atas nama agus');
    $customerResult = LocalDatabaseAssistant::answer($pelangganUser, 'apakah ada pelanggan atas nama agus');
    check(
        '14. Admin dan CS boleh mencari pelanggan',
        !empty($adminResult['ok']) && !empty($csResult['ok'])
            && $isAgus($adminResult),
        json_encode($adminResult, JSON_UNESCAPED_UNICODE)
    );
    check(
        '15. pelanggan tidak boleh mencari data pelanggan lain',
        ($customerResult['intent'] ?? '') === 'access.denied'
            && resultRows($customerResult) === []
            && !str_contains((string) ($customerResult['reply'] ?? ''), $agusName),
        json_encode($customerResult, JSON_UNESCAPED_UNICODE)
    );

    // 16. Tara kelola hanya memakai sumber lokal (request cloud diuji mock provider).
    check(
        '16. hasil pencarian pelanggan berasal dari database lokal',
        ($adminResult['provider'] ?? '') === 'local'
            && ($adminResult['model'] ?? '') === 'database-assistant',
        json_encode($adminResult, JSON_UNESCAPED_UNICODE)
    );

    // 17. Bandingkan hasil AI dengan query Data Pelanggan yang sebenarnya.
    $direct = AiOperationsReadService::customerSearch($admin, 'agus', 10);
    $directNames = array_map(
        static fn (array $row): string => (string) ($row['nama'] ?? ''),
        (array) ($direct['customers'] ?? [])
    );
    check(
        '17. nama hasil AI sama dengan query tabel pelanggan/users',
        in_array($agusName, $directNames, true)
            && $agusPppoe === (string) ($agus['username_pppoe'] ?? ''),
        json_encode($directNames, JSON_UNESCAPED_UNICODE)
    );

    // 18. Tidak ada data pribadi berlebih pada pertanyaan keberadaan.
    $columns = resultColumns($result);
    $lookupResult = LocalDatabaseAssistant::answer($admin, 'apakah ada pelanggan atas nama agus');
    $lookupColumns = resultColumns($lookupResult);
    check(
        '18. pertanyaan keberadaan tidak membocorkan WhatsApp/alamat',
        !in_array('whatsapp', $lookupColumns, true)
            && !in_array('alamat', $lookupColumns, true)
            && !str_contains(json_encode($lookupResult, JSON_UNESCAPED_UNICODE) ?: '', '0812000000')
            && !str_contains(json_encode($lookupResult, JSON_UNESCAPED_UNICODE) ?: '', 'Jl.'),
        implode(',', $lookupColumns)
    );

    // 19. Follow-up memakai pelanggan yang baru ditemukan.
    $resetContext($users);
    LocalDatabaseAssistant::answer($admin, 'apakah ada pelanggan atas nama agus');
    $summary = LocalDatabaseAssistant::answer($admin, 'berapa pengaduannya');
    $detail = LocalDatabaseAssistant::answer($admin, 'pelanggan itu');
    check(
        '19. follow-up memakai konteks pelanggan yang benar',
        ($summary['intent'] ?? '') === 'customers.complaint_summary'
            && str_contains((string) ($summary['reply'] ?? ''), $agusName)
            && str_contains((string) ($summary['reply'] ?? ''), (string) $agusComplaints)
            && ($detail['intent'] ?? '') === 'customers.detail'
            && str_contains((string) ($detail['reply'] ?? ''), $agusName),
        json_encode([$summary['reply'] ?? '', $detail['reply'] ?? ''], JSON_UNESCAPED_UNICODE)
    );

    // 20. Intent teknisi tidak rusak.
    $techResult = LocalDatabaseAssistant::answer($admin, 'berapa jumlah teknisi');
    check(
        '20. intent teknisi/karyawan tidak rusak',
        ($techResult['intent'] ?? '') === 'technicians.count'
            && str_contains((string) ($techResult['reply'] ?? ''), (string) $expectedTechnicians),
        (string) ($techResult['reply'] ?? '')
    );
} finally {
    $resetContext($users);
    foreach ($tempUserIds as $userId) {
        removeTempCustomer($db, (int) $userId);
    }
    if ($fixtureAgus !== null && $agusCreated) {
        removeTempCustomer($db, (int) $fixtureAgus['user_id']);
    }
}

echo "\nHASIL TAHAP 20 CUSTOMER SEARCH: {$PASS} PASS, {$FAIL} FAIL\n";
if ($FAILED !== []) {
    echo "GAGAL:\n- " . implode("\n- ", $FAILED) . "\n";
    exit(1);
}
