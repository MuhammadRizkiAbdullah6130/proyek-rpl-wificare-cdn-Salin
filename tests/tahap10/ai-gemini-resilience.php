<?php
/**
 * WiFiCare CDN — Tahap 10: ketahanan Gemini (retry + fallback model).
 *
 * Menguji AiAssistantService TANPA jaringan/kunci nyata memakai mock lokal
 * (tests/tahap10/mock-gemini.php). Membuktikan:
 * - model utama sehat -> langsung dipakai;
 * - 503/404 (sementara) -> dicoba ulang lalu beralih ke model cadangan;
 * - 400/403 (permanen) -> TIDAK diulang dan TIDAK memakai cadangan;
 * - jawaban kosong (SAFETY) -> pesan aman, tanpa cadangan;
 * - kunci API tidak pernah ikut ditulis ke berkas log permintaan.
 *
 * Jalankan (server mock dijalankan otomatis):
 *   php tests/tahap10/ai-gemini-resilience.php
 */

declare(strict_types=1);

define('MOCK_HOST', '127.0.0.1');
define('MOCK_PORT', 8096);
define('MOCK_BASE', 'http://' . MOCK_HOST . ':' . MOCK_PORT . '/v1beta');
define('TMP', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_t10');
if (!is_dir(TMP)) {
    @mkdir(TMP, 0775, true);
}
define('MOCK_LOG', TMP . DIRECTORY_SEPARATOR . 'mock_gemini_hits.log');

// --- Konfigurasi wajib diset SEBELUM bootstrap (env tidak menimpa OS env) ----
putenv('APP_ENV=testing');
putenv('AI_PROVIDER=gemini');
putenv('GEMINI_API_KEY=mock-key-should-never-leak');
putenv('GEMINI_API_BASE_URL=' . MOCK_BASE);
putenv('AI_MAX_RETRIES=1');

@unlink(MOCK_LOG);

require_once __DIR__ . '/../../app/bootstrap.php';

// Tahap 11: `AiSettingsService` memprioritaskan baris `ai_settings` di database.
// Uji offline ini mengarahkan provider lewat env/konfigurasi, jadi baris
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

/** Tentukan model utama/cadangan lalu panggil layanan. */
function askWith(string $primary, string $fallback, string $message = 'Halo'): array
{
    $GLOBALS['app_config']['ai']['gemini_model'] = $primary;
    $GLOBALS['app_config']['ai']['gemini_model_fallback'] = $fallback;

    return AiAssistantService::chat(['id' => 1, 'role' => 'pelanggan', 'name' => 'Uji'], $message);
}

/** Jumlah baris log mock saat ini (penanda awal). */
function hitsMark(): int
{
    return is_file(MOCK_LOG) ? count(file(MOCK_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0;
}

/** Nama model yang dicatat mock setelah penanda tertentu. */
function hitsSince(int $mark): array
{
    if (!is_file(MOCK_LOG)) {
        return [];
    }
    $lines = file(MOCK_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    return array_values(array_slice($lines, $mark));
}

// --- Jalankan server mock ----------------------------------------------------
echo "==== TAHAP 10 — KETAHANAN GEMINI (retry + fallback) ====\n";

$router = __DIR__ . '/mock-gemini.php';
$cmd = escapeshellarg(PHP_BINARY) . ' -S ' . MOCK_HOST . ':' . MOCK_PORT . ' ' . escapeshellarg($router);
$devNull = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$descriptorSpec = [1 => ['file', $devNull, 'w'], 2 => ['file', $devNull, 'w']];

// Pastikan port belum dipakai proses lain (menghindari mock "asing"/hang).
$busy = @fsockopen(MOCK_HOST, MOCK_PORT, $errno, $errstr, 0.3);
if (is_resource($busy)) {
    fclose($busy);
    echo "GAGAL: port " . MOCK_PORT . " sudah dipakai. Hentikan proses itu lalu jalankan ulang.\n";
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

/** Hentikan server mock beserta proses anaknya. */
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

check('server mock Gemini berjalan', $started, 'port ' . MOCK_PORT);

if (!$started) {
    echo "Tidak dapat memulai mock. Pastikan port " . MOCK_PORT . " bebas.\n";
    stopMock($proc, $childPid);
    exit(1);
}

try {
    $user = ['id' => 1, 'role' => 'pelanggan', 'name' => 'Uji'];

    // 1. Model utama sehat -> langsung dipakai, tanpa cadangan.
    $mark = hitsMark();
    $res = askWith('mock-ok', 'mock-missing');
    $hits = hitsSince($mark);
    check('model sehat: ok=true', !empty($res['ok']));
    check('model sehat: memakai model utama', str_contains((string) ($res['reply'] ?? ''), 'MOCK_OK:mock-ok'), 'reply=' . ($res['reply'] ?? ''));
    check('model sehat: dipanggil tepat sekali', $hits === ['mock-ok'], 'hits=' . implode(',', $hits));

    // 2. 503 sementara -> retry lalu fallback.
    $mark = hitsMark();
    $res = askWith('mock-busy', 'mock-ok');
    $hits = hitsSince($mark);
    check('503: akhirnya ok=true via cadangan', !empty($res['ok']));
    check('503: balasan dari model cadangan', str_contains((string) ($res['reply'] ?? ''), 'MOCK_OK:mock-ok'), 'reply=' . ($res['reply'] ?? ''));
    check('503: model utama dicoba ulang lalu cadangan', $hits === ['mock-busy', 'mock-busy', 'mock-ok'], 'hits=' . implode(',', $hits));

    // 3. 404 model tak tersedia -> fallback.
    $mark = hitsMark();
    $res = askWith('mock-missing', 'mock-ok');
    $hits = hitsSince($mark);
    check('404: akhirnya ok=true via cadangan', !empty($res['ok']));
    check('404: beralih ke model cadangan', $hits === ['mock-missing', 'mock-missing', 'mock-ok'], 'hits=' . implode(',', $hits));

    // 4. 503 tanpa cadangan -> gagal dengan pesan "sibuk".
    $mark = hitsMark();
    $res = askWith('mock-busy', '');
    $hits = hitsSince($mark);
    check('503 tanpa cadangan: ok=false', empty($res['ok']));
    check('503 tanpa cadangan: pesan sibuk', str_contains((string) ($res['error'] ?? ''), 'sibuk'), 'error=' . ($res['error'] ?? ''));
    check('503 tanpa cadangan: dicoba ulang sekali', $hits === ['mock-busy', 'mock-busy'], 'hits=' . implode(',', $hits));

    // 5. 400 permanen -> TIDAK retry, TIDAK pakai cadangan.
    $mark = hitsMark();
    $res = askWith('mock-bad', 'mock-ok');
    $hits = hitsSince($mark);
    check('400: ok=false', empty($res['ok']));
    check('400: pesan tidak valid', str_contains((string) ($res['error'] ?? ''), 'tidak valid'), 'error=' . ($res['error'] ?? ''));
    check('400: tanpa retry/cadangan (1 panggilan)', $hits === ['mock-bad'], 'hits=' . implode(',', $hits));

    // 6. 403 permanen -> TIDAK retry, TIDAK pakai cadangan.
    $mark = hitsMark();
    $res = askWith('mock-forbid', 'mock-ok');
    $hits = hitsSince($mark);
    check('403: ok=false', empty($res['ok']));
    check('403: pesan izin/kunci', str_contains((string) ($res['error'] ?? ''), 'izin') || str_contains((string) ($res['error'] ?? ''), 'Kunci'), 'error=' . ($res['error'] ?? ''));
    check('403: tanpa retry/cadangan (1 panggilan)', $hits === ['mock-forbid'], 'hits=' . implode(',', $hits));

    // 7. Jawaban kosong (SAFETY) -> pesan aman, tanpa cadangan.
    $mark = hitsMark();
    $res = askWith('mock-safe', 'mock-ok');
    $hits = hitsSince($mark);
    check('SAFETY: ok=false', empty($res['ok']));
    check('SAFETY: pesan kebijakan keamanan', str_contains((string) ($res['error'] ?? ''), 'keamanan'), 'error=' . ($res['error'] ?? ''));
    check('SAFETY: tanpa cadangan (1 panggilan)', $hits === ['mock-safe'], 'hits=' . implode(',', $hits));

    // 8. Kunci API tidak bocor ke berkas log permintaan.
    $logText = is_file(MOCK_LOG) ? (string) file_get_contents(MOCK_LOG) : '';
    check('kunci API tidak bocor ke log mock', !str_contains($logText, 'mock-key-should-never-leak'));

    // 9. Daftar cadangan ganda tidak menghasilkan panggilan ganda.
    $mark = hitsMark();
    $res = askWith('mock-busy', 'mock-ok,mock-ok,mock-ok');
    $hits = hitsSince($mark);
    check('cadangan duplikat: hanya satu panggilan cadangan', $hits === ['mock-busy', 'mock-busy', 'mock-ok'], 'hits=' . implode(',', $hits));
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
echo "SEMUA TES KETAHANAN GEMINI LULUS.\n";
exit(0);
