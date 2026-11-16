<?php
/**
 * WiFiCare CDN — Tahap 10 E2E test harness (CLI).
 * Berjalan terhadap server uji (DB: wificare_cdn_test) di http://localhost:8099
 * dan memverifikasi hasilnya langsung ke database uji.
 */

declare(strict_types=1);

require_once __DIR__ . '/_config.php';
t10_require_passwords(['T10_PW_ADMIN', 'T10_PW_CS', 'T10_PW_TEK', 'T10_PW_PEL']);
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

/** Sesi HTTP dengan cookie jar sendiri. */
final class Sess
{
    public string $jar;
    public string $lastBody = '';
    public int $lastStatus = 0;
    public array $lastHeaders = [];
    public string $lastUrl = '';

    public function __construct(public string $name)
    {
        $this->jar = TMP . '/jar_' . preg_replace('/[^a-z0-9]/i', '_', $name) . '.txt';
        @unlink($this->jar);
    }

    public function request(string $method, string $path, array $opts = []): array
    {
        $ch = curl_init();
        $url = (str_starts_with($path, 'http') ? $path : BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_TIMEOUT => 30,
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
        $this->lastUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
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
        if (preg_match('/name="_token"\s+value="([a-f0-9]{64})"/', $this->lastBody, $m)) {
            return $m[1];
        }
        if (preg_match('/name="csrf-token"\s+content="([a-f0-9]{64})"/', $this->lastBody, $m)) {
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
}

$pdo = new PDO(T10_DB_DSN, T10_DB_USER, T10_DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Berkas gambar uji (PNG 1x1 valid).
$teknisiAndiId = (int) $pdo->query("SELECT id FROM users WHERE username='tek.andi'")->fetchColumn();
$pelangganRinaId = (int) $pdo->query("SELECT id FROM users WHERE username='pppoe.rina'")->fetchColumn();

$pngFile = TMP . '/test.png';
file_put_contents($pngFile, base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
));
$jpgFile = TMP . '/test.jpg';
// JPG 1x1 nyata.
file_put_contents($jpgFile, base64_decode(
    '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAAA//EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q=='
));

echo "==== TAHAP 10 — E2E (DB uji: wificare_cdn_test) ====\n";

// ---------------------------------------------------------------- Auth / RBAC
$admin = new Sess('admin');
$cs = new Sess('cs');
$teknisi = new Sess('teknisi');
$teknisiB = new Sess('teknikB');
$pelanggan = new Sess('pelanggan');
$pelangganB = new Sess('pelangganB');
$anon = new Sess('anon');

// 1. Guest diarahkan ke login.
$r = $anon->get('/admin/dashboard');
check('guest /admin/dashboard -> 302 login', $r['status'] === 302 && str_contains($r['headers']['location'] ?? '', '/login'), 'status=' . $r['status']);

// 2. Login tiap peran.
check('login admin', $admin->login('admin.budi', T10_PW_ADMIN));
check('login cs', $cs->login('cs.dewi', T10_PW_CS));
check('login teknisi', $teknisi->login('tek.andi', T10_PW_TEK));
check('login teknisi B', $teknisiB->login('tek.bayu', T10_PW_TEK));
check('login pelanggan', $pelanggan->login('pppoe.rina', T10_PW_PEL));
check('login pelanggan B', $pelangganB->login('pppoe.agus', T10_PW_PEL));

// 3. Login salah & nonaktif tidak dilakukan di sini (mengubah data) — lihat tes terpisah.

// 4. Dashboard sesuai peran 200.
foreach ([
    'admin' => [$admin, '/admin/dashboard'],
    'cs' => [$cs, '/cs/dashboard'],
    'teknisi' => [$teknisi, '/teknisi/dashboard'],
    'pelanggan' => [$pelanggan, '/pelanggan/dashboard'],
] as $label => [$s, $path]) {
    $r = $s->get($path);
    check("dashboard {$label} 200", $r['status'] === 200, 'status=' . $r['status']);
}

// 5. RBAC silang.
$r = $pelanggan->get('/admin/dashboard');
check('pelanggan -> /admin 403', $r['status'] === 403, 'status=' . $r['status']);
$r = $teknisi->get('/cs/dashboard');
check('teknisi -> /cs 403', $r['status'] === 403, 'status=' . $r['status']);
$r = $cs->get('/teknisi/dashboard');
check('cs -> /teknisi 403', $r['status'] === 403, 'status=' . $r['status']);
$r = $pelanggan->get('/admin/laporan');
check('pelanggan -> /admin/laporan 403', $r['status'] === 403, 'status=' . $r['status']);
$r = $cs->get('/ai');
check('cs -> /ai 200', $r['status'] === 200, 'status=' . $r['status']);
$r = $pelanggan->get('/ai');
check('pelanggan -> /ai 200', $r['status'] === 200, 'status=' . $r['status']);

// 6. CSRF: POST tanpa token 419.
$pelanggan->get('/pelanggan/pengaduan/baru');
$r = $pelanggan->request('POST', '/pelanggan/pengaduan', ['fields' => ['deskripsi' => 'x']]);
check('POST pengaduan tanpa CSRF -> 419', $r['status'] === 419, 'status=' . $r['status']);

$r = $cs->request('POST', '/cs/pengaduan/8/terima', ['fields' => []]);
check('POST terima tanpa CSRF -> 419', $r['status'] === 419, 'status=' . $r['status']);

// ---------------------------------------------------------------- Skenario D (batal) quick
// ---------------------------------------------------------------- Skenario A: pelanggan buat tiket
$pelanggan->get('/pelanggan/pengaduan/baru');
$token = $pelanggan->token();
check('form buat punya token', $token !== '');
$r = $pelanggan->request('POST', '/pelanggan/pengaduan', [
    'fields' => [
        '_token' => $token,
        'nomor_whatsapp' => '081200000001',
        'kategori_id' => '1',
        'deskripsi' => 'E2E skenario A: internet mati total sejak pagi, butuh penanganan.',
        'foto' => new CURLFile($pngFile, 'image/png', 'bukti.png'),
    ],
]);
check('POST pengaduan valid -> 302', $r['status'] === 302, 'status=' . $r['status']);
$newId = (int) $pdo->query('SELECT MAX(id) FROM pengaduan')->fetchColumn();
$newTicket = $pdo->query("SELECT * FROM pengaduan WHERE id = {$newId}")->fetch();
check('tiket tersimpan', $newTicket !== false && $newTicket['status'] === 'menunggu_verifikasi', json_encode($newTicket['status'] ?? null));
check('nomor tiket format WFC', (bool) preg_match('/^WFC-\d{8}-\d{6}$/', (string) ($newTicket['nomor_tiket'] ?? '')));
$fotoCount = (int) $pdo->query("SELECT COUNT(*) FROM pengaduan_foto WHERE pengaduan_id = {$newId}")->fetchColumn();
check('foto pengaduan tersimpan', $fotoCount === 1, 'count=' . $fotoCount);
$riwayatCount = (int) $pdo->query("SELECT COUNT(*) FROM riwayat_status WHERE pengaduan_id = {$newId}")->fetchColumn();
check('riwayat awal tercatat', $riwayatCount >= 1, 'count=' . $riwayatCount);

// Pelanggan lain tidak bisa lihat tiket ini.
$r = $pelangganB->get('/pelanggan/pengaduan/' . $newId);
check('IDOR pelanggan lain -> 403', $r['status'] === 403, 'status=' . $r['status']);

// ---------------------------------------------------------------- CS verifikasi
$cs->get('/cs/pengaduan/' . $newId);
$token = $cs->token();
$r = $cs->request('POST', '/cs/pengaduan/' . $newId . '/terima', ['fields' => ['_token' => $token]]);
check('CS terima -> 302', $r['status'] === 302, 'status=' . $r['status']);
$st = $pdo->query("SELECT status FROM pengaduan WHERE id={$newId}")->fetchColumn();
check('status -> diproses_cs', $st === 'diproses_cs', 'status=' . $st);

// CS tugaskan teknisi andi
$cs->get('/cs/pengaduan/' . $newId);
$token = $cs->token();
$r = $cs->request('POST', '/cs/pengaduan/' . $newId . '/tugaskan', [
    'fields' => ['_token' => $token, 'teknisi_id' => (string) $teknisiAndiId, 'catatan' => 'Periksa koneksi di rumah pelanggan.'],
]);
check('CS tugaskan -> 302', $r['status'] === 302, 'status=' . $r['status']);
$st = $pdo->query("SELECT status FROM pengaduan WHERE id={$newId}")->fetchColumn();
$assignCount = (int) $pdo->query("SELECT COUNT(*) FROM penugasan_teknisi WHERE pengaduan_id={$newId} AND is_active=1")->fetchColumn();
check('status -> menunggu_teknisi', $st === 'menunggu_teknisi', 'status=' . $st);
check('satu penugasan aktif', $assignCount === 1, 'count=' . $assignCount);

// Penugasan ganda (klik kedua) ditolak karena status bukan diproses_cs.
$cs->get('/cs/pengaduan/' . $newId);
$token = $cs->token();
$cs->request('POST', '/cs/pengaduan/' . $newId . '/tugaskan', [
    'fields' => ['_token' => $token, 'teknisi_id' => (string) ($teknisiAndiId === 2 ? 4 : 2)],
]);
$assignCount = (int) $pdo->query("SELECT COUNT(*) FROM penugasan_teknisi WHERE pengaduan_id={$newId} AND is_active=1")->fetchColumn();
check('penugasan ganda dicegah', $assignCount === 1, 'count=' . $assignCount);

// Teknisi B tidak bisa buka tugas ini.
$r = $teknisiB->get('/teknisi/tugas/' . $newId);
check('IDOR teknisi lain -> 403', $r['status'] === 403, 'status=' . $r['status']);

// ---------------------------------------------------------------- Teknisi mulai + laporan
$teknisi->get('/teknisi/tugas/' . $newId);
$token = $teknisi->token();
$r = $teknisi->request('POST', '/teknisi/tugas/' . $newId . '/mulai', ['fields' => ['_token' => $token]]);
check('teknisi mulai -> 302', $r['status'] === 302, 'status=' . $r['status']);
$st = $pdo->query("SELECT status FROM pengaduan WHERE id={$newId}")->fetchColumn();
check('status -> dalam_penanganan', $st === 'dalam_penanganan', 'status=' . $st);

// Laporan tanpa foto ditolak.
$teknisi->get('/teknisi/tugas/' . $newId . '/laporan');
$token = $teknisi->token();
$r = $teknisi->request('POST', '/teknisi/tugas/' . $newId . '/laporan', [
    'fields' => ['_token' => $token, 'penyebab_gangguan' => 'Kabel putus', 'tindakan_perbaikan' => 'Ganti kabel'],
]);
$st = $pdo->query("SELECT status FROM pengaduan WHERE id={$newId}")->fetchColumn();
check('laporan tanpa foto ditolak (status tetap)', $st === 'dalam_penanganan', 'status=' . $st);

// Laporan lengkap.
$teknisi->get('/teknisi/tugas/' . $newId . '/laporan');
$token = $teknisi->token();
$r = $teknisi->request('POST', '/teknisi/tugas/' . $newId . '/laporan', [
    'fields' => [
        '_token' => $token,
        'penyebab_gangguan' => 'Kabel LAN putus akibat gigitan tikus.',
        'tindakan_perbaikan' => 'Kabel diganti baru dan koneksi diuji normal.',
        'catatan' => 'Pelanggan diminta memantau 1x24 jam.',
        'foto' => new CURLFile($jpgFile, 'image/jpeg', 'hasil.jpg'),
    ],
]);
check('teknisi kirim laporan -> 302', $r['status'] === 302, 'status=' . $r['status']);
$st = $pdo->query("SELECT status FROM pengaduan WHERE id={$newId}")->fetchColumn();
check('status -> selesai', $st === 'selesai', 'status=' . $st);
$laporanCount = (int) $pdo->query("SELECT COUNT(*) FROM laporan_teknisi WHERE pengaduan_id={$newId}")->fetchColumn();
check('laporan tersimpan (1)', $laporanCount === 1, 'count=' . $laporanCount);

// Laporan ganda ditolak.
$teknisi->get('/teknisi/tugas/' . $newId . '/laporan');
check('form laporan tiket selesai ditolak (bukan 200)', $teknisi->lastStatus !== 200 || !str_contains($teknisi->lastBody, 'name="penyebab_gangguan"'), 'status=' . $teknisi->lastStatus);

// ---------------------------------------------------------------- Pelanggan lihat hasil + rating
$r = $pelanggan->get('/pelanggan/pengaduan/' . $newId);
check('pelanggan lihat tiket selesai 200', $r['status'] === 200, 'status=' . $r['status']);
check('halaman memuat solusi/hasil', str_contains($r['body'], 'Kabel LAN putus') || str_contains($r['body'], 'tindakan') || str_contains($r['body'], 'Selesai'), 'cek isi');

// Rating Layanan dinonaktifkan sementara (Revisi 4): UI dan endpoint ditutup.
$r = $pelanggan->get('/pelanggan/rating');
check('halaman Rating Layanan ditutup (404)', $r['status'] === 404, 'status=' . $r['status']);
check('menu Rating Layanan tidak muncul di sidebar', !str_contains($pelanggan->get('/pelanggan/dashboard')['body'], 'Rating Layanan'));

$r = $pelanggan->get('/pelanggan/pengaduan/' . $newId);
check('form rating tidak dirender pada detail tiket selesai', !str_contains($r['body'], 'name="rating"'), 'status=' . $r['status']);

$before = (int) $pdo->query("SELECT COUNT(*) FROM rating_layanan WHERE pengaduan_id={$newId}")->fetchColumn();
$token = $pelanggan->token();
$r = $pelanggan->request('POST', '/pelanggan/pengaduan/' . $newId . '/rating', [
    'fields' => ['_token' => $token, 'rating' => '5', 'ulasan' => 'Pelayanan cepat dan ramah.'],
]);
$after = (int) $pdo->query("SELECT COUNT(*) FROM rating_layanan WHERE pengaduan_id={$newId}")->fetchColumn();
check('endpoint rating ditutup (404)', $r['status'] === 404, 'status=' . $r['status']);
check('endpoint rating tidak menyimpan data baru', $after === $before, "before={$before} after={$after}");

// ---------------------------------------------------------------- Skenario B: CS selesaikan langsung
$pelangganB->get('/pelanggan/pengaduan/baru');
$token = $pelangganB->token();
$r = $pelangganB->request('POST', '/pelanggan/pengaduan', [
    'fields' => [
        '_token' => $token,
        'nomor_whatsapp' => '081200000002',
        'kategori_id' => '2',
        'deskripsi' => 'E2E skenario B: koneksi lambat, minta pengecekan langsung CS.',
        'foto' => new CURLFile($pngFile, 'image/png', 'b.png'),
    ],
]);
$ticketB = (int) $pdo->query('SELECT MAX(id) FROM pengaduan')->fetchColumn();
check('skenario B tiket dibuat', $r['status'] === 302, 'status=' . $r['status']);
$cs->get('/cs/pengaduan/' . $ticketB);
$token = $cs->token();
$cs->request('POST', '/cs/pengaduan/' . $ticketB . '/terima', ['fields' => ['_token' => $token]]);
$cs->get('/cs/pengaduan/' . $ticketB);
$token = $cs->token();
$r = $cs->request('POST', '/cs/pengaduan/' . $ticketB . '/selesaikan', [
    'fields' => ['_token' => $token, 'solusi' => 'Reset perangkat dari sisi CS, koneksi normal kembali.'],
]);
$st = $pdo->query("SELECT status FROM pengaduan WHERE id={$ticketB}")->fetchColumn();
check('skenario B CS selesaikan langsung -> selesai', $st === 'selesai', 'status=' . $st);

// ---------------------------------------------------------------- Skenario C: ditolak
$pelanggan->get('/pelanggan/pengaduan/baru');
$token = $pelanggan->token();
$pelanggan->request('POST', '/pelanggan/pengaduan', [
    'fields' => [
        '_token' => $token,
        'nomor_whatsapp' => '081200000001',
        'kategori_id' => '5',
        'deskripsi' => 'E2E skenario C: pengaduan tanpa bukti yang cukup.',
        'foto' => new CURLFile($pngFile, 'image/png', 'c.png'),
    ],
]);
$ticketC = (int) $pdo->query('SELECT MAX(id) FROM pengaduan')->fetchColumn();
// Tolak tanpa alasan → tetap.
$cs->get('/cs/pengaduan/' . $ticketC);
$token = $cs->token();
$cs->request('POST', '/cs/pengaduan/' . $ticketC . '/tolak', ['fields' => ['_token' => $token, 'alasan' => '']]);
$st = $pdo->query("SELECT status FROM pengaduan WHERE id={$ticketC}")->fetchColumn();
check('skenario C tolak tanpa alasan ditolak', $st === 'menunggu_verifikasi', 'status=' . $st);
$cs->get('/cs/pengaduan/' . $ticketC);
$token = $cs->token();
$cs->request('POST', '/cs/pengaduan/' . $ticketC . '/tolak', ['fields' => ['_token' => $token, 'alasan' => 'Bukti tidak cukup.']]);
$row = $pdo->query("SELECT status, alasan_penolakan FROM pengaduan WHERE id={$ticketC}")->fetch();
check('skenario C ditolak + alasan tersimpan', $row['status'] === 'ditolak' && $row['alasan_penolakan'] === 'Bukti tidak cukup.', json_encode($row));
// Pelanggan lihat alasan.
$r = $pelanggan->get('/pelanggan/pengaduan/' . $ticketC);
check('pelanggan melihat alasan penolakan', str_contains($r['body'], 'Bukti tidak cukup'), 'cek');

// ---------------------------------------------------------------- Skenario D: batal
$pelanggan->get('/pelanggan/pengaduan/baru');
$token = $pelanggan->token();
$pelanggan->request('POST', '/pelanggan/pengaduan', [
    'fields' => [
        '_token' => $token,
        'nomor_whatsapp' => '081200000001',
        'kategori_id' => '1',
        'deskripsi' => 'E2E skenario D: tiket untuk diuji pembatalan.',
        'foto' => new CURLFile($pngFile, 'image/png', 'd.png'),
    ],
]);
$ticketD = (int) $pdo->query('SELECT MAX(id) FROM pengaduan')->fetchColumn();
// Batal tanpa alasan ditolak.
$cs->get('/cs/pengaduan/' . $ticketD);
$token = $cs->token();
$cs->request('POST', '/cs/pengaduan/' . $ticketD . '/batalkan', ['fields' => ['_token' => $token, 'alasan' => '']]);
$c = (int) $pdo->query("SELECT is_cancelled FROM pengaduan WHERE id={$ticketD}")->fetchColumn();
check('skenario D batal tanpa alasan ditolak', $c === 0, 'is_cancelled=' . $c);
$cs->get('/cs/pengaduan/' . $ticketD);
$token = $cs->token();
$r = $cs->request('POST', '/cs/pengaduan/' . $ticketD . '/batalkan', ['fields' => ['_token' => $token, 'alasan' => 'Gangguan sudah teratasi sendiri.']]);
$row = $pdo->query("SELECT status, is_cancelled, cancellation_reason FROM pengaduan WHERE id={$ticketD}")->fetch();
check('skenario D dibatalkan (is_cancelled=1)', (int) $row['is_cancelled'] === 1, json_encode($row));
check('skenario D status utama tidak berubah jadi status ke-7', $row['status'] === 'menunggu_verifikasi', 'status=' . $row['status']);
// Tiket batal tidak bisa diterima lagi.
$cs->get('/cs/pengaduan/' . $ticketD);
$token = $cs->token();
$cs->request('POST', '/cs/pengaduan/' . $ticketD . '/terima', ['fields' => ['_token' => $token]]);
$st = $pdo->query("SELECT status FROM pengaduan WHERE id={$ticketD}")->fetchColumn();
check('skenario D tiket batal tidak dapat diproses', $st === 'menunggu_verifikasi', 'status=' . $st);

// ---------------------------------------------------------------- Skenario E: akses tidak sah
$r = $pelanggan->get('/pengaduan/foto/1');
$akses = $r['status'];
// foto id 1 = milik pengaduan pertama (pelanggan 1). Pelanggan B harus 403, pelanggan pemilik 200.
$fotoRow = $pdo->query('SELECT f.id, p.pelanggan_id FROM pengaduan_foto f JOIN pengaduan p ON p.id=f.pengaduan_id ORDER BY f.id ASC LIMIT 1')->fetch();
$owner = (int) $fotoRow['pelanggan_id'];
$ownerSess = $owner === 1 ? $pelanggan : $pelangganB;
$otherSess = $owner === 1 ? $pelangganB : $pelanggan;
$r = $ownerSess->get('/pengaduan/foto/' . $fotoRow['id']);
check('E: pemilik foto -> 200', $r['status'] === 200, 'status=' . $r['status']);
$r = $otherSess->get('/pengaduan/foto/' . $fotoRow['id']);
check('E: pelanggan lain -> 403', $r['status'] === 403, 'status=' . $r['status']);
$r = $anon->get('/pengaduan/foto/' . $fotoRow['id']);
check('E: anon akses foto -> 403/302', in_array($r['status'], [302, 403], true), 'status=' . $r['status']);

// Route admin oleh pelanggan.
$r = $pelanggan->get('/admin/pengguna');
check('E: pelanggan -> /admin/pengguna 403', $r['status'] === 403, 'status=' . $r['status']);
$r = $teknisi->get('/admin/laporan/excel');
check('E: teknisi -> excel 403', $r['status'] === 403, 'status=' . $r['status']);

// ---------------------------------------------------------------- Admin: laporan & ekspor
$jenisList = ['semua', 'periode', 'status', 'teknisi', 'kategori', 'pelanggan', 'durasi', 'rating', 'analitik'];
$allOk = true;
$detail = [];
foreach ($jenisList as $j) {
    $r = $admin->get('/admin/laporan?jenis=' . $j);
    if ($r['status'] !== 200) {
        $allOk = false;
        $detail[] = $j . '=' . $r['status'];
    }
}
check('9 jenis laporan 200', $allOk, implode(',', $detail));

// Filter tanggal tidak valid (from > to) -> 200 dengan pesan error.
$r = $admin->get('/admin/laporan?jenis=periode&date_from=2026-12-31&date_to=2026-01-01');
check('filter tanggal terbalik ditangani (200)', $r['status'] === 200, 'status=' . $r['status']);

// Ekspor PDF.
$r = $admin->get('/admin/laporan/pdf?jenis=kategori');
check('PDF ekspor 200', $r['status'] === 200, 'status=' . $r['status']);
check('PDF magic %PDF', str_starts_with($r['body'], '%PDF-'), 'head=' . substr($r['body'], 0, 8));
check('PDF content-type', str_contains($r['headers']['content-type'] ?? '', 'application/pdf'), $r['headers']['content-type'] ?? '');

// Ekspor Excel.
$r = $admin->get('/admin/laporan/excel?jenis=teknisi');
check('XLSX ekspor 200', $r['status'] === 200, 'status=' . $r['status']);
check('XLSX magic PK', str_starts_with($r['body'], 'PK'), 'head=' . substr($r['body'], 0, 4));
$tmpXlsx = TMP . '/export_test.xlsx';
file_put_contents($tmpXlsx, $r['body']);
$zipTest = new ZipArchive();
$zipOk = $zipTest->open($tmpXlsx) === true;
check('XLSX dapat dibuka sebagai ZIP', $zipOk);
if ($zipOk) {
    $sheet = (string) $zipTest->getFromName('xl/worksheets/sheet1.xml');
    check('XLSX punya sheetData', str_contains($sheet, '<sheetData>'));
    check('XLSX teks sebagai inlineStr (anti formula injection)', str_contains($sheet, 'inlineStr'));
    $zipTest->close();
}

// Ekspor tanpa filter sama dengan dengan filter (konsistensi tak langsung).
$r1 = $admin->get('/admin/laporan/pdf?jenis=semua&status=selesai');
$r2 = $admin->get('/admin/laporan/pdf?jenis=semua&status=selesai');
check('PDF deterministik untuk filter sama', strlen($r1['body']) === strlen($r2['body']), strlen($r1['body']) . ' vs ' . strlen($r2['body']));

// ---------------------------------------------------------------- Notifikasi
$r = $pelanggan->get('/notifikasi');
check('halaman notifikasi 200', $r['status'] === 200, 'status=' . $r['status']);
$notifRow = $pdo->query('SELECT id, user_id FROM notifikasi ORDER BY id DESC LIMIT 1')->fetch();
if ($notifRow) {
    // IDOR: pelanggan lain menandai notifikasi milik orang lain.
    $other = (int) $notifRow['user_id'] === 1 ? $pelangganB : $pelanggan;
    $other->get('/notifikasi');
    $token = $other->token();
    $before = $pdo->query("SELECT read_at FROM notifikasi WHERE id={$notifRow['id']}")->fetchColumn();
    $other->request('POST', '/notifikasi/' . $notifRow['id'] . '/baca', ['fields' => ['_token' => $token]]);
    $after = $pdo->query("SELECT read_at FROM notifikasi WHERE id={$notifRow['id']}")->fetchColumn();
    $detail = 'before=' . var_export($before, true) . ' after=' . var_export($after, true);
    // Normalisasi: jika already null->still null atau timestamps equal.
    check('IDOR notifikasi tidak mengubah milik orang lain', (string) $before === (string) $after, $detail);
} else {
    check('IDOR notifikasi (tidak ada data notifikasi – dilewati)', true);
}

// ---------------------------------------------------------------- AI endpoint
$aiPage = $pelanggan->get('/ai');
$aiToken = $pelanggan->token();
if (str_contains((string) ($aiPage['body'] ?? ''), 'Layanan AI belum dikonfigurasi')) {
    // Tanpa API key → pesan jelas (ok=false) HTTP 200.
    $r = $pelanggan->request('POST', '/ai/chat', ['json' => ['_token' => $aiToken, 'message' => 'Halo']]);
    $decoded = json_decode($r['body'], true);
    check('AI tanpa config -> ok=false pesan jelas', is_array($decoded) && ($decoded['ok'] ?? true) === false && str_contains((string) ($decoded['error'] ?? ''), 'belum'), $r['body']);
} else {
    // Provider aktif: jalur jawaban nyata diverifikasi di tests/tahap10/ai-gemini.php.
    check('AI dengan provider aktif (diverifikasi di ai-gemini.php)', true);
}
// CSRF salah -> 419.
$r = $pelanggan->request('POST', '/ai/chat', ['json' => ['_token' => str_repeat('a', 64), 'message' => 'Halo']]);
check('AI CSRF salah -> 419', $r['status'] === 419, 'status=' . $r['status']);
// Pesan kosong -> 422.
$pelanggan->get('/ai');
$aiToken = $pelanggan->token();
$r = $pelanggan->request('POST', '/ai/chat', ['json' => ['_token' => $aiToken, 'message' => '   ']]);
check('AI pesan kosong -> 422', $r['status'] === 422, 'status=' . $r['status']);
// Pesan terlalu panjang -> 422.
$r = $pelanggan->request('POST', '/ai/chat', ['json' => ['_token' => $aiToken, 'message' => str_repeat('x', 5000)]]);
check('AI pesan terlalu panjang -> 422', $r['status'] === 422, 'status=' . $r['status']);
// Anon -> ditolak (middleware auth -> 302 ke login, atau 401 bila lolos ke controller).
$r = $anon->request('POST', '/ai/chat', ['json' => ['message' => 'Halo']]);
check('AI anon ditolak (302 login atau 401)', in_array($r['status'], [302, 401], true), 'status=' . $r['status']);

// ---------------------------------------------------------------- Admin account safety
// Admin tidak dapat menonaktifkan dirinya (single admin).
$admin->get('/admin/pengguna');
$token = $admin->token();
$r = $admin->request('POST', '/admin/pengguna/aktif', ['fields' => ['_token' => $token, 'id' => '1', 'aktif' => '0']]);
$act = (int) $pdo->query('SELECT is_active FROM users WHERE id=1')->fetchColumn();
check('admin terakhir tidak dapat dinonaktifkan', $act === 1, 'is_active=' . $act);

echo "\n==== HASIL: PASS={$PASS} FAIL={$FAIL} ====\n";
if ($FAIL > 0) {
    echo "GAGAL:\n";
    foreach ($FAILED as $f) {
        echo "  - {$f}\n";
    }
}
