<?php
/**
 * WiFiCare CDN — Tahap 10: uji alur AI nyata (endpoint widget) memakai mock
 * provider (bukan bagian aplikasi). Membuktikan bahwa widget yang sama
 * (endpoint /ai/chat) mengembalikan balasan AI nyata bila dikonfigurasi, dan
 * tetap menangani error + tidak membocorkan kunci.
 *
 * Prasyarat:
 * - mock provider berjalan di http://localhost:8097 (mock-ai.php)
 * - server aplikasi (DB uji) berjalan di T10_BASE (default :8099) DENGAN
 *   AI_API_KEY/AI_MODEL/AI_API_BASE_URL mengarah ke mock.
 */

declare(strict_types=1);

require_once __DIR__ . '/_config.php';
t10_require_passwords(['T10_PW_ADMIN', 'T10_PW_PEL']);
define('BASE', T10_BASE);
define('TMP', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_t10');
if (!is_dir(TMP)) { @mkdir(TMP, 0775, true); }

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

final class Sess
{
    public string $jar;
    public string $lastBody = '';
    public int $lastStatus = 0;
    public array $lastHeaders = [];

    public function __construct(public string $name)
    {
        $this->jar = TMP . '/ailive_jar_' . preg_replace('/[^a-z0-9]/i', '_', $name) . '.txt';
        @unlink($this->jar);
    }

    public function request(string $method, string $path, array $opts = []): array
    {
        $ch = curl_init();
        $url = BASE . $path;
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_TIMEOUT => 40,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        }
        if (isset($opts['fields'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['fields']);
        }
        if (isset($opts['json'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opts['json']));
            curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(
                $opts['headers'] ?? [],
                ['Content-Type: application/json']
            ));
        } elseif (isset($opts['headers'])) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $opts['headers']);
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            $this->lastStatus = 0;
            $this->lastBody = 'CURL_ERROR: ' . curl_error($ch);
            curl_close($ch);
            return $this->snapshot();
        }
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $this->lastStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerText = substr($raw, 0, $headerSize);
        $this->lastBody = substr($raw, $headerSize);
        $this->lastHeaders = [];
        foreach (explode("\r\n", $headerText) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $this->lastHeaders[strtolower(trim($k))] = trim($v);
            }
        }
        curl_close($ch);
        return $this->snapshot();
    }

    private function snapshot(): array
    {
        return ['status' => $this->lastStatus, 'body' => $this->lastBody, 'headers' => $this->lastHeaders];
    }

    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    public function token(): string
    {
        if (preg_match('/name="csrf-token"\s+content="([a-f0-9]{64})"/', $this->lastBody, $m)) {
            return $m[1];
        }
        if (preg_match('/name="_token"\s+value="([a-f0-9]{64})"/', $this->lastBody, $m)) {
            return $m[1];
        }
        return '';
    }

    public function login(string $username, string $password): bool
    {
        $this->get('/login');
        $token = $this->token();
        $r = $this->request('POST', '/login', [
            'fields' => ['_token' => $token, 'username' => $username, 'password' => $password],
        ]);
        return $r['status'] === 302 && !str_contains($r['headers']['location'] ?? '', '/login');
    }

    /** @return array{code:int, json:array<string,mixed>} */
    public function chat(string $message, string $token): array
    {
        $r = $this->request('POST', '/ai/chat', [
            'json' => ['message' => $message, '_token' => $token],
            'headers' => ['X-CSRF-Token: ' . $token],
        ]);
        $json = json_decode($r['body'], true);
        return ['code' => $r['status'], 'json' => is_array($json) ? $json : []];
    }
}

echo "==== TAHAP 10 — AI LIVE (mock provider) @ " . BASE . " ====\n";

$pelanggan = new Sess('ailive_pelanggan');
check('login pelanggan', $pelanggan->login('pppoe.rina', T10_PW_PEL));
$pelanggan->get('/pelanggan/dashboard');
$token = $pelanggan->token();

// Widget dikonfigurasi: tidak menampilkan pesan "belum dikonfigurasi".
$dash = $pelanggan->get('/pelanggan/dashboard')['body'];
check('widget dikonfigurasi: tanpa pesan belum dikonfigurasi', !str_contains($dash, 'Layanan AI belum dikonfigurasi'));
check('widget dikonfigurasi: input tidak disabled', !preg_match('/data-aiw-input[^>]*disabled/', $dash));

// Balasan AI nyata melalui endpoint widget.
$chat = $pelanggan->chat('Halo asisten, apa kabar?', $token);
check('chat 200 dan ok=true', $chat['code'] === 200 && ($chat['json']['ok'] ?? false) === true, 'code=' . $chat['code'] . ' body=' . substr(json_encode($chat['json']), 0, 160));
check('balasan AI tidak kosong', trim((string) ($chat['json']['reply'] ?? '')) !== '');

// Peran aman: pesan berisi tiket tetap diproses.
$ticket = $pelanggan->chat('TOOLCALL status WFC-20260101-000001', $token);
check('tool calling backend dieksekusi', ($ticket['json']['ok'] ?? false) === true && str_contains((string) ($ticket['json']['reply'] ?? ''), 'TOOL_RESULT='));

// Error provider ditangani sebagai ok=false (tanpa respons palsu).
$err = $pelanggan->chat('ERROR please', $token);
check('error provider -> ok=false + pesan', ($err['json']['ok'] ?? true) === false && trim((string) ($err['json']['error'] ?? '')) !== '');

// Timeout ditangani.
$slow = $pelanggan->chat('SLOW response please', $token);
check('timeout ditangani (pesan waktu)', ($slow['json']['ok'] ?? true) === false && str_contains((string) ($slow['json']['error'] ?? ''), 'waktu'));

// Output berbahaya tetap berupa teks di sisi klien (textContent).
$widgetJs = $pelanggan->get('/assets/js/ai-widget.js')['body'];
check('widget memakai textContent untuk balasan', str_contains($widgetJs, 'textContent'));
$xss = $pelanggan->chat('XSS test', $token);
check('XSS dikembalikan sebagai string JSON', ($xss['json']['reply'] ?? '') === '<script>alert(1)</script>');

// Kunci API tidak bocor.
$admin = new Sess('ailive_admin');
$admin->login('admin.budi', T10_PW_ADMIN);
$aiHtml = $admin->get('/admin/dashboard')['body'];
check('kunci API tidak terlihat di HTML dashboard', !str_contains($aiHtml, 'test-key-123') && !str_contains($aiHtml, 'AI_API_KEY'));
check('kunci API tidak terlihat di ai-widget.js', !str_contains($widgetJs, 'test-key-123') && !str_contains($widgetJs, 'api_key'));

echo "----------------------------------------------------------------\n";
echo "TOTAL: " . ($PASS + $FAIL) . " | PASS: {$PASS} | FAIL: {$FAIL}\n";
if ($FAIL > 0) {
    echo "GAGAL:\n";
    foreach ($FAILED as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}
echo "SEMUA TES AI LIVE LULUS.\n";
exit(0);
