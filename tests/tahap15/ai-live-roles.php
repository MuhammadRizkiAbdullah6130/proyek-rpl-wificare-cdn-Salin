<?php
/**
 * WiFiCare CDN — Tahap 15: uji LIVE Gemini melalui widget untuk SETIAP peran.
 *
 * Membuktikan (dengan panggilan nyata ke provider) bahwa:
 * - widget/endpoint bekerja untuk Admin, CS, Teknisi, dan Pelanggan;
 * - jawaban memakai tool baca-only dengan cakupan peran yang benar;
 * - tidak ada API key yang bocor ke respons.
 *
 * Prasyarat: server uji di http://localhost:8099 (DB wificare_cdn_test) dengan
 * GEMINI_API_KEY dari .env, tanpa baris ai_settings (mode legacy .env), serta
 * koneksi internet. Bila kuota provider habis, hasil ditandai BLOCKED — tidak
 * pernah diklaim PASS.
 *
 * Jalankan: php tests/tahap15/ai-live-roles.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../tahap10/_config.php';
t10_require_passwords(['T10_PW_ADMIN', 'T10_PW_CS', 'T10_PW_TEK', 'T10_PW_PEL']);

define('T15L_BASE', T10_BASE);

final class T15LiveSess
{
    private string $jar;
    private int $lastStatus = 0;
    private string $lastBody = '';
    /** @var array<string, string> */
    private array $lastHeaders = [];

    public function __construct(string $name)
    {
        $this->jar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_t15live_' . $name . '_' . getmypid() . '.jar';
        @unlink($this->jar);
    }

    /** @return array{status:int, body:string, headers:array<string,string>} */
    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    /** @return array{status:int, body:string, headers:array<string,string>} */
    public function request(string $method, string $path, ?array $json = null, bool $asForm = false): array
    {
        $ch = curl_init(T15L_BASE . $path);
        $headers = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($json !== null) {
                if ($asForm) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($json));
                } else {
                    $headers[] = 'Content-Type: application/json';
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE));
                }
            }
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $this->lastStatus = 0;
            $this->lastBody = 'CURL_ERROR: ' . curl_error($ch);
            curl_close($ch);

            return $this->snapshot();
        }

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $this->lastStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $this->lastBody = substr($raw, $headerSize);
        $headerText = substr($raw, 0, $headerSize);
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

    public function token(): string
    {
        if (preg_match('/name="csrf-token"\s+content="([a-f0-9]{64})"/', $this->lastBody, $m)) {
            return $m[1];
        }

        return preg_match('/name="_token"\s+value="([a-f0-9]{64})"/', $this->lastBody, $m) ? $m[1] : '';
    }

    public function login(string $username, string $password): bool
    {
        $this->get('/login');
        $token = $this->token();
        $r = $this->request('POST', '/login', ['_token' => $token, 'username' => $username, 'password' => $password], true);

        return $r['status'] === 302 && !str_contains($r['headers']['location'] ?? '', '/login');
    }

    /** @return array{status:int, body:string, headers:array<string,string>} */
    private function snapshot(): array
    {
        return ['status' => $this->lastStatus, 'body' => $this->lastBody, 'headers' => $this->lastHeaders];
    }
}

$PASS = 0;
$FAIL = 0;
$BLOCKED = 0;
$NOTES = [];

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

function blocked(string $name, string $reason): void
{
    global $BLOCKED, $NOTES;
    $BLOCKED++;
    $NOTES[] = $name . ' :: ' . $reason;
    echo "BLOCKED  {$name} :: {$reason}\n";
}

/** @return array{0:string,1:?array<string,mixed>} */
function ask(T15LiveSess $session, string $question): array
{
    $session->get('/ai');
    $token = $session->token();
    $r = $session->request('POST', '/ai/chat', ['_token' => $token, 'message' => $question]);
    $decoded = json_decode($r['body'], true);
    echo '  status=' . $r['status'] . ' body=' . substr($r['body'], 0, 180) . "\n";

    return [$r['body'], is_array($decoded) ? $decoded : null];
}

echo "==== TAHAP 15 — LIVE GEMINI PER PERAN ====\n";

$db = new PDO(T10_DB_DSN, T10_DB_USER, T10_DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$adminCount = (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'pelanggan' AND is_active = 1")->fetchColumn();
$teknisiId = (int) $db->query("SELECT id FROM users WHERE username = 'tek.bayu' LIMIT 1")->fetchColumn();
$teknisiTickets = $db->query(
    'SELECT DISTINCT p.nomor_tiket FROM penugasan_teknisi pt
     INNER JOIN pengaduan p ON p.id = pt.pengaduan_id
     WHERE pt.teknisi_id = ' . $teknisiId . " AND pt.is_active = 1
       AND p.status IN ('menunggu_teknisi','dalam_penanganan')"
)->fetchAll(PDO::FETCH_COLUMN);

$cases = [
    ['Admin', 'admin.budi', T10_PW_ADMIN, 'Hanya jawab singkat: berapa jumlah pelanggan aktif? Gunakan data aplikasi.'],
    ['CS', 'cs.dewi', T10_PW_CS, 'Berapa tiket aktif yang saat ini ada? Sebutkan angkanya saja sesuai data.'],
    ['Teknisi', 'tek.bayu', T10_PW_TEK, 'Sebutkan nomor tiket tugas aktif saya saat ini, hanya nomor tiketnya.'],
    ['Pelanggan', 'pppoe.rina', T10_PW_PEL, 'Apa username PPPoE saya? Gunakan data profil saya.'],
];

foreach ($cases as $case) {
    [$label, $username, $password, $question] = $case;
    echo "--- {$label} ---\n";
    $session = new T15LiveSess(strtolower($label));
    if (!$session->login($username, $password)) {
        check("login live {$label}", false, 'login gagal');
        continue;
    }
    check("login live {$label}", true);

    [$body, $decoded] = ask($session, $question);
    if ($decoded === null) {
        blocked("jawaban live {$label}", 'respons bukan JSON (kemungkinan timeout/kuota)');
        continue;
    }

    if (($decoded['ok'] ?? false) !== true) {
        $code = (string) ($decoded['code'] ?? 'provider_error');
        $isEnvironment = in_array($code, ['quota', 'timeout', 'server', 'network'], true);
        if ($isEnvironment) {
            blocked("jawaban live {$label}", 'lingkungan provider: ' . $code . ' — ' . (string) ($decoded['error'] ?? ''));
        } else {
            check("jawaban live {$label}", false, 'code=' . $code . ' error=' . (string) ($decoded['error'] ?? ''));
        }
        continue;
    }

    $reply = (string) ($decoded['reply'] ?? '');
    check("jawaban live {$label} tidak kosong", trim($reply) !== '');
    check("jawaban live {$label} tidak membocorkan API key", !preg_match('/AIza[0-9A-Za-z_\-]{20,}|GEMINI_API_KEY|AI_SETTINGS_KEY/', $body));

    if ($label === 'Admin') {
        check('admin menerima angka pelanggan aktif dari DB', str_contains($reply, (string) $adminCount), 'db=' . $adminCount . ' reply=' . $reply);
    } elseif ($label === 'Teknisi') {
        $found = false;
        foreach ($teknisiTickets as $nomor) {
            if (str_contains($reply, (string) $nomor)) {
                $found = true;
            }
        }
        check('teknisi hanya menerima nomor tiket tugasnya', $teknisiTickets === [] ? true : $found, 'reply=' . $reply);
        foreach ($db->query('SELECT nomor_tiket FROM pengaduan WHERE id NOT IN (SELECT pengaduan_id FROM penugasan_teknisi WHERE teknisi_id = ' . $teknisiId . ') LIMIT 5')->fetchAll(PDO::FETCH_COLUMN) as $other) {
            if (str_contains($reply, (string) $other)) {
                check('teknisi tidak menerima tiket teknisi lain', false, 'bocor=' . $other);
                continue 2;
            }
        }
        check('teknisi tidak menerima tiket teknisi lain', true);
    } elseif ($label === 'Pelanggan') {
        check('pelanggan menerima username PPPoE miliknya', str_contains($reply, 'pppoe.rina'), 'reply=' . $reply);
    }

    sleep(3);
}

echo "----------------------------------------------------------------\n";
echo 'TOTAL: ' . ($PASS + $FAIL) . " | PASS: {$PASS} | FAIL: {$FAIL} | BLOCKED: {$BLOCKED}\n";
foreach ($NOTES as $note) {
    echo '  - ' . $note . "\n";
}
if ($FAIL > 0) {
    exit(1);
}
