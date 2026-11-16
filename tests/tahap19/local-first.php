<?php
/**
 * WiFiCare CDN - Tahap 19: Local-First AI, Intent Akurat, dan Monitoring.
 *
 * Pengujian nyata terhadap database uji `wificare_cdn_test`:
 * - jumlah/daftar/pencarian pelanggan dipisahkan;
 * - peringkat teknisi, tie, dan teknisi tanpa penugasan;
 * - semua intent database yang dikenali dijawab lokal lebih dulu;
 * - cloud timeout tidak memblokir jawaban database;
 * - kegagalan MySQL, akses ditolak, kredensial, dan SQL bebas dibedakan;
 * - audit metadata dan statistik sumber jawaban.
 *
 * Fixture teknisi nol dibuat sementara lalu dihapus. Konfigurasi AI dan log uji
 * juga dipulihkan/dibersihkan agar database uji tidak berubah permanen.
 *
 * Jalankan:
 *   php tests/tahap19/local-first.php
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

function blocked(string $name, string $detail = ''): void
{
    global $BLOCKED;
    $BLOCKED++;
    echo "BLOCKED  {$name}" . ($detail !== '' ? " :: {$detail}" : '') . "\n";
}

/** @param array<string, mixed> $params */
function scalar(PDO $db, string $sql, array $params = []): int
{
    $statement = $db->prepare($sql);
    $statement->execute($params);

    return (int) $statement->fetchColumn();
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

/** @return array<int, array<string, mixed>> */
function technicianMetrics(PDO $db): array
{
    $statement = $db->query(
        "SELECT u.nama,
                COUNT(DISTINCT CASE WHEN pt.is_active = 1 AND p.is_cancelled = 0
                                    THEN pt.pengaduan_id END) AS total_tugas,
                COUNT(DISTINCT CASE WHEN p.status = 'selesai' AND p.is_cancelled = 0
                                    THEN pt.pengaduan_id END) AS selesai,
                COUNT(DISTINCT CASE WHEN p.status IN ('menunggu_teknisi','dalam_penanganan')
                                         AND p.is_cancelled = 0
                                    THEN pt.pengaduan_id END) AS aktif
         FROM users u
         LEFT JOIN penugasan_teknisi pt ON pt.teknisi_id = u.id
         LEFT JOIN pengaduan p ON p.id = pt.pengaduan_id
         WHERE u.role = 'teknisi'
         GROUP BY u.id, u.nama
         ORDER BY u.nama ASC"
    );

    return $statement->fetchAll();
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array{value: int, names: array<int, string>}
 */
function metricExtreme(array $rows, string $metric, bool $maximum): array
{
    $values = array_map(static fn (array $row): int => (int) ($row[$metric] ?? 0), $rows);
    if ($values === []) {
        return ['value' => 0, 'names' => []];
    }
    $target = $maximum ? max($values) : min($values);
    $names = [];
    foreach ($rows as $row) {
        if ((int) ($row[$metric] ?? 0) === $target) {
            $names[] = (string) ($row['nama'] ?? '');
        }
    }

    return ['value' => $target, 'names' => $names];
}

function hasSecretPattern(string $text): bool
{
    foreach ([
        '/password_hash/i',
        '/api_key_encrypted/i',
        '/AIza[0-9A-Za-z_-]{20,}/',
        '/sk-[A-Za-z0-9_-]{20,}/',
        '/Bearer\s+[A-Za-z0-9._-]{12,}/',
        '/private_key/i',
    ] as $pattern) {
        if (preg_match($pattern, $text) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<int, array<string, mixed>> $rows
 */
function restoreTable(PDO $db, string $table, array $rows): void
{
    $db->exec('DELETE FROM `' . $table . '`');
    if ($rows === []) {
        return;
    }

    $columns = array_keys($rows[0]);
    $columnSql = implode(', ', array_map(
        static fn (string $column): string => '`' . str_replace('`', '``', $column) . '`',
        $columns
    ));
    $placeholders = implode(', ', array_map(
        static fn (string $column): string => ':' . $column,
        $columns
    ));
    $statement = $db->prepare(
        'INSERT INTO `' . $table . '` (' . $columnSql . ') VALUES (' . $placeholders . ')'
    );
    foreach ($rows as $row) {
        $params = [];
        foreach ($columns as $column) {
            $params[$column] = $row[$column] ?? null;
        }
        $statement->execute($params);
    }
}

/** @return array{0: resource|null, 1: int, 2: bool} */
function startMock(string $router, string $host, int $port, string $logFile)
{
    $cmd = escapeshellarg(PHP_BINARY) . ' -S ' . $host . ':' . $port . ' ' . escapeshellarg($router);
    $devNull = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $descriptorSpec = [1 => ['file', $devNull, 'w'], 2 => ['file', $devNull, 'w']];
    $env = getenv();
    $env['MOCK_GEMINI_LOG'] = $logFile;

    $process = proc_open($cmd, $descriptorSpec, $pipes, __DIR__, $env);
    $pid = is_resource($process) ? (int) (proc_get_status($process)['pid'] ?? 0) : 0;
    $started = false;
    if (is_resource($process)) {
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen($host, $port, $errno, $errstr, 0.2);
            if (is_resource($socket)) {
                fclose($socket);
                $started = true;
                break;
            }
            usleep(100000);
        }
    }

    return [$process, $pid, $started];
}

function stopMock($process, int $pid): void
{
    if ($pid > 0 && PHP_OS_FAMILY === 'Windows') {
        @exec('taskkill /F /T /PID ' . $pid . ' 2>&1');
    }
    if (is_resource($process)) {
        @proc_terminate($process);
        @proc_close($process);
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

AiContext::clear((int) $admin['id']);
AiContext::clear((int) $cs['id']);
AiContext::clear((int) $teknisi['id']);
AiContext::clear((int) $pelanggan['id']);

$settingsSnapshot = $db->query('SELECT * FROM ai_settings')->fetchAll();
$keysSnapshot = $db->query('SELECT * FROM ai_provider_keys')->fetchAll();
$usageBeforeMax = scalar($db, 'SELECT COALESCE(MAX(id), 0) FROM ai_usage_log');
$auditBeforeMax = scalar($db, 'SELECT COALESCE(MAX(id), 0) FROM ai_data_access_log');
$tempUsernames = [];
$mockProcess = null;
$mockPid = 0;
$suffix = (new DateTimeImmutable('now'))->format('His') . (string) random_int(100, 999);
$mockPort = 18097;

echo "==== TAHAP 19 - LOCAL-FIRST AI, INTENT, DAN MONITORING (DB uji) ====\n";

try {
    // Tanpa key cloud: semua jawaban data harus tetap tersedia dari MySQL.
    // Pilih provider yang tidak punya key di .env agar pengujian tidak
    // bergantung pada kredensial pengembang.
    $db->exec('DELETE FROM ai_provider_keys');
    AiSetting::save([
        'provider' => 'deepseek',
        'hybrid_mode' => 1,
        'local_db_enabled' => 1,
        'prefer_local_db' => 1,
        'fallback_enabled' => 1,
        'ollama_enabled' => 0,
        'api_key_encrypted' => null,
        'model' => 'deepseek-chat',
        'is_enabled' => 1,
        'max_output_tokens' => 512,
        'timeout_seconds' => 5,
        'rate_limit' => 100,
        'rate_window' => 60,
        'daily_limit' => 1000,
        'max_message_length' => 2000,
        'system_instruction' => '',
        'updated_by' => (int) $admin['id'],
    ]);
    AiSetting::forget();
    AiProviderKey::forget();

    $expectedCustomers = scalar($db, 'SELECT COUNT(*) FROM pelanggan');
    $expectedActiveCustomers = scalar(
        $db,
        'SELECT COUNT(*) FROM pelanggan p INNER JOIN users u ON u.id = p.user_id WHERE u.is_active = 1'
    );
    $expectedInactiveCustomers = scalar(
        $db,
        'SELECT COUNT(*) FROM pelanggan p INNER JOIN users u ON u.id = p.user_id WHERE u.is_active = 0'
    );

    // 1. Jumlah pelanggan.
    $r = LocalDatabaseAssistant::answer($admin, 'Berapa jumlah pelanggan?');
    check(
        'T01 customers.count akurat dan bukan pencarian nama',
        ($r['intent'] ?? '') === 'customers.count'
            && preg_match('/Total pelanggan:\s*' . $expectedCustomers . '\b/', (string) ($r['reply'] ?? '')) === 1,
        json_encode($r)
    );

    // 2. Daftar pelanggan + konteks lama dibersihkan.
    $customers = $db->query(
        'SELECT u.nama FROM pelanggan p INNER JOIN users u ON u.id = p.user_id ORDER BY u.nama ASC'
    )->fetchAll();
    $r = LocalDatabaseAssistant::answer($admin, 'Pelanggan siapa aja?');
    $tableRows = is_array($r['tables'][0]['rows'] ?? null) ? $r['tables'][0]['rows'] : [];
    $listedNames = array_map(static fn (array $row): string => (string) ($row['nama'] ?? ''), $tableRows);
    $allNamesListed = true;
    foreach ($customers as $customer) {
        if (!in_array((string) ($customer['nama'] ?? ''), $listedNames, true)) {
            $allNamesListed = false;
            break;
        }
    }
    $follow = LocalDatabaseAssistant::answer($admin, 'Apa PPPoE-nya?');
    $contextCleared = $follow === null || ($follow['intent'] ?? '') !== 'customers.detail';
    check(
        'T02 "pelanggan siapa aja" -> customers.list dan konteks dibersihkan',
        ($r['intent'] ?? '') === 'customers.list'
            && $allNamesListed
            && count($listedNames) === count($customers)
            && !str_contains(strtolower((string) ($r['reply'] ?? '')), 'mana yang anda maksud')
            && $contextCleared,
        json_encode($r)
    );

    // 3. Pencarian nama tetap menjadi customers.search dan konteks follow-up aman.
    $customer = $customers[0] ?? ['nama' => ''];
    $customerName = (string) ($customer['nama'] ?? '');
    $customerPppoe = (string) $db->query(
        'SELECT p.username_pppoe FROM pelanggan p INNER JOIN users u ON u.id = p.user_id
         WHERE u.nama = ' . $db->quote($customerName) . ' LIMIT 1'
    )->fetchColumn();
    $r = LocalDatabaseAssistant::answer($admin, 'Siapa pelanggan ' . $customerName . '?');
    $searchRows = is_array($r['tables'][0]['rows'] ?? null) ? $r['tables'][0]['rows'] : [];
    $searchNames = array_map(static fn (array $row): string => (string) ($row['nama'] ?? ''), $searchRows);
    $follow = LocalDatabaseAssistant::answer($admin, 'Apa PPPoE-nya?');
    check(
        'T03 customers.search tetap akurat dan konteks pelanggan dipakai',
        ($r['intent'] ?? '') === 'customers.search'
            && in_array($customerName, $searchNames, true)
            && ($follow['intent'] ?? '') === 'customers.detail'
            && str_contains(json_encode($follow, JSON_UNESCAPED_UNICODE), $customerPppoe),
        json_encode(['search' => $r, 'follow' => $follow])
    );

    // 4. Jumlah pelanggan aktif/nonaktif.
    $active = LocalDatabaseAssistant::answer($admin, 'Berapa pelanggan aktif?');
    $inactive = LocalDatabaseAssistant::answer($admin, 'Berapa pelanggan nonaktif?');
    check(
        'T04 customers.count memisahkan aktif dan nonaktif',
        ($active['intent'] ?? '') === 'customers.count'
            && preg_match('/Jumlah pelanggan aktif:\s*' . $expectedActiveCustomers . '\b/', (string) ($active['reply'] ?? '')) === 1
            && ($inactive['intent'] ?? '') === 'customers.count'
            && preg_match('/Jumlah pelanggan nonaktif:\s*' . $expectedInactiveCustomers . '\b/', (string) ($inactive['reply'] ?? '')) === 1,
        json_encode(['active' => $active, 'inactive' => $inactive])
    );

    // 5. Jumlah akun teknisi.
    $expectedTechnicians = scalar($db, "SELECT COUNT(*) FROM users WHERE role = 'teknisi'");
    $r = LocalDatabaseAssistant::answer($admin, 'Berapa jumlah teknisi?');
    check(
        'T05 technicians.count akurat',
        ($r['intent'] ?? '') === 'technicians.count'
            && preg_match('/Jumlah teknisi:\s*' . $expectedTechnicians . '\b/', (string) ($r['reply'] ?? '')) === 1,
        json_encode($r)
    );

    // 6. Daftar teknisi hanya teknisi.
    $technicians = $db->query("SELECT nama FROM users WHERE role = 'teknisi' ORDER BY nama ASC")->fetchAll();
    $r = LocalDatabaseAssistant::answer($admin, 'Siapa saja teknisi?');
    $techRows = is_array($r['tables'][0]['rows'] ?? null) ? $r['tables'][0]['rows'] : [];
    $techNames = array_map(static fn (array $row): string => (string) ($row['nama'] ?? ''), $techRows);
    $allTechListed = true;
    foreach ($technicians as $technician) {
        if (!in_array((string) ($technician['nama'] ?? ''), $techNames, true)) {
            $allTechListed = false;
            break;
        }
    }
    check(
        'T06 technicians.list hanya memuat akun teknisi',
        ($r['intent'] ?? '') === 'technicians.list'
            && $allTechListed
            && count($techNames) === count($technicians),
        json_encode($r)
    );

    $metrics = technicianMetrics($db);
    $mostAssignments = metricExtreme($metrics, 'total_tugas', true);
    $leastAssignments = metricExtreme($metrics, 'total_tugas', false);

    // 7. Peringkat penugasan terbanyak.
    $r = LocalDatabaseAssistant::answer($admin, 'Siapa teknisi yang paling banyak tugas?');
    $mostOk = ($r['intent'] ?? '') === 'technicians.most_assignments'
        && str_contains((string) ($r['reply'] ?? ''), (string) $mostAssignments['value']);
    foreach ($mostAssignments['names'] as $name) {
        $mostOk = $mostOk && str_contains((string) ($r['reply'] ?? ''), $name);
    }
    check('T07 most_assignments sesuai MySQL', $mostOk, json_encode(['expected' => $mostAssignments, 'actual' => $r]));

    // 8. Peringkat penugasan paling sedikit.
    $r = LocalDatabaseAssistant::answer($admin, 'Siapa teknisi yang paling sedikit tugas?');
    $leastOk = ($r['intent'] ?? '') === 'technicians.least_assignments'
        && str_contains((string) ($r['reply'] ?? ''), (string) $leastAssignments['value']);
    foreach ($leastAssignments['names'] as $name) {
        $leastOk = $leastOk && str_contains((string) ($r['reply'] ?? ''), $name);
    }
    check('T08 least_assignments sesuai MySQL', $leastOk, json_encode(['expected' => $leastAssignments, 'actual' => $r]));

    // 9. Bahasa informal tidak boleh dianggap jumlah akun.
    $r = LocalDatabaseAssistant::answer($admin, 'Siapa teknisi yang kerjaannya banyak?');
    check(
        'T09 "kerjaannya banyak" -> peringkat, bukan jumlah akun',
        ($r['intent'] ?? '') === 'technicians.most_assignments'
            && str_contains((string) ($r['reply'] ?? ''), (string) $mostAssignments['value']),
        json_encode($r)
    );

    // 10. Tiket selesai.
    $mostCompleted = metricExtreme($metrics, 'selesai', true);
    $r = LocalDatabaseAssistant::answer($admin, 'Siapa paling banyak menyelesaikan tiket?');
    $completedOk = ($r['intent'] ?? '') === 'technicians.most_completed'
        && str_contains((string) ($r['reply'] ?? ''), (string) $mostCompleted['value']);
    foreach ($mostCompleted['names'] as $name) {
        $completedOk = $completedOk && str_contains((string) ($r['reply'] ?? ''), $name);
    }
    check('T10 most_completed memakai tiket selesai', $completedOk, json_encode(['expected' => $mostCompleted, 'actual' => $r]));

    // 11. Tugas aktif + tie.
    $mostActive = metricExtreme($metrics, 'aktif', true);
    $r = LocalDatabaseAssistant::answer($admin, 'Siapa teknisi dengan tugas aktif terbanyak?');
    $activeOk = ($r['intent'] ?? '') === 'technicians.most_active'
        && str_contains((string) ($r['reply'] ?? ''), (string) $mostActive['value']);
    foreach ($mostActive['names'] as $name) {
        $activeOk = $activeOk && str_contains((string) ($r['reply'] ?? ''), $name);
    }
    if (count($mostActive['names']) > 1) {
        $activeOk = $activeOk && str_contains((string) ($r['reply'] ?? ''), ' dan ');
    }
    check('T11 most_active membedakan tugas aktif dan tie', $activeOk, json_encode(['expected' => $mostActive, 'actual' => $r]));

    // Fixture sementara: dua teknisi tanpa penugasan untuk tie nol.
    $tempNames = ['Zz Teknisi Nol A ' . $suffix, 'Zz Teknisi Nol B ' . $suffix];
    $insert = $db->prepare(
        "INSERT INTO users (username, password_hash, nama, role, is_active)
         VALUES (:username, :password_hash, :nama, 'teknisi', 1)"
    );
    foreach ($tempNames as $index => $name) {
        $username = 't19.zero.' . strtolower(chr(97 + $index)) . '.' . $suffix;
        $insert->execute([
            'username' => $username,
            'password_hash' => password_hash('TempOnly!' . $suffix, PASSWORD_BCRYPT),
            'nama' => $name,
        ]);
        $tempUsernames[] = $username;
    }

    // 12. Teknisi belum pernah ditugaskan.
    $r = LocalDatabaseAssistant::answer($admin, 'Siapa teknisi yang belum pernah mendapatkan tugas?');
    $neverOk = ($r['intent'] ?? '') === 'technicians.never_assigned';
    foreach ($tempNames as $name) {
        $neverOk = $neverOk && str_contains((string) ($r['reply'] ?? ''), $name);
    }
    check('T12 never_assigned mendeteksi teknisi 0 penugasan', $neverOk, json_encode($r));

    // 13. Paling sedikit + tie 0.
    $r = LocalDatabaseAssistant::answer($admin, 'Siapa teknisi yang paling sedikit tugas?');
    $leastZeroOk = ($r['intent'] ?? '') === 'technicians.least_assignments'
        && str_contains((string) ($r['reply'] ?? ''), '0 penugasan');
    foreach ($tempNames as $name) {
        $leastZeroOk = $leastZeroOk && str_contains((string) ($r['reply'] ?? ''), $name);
    }
    check('T13 least_assignments menampilkan tie 0 penugasan', $leastZeroOk, json_encode($r));

    foreach ($tempUsernames as $username) {
        $delete = $db->prepare('DELETE FROM users WHERE username = :username');
        $delete->execute(['username' => $username]);
    }
    $tempUsernames = [];

    // 14. Regresi jumlah karyawan tidak terpengaruh perubahan routing.
    $expectedEmployees = scalar($db, "SELECT COUNT(*) FROM users WHERE role IN ('admin','cs','teknisi')");
    $r = LocalDatabaseAssistant::answer($admin, 'Berapa jumlah karyawan?');
    check(
        'T14 employees.count tetap akurat (pelanggan tidak dihitung)',
        ($r['intent'] ?? '') === 'employees.count'
            && str_contains((string) ($r['reply'] ?? ''), (string) $expectedEmployees),
        json_encode($r)
    );

    // 15. Tanpa key cloud, pertanyaan data tetap dijawab MySQL.
    $r = AiAssistantService::chat($admin, 'Berapa jumlah pelanggan?');
    check(
        'T15 jawaban lokal tetap tersedia tanpa key cloud',
        AiAssistantService::isConfigured() === false
            && ($r['ok'] ?? false) === true
            && ($r['provider'] ?? '') === 'local'
            && ($r['intent'] ?? '') === 'customers.count'
            && str_contains((string) ($r['reply'] ?? ''), (string) $expectedCustomers),
        json_encode($r)
    );

    // 16. Semua intent lokal dianggap local-first.
    $method = new ReflectionMethod(AiAssistantService::class, 'shouldUseLocalFirst');
    $method->setAccessible(true);
    $localFirstIntents = [
        'customers.count', 'customers.list', 'customers.search', 'customers.detail',
        'technicians.count', 'technicians.list', 'technicians.most_assignments',
        'technicians.least_assignments', 'technicians.most_completed',
        'technicians.most_active', 'technicians.never_assigned',
        'employees.count', 'employees.list', 'access.denied',
        'security.credentials_refused', 'security.sql_refused',
    ];
    $allLocalFirst = true;
    foreach ($localFirstIntents as $intent) {
        $allLocalFirst = $allLocalFirst && $method->invoke(null, $intent) === true;
    }
    $allLocalFirst = $allLocalFirst && $method->invoke(null, 'unsupported') === false;
    check('T16 semua intent database masuk jalur local-first', $allLocalFirst);

    // 17. Intent tak dikenal tidak mengarang angka.
    $r = AiAssistantService::chat($admin, 'Apa itu WiFiCare?');
    $unknownOk = ($r['ok'] ?? true) === false
        && ($r['code'] ?? '') === 'not_configured'
        && str_contains(strtolower((string) ($r['error'] ?? '')), 'belum dikonfigurasi')
        && preg_match('/\d/', (string) ($r['error'] ?? '')) !== 1;
    check('T17 intent tak dikenal jujur tanpa angka', $unknownOk, json_encode($r));

    // 18. Timeout cloud dengan mock lokal (tanpa API key nyata).
    $mockLog = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_t19_mock.log';
    @unlink($mockLog);
    [$mockProcess, $mockPid, $mockStarted] = startMock(
        __DIR__ . '/mock-timeout.php',
        '127.0.0.1',
        $mockPort,
        $mockLog
    );

    if (!$mockStarted || !Crypto::available() || !Crypto::hasMasterKey()) {
        blocked(
            'T18 timeout cloud terpisah',
            'Mock provider atau enkripsi tidak tersedia; tidak ada provider cloud nyata yang dipakai.'
        );
    } else {
        $encrypted = Crypto::encrypt('t19-fake-cloud-key-1234567890');
        AiSetting::save([
            'provider' => 'gemini',
            'hybrid_mode' => 1,
            'local_db_enabled' => 1,
            'prefer_local_db' => 1,
            'fallback_enabled' => 1,
            'ollama_enabled' => 0,
            'api_key_encrypted' => $encrypted,
            'model' => 'mock-timeout',
            'is_enabled' => 1,
            'max_output_tokens' => 128,
            'timeout_seconds' => 5,
            'rate_limit' => 100,
            'rate_window' => 60,
            'daily_limit' => 1000,
            'max_message_length' => 2000,
            'system_instruction' => '',
            'updated_by' => (int) $admin['id'],
        ]);
        AiProviderKey::save(
            'gemini',
            $encrypted,
            'mock-timeout',
            'http://127.0.0.1:' . $mockPort . '/v1beta',
            (int) $admin['id']
        );
        AiSetting::forget();
        AiProviderKey::forget();

        $startedAt = microtime(true);
        $r = AiAssistantService::chat($admin, 'Apa itu WiFiCare?');
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        check(
            'T18 timeout cloud terpisah dan tidak mengarang data',
            ($r['ok'] ?? true) === false
                && ($r['code'] ?? '') === 'timeout'
                && $durationMs >= 2500
                && $durationMs <= 20000
                && !str_contains((string) ($r['error'] ?? ''), 'MOCK_TIMEOUT_SHOULD_NOT_ARRIVE'),
            json_encode(['result' => $r, 'duration_ms' => $durationMs])
        );

        // 19. Dengan cloud yang sama, pertanyaan database tetap dijawab lokal
        // tanpa menunggu provider.
        $startedAt = microtime(true);
        $r = AiAssistantService::chat($admin, 'Berapa jumlah pelanggan?');
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        check(
            'T19 cloud terkonfigurasi tidak dipanggil untuk data lokal',
            ($r['ok'] ?? false) === true
                && ($r['provider'] ?? '') === 'local'
                && ($r['intent'] ?? '') === 'customers.count'
                && $durationMs < 2000,
            json_encode(['result' => $r, 'duration_ms' => $durationMs])
        );
    }
    stopMock($mockProcess, $mockPid);
    $mockProcess = null;
    $mockPid = 0;

    // 20. Kegagalan MySQL: pesan khusus dan tidak ada angka karangan.
    $sqlite = null;
    try {
        $sqlite = new PDO('sqlite::memory:');
    } catch (Throwable $exception) {
        $sqlite = null;
    }
    if (!$sqlite instanceof PDO) {
        blocked('T20 kegagalan MySQL jujur', 'Driver PDO SQLite tidak tersedia untuk simulasi.');
    } else {
        $pdoProperty = new ReflectionProperty(Database::class, 'pdo');
        $readProperty = new ReflectionProperty(Database::class, 'readPdo');
        $pdoProperty->setAccessible(true);
        $readProperty->setAccessible(true);
        $originalPdo = $pdoProperty->getValue();
        $originalRead = $readProperty->getValue();
        $pdoProperty->setValue(null, $sqlite);
        $readProperty->setValue(null, $sqlite);

        try {
            $r = LocalDatabaseAssistant::answer($admin, 'Berapa jumlah pelanggan?', false);
            $dbFailOk = ($r['ok'] ?? true) === false
                && ($r['code'] ?? '') === 'database_unavailable'
                && str_contains((string) ($r['error'] ?? ''), 'Data belum dapat diakses saat ini.')
                && preg_match('/\d/', (string) ($r['error'] ?? '')) !== 1;
        } finally {
            $pdoProperty->setValue(null, $originalPdo);
            $readProperty->setValue(null, $originalRead);
        }
        $afterRestore = LocalDatabaseAssistant::answer($admin, 'Berapa jumlah pelanggan?', false);
        check(
            'T20 kegagalan MySQL -> pesan khusus, pulih setelah DB kembali',
            $dbFailOk && ($afterRestore['intent'] ?? '') === 'customers.count',
            json_encode(['fail' => $r ?? null, 'restored' => $afterRestore ?? null])
        );
    }

    // 21. Permintaan kredensial ditolak.
    $r = LocalDatabaseAssistant::answer($admin, 'Tampilkan API key Gemini yang aktif', false);
    check(
        'T21 permintaan API key/kredensial ditolak tanpa kebocoran',
        ($r['intent'] ?? '') === 'security.credentials_refused'
            && str_contains(strtolower((string) ($r['reply'] ?? '')), 'tidak dapat')
            && !hasSecretPattern(json_encode($r, JSON_UNESCAPED_UNICODE)),
        json_encode($r)
    );

    // 22. SQL bebas ditolak dan data tidak berubah.
    $usersBefore = scalar($db, 'SELECT COUNT(*) FROM users');
    $r = LocalDatabaseAssistant::answer($admin, 'Jalankan DELETE FROM users', false);
    $usersAfter = scalar($db, 'SELECT COUNT(*) FROM users');
    check(
        'T22 SQL bebas ditolak dan tidak mengubah data',
        ($r['intent'] ?? '') === 'security.sql_refused'
            && $usersBefore === $usersAfter
            && !str_contains(strtolower((string) ($r['reply'] ?? '')), 'deleted'),
        json_encode($r)
    );

    // 23. CS tidak boleh melihat peringkat seluruh teknisi.
    $r = LocalDatabaseAssistant::answer($cs, 'Siapa teknisi paling banyak tugas?', false);
    check(
        'T23 RBAC peringkat teknisi: CS ditolak',
        ($r['intent'] ?? '') === 'access.denied'
            && empty($r['tables'])
            && str_contains(strtolower((string) ($r['reply'] ?? '')), 'administrator'),
        json_encode($r)
    );

    // 24. Teknisi tidak boleh membaca daftar pelanggan.
    $r = LocalDatabaseAssistant::answer($teknisi, 'Pelanggan siapa aja?', false);
    check(
        'T24 RBAC daftar pelanggan: teknisi ditolak',
        ($r['intent'] ?? '') === 'access.denied'
            && empty($r['tables'])
            && str_contains(strtolower((string) ($r['reply'] ?? '')), 'administrator'),
        json_encode($r)
    );

    // 25. Monitoring sumber dan audit metadata.
    AiUsage::record($admin, 'local', 'database-assistant', 'chat', true, '', 20, ['total' => 0]);
    AiUsage::record($admin, 'gemini', 'mock-timeout', 'chat', false, 'timeout', 20, ['total' => 0]);
    $usage = AiUsage::stats(1000);
    $audit = AiDataAccessLog::stats(30);
    $view = AiSettingsService::viewData();
    $recent = AiDataAccessLog::recent(10);
    $recentJson = json_encode($recent, JSON_UNESCAPED_UNICODE);
    $metadataOnly = true;
    foreach ($recent as $row) {
        foreach (['message', 'keyword', 'whatsapp', 'nomor_whatsapp', 'alamat', 'api_key'] as $forbidden) {
            if (array_key_exists($forbidden, $row)) {
                $metadataOnly = false;
            }
        }
    }
    check(
        'T25 monitoring sumber + audit metadata tanpa data sensitif',
        ($usage['local_month'] ?? 0) >= 1
            && ($usage['cloud_month'] ?? 0) >= 1
            && ($usage['timeout_month'] ?? 0) >= 1
            && ($audit['local'] ?? 0) >= 1
            && ($audit['unknown_intent'] ?? 0) >= 1
            && ($view['source_usage']['local'] ?? 0) >= 1
            && $metadataOnly
            && !hasSecretPattern((string) $recentJson),
        json_encode([
            'usage' => $usage,
            'audit' => $audit,
            'source_usage' => $view['source_usage'] ?? null,
            'recent' => $recent,
        ])
    );
} finally {
    // Bersihkan fixture dan pulihkan konfigurasi/log uji.
    foreach ($tempUsernames as $username) {
        try {
            $delete = $db->prepare('DELETE FROM users WHERE username = :username');
            $delete->execute(['username' => $username]);
        } catch (Throwable $exception) {
            // Jangan menutupi hasil tes utama.
        }
    }

    stopMock($mockProcess, $mockPid);

    try {
        restoreTable($db, 'ai_settings', $settingsSnapshot);
        restoreTable($db, 'ai_provider_keys', $keysSnapshot);
        AiSetting::forget();
        AiProviderKey::forget();
    } catch (Throwable $exception) {
        echo 'PERINGATAN pemulihan konfigurasi AI: ' . $exception->getMessage() . "\n";
    }

    try {
        $db->prepare('DELETE FROM ai_usage_log WHERE id > :id')->execute(['id' => $usageBeforeMax]);
        $db->prepare('DELETE FROM ai_data_access_log WHERE id > :id')->execute(['id' => $auditBeforeMax]);
    } catch (Throwable $exception) {
        echo 'PERINGATAN pembersihan log uji: ' . $exception->getMessage() . "\n";
    }

    AiContext::clear((int) $admin['id']);
    AiContext::clear((int) $cs['id']);
    AiContext::clear((int) $teknisi['id']);
    AiContext::clear((int) $pelanggan['id']);
}

echo "\n============================================================\n";
echo 'TOTAL: ' . ($PASS + $FAIL + $BLOCKED) . ' | PASS: ' . $PASS . ' | FAIL: ' . $FAIL
    . ' | BLOCKED: ' . $BLOCKED . "\n";
if ($FAILED !== []) {
    echo "GAGAL:\n- " . implode("\n- ", $FAILED) . "\n";
    exit(1);
}
if ($BLOCKED > 0) {
    echo "SELESAI DENGAN BAGIAN YANG BLOCKED.\n";
    exit(2);
}
echo "SEMUA TES TAHAP 19 LULUS.\n";
exit(0);
