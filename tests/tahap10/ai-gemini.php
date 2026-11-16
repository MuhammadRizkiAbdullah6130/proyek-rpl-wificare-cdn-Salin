<?php
/**
 * WiFiCare CDN — Tahap 10: uji LIVE integrasi Gemini via endpoint Floating AI.
 *
 * Menguji jalur penuh: login → jendela Floating AI (widget) → POST /ai/chat
 * (session + CSRF + rate limit) → AiAssistantService → Google Gemini →
 * respons tampil di widget.
 *
 * Catatan:
 * - Uji ini memakai jaringan internet DAN kunci API nyata (dibaca server, bukan
 *   dari skrip ini). Kunci TIDAK pernah dicetak.
 * - Bila provider bukan Gemini atau belum dikonfigurasi, uji ditandai SKIP
 *   (tidak gagal).
 * - Jalankan terhadap server uji: lihat tests/tahap10/_config.php (T10_BASE).
 */

declare(strict_types=1);

require_once __DIR__ . '/_config.php';
t10_require_passwords(['T10_PW_PEL']);
define('BASE', T10_BASE);
define('TMP', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_t10');
if (!is_dir(TMP)) { @mkdir(TMP, 0775, true); }

$PASS = 0; $FAIL = 0; $SKIP = 0; $FAILED = [];

function check(string $name, bool $ok, string $detail = ''): void
{
    global $PASS, $FAIL, $FAILED;
    if ($ok) { $PASS++; echo "PASS  {$name}\n"; }
    else { $FAIL++; $FAILED[] = $name . ($detail !== '' ? " :: {$detail}" : ''); echo "FAIL  {$name}" . ($detail !== '' ? " :: {$detail}" : '') . "\n"; }
}
function skip(string $name, string $detail = ''): void
{
    global $SKIP;
    $SKIP++;
    echo "SKIP  {$name}" . ($detail !== '' ? " :: {$detail}" : '') . "\n";
}
function showQ(string $q): void { echo "  Q: {$q}\n"; }
function showA(string $a): void { echo "  A: " . str_replace("\n", "\n     ", $a) . "\n"; }

final class Sess
{
    public string $jar; public string $lastBody = ''; public int $lastStatus = 0; public array $lastHeaders = [];
    public function __construct(public string $name)
    {
        $this->jar = TMP . '/gem_jar_' . preg_replace('/[^a-z0-9]/i', '_', $name) . '.txt';
        @unlink($this->jar);
    }
    public function request(string $method, string $path, array $opts = []): array
    {
        $ch = curl_init();
        $url = (str_starts_with($path, 'http') ? $path : BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 60,
        ]);
        if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); }
        if (isset($opts['fields'])) { curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['fields']); }
        if (isset($opts['headers'])) { curl_setopt($ch, CURLOPT_HTTPHEADER, $opts['headers']); }
        $raw = curl_exec($ch);
        if ($raw === false) { $this->lastStatus = 0; $this->lastBody = 'CURL_ERROR: ' . curl_error($ch); curl_close($ch); return $this->snap(); }
        $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $this->lastStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ht = substr($raw, 0, $hs);
        $this->lastBody = substr($raw, $hs);
        $this->lastHeaders = [];
        foreach (explode("\r\n", $ht) as $line) {
            if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $this->lastHeaders[strtolower(trim($k))] = trim($v); }
        }
        curl_close($ch);
        return $this->snap();
    }
    private function snap(): array { return ['status' => $this->lastStatus, 'body' => $this->lastBody, 'headers' => $this->lastHeaders]; }
    public function get(string $p): array { return $this->request('GET', $p); }
    public function token(): string
    {
        if (preg_match('/name="_token"\s+value="([a-f0-9]{64})"/', $this->lastBody, $m)) { return $m[1]; }
        return '';
    }
    public function login(string $u, string $p): array
    {
        $this->get('/login');
        return $this->request('POST', '/login', ['fields' => ['_token' => $this->token(), 'username' => $u, 'password' => $p]]);
    }
    public function location(): string { return $this->lastHeaders['location'] ?? ''; }
}

echo "==== TAHAP 10 — LIVE GEMINI VIA FLOATING AI ====\n";

$asked = [];

// --- 1. Login pelanggan ------------------------------------------------------
$s = new Sess('gemini_pelanggan');
$r = $s->login('pppoe.rina', T10_PW_PEL);
check('login pelanggan sukses', $r['status'] === 302 && str_contains($s->location(), '/pelanggan/dashboard'), 'status=' . $r['status']);

$dash = $s->get('/pelanggan/dashboard');
$body = $dash['body'];
check('dashboard memuat Floating AI widget', str_contains($body, 'data-ai-widget'));
$configured = !str_contains($body, 'Layanan AI belum dikonfigurasi');
check('widget melaporkan layanan AI terkonfigurasi', $configured);

// endpoint + CSRF dari atribut formulir widget
preg_match('/data-aiw-form\s+data-endpoint="([^"]+)"\s+data-token="([a-f0-9]{64})"/', $body, $mm);
$endpoint = $mm[1] ?? '';
$csrf = $mm[2] ?? '';
check('widget punya endpoint chat + token CSRF', $endpoint !== '' && $csrf !== '');
check('endpoint mengarah ke /ai/chat', str_contains($endpoint, '/ai/chat'));

// --- 2. Proteksi endpoint ----------------------------------------------------
$anon = new Sess('gemini_anon');
$anonResponse = $anon->request('POST', '/ai/chat', [
    'fields' => json_encode(['message' => 'halo', '_token' => str_repeat('a', 64)]),
    'headers' => ['Content-Type: application/json'],
]);
check('POST /ai/chat tanpa login ditolak (401/302)', in_array($anonResponse['status'], [302, 401], true), 'status=' . $anonResponse['status']);

$noCsrf = $s->request('POST', '/ai/chat', [
    'fields' => json_encode(['message' => 'halo']),
    'headers' => ['Content-Type: application/json'],
]);
check('POST /ai/chat tanpa CSRF ditolak (419)', $noCsrf['status'] === 419, 'status=' . $noCsrf['status']);

if (!$configured) {
    skip('uji balasan Gemini (3 pertanyaan)', 'layanan belum dikonfigurasi di server uji');
} else {
    // --- 3. Tiga pertanyaan nyata ------------------------------------------
    $questions = [
        'Halo, kamu siapa?',
        'Bagaimana cara mengatasi WiFi yang terhubung tetapi tidak ada internet?',
        'Bagaimana cara membuat pengaduan di WiFiCare CDN?',
    ];

    foreach ($questions as $i => $q) {
        $n = $i + 1;
        $res = $s->request('POST', $endpoint, [
            'fields' => json_encode(['message' => $q, '_token' => $csrf]),
            'headers' => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf, 'X-Requested-With: XMLHttpRequest'],
        ]);
        $data = json_decode($res['body'], true);
        $asked[] = ['q' => $q, 'r' => $data];

        showQ($q);
        check("Q{$n} respons JSON valid", is_array($data), 'body=' . mb_substr($res['body'], 0, 120));
        check("Q{$n} ok=true", is_array($data) && !empty($data['ok']), 'resp=' . mb_substr($res['body'], 0, 200));
        $reply = is_array($data) ? (string) ($data['reply'] ?? '') : '';
        check("Q{$n} balasan tidak kosong", trim($reply) !== '');
        if ($reply !== '') { showA($reply); }

        // Jangan pernah bocorkan kunci API / header rahasia.
        check("Q{$n} tidak membocorkan kunci API", !str_contains($res['body'], 'AIza') && !stripos($res['body'], 'x-goog-api-key') && !stripos($res['body'], 'api_key'));
    }
}

echo "----------------------------------------------------------------\n";
echo "TOTAL: " . ($PASS + $FAIL + $SKIP) . " | PASS: {$PASS} | FAIL: {$FAIL} | SKIP: {$SKIP}\n";
if ($FAIL > 0) {
    echo "GAGAL:\n";
    foreach ($FAILED as $f) { echo "  - {$f}\n"; }
    exit(1);
}
echo "SEMUA TES LIVE GEMINI (VIA FLOATING AI) LULUS.\n";
exit(0);
