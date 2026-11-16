<?php
/**
 * WiFiCare CDN - Tahap 20: klasifikasi provider, kuota, dan monitoring Admin.
 *
 * Provider mock lokal dipakai agar tidak memakai kredensial/kuota nyata.
 * Seluruh konfigurasi AI dan baris log/notifikasi yang dibuat selama tes
 * dipulihkan/dihapus pada akhir pengujian.
 *
 * Jalankan:
 *   php tests/tahap20/provider-monitoring.php
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
    $env['MOCK_AI_LOG'] = $logFile;

    $process = proc_open($cmd, $descriptorSpec, $pipes, __DIR__ . '/fixtures', $env);
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
if ($admin === null) {
    echo "Tidak ada akun admin untuk pengujian.\n";
    exit(1);
}

if (!Crypto::isReady()) {
    blocked('enkripsi kunci uji tersedia', 'Crypto belum siap');
    echo "\nHASIL TAHAP 20 PROVIDER: {$PASS} PASS, {$FAIL} FAIL, {$BLOCKED} BLOCKED\n";
    exit($BLOCKED > 0 ? 1 : 0);
}

$settingsSnapshot = $db->query('SELECT * FROM ai_settings')->fetchAll();
$keysSnapshot = $db->query('SELECT * FROM ai_provider_keys')->fetchAll();
$maxEvent = scalar($db, 'SELECT COALESCE(MAX(id), 0) FROM ai_provider_events');
$maxNotif = scalar($db, 'SELECT COALESCE(MAX(id), 0) FROM notifikasi');
$maxUsage = scalar($db, 'SELECT COALESCE(MAX(id), 0) FROM ai_usage_log');

$suffix = bin2hex(random_bytes(4));
$secret = 'SECRET-MOCK-KEY-' . $suffix;
$logFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR . 'wificare-tahap20-' . getmypid() . '.log';
$mockProcess = null;
$mockPid = 0;
$started = false;
$host = '127.0.0.1';
$port = 18110;
$startAttempts = 0;

while (!$started && $startAttempts < 12) {
    $probe = @fsockopen($host, $port, $errno, $errstr, 0.2);
    if (is_resource($probe)) {
        fclose($probe);
        $port++;
        $startAttempts++;
        continue;
    }

    [$mockProcess, $mockPid, $started] = startMock(
        __DIR__ . '/fixtures/mock-ai-router.php',
        $host,
        $port,
        $logFile
    );
    if (!$started) {
        stopMock($mockProcess, $mockPid);
        $mockProcess = null;
        $mockPid = 0;
        $port++;
        $startAttempts++;
    }
}

if (!$started) {
    blocked('mock provider lokal dapat dijalankan');
    echo "\nHASIL TAHAP 20 PROVIDER: {$PASS} PASS, {$FAIL} FAIL, {$BLOCKED} BLOCKED\n";
    exit(1);
}

$base = 'http://' . $host . ':' . $port . '/v1';
$baseGemini = 'http://' . $host . ':' . $port . '/v1beta';

$setProvider = static function (string $provider, string $model, int $timeout = 5) use ($db, $admin, $base, $baseGemini, $secret): void {
    $db->exec('DELETE FROM ai_settings');
    $db->exec('DELETE FROM ai_provider_keys');
    AiSetting::forget();
    AiProviderKey::forget();

    AiSetting::save([
        'provider' => $provider,
        'hybrid_mode' => 1,
        'local_db_enabled' => 0,
        'prefer_local_db' => 0,
        'fallback_enabled' => 0,
        'ollama_enabled' => 0,
        'model' => $model,
        'is_enabled' => 1,
        'max_output_tokens' => 256,
        'timeout_seconds' => $timeout,
        'rate_limit' => 1000,
        'rate_window' => 60,
        'daily_limit' => 10000,
        'max_message_length' => 2000,
        'system_instruction' => '',
        'updated_by' => (int) $admin['id'],
    ]);

    AiProviderKey::save(
        $provider,
        Crypto::encrypt($secret),
        $model,
        $provider === 'gemini' ? $baseGemini : $base,
        (int) $admin['id']
    );
};

$newNotifications = static function (string $model, string $title) use ($db, $maxNotif): int {
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM notifikasi
         WHERE id > :max_id AND judul = :judul AND pesan LIKE :model'
    );
    $statement->execute([
        'max_id' => $maxNotif,
        'judul' => $title,
        'model' => '%' . $model . '%',
    ]);

    return (int) $statement->fetchColumn();
};

$chat = static function (array $user, string $message): array {
    return AiAssistantService::chat($user, $message);
};

try {
    // 1. Gemini 429 per menit harus rate limit, bukan kuota habis.
    $rateQuota = AiProviderRegistry::get('gemini')->testConnection(
        'mock-key',
        'ratequota_' . $suffix,
        $baseGemini,
        5
    );
    check(
        'Gemini 429 per-menit diklasifikasi rate limit',
        ($rateQuota['error_type'] ?? '') === 'rate_limit'
            && (int) ($rateQuota['retry_after'] ?? 0) === 20,
        json_encode($rateQuota, JSON_UNESCAPED_UNICODE)
    );

    // 2. Kuota harian/credit benar-benar habis.
    $quota = AiProviderRegistry::get('openai')->testConnection(
        'mock-key',
        'quota_' . $suffix,
        $base,
        5
    );
    check(
        'kuota habis diklasifikasi quota_exceeded',
        ($quota['error_type'] ?? '') === 'quota_exceeded',
        json_encode($quota, JSON_UNESCAPED_UNICODE)
    );

    // 3. Rate limit sementara dengan Retry-After.
    $rate = AiProviderRegistry::get('openai')->testConnection(
        'mock-key',
        'rate_' . $suffix,
        $base,
        5
    );
    check(
        'rate limit sementara membawa retry-after',
        ($rate['error_type'] ?? '') === 'rate_limit'
            && (int) ($rate['retry_after'] ?? 0) === 30,
        json_encode($rate, JSON_UNESCAPED_UNICODE)
    );

    // 4-8. Error selain kuota/rate.
    foreach ([
        'auth' => 'authentication_error',
        'permission' => 'permission_error',
        'server' => 'server_error',
        'model' => 'model_unavailable',
    ] as $mode => $expected) {
        $result = AiProviderRegistry::get('openai')->testConnection(
            'mock-key',
            $mode . '_' . $suffix,
            $base,
            5
        );
        check(
            "klasifikasi provider {$mode}",
            ($result['error_type'] ?? '') === $expected,
            json_encode($result, JSON_UNESCAPED_UNICODE)
        );
    }

    $timeout = AiProviderRegistry::get('openai')->testConnection(
        'mock-key',
        'timeout_' . $suffix,
        $base,
        5
    );
    check(
        'timeout diklasifikasi terpisah',
        ($timeout['error_type'] ?? '') === 'timeout',
        json_encode($timeout, JSON_UNESCAPED_UNICODE)
    );

    $network = AiProviderRegistry::get('openai')->testConnection(
        'mock-key',
        'success_' . $suffix,
        'http://127.0.0.1:1/v1',
        5
    );
    check(
        'masalah jaringan diklasifikasi terpisah',
        ($network['error_type'] ?? '') === 'network_error',
        json_encode($network, JSON_UNESCAPED_UNICODE)
    );

    // 9. testConnection Admin memakai classifier yang sama.
    $serviceTest = AiSettingsService::testProvider(
        'openai',
        $secret,
        'quota_' . $suffix . '_service',
        $base
    );
    check(
        'tes koneksi Admin memakai classifier dan menyimpan event',
        ($serviceTest['error_type'] ?? '') === 'quota_exceeded'
            && scalar($db, 'SELECT COUNT(*) FROM ai_provider_events WHERE id > :id AND model = :model', [
                'id' => $maxEvent,
                'model' => 'quota_' . $suffix . '_service',
            ]) === 1,
        json_encode($serviceTest, JSON_UNESCAPED_UNICODE)
    );

    // 10-11. Chat kuota mencatat event dan notifikasi Admin sekali.
    $quotaModel = 'quota_chat_' . $suffix;
    $setProvider('deepseek', $quotaModel);
    $chatQuota = $chat($admin, 'Apa itu WiFiCare?');
    check(
        'chat kuota mengembalikan error aman',
        ($chatQuota['error_type'] ?? '') === 'quota_exceeded'
            && !empty($chatQuota['error']),
        json_encode($chatQuota, JSON_UNESCAPED_UNICODE)
    );
    check(
        'event kuota tersimpan dengan provider, model, HTTP, dan waktu',
        scalar(
            $db,
            'SELECT COUNT(*) FROM ai_provider_events
             WHERE id > :id AND provider = :provider AND model = :model
               AND event_type = :event_type AND http_status = 429
               AND notification_sent = 1',
            [
                'id' => $maxEvent,
                'provider' => 'deepseek',
                'model' => $quotaModel,
                'event_type' => 'quota_exceeded',
            ]
        ) === 1,
        json_encode($db->query('SELECT * FROM ai_provider_events ORDER BY id DESC LIMIT 3')->fetchAll(), JSON_UNESCAPED_UNICODE)
    );
    check(
        'notifikasi Admin kuota dibuat sekali',
        $newNotifications($quotaModel, 'Kuota atau Kredit AI Habis') === 1,
        'count=' . $newNotifications($quotaModel, 'Kuota atau Kredit AI Habis')
    );

    $chatQuotaAgain = $chat($admin, 'Apa itu WiFiCare?');
    check(
        'kuota berulang tidak menduplikasi notifikasi',
        $newNotifications($quotaModel, 'Kuota atau Kredit AI Habis') === 1
            && ($chatQuotaAgain['error_type'] ?? '') === 'quota_exceeded',
        'count=' . $newNotifications($quotaModel, 'Kuota atau Kredit AI Habis')
    );

    // 12. Rate limit sementara berbeda dari kuota dan menampilkan retry.
    $rateModel = 'rate_chat_' . $suffix;
    $setProvider('openai', $rateModel);
    $chatRate = $chat($admin, 'Apa itu WiFiCare?');
    $rateMessage = '';
    $statement = $db->prepare('SELECT pesan FROM notifikasi WHERE id > :id AND pesan LIKE :model ORDER BY id DESC LIMIT 1');
    $statement->execute(['id' => $maxNotif, 'model' => '%' . $rateModel . '%']);
    $rateMessage = (string) $statement->fetchColumn();
    check(
        'chat rate limit dibedakan dari kuota',
        ($chatRate['error_type'] ?? '') === 'rate_limit'
            && (int) ($chatRate['retry_after'] ?? 0) === 30,
        json_encode($chatRate, JSON_UNESCAPED_UNICODE)
    );
    check(
        'notifikasi rate menampilkan retry dari provider',
        $newNotifications($rateModel, 'Batas Permintaan AI Tercapai') === 1
            && str_contains($rateMessage, '30 detik'),
        $rateMessage
    );

    // 13. Autentikasi/permission tidak dianggap kuota.
    $authModel = 'auth_chat_' . $suffix;
    $setProvider('openai', $authModel);
    $chatAuth = $chat($admin, 'Apa itu WiFiCare?');
    check(
        'autentikasi tidak memicu notifikasi kuota',
        ($chatAuth['error_type'] ?? '') === 'authentication_error'
            && $newNotifications($authModel, 'Kuota atau Kredit AI Habis') === 0,
        json_encode($chatAuth, JSON_UNESCAPED_UNICODE)
    );

    // 14. Database lokal tetap berjalan saat provider cloud kehabisan kuota.
    $localModel = 'quota_local_' . $suffix;
    $setProvider('deepseek', $localModel);
    AiSetting::save(array_merge(
        AiSetting::get() ?? [],
        [
            'local_db_enabled' => 1,
            'prefer_local_db' => 1,
            'fallback_enabled' => 1,
        ]
    ));
    AiSetting::forget();
    $customerName = (string) $db->query(
        'SELECT u.nama FROM pelanggan p INNER JOIN users u ON u.id = p.user_id
         ORDER BY p.id ASC LIMIT 1'
    )->fetchColumn();
    $linesBefore = is_file($logFile) ? count(file($logFile, FILE_IGNORE_NEW_LINES)) : 0;
    $localChat = $chat($admin, 'Siapa pelanggan yang terbaru pengaduan?');
    $localCustomerChat = $chat($admin, 'apakah ada pelanggan atas nama ' . $customerName);
    $linesAfter = is_file($logFile) ? count(file($logFile, FILE_IGNORE_NEW_LINES)) : 0;
    check(
        'database lokal menjawab saat cloud kuota habis',
        !empty($localChat['ok'])
            && ($localChat['intent'] ?? '') === 'complaints.latest_customer',
        json_encode($localChat, JSON_UNESCAPED_UNICODE)
    );
    check(
        'pencarian nama pelanggan tetap lokal saat cloud gagal',
        !empty($localCustomerChat['ok'])
            && ($localCustomerChat['intent'] ?? '') === 'customers.search'
            && str_contains((string) ($localCustomerChat['reply'] ?? ''), $customerName),
        json_encode($localCustomerChat, JSON_UNESCAPED_UNICODE)
    );
    check(
        'intent lokal tidak memanggil provider cloud',
        $linesAfter === $linesBefore,
        "before={$linesBefore} after={$linesAfter}"
    );

    // 15. Monitoring overview aman dan berisi data event.
    $overview = AiProviderMonitor::overview(30);
    check(
        'overview monitoring tersedia dan memuat event',
        !empty($overview['available'])
            && is_array($overview['events'])
            && $overview['events'] !== []
            && is_array($overview['statuses'])
            && count($overview['statuses']) >= 3,
        json_encode($overview, JSON_UNESCAPED_UNICODE)
    );

    // 16. Usage mencatat sumber dan token per provider/model.
    AiUsage::record(
        $admin,
        'openai',
        'gpt-mock-' . $suffix,
        'chat',
        true,
        '',
        25,
        ['prompt' => 5, 'completion' => 7, 'total' => 12],
        'cloud_ai'
    );
    AiUsage::record(
        $admin,
        'local',
        'database-assistant',
        'database',
        true,
        '',
        5,
        [],
        'local_database'
    );
    $stats = AiUsage::stats(100);
    $providerRows = is_array($stats['providers_month'] ?? null) ? $stats['providers_month'] : [];
    $cloudRow = null;
    $localRow = null;
    foreach ($providerRows as $row) {
        if (($row['model'] ?? '') === 'gpt-mock-' . $suffix) {
            $cloudRow = $row;
        }
        if (($row['provider'] ?? '') === 'local' && ($row['model'] ?? '') === 'database-assistant') {
            $localRow = $row;
        }
    }
    check(
        'usage mencatat source dan token provider cloud',
        is_array($cloudRow)
            && ($cloudRow['source'] ?? '') === 'cloud_ai'
            && (int) ($cloudRow['total_tokens'] ?? 0) === 12
            && (int) ($cloudRow['token_reports'] ?? 0) === 1,
        json_encode($cloudRow, JSON_UNESCAPED_UNICODE)
    );
    check(
        'usage membedakan sumber database lokal',
        is_array($localRow) && ($localRow['source'] ?? '') === 'local_database',
        json_encode($localRow, JSON_UNESCAPED_UNICODE)
    );

    // 17. Tidak ada rahasia di event/notifikasi.
    $notificationText = (string) $db->query(
        'SELECT COALESCE(GROUP_CONCAT(pesan SEPARATOR " "), "") FROM notifikasi WHERE id > ' . $maxNotif
    )->fetchColumn();
    $eventText = (string) $db->query(
        'SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(" ", provider, model, event_type, error_code, provider_code) SEPARATOR " "), "") FROM ai_provider_events WHERE id > ' . $maxEvent
    )->fetchColumn();
    check(
        'API key dan header tidak bocor ke event/notifikasi',
        !str_contains($notificationText, $secret)
            && !str_contains($notificationText, 'Bearer')
            && !str_contains($eventText, $secret)
            && !str_contains($eventText, 'Bearer'),
        'notifikasi/event memuat rahasia'
    );
} finally {
    if (is_file($logFile)) {
        @unlink($logFile);
    }
    try {
        $db->exec('DELETE FROM ai_settings');
        $db->exec('DELETE FROM ai_provider_keys');
        restoreTable($db, 'ai_settings', is_array($settingsSnapshot) ? $settingsSnapshot : []);
        restoreTable($db, 'ai_provider_keys', is_array($keysSnapshot) ? $keysSnapshot : []);
        AiSetting::forget();
        AiProviderKey::forget();
        $db->exec('DELETE FROM notifikasi WHERE id > ' . $maxNotif);
        $db->exec('DELETE FROM ai_provider_events WHERE id > ' . $maxEvent);
        $db->exec('DELETE FROM ai_usage_log WHERE id > ' . $maxUsage);
    } catch (Throwable $exception) {
        app_log('Gagal membersihkan fixture Tahap 20: ' . $exception->getMessage());
    }
    stopMock($mockProcess, $mockPid);
}

echo "\nHASIL TAHAP 20 PROVIDER: {$PASS} PASS, {$FAIL} FAIL, {$BLOCKED} BLOCKED\n";
if ($FAILED !== []) {
    echo "GAGAL:\n- " . implode("\n- ", $FAILED) . "\n";
    exit(1);
}
