<?php
/**
 * WiFiCare CDN — Uji Perbaikan 7 Bug & Revisi Final (Master Prompt).
 *
 * Cakupan:
 *  - BUG 1 : SQLSTATE[HY093] pada pencarian (placeholder `:q` dipakai berulang).
 *  - BUG 2 : identitas pelanggan (Heri melihat Heri, Dewi melihat Dewi).
 *  - BUG 3 : unggah foto pengaduan benar-benar tersimpan (nama field `foto[]`).
 *  - REVISI 4 : pemisahan Data Pelanggan vs Data Karyawan.
 *  - REVISI 5 : penyederhanaan filter Semua Pengaduan (Cari + Status + Periode).
 *  - REVISI 6 : penyederhanaan filter Laporan (Jenis + Periode, default Bulan Ini).
 *  - REVISI 7 : kerapian layout Laporan & Analitik.
 *
 * Prasyarat: server uji di http://localhost:8099 (DB uji wificare_cdn_test).
 * Jalankan: php tests/tahap12/prompt7.php
 *
 * Catatan: sebagian pemeriksaan model dijalankan langsung (read-only) pada DB
 * bawaan aplikasi, sebagian lagi lewat HTTP ke server uji.
 */

declare(strict_types=1);

require __DIR__ . '/../tahap10/_config.php';
t10_require_passwords(['T10_PW_ADMIN', 'T10_PW_PEL']);

define('P7_BASE', T10_BASE);
define('P7_PREFIX', '/aduan-pelanggan/public'); // url() memakai APP_BASE_PATH
define('P7_TMP', sys_get_temp_dir());

$PASS = 0;
$FAIL = 0;
$FAILED = [];

function p7_check(string $name, bool $ok, string $detail = ''): void
{
    global $PASS, $FAIL, $FAILED;
    if ($ok) {
        $PASS++;
        echo "PASS  {$name}\n";

        return;
    }

    $FAIL++;
    $FAILED[] = $name . ($detail !== '' ? ' :: ' . $detail : '');
    echo "FAIL  {$name}" . ($detail !== '' ? ' :: ' . $detail : '') . "\n";
}

/**
 * Klien HTTP sederhana dengan cookie jar.
 */
final class P7Client
{
    private string $jar;

    public function __construct(string $tag)
    {
        $this->jar = P7_TMP . '/p7_' . $tag . '.txt';
        @unlink($this->jar);
    }

    /** @param array<string, mixed>|null $post */
    public function request(string $method, string $path, ?array $post = null, bool $multipart = false, bool $raw = false): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => P7_BASE . $path,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_TIMEOUT => 60,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $post : http_build_query($post ?? []));
        }
        $res = curl_exec($ch);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        return [
            'status' => $status,
            'body' => $raw ? (string) $res : substr((string) $res, $headerSize),
            'headers' => substr((string) $res, 0, $headerSize),
            'type' => $type,
        ];
    }

    public function get(string $path, bool $raw = false): array
    {
        return $this->request('GET', $path, null, false, $raw);
    }

    /** @param array<string, mixed> $post */
    public function post(string $path, array $post, bool $multipart = false): array
    {
        return $this->request('POST', $path, $post, $multipart);
    }

    public function login(string $username, string $password): array
    {
        $page = $this->get('/login');
        $token = self::token($page['body']);

        return $this->post('/login', ['_token' => $token, 'username' => $username, 'password' => $password]);
    }

    public static function token(string $html): string
    {
        return preg_match('/name="_token"\s+value="([a-f0-9]{64})"/', $html, $m) ? $m[1] : '';
    }

    public static function location(array $response): string
    {
        return preg_match('/^Location:\s*(.+)$/mi', $response['headers'], $m) ? trim($m[1]) : '';
    }
}

function p7_has_error(array $response): bool
{
    return str_contains($response['body'], 'PDOException')
        || str_contains($response['body'], 'SQLSTATE')
        || $response['status'] >= 500;
}

/* =========================================================================
 * BUG 1 — HY093 pada pencarian (langsung pada model, read-only)
 * ====================================================================== */
echo "--- BUG 1: pencarian tanpa SQLSTATE[HY093] ---\n";
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

$modelCases = [
    'User::paginateForAdmin' => static fn () => User::paginateForAdmin(['q' => 'a'], 1, 10),
    'Pelanggan::paginateForAdmin' => static fn () => Pelanggan::paginateForAdmin(['q' => 'a'], 1, 10),
    'Pelanggan::paginateForCs' => static fn () => Pelanggan::paginateForCs(['q' => 'a'], 1, 10),
    'Pengaduan::paginateForAdmin' => static fn () => Pengaduan::paginateForAdmin(['q' => 'a'], 1, 10),
];
foreach ($modelCases as $label => $fn) {
    try {
        $result = $fn();
        p7_check('BUG1 ' . $label . ' berhasil', isset($result['total']));
    } catch (Throwable $e) {
        p7_check('BUG1 ' . $label . ' berhasil', false, get_class($e) . ': ' . $e->getMessage());
    }
}

/* =========================================================================
 * HTTP — login admin pada server uji
 * ====================================================================== */
$admin = new P7Client('admin');
$login = $admin->login('admin.budi', T10_PW_ADMIN);
p7_check('Login admin berhasil', $login['status'] === 302, 'status=' . $login['status']);

// BUG1 lewat HTTP pada seluruh daftar admin.
echo "--- BUG 1: pencarian via HTTP ---\n";
foreach ([
    '/admin/pengguna?q=budi',
    '/admin/pelanggan?q=a',
    '/admin/kategori?q=g',
    '/admin/audit?q=login',
    '/admin/pengaduan?q=r',
    '/admin/teknisi?q=a',
] as $path) {
    $r = $admin->get(P7_PREFIX . $path);
    p7_check('BUG1 HTTP ' . $path . ' tanpa error', $r['status'] === 200 && !p7_has_error($r), 'status=' . $r['status']);
}

/* =========================================================================
 * REVISI 4 — Data Pelanggan vs Data Karyawan
 * ====================================================================== */
echo "--- REVISI 4: Data Pelanggan vs Data Karyawan ---\n";
$karyawan = $admin->get(P7_PREFIX . '/admin/pengguna');
p7_check('R4 halaman Data Karyawan tampil', $karyawan['status'] === 200 && str_contains($karyawan['body'], 'Data Karyawan'));
p7_check('R4 label "Tambah Karyawan"', str_contains($karyawan['body'], 'Tambah Karyawan'));
p7_check('R4 tidak ada "Manajemen Pengguna"', !str_contains($karyawan['body'], 'Manajemen Pengguna'));
p7_check('R4 tidak menawarkan peran pelanggan', !str_contains($karyawan['body'], 'value="pelanggan"'));
$pelangganPage = $admin->get(P7_PREFIX . '/admin/pelanggan');
p7_check('R4 halaman Data Pelanggan tampil', $pelangganPage['status'] === 200 && str_contains($pelangganPage['body'], 'Data Pelanggan'));
p7_check('R4 sidebar memuat keduanya', str_contains($karyawan['body'], 'Data Karyawan') && str_contains($karyawan['body'], 'Data Pelanggan'));

/* =========================================================================
 * REVISI 5 — filter Semua Pengaduan
 * ====================================================================== */
echo "--- REVISI 5: filter Semua Pengaduan ---\n";
$pengaduan = $admin->get(P7_PREFIX . '/admin/pengaduan');
p7_check('R5 halaman 200', $pengaduan['status'] === 200 && !p7_has_error($pengaduan));
p7_check('R5 placeholder pencarian', str_contains($pengaduan['body'], 'Cari nomor tiket, pelanggan, atau PPPoE'));
p7_check('R5 select Status ada', str_contains($pengaduan['body'], 'name="status"'));
p7_check('R5 select Periode ada', str_contains($pengaduan['body'], 'name="periode"'));
p7_check('R5 tanpa filter Kategori', !str_contains($pengaduan['body'], 'name="kategori_id"'));
p7_check('R5 tanpa filter Teknisi', !str_contains($pengaduan['body'], 'name="teknisi_id"'));
foreach (['hari_ini', '7_hari', 'bulan_ini'] as $periode) {
    $r = $admin->get(P7_PREFIX . '/admin/pengaduan?periode=' . $periode);
    p7_check('R5 periode=' . $periode . ' OK', $r['status'] === 200 && !p7_has_error($r));
}

/* =========================================================================
 * REVISI 6 & 7 — filter & layout Laporan
 * ====================================================================== */
echo "--- REVISI 6 & 7: Laporan & Analitik ---\n";
$laporan = $admin->get(P7_PREFIX . '/admin/laporan');
p7_check('R7 halaman 200', $laporan['status'] === 200 && !p7_has_error($laporan));
p7_check('R7 subjudul operasional', str_contains($laporan['body'], 'Ringkasan data operasional WiFiCare CDN.'));
p7_check('R6 select Jenis Laporan', str_contains($laporan['body'], 'Jenis Laporan'));
p7_check('R6 opsi Pilih Tanggal Sendiri', str_contains($laporan['body'], 'Pilih Tanggal Sendiri'));
foreach (['Total Pengaduan', 'Selesai', 'Dalam Proses', 'Rata-rata Rating'] as $card) {
    p7_check('R7 kartu "' . $card . '"', str_contains($laporan['body'], $card));
}
p7_check('R6 tautan Export PDF', str_contains($laporan['body'], 'Export PDF'));
p7_check('R6 tautan Export Excel', str_contains($laporan['body'], 'Export Excel'));
p7_check('R6 tanpa filter lama status', !str_contains($laporan['body'], 'name="status"'));
foreach (['semua', 'periode', 'status', 'teknisi', 'kategori', 'pelanggan', 'durasi', 'rating', 'analitik'] as $jenis) {
    $r = $admin->get(P7_PREFIX . '/admin/laporan?jenis=' . $jenis);
    p7_check('R6 jenis=' . $jenis . ' OK', $r['status'] === 200 && !p7_has_error($r));
}
foreach (['hari_ini', '7_hari', 'bulan_ini', 'bulan_lalu', 'tahun_ini', 'semua'] as $rentang) {
    $r = $admin->get(P7_PREFIX . '/admin/laporan?rentang=' . $rentang);
    p7_check('R6 rentang=' . $rentang . ' OK', $r['status'] === 200 && !p7_has_error($r));
}
$custom = $admin->get(P7_PREFIX . '/admin/laporan?rentang=pilih_tanggal&date_from=2026-01-01&date_to=2026-12-31');
p7_check('R6 tanggal kustom OK', $custom['status'] === 200 && !p7_has_error($custom));
$invalid = $admin->get(P7_PREFIX . '/admin/laporan?rentang=pilih_tanggal&date_from=2026-12-31&date_to=2026-01-01');
p7_check('R6 tanggal terbalik ditangani', $invalid['status'] === 200 && str_contains($invalid['body'], 'Tanggal awal tidak boleh'));
$pdf = $admin->get(P7_PREFIX . '/admin/laporan/pdf?jenis=semua&rentang=bulan_ini', true);
p7_check('R6 Export PDF', str_contains($pdf['type'], 'application/pdf') && strlen($pdf['body']) > 100, 'type=' . $pdf['type']);
$xlsx = $admin->get(P7_PREFIX . '/admin/laporan/excel?jenis=semua&rentang=bulan_ini', true);
p7_check('R6 Export Excel', str_contains($xlsx['type'], 'spreadsheet') && strlen($xlsx['body']) > 100, 'type=' . $xlsx['type']);

/* =========================================================================
 * BUG 2 — identitas pelanggan
 * ====================================================================== */
echo "--- BUG 2: identitas pelanggan ---\n";
$rina = new P7Client('rina');
$rina->login('pppoe.rina', T10_PW_PEL);
$profil = $rina->get(P7_PREFIX . '/pelanggan/profil');
p7_check('BUG2 rina melihat namanya', str_contains($profil['body'], 'value="Rina Marlina"'), 'status=' . $profil['status']);
p7_check('BUG2 rina melihat PPPoE-nya', str_contains($profil['body'], 'value="pppoe.rina"'));
p7_check('BUG2 tidak melihat Dewi', !str_contains($profil['body'], 'Dewi Lestari') && !str_contains($profil['body'], 'dewi.pppoe'));
p7_check('BUG2 tanpa badge DEMO', !str_contains($profil['body'], 'demo-badge'));
p7_check('BUG2 tanpa data contoh lama', !str_contains($profil['body'], '0812-3456-7890'));

// Ubah nama lalu kembalikan (hanya data sendiri yang berubah).
$token = P7Client::token($profil['body']);
$rina->post(P7_PREFIX . '/pelanggan/profil', ['_token' => $token, 'nama' => 'Rina Marlina Uji']);
$after = $rina->get(P7_PREFIX . '/pelanggan/profil');
p7_check('BUG2 nama berubah menjadi data sendiri', str_contains($after['body'], 'value="Rina Marlina Uji"'));

// Nomor WhatsApp dikunci: request manipulatif tidak boleh mengubah nomor aktif.
$rina->post(P7_PREFIX . '/pelanggan/profil', [
    '_token' => P7Client::token($after['body']),
    'nama' => 'Rina Marlina Uji',
    'nomor_whatsapp' => '081200009999',
]);
$afterAttempt = $rina->get(P7_PREFIX . '/pelanggan/profil');
p7_check('BUG2 whatsapp tidak berubah lewat Profil', str_contains($afterAttempt['body'], 'value="081200000001"'));

$rina->post(P7_PREFIX . '/pelanggan/profil', ['_token' => P7Client::token($afterAttempt['body']), 'nama' => 'Rina Marlina']);
$restored = $rina->get(P7_PREFIX . '/pelanggan/profil');
p7_check('BUG2 data dipulihkan', str_contains($restored['body'], 'value="Rina Marlina"') && str_contains($restored['body'], 'value="081200000001"'));

$agus = new P7Client('agus');
$agus->login('pppoe.agus', T10_PW_PEL);
$agusProfil = $agus->get(P7_PREFIX . '/pelanggan/profil');
p7_check('BUG2 agus melihat namanya', str_contains($agusProfil['body'], 'value="Agus Setiawan"'));
p7_check('BUG2 agus tidak melihat data rina', !str_contains($agusProfil['body'], 'Rina Marlina') && !str_contains($agusProfil['body'], 'pppoe.rina'));

/* =========================================================================
 * BUG 3 — unggah foto pengaduan
 * ====================================================================== */
echo "--- BUG 3: unggah foto pengaduan ---\n";
$form = $rina->get(P7_PREFIX . '/pelanggan/pengaduan/baru');
p7_check('BUG3 input berkas bernama foto[]', (bool) preg_match('/<input[^>]*type="file"[^>]*name="foto\[\]"/', $form['body']), 'form len=' . strlen($form['body']));
$formToken = P7Client::token($form['body']);
preg_match('/name="kategori_id"[\s\S]*?<option value="(\d+)"/', $form['body'], $mk);
$kategori = $mk[1] ?? '1';

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC');
$pngFile = P7_TMP . '/p7_valid.png';
file_put_contents($pngFile, $png);
$badFile = P7_TMP . '/p7_bad.txt';
file_put_contents($badFile, 'bukan gambar');

// Tanpa foto -> ditolak.
$noFile = $rina->post(P7_PREFIX . '/pelanggan/pengaduan', [
    '_token' => $formToken, 'nomor_whatsapp' => '081200000001',
    'kategori_id' => $kategori, 'deskripsi' => 'Uji tanpa foto, harus ditolak.',
]);
p7_check('BUG3 tanpa foto tidak membuat tiket', str_contains(P7Client::location($noFile), '/baru'));

// Berkas tidak valid -> ditolak.
$bad = $rina->post(P7_PREFIX . '/pelanggan/pengaduan', [
    '_token' => $formToken, 'nomor_whatsapp' => '081200000001',
    'kategori_id' => $kategori, 'deskripsi' => 'Uji berkas tidak valid, harus ditolak.',
    'foto[]' => new CURLFile($badFile, 'text/plain', 'evil.txt'),
], true);
p7_check('BUG3 berkas tidak valid tidak membuat tiket', str_contains(P7Client::location($bad), '/baru'));

// PNG valid -> tiket + foto tersimpan.
$ok = $rina->post(P7_PREFIX . '/pelanggan/pengaduan', [
    '_token' => $formToken, 'nomor_whatsapp' => '081200000001',
    'kategori_id' => $kategori, 'deskripsi' => 'Uji unggah foto PNG valid via form nyata.',
    'foto[]' => new CURLFile($pngFile, 'image/png', 'bukti.png'),
], true);
$loc = P7Client::location($ok);
p7_check('BUG3 PNG valid membuat tiket', (bool) preg_match('#/pelanggan/pengaduan/\d+#', $loc), $loc);
$detailPath = parse_url($loc, PHP_URL_PATH) ?: '';
$detail = $rina->get($detailPath);
preg_match('/WFC-\d{8}-\d{6}/', $detail['body'], $mt);
$nomor = $mt[0] ?? '';
preg_match('#/pengaduan/foto/(\d+)#', $detail['body'], $mf);
$fotoId = $mf[1] ?? '0';
p7_check('BUG3 detail menampilkan nomor tiket', $nomor !== '');
p7_check('BUG3 detail memuat foto', $fotoId !== '0');
$riwayat = $rina->get(P7_PREFIX . '/pelanggan/pengaduan');
p7_check('BUG3 tiket muncul di riwayat', $nomor !== '' && str_contains($riwayat['body'], $nomor));
$foto = $rina->get(P7_PREFIX . '/pengaduan/foto/' . $fotoId, true);
p7_check('BUG3 pemilik dapat membuka foto', $foto['status'] === 200 && str_contains(strtolower($foto['type']), 'image/png'), 'status=' . $foto['status']);
$fotoLain = $agus->get(P7_PREFIX . '/pengaduan/foto/' . $fotoId, true);
p7_check('BUG3 pelanggan lain ditolak (403)', $fotoLain['status'] === 403, 'status=' . $fotoLain['status']);

/* =========================================================================
 * Hasil
 * ====================================================================== */
echo "----------------------------------------------------------------\n";
echo 'TOTAL: ' . ($PASS + $FAIL) . " | PASS: {$PASS} | FAIL: {$FAIL}\n";
if ($FAIL > 0) {
    echo "GAGAL:\n";
    foreach ($FAILED as $item) {
        echo '  - ' . $item . "\n";
    }
    exit(1);
}
echo "SEMUA TES PERBAIKAN 7 BUG & REVISI FINAL LULUS.\n";
