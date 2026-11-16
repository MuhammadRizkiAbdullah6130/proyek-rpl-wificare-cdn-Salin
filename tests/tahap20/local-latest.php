<?php
/**
 * WiFiCare CDN - Tahap 20: pengaduan terbaru dan follow-up lokal.
 *
 * Menguji alur nyata terhadap database uji:
 * - "Siapa pelanggan yang terbaru pengaduan?" dan "Siapa yang terakhir membuat
 *   tiket?" dijawab dari MySQL, bukan cloud;
 * - follow-up nama pelanggan, "pelanggan itu", "yang terakhir", "tiket tadi",
 *   dan "berapa pengaduannya" memakai konteks aman;
 * - konteks tidak bocor lintas akun/role;
 * - nama duplikat meminta klarifikasi PPPoE.
 *
 * Jalankan:
 *   php tests/tahap20/local-latest.php
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

function resetContext(array $users): void
{
    foreach ($users as $user) {
        AiContext::clear((int) ($user['id'] ?? 0));
    }
}

$db = Database::pdo();
$admin = firstUserByRole($db, 'admin');
$cs = firstUserByRole($db, 'cs');
$teknisi = firstUserByRole($db, 'teknisi');
$pelanggan = firstUserByRole($db, 'pelanggan');

if ($admin === null || $cs === null || $teknisi === null || $pelanggan === null) {
    echo "Tidak dapat melanjutkan tanpa akun uji lengkap.\n";
    exit(1);
}

$users = [$admin, $cs, $teknisi, $pelanggan];
resetContext($users);

$latest = $db->query(
    'SELECT p.nomor_tiket, p.pelanggan_id, p.nama_pelanggan_snapshot, p.created_at
     FROM pengaduan p
     ORDER BY p.created_at DESC, p.id DESC
     LIMIT 1'
)->fetch();

if (!is_array($latest)) {
    echo "Database uji tidak memiliki pengaduan.\n";
    exit(1);
}

$latestName = (string) $latest['nama_pelanggan_snapshot'];
$latestTicket = (string) $latest['nomor_tiket'];
$latestCustomerId = (int) $latest['pelanggan_id'];
$latestCount = scalar(
    $db,
    'SELECT COUNT(*) FROM pengaduan WHERE pelanggan_id = :id AND is_cancelled = 0',
    ['id' => $latestCustomerId]
);

// 1-2. Pertanyaan global terbaru tidak boleh meminta nama pelanggan.
$result = LocalDatabaseAssistant::answer($admin, 'Siapa pelanggan yang terbaru pengaduan?');
check(
    'pertanyaan pelanggan terbaru memakai intent khusus',
    ($result['intent'] ?? '') === 'complaints.latest_customer',
    json_encode($result, JSON_UNESCAPED_UNICODE)
);
check(
    'jawaban pelanggan terbaru sesuai MySQL',
    !empty($result['ok'])
        && str_contains((string) ($result['reply'] ?? ''), $latestName)
        && str_contains((string) ($result['reply'] ?? ''), $latestTicket),
    (string) ($result['reply'] ?? '')
);

// 3-4. Daftar pengaduan terbaru menyimpan tiket terakhir tanpa pelanggan.
$result = LocalDatabaseAssistant::answer($admin, 'Tampilkan pengaduan terbaru');
$context = AiContext::get((int) $admin['id']);
check(
    'daftar terbaru memakai complaints.latest_ticket',
    ($result['intent'] ?? '') === 'complaints.latest_ticket',
    json_encode($result, JSON_UNESCAPED_UNICODE)
);
check(
    'daftar terbaru membersihkan pelanggan tetapi menyimpan tiket',
    ($context['last_customer_id'] ?? null) === null
        && ($context['last_ticket'] ?? '') === $latestTicket,
    json_encode($context, JSON_UNESCAPED_UNICODE)
);

$result = LocalDatabaseAssistant::answer($admin, 'status tiket tadi');
check(
    'follow-up tiket tadi memakai tiket terakhir',
    ($result['intent'] ?? '') === 'complaints.detail'
        && str_contains((string) ($result['reply'] ?? ''), $latestTicket),
    (string) ($result['reply'] ?? '')
);

// 5. Nama pendek setelah pertanyaan terbaru.
resetContext($users);
LocalDatabaseAssistant::answer($admin, 'Siapa pelanggan yang terbaru pengaduan?');
$result = LocalDatabaseAssistant::answer($admin, $latestName);
$tableNames = [];
foreach ((array) ($result['tables'] ?? []) as $table) {
    foreach ((array) ($table['rows'] ?? []) as $row) {
        $tableNames[] = (string) ($row['nama'] ?? '');
    }
}
check(
    'nama pendek setelah konteks dijawab lokal',
    ($result['intent'] ?? '') === 'customers.search'
        && in_array($latestName, $tableNames, true),
    json_encode($result, JSON_UNESCAPED_UNICODE)
);

// 6. "Berapa pengaduannya?" memakai pelanggan yang sedang dibahas.
$result = LocalDatabaseAssistant::answer($admin, 'berapa pengaduannya');
check(
    'berapa pengaduannya memakai konteks pelanggan',
    ($result['intent'] ?? '') === 'customers.complaint_summary'
        && str_contains((string) ($result['reply'] ?? ''), (string) $latestCount),
    (string) ($result['reply'] ?? '')
);

// 7-8. Referensi pelanggan dan "yang terakhir".
$result = LocalDatabaseAssistant::answer($admin, 'pelanggan itu');
check(
    'pelanggan itu membuka detail pelanggan konteks',
    ($result['intent'] ?? '') === 'customers.detail'
        && str_contains((string) ($result['reply'] ?? ''), $latestName),
    (string) ($result['reply'] ?? '')
);

resetContext($users);
LocalDatabaseAssistant::answer($admin, 'Siapa pelanggan yang terbaru pengaduan?');
$result = LocalDatabaseAssistant::answer($admin, 'yang terakhir');
check(
    'yang terakhir memakai konteks pelanggan terbaru',
    ($result['intent'] ?? '') === 'customers.detail'
        && str_contains((string) ($result['reply'] ?? ''), $latestName),
    (string) ($result['reply'] ?? '')
);

// 9. Nama duplikat harus meminta klarifikasi, bukan menebak.
$customerRows = $db->query(
    "SELECT id, nama FROM users WHERE role = 'pelanggan' AND is_active = 1 ORDER BY id ASC LIMIT 2"
)->fetchAll();
$originalNames = [];
foreach ($customerRows as $row) {
    $originalNames[(int) $row['id']] = (string) $row['nama'];
}

$duplicateName = '';
try {
    if (count($customerRows) >= 2) {
        $duplicateName = (string) $customerRows[0]['nama'];
        $db->prepare('UPDATE users SET nama = :nama WHERE id = :id')->execute([
            'nama' => $duplicateName,
            'id' => (int) $customerRows[1]['id'],
        ]);
        resetContext($users);
        $result = LocalDatabaseAssistant::answer($admin, 'pengaduan ' . $duplicateName);
        check(
            'nama duplikat meminta klarifikasi PPPoE',
            ($result['intent'] ?? '') === 'customers.complaints'
                && str_contains(mb_strtolower((string) ($result['reply'] ?? '')), 'pppoe'),
            (string) ($result['reply'] ?? '')
        );
    } else {
        check('nama duplikat meminta klarifikasi PPPoE', false, 'butuh dua pelanggan uji');
    }
} finally {
    foreach ($originalNames as $id => $name) {
        $db->prepare('UPDATE users SET nama = :nama WHERE id = :id')->execute([
            'nama' => $name,
            'id' => $id,
        ]);
    }
}

// 10-11. RBAC pertanyaan global.
resetContext($users);
$adminResult = LocalDatabaseAssistant::answer($admin, 'Siapa yang terakhir membuat tiket?');
$csResult = LocalDatabaseAssistant::answer($cs, 'Siapa yang terakhir membuat tiket?');
$teknisiResult = LocalDatabaseAssistant::answer($teknisi, 'Siapa yang terakhir membuat tiket?');
$pelangganResult = LocalDatabaseAssistant::answer($pelanggan, 'Siapa yang terakhir membuat tiket?');
check(
    'admin dan CS boleh melihat pelanggan terbaru',
    !empty($adminResult['ok'])
        && !empty($csResult['ok'])
        && ($adminResult['intent'] ?? '') === 'complaints.latest_customer'
        && ($csResult['intent'] ?? '') === 'complaints.latest_customer',
    json_encode([$adminResult, $csResult], JSON_UNESCAPED_UNICODE)
);
check(
    'teknisi dan pelanggan ditolak untuk data global',
    ($teknisiResult['intent'] ?? '') === 'access.denied'
        && ($pelangganResult['intent'] ?? '') === 'access.denied'
        && str_contains((string) ($teknisiResult['reply'] ?? ''), 'tidak memiliki izin')
        && str_contains((string) ($pelangganResult['reply'] ?? ''), 'tidak memiliki izin'),
    json_encode([$teknisiResult, $pelangganResult], JSON_UNESCAPED_UNICODE)
);

// 12. Konteks terikat user/session.
resetContext($users);
LocalDatabaseAssistant::answer($admin, 'Siapa pelanggan yang terbaru pengaduan?');
check(
    'konteks tidak terbaca oleh akun lain',
    AiContext::get((int) $admin['id']) !== []
        && AiContext::get((int) $teknisi['id']) === [],
    json_encode(AiContext::get((int) $teknisi['id']), JSON_UNESCAPED_UNICODE)
);

// 13. Login/logout membersihkan seluruh konteks sesi.
AiContext::clearAll();
check(
    'clearAll membersihkan konteks semua akun',
    AiContext::get((int) $admin['id']) === []
        && AiContext::get((int) $teknisi['id']) === [],
    json_encode(AiContext::get((int) $admin['id']), JSON_UNESCAPED_UNICODE)
);

resetContext($users);

echo "\nHASIL TAHAP 20 LOCAL LATEST: {$PASS} PASS, {$FAIL} FAIL\n";
if ($FAILED !== []) {
    echo "GAGAL:\n- " . implode("\n- ", $FAILED) . "\n";
    exit(1);
}
