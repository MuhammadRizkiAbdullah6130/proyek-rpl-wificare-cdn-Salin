<?php
/**
 * WiFiCare CDN — Tahap 10: uji offline WiFiCare AI (read-only + tool calling).
 *
 * Menguji AiAssistantService/AiToolService/AiReadService memakai mock Gemini
 * lokal (tests/tahap10/mock-gemini.php) — tanpa jaringan dan tanpa kunci nyata.
 *
 * Membuktikan:
 * - aturan produk: permintaan coding ditolak dengan kalimat tetap (tanpa panggilan API);
 * - function calling Gemini berjalan (functionCall → tool read-only → jawaban);
 * - argumen kosong dikodekan benar ({}, bukan []) sehingga tidak error;
 * - tool di luar hak peran ditolak (RBAC);
 * - allowlist tidak memuat tool tulis (INSERT/UPDATE/DELETE/DROP/ALTER);
 * - deklarasi tool disaring sesuai peran;
 * - pembacaan data ter-scope (pelanggan/teknisi/CS/Admin).
 *
 * Jalankan: php tests/tahap10/ai-read-tools.php
 */

declare(strict_types=1);

define('MOCK_HOST', '127.0.0.1');
define('MOCK_PORT', 8098);
define('MOCK_BASE', 'http://' . MOCK_HOST . ':' . MOCK_PORT . '/v1beta');
define('TMP', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_t10');
if (!is_dir(TMP)) {
    @mkdir(TMP, 0775, true);
}
define('MOCK_LOG', TMP . DIRECTORY_SEPARATOR . 'mock_tools_hits.log');

@unlink(MOCK_LOG);

require_once __DIR__ . '/../../app/bootstrap.php';

// Tahap 11: `AiSettingsService` memprioritaskan baris `ai_settings` di database.
// Uji offline ini mengarahkan provider lewat konfigurasi runtime, jadi baris
// tersebut dinetralkan sementara (non-destruktif) lalu dipulihkan.
$__aiBackup = null;
try {
    AiSetting::forget();
    $__pdo = Database::pdo();
    $__row = $__pdo->query('SELECT id, provider, model, api_key_encrypted, is_enabled FROM ai_settings ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (is_array($__row) && $__row !== []) {
        $__aiBackup = $__row;
        $__pdo->exec("UPDATE ai_settings SET provider = '', model = NULL, api_key_encrypted = NULL, is_enabled = 1 WHERE id = " . (int) $__row['id']);
        AiSetting::forget();
    }
} catch (Throwable $__e) {
    $__aiBackup = null;
}
register_shutdown_function(static function () use ($__aiBackup): void {
    if (!is_array($__aiBackup) || $__aiBackup === []) {
        return;
    }
    try {
        Database::pdo()->prepare(
            'UPDATE ai_settings SET provider = :provider, model = :model, api_key_encrypted = :api_key_encrypted, is_enabled = :is_enabled WHERE id = :id'
        )->execute([
            'provider' => $__aiBackup['provider'],
            'model' => $__aiBackup['model'],
            'api_key_encrypted' => $__aiBackup['api_key_encrypted'],
            'is_enabled' => $__aiBackup['is_enabled'],
            'id' => $__aiBackup['id'],
        ]);
        AiSetting::forget();
    } catch (Throwable $e) {
        // Pemulihan bersifat best-effort.
    }
});

$PASS = 0;
$FAIL = 0;
$FAILED = [];

function check(string $name, bool $ok, string $detail = ''): void
{
    global $PASS, $FAIL, $FAILED;
    if ($ok) {
        $PASS++;
        echo "PASS  {$name}\n";
    } else {
        $FAIL++;
        $FAILED[] = $name . ($detail !== '' ? " :: {$detail}" : '');
        echo "FAIL  {$name}" . ($detail !== '' ? " :: {$detail}" : '') . "\n";
    }
}

/**
 * Arahkan AiAssistantService ke mock & set model/model-cadangan.
 */
function useModel(string $model, string $fallback = ''): void
{
    $GLOBALS['app_config']['ai']['provider'] = 'gemini';
    $GLOBALS['app_config']['ai']['gemini_api_key'] = 'mock-key-should-never-leak';
    $GLOBALS['app_config']['ai']['gemini_base_url'] = MOCK_BASE;
    $GLOBALS['app_config']['ai']['gemini_model'] = $model;
    $GLOBALS['app_config']['ai']['gemini_model_fallback'] = $fallback;
    $GLOBALS['app_config']['ai']['max_retries'] = 0;
}

// --- Server mock -------------------------------------------------------------
echo "==== TAHAP 10 — WIFICARE AI (READ-ONLY + TOOL CALLING) ====\n";

$router = __DIR__ . '/mock-gemini.php';
$cmd = escapeshellarg(PHP_BINARY) . ' -S ' . MOCK_HOST . ':' . MOCK_PORT . ' ' . escapeshellarg($router);
$devNull = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$descriptorSpec = [1 => ['file', $devNull, 'w'], 2 => ['file', $devNull, 'w']];

$busy = @fsockopen(MOCK_HOST, MOCK_PORT, $errno, $errstr, 0.3);
if (is_resource($busy)) {
    fclose($busy);
    echo 'GAGAL: port ' . MOCK_PORT . " sudah dipakai. Hentikan proses itu lalu jalankan ulang.\n";
    exit(1);
}

$env = getenv();
$env['MOCK_GEMINI_LOG'] = MOCK_LOG;
$proc = proc_open($cmd, $descriptorSpec, $pipes, __DIR__, $env);
$childPid = is_resource($proc) ? (int) (proc_get_status($proc)['pid'] ?? 0) : 0;

$started = false;
if (is_resource($proc)) {
    for ($i = 0; $i < 50; $i++) {
        $sock = @fsockopen(MOCK_HOST, MOCK_PORT, $errno, $errstr, 0.2);
        if (is_resource($sock)) {
            fclose($sock);
            $started = true;
            break;
        }
        usleep(100000);
    }
}

function stopMock($proc, int $pid): void
{
    if ($pid > 0 && PHP_OS_FAMILY === 'Windows') {
        @exec('taskkill /F /T /PID ' . $pid . ' 2>&1');
    }
    if (is_resource($proc)) {
        @proc_terminate($proc);
        @proc_close($proc);
    }
}

check('server mock berjalan', $started, 'port ' . MOCK_PORT);
if (!$started) {
    stopMock($proc, $childPid);
    exit(1);
}

try {
    $pelanggan = ['id' => 4, 'role' => 'pelanggan', 'name' => 'Rina Marlina'];
    $teknisi = ['id' => 3, 'role' => 'teknisi', 'name' => 'Andi Pratama'];
    $cs = ['id' => 2, 'role' => 'cs', 'name' => 'Dewi Lestari'];
    $admin = ['id' => 1, 'role' => 'admin', 'name' => 'Budi Santoso'];

    // 1. Aturan produk: tolak coding (tanpa memanggil provider).
    $r = AiAssistantService::chat($pelanggan, 'Tolong buatkan saya script Python untuk mengambil data dari MySQL');
    check('tolak permintaan coding (Python)', !empty($r['ok']) && ($r['reply'] ?? '') === AiKnowledge::codingRefusal(), 'reply=' . ($r['reply'] ?? ''));

    $r = AiAssistantService::chat($admin, 'Buat query SQL SELECT * FROM users untuk saya');
    check('tolak permintaan coding (SQL)', !empty($r['ok']) && ($r['reply'] ?? '') === AiKnowledge::codingRefusal());

    check('deteksi coding: buatkan script = true', AiAssistantService::looksLikeCodingRequest('buatkan script untuk saya'));
    check('deteksi coding: pertanyaan biasa = false', !AiAssistantService::looksLikeCodingRequest('Berapa pengaduan saya yang masih aktif?'));

    // 2. Allowlist tidak memuat tool tulis.
    $writeVerbs = ['insert', 'update', 'delete', 'drop', 'alter', 'truncate', 'create', 'set_', 'add', 'save'];
    $names = strtolower(implode(',', AiToolService::allowedNames()));
    $hasWrite = false;
    foreach ($writeVerbs as $verb) {
        if (str_contains($names, $verb)) {
            $hasWrite = true;
        }
    }
    check('tanpa tool tulis (INSERT/UPDATE/DELETE/... )', !$hasWrite, 'names=' . $names);

    // Tool tak dikenal ditolak.
    $deny = AiToolService::execute('run_sql', ['q' => 'DROP TABLE users'], $admin);
    check('tool tak dikenal ditolak', ($deny['error'] ?? '') === 'tool_not_allowed');

    // 3. Deklarasi tool sesuai peran.
    $adminTools = AiToolService::allowedNames('admin');
    $pelangganTools = AiToolService::allowedNames('pelanggan');
    check('admin punya get_customer_count', in_array('get_customer_count', $adminTools, true));
    check('pelanggan TIDAK punya get_customer_count', !in_array('get_customer_count', $pelangganTools, true));
    check('pelanggan TIDAK punya get_technician_assignments', !in_array('get_technician_assignments', $pelangganTools, true));
    check('teknisi punya get_technician_assignments', in_array('get_technician_assignments', AiToolService::allowedNames('teknisi'), true));

    // 4. Function calling: status tiket (pelanggan, tiket miliknya).
    useModel('mock-tool-status');
    $r = AiAssistantService::chat($pelanggan, 'Tolong tampilkan status tiket saya yang terakhir.');
    $reply = (string) ($r['reply'] ?? '');
    check('tool loop status: ok', !empty($r['ok']), json_encode($r));
    check('tool loop status: jawaban memuat hasil tool', str_contains($reply, 'FINAL_TOOL_RESULT='), $reply);
    check('tool loop status: tool benar', str_contains($reply, 'get_ticket_status'), $reply);
    check('tool loop status: data asli tiket (found=true)', str_contains($reply, '"found":true'), $reply);

    // 5. Function calling: argumen kosong harus {} (bukan []).
    useModel('mock-tool-count');
    $r = AiAssistantService::chat($pelanggan, 'Berapa tiket aktif saya?');
    $reply = (string) ($r['reply'] ?? '');
    check('tool loop arg kosong: ok (tanpa error 400)', !empty($r['ok']), json_encode($r));
    check('tool loop arg kosong: tool count dipanggil', str_contains($reply, 'get_my_active_ticket_count'), $reply);
    check('tool loop arg kosong: data asli (active)', str_contains($reply, '"active":'), $reply);

    // 6. RBAC tool: pelanggan memanggil tool admin → ditolak.
    useModel('mock-tool-customer');
    $r = AiAssistantService::chat($pelanggan, 'Berapa jumlah pelanggan?');
    $reply = (string) ($r['reply'] ?? '');
    check('RBAC tool: pelanggan -> get_customer_count ditolak', str_contains($reply, 'tool_not_allowed'), $reply);

    // 7. Admin memanggil tool admin → data asli.
    useModel('mock-tool-customer');
    $r = AiAssistantService::chat($admin, 'Berapa jumlah pelanggan aktif?');
    $reply = (string) ($r['reply'] ?? '');
    check('admin get_customer_count: data asli', str_contains($reply, 'pelanggan_aktif'), $reply);

    // 8. Statistik status via tool.
    useModel('mock-tool-statistics');
    $r = AiAssistantService::chat($admin, 'Berapa tiket per status?');
    $reply = (string) ($r['reply'] ?? '');
    check('statistik status via tool: ok', !empty($r['ok']) && str_contains($reply, 'by_status'), $reply);

    // 9. Model tanpa tool (balasan teks biasa) tetap bekerja meski tools dikirim.
    useModel('mock-ok');
    $r = AiAssistantService::chat($pelanggan, 'Halo, apa kabar?');
    check('balasan teks biasa tetap jalan', !empty($r['ok']) && str_contains((string) ($r['reply'] ?? ''), 'MOCK_OK'), json_encode($r));

    // 10. Pembacaan ter-scope.
    $t = AiReadService::ticketsForUser($pelanggan, 3);
    check('tiket pelanggan hanya miliknya (ada data)', ($t['count'] ?? 0) > 0, json_encode($t));
    $assign = AiReadService::technicianAssignmentsForUser($teknisi);
    check('tugas teknisi terisi', ($assign['count'] ?? 0) > 0, json_encode($assign));
    $denied = AiReadService::technicianAssignmentsForUser($cs);
    check('CS tidak melihat kinerja teknisi', ($denied['error'] ?? '') === 'not_allowed');

    // 11. Kunci API tidak bocor ke log mock.
    $logText = is_file(MOCK_LOG) ? (string) file_get_contents(MOCK_LOG) : '';
    check('kunci API tidak bocor ke log mock', !str_contains($logText, 'mock-key-should-never-leak'));
} finally {
    stopMock($proc, $childPid);
}

echo "----------------------------------------------------------------\n";
echo 'TOTAL: ' . ($PASS + $FAIL) . " | PASS: {$PASS} | FAIL: {$FAIL}\n";
if ($FAIL > 0) {
    echo "GAGAL:\n";
    foreach ($FAILED as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}
echo "SEMUA TES WIFICARE AI (READ-ONLY) LULUS.\n";
exit(0);
