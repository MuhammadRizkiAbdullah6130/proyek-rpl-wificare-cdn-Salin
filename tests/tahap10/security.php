<?php
/**
 * WiFiCare CDN — Tahap 10 edge/security tests (CLI) terhadap server uji.
 */

declare(strict_types=1);

require_once __DIR__ . '/_config.php';
t10_require_passwords(['T10_PW_ADMIN', 'T10_PW_CS', 'T10_PW_TEK', 'T10_PW_PEL']);
define('BASE', T10_BASE);
define('TMP', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_t10');
if (!is_dir(TMP)) { @mkdir(TMP, 0775, true); }

$PASS = 0; $FAIL = 0; $FAILED = [];

function check(string $name, bool $ok, string $detail = ''): void
{
    global $PASS, $FAIL, $FAILED;
    if ($ok) { $PASS++; echo "PASS  {$name}\n"; }
    else { $FAIL++; $FAILED[] = $name . ($detail !== '' ? " :: {$detail}" : ''); echo "FAIL  {$name}" . ($detail !== '' ? " :: {$detail}" : '') . "\n"; }
}

final class Sess
{
    public string $jar; public string $lastBody=''; public int $lastStatus=0; public array $lastHeaders=[]; public string $lastUrl='';
    public function __construct(public string $name)
    { $this->jar = TMP.'/edge_'.preg_replace('/[^a-z0-9]/i','_',$name).'.txt'; @unlink($this->jar); }
    public function request(string $method, string $path, array $opts=[]): array
    {
        $ch = curl_init();
        $url = str_starts_with($path,'http') ? $path : BASE.$path;
        curl_setopt_array($ch,[CURLOPT_URL=>$url,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIEJAR=>$this->jar,CURLOPT_COOKIEFILE=>$this->jar,CURLOPT_TIMEOUT=>30]);
        if ($method==='POST') curl_setopt($ch,CURLOPT_POST,true);
        if (isset($opts['fields'])) curl_setopt($ch,CURLOPT_POSTFIELDS,$opts['fields']);
        if (isset($opts['json'])) { curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($opts['json'])); curl_setopt($ch,CURLOPT_HTTPHEADER,array_merge($opts['headers']??[],['Content-Type: application/json'])); }
        elseif (isset($opts['headers'])) curl_setopt($ch,CURLOPT_HTTPHEADER,$opts['headers']);
        $raw = curl_exec($ch);
        if ($raw === false) { $this->lastStatus=0; $this->lastBody='CURL_ERROR'; curl_close($ch); return ['status'=>0,'body'=>'CURL_ERROR','headers'=>[]]; }
        $hs = curl_getinfo($ch,CURLINFO_HEADER_SIZE);
        $this->lastStatus=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        $this->lastUrl=(string)curl_getinfo($ch,CURLINFO_EFFECTIVE_URL);
        $ht=substr($raw,0,$hs); $this->lastBody=substr($raw,$hs); $this->lastHeaders=[];
        foreach (explode("\r\n",$ht) as $line) { if (str_contains($line,':')) { [$k,$v]=explode(':',$line,2); $this->lastHeaders[strtolower(trim($k))]=trim($v); } }
        curl_close($ch);
        return ['status'=>$this->lastStatus,'body'=>$this->lastBody,'headers'=>$this->lastHeaders];
    }
    public function get(string $p): array { return $this->request('GET',$p); }
    public function token(): string
    {
        if (preg_match('/name="_token"\s+value="([a-f0-9]{64})"/',$this->lastBody,$m)) return $m[1];
        if (preg_match('/name="csrf-token"\s+content="([a-f0-9]{64})"/',$this->lastBody,$m)) return $m[1];
        return '';
    }
    public function login(string $u,string $p): bool
    {
        $this->get('/login'); $t=$this->token();
        $r=$this->request('POST','/login',['fields'=>['_token'=>$t,'username'=>$u,'password'=>$p]]);
        return $r['status']===302 && !str_contains($r['headers']['location']??'','/login');
    }
}

$pdo = new PDO(T10_DB_DSN, T10_DB_USER, T10_DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);

// File uji.
$png = TMP.'/edge.png'; file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
$fakeImg = TMP.'/evil.png'; file_put_contents($fakeImg, "<?php echo 'pwned'; ?>\nThis is not an image.\n");

$admin=new Sess('admin'); $cs=new Sess('cs'); $tek=new Sess('tek'); $pel=new Sess('pel'); $pelB=new Sess('pelB'); $anon=new Sess('anon');
$admin->login('admin.budi',T10_PW_ADMIN); $cs->login('cs.dewi',T10_PW_CS);
$tek->login('tek.andi',T10_PW_TEK); $pel->login('pppoe.rina',T10_PW_PEL); $pelB->login('pppoe.agus',T10_PW_PEL);

echo "==== TAHAP 10 — EDGE/SECURITY ====\n";

// ---------------- XSS ----------------
$xss = '<script>alert("xss")</script><img src=x onerror=alert(1)>';
$pel->get('/pelanggan/pengaduan/baru'); $t=$pel->token();
$r=$pel->request('POST','/pelanggan/pengaduan',['fields'=>[
    '_token'=>$t,'nomor_whatsapp'=>'081200000001','kategori_id'=>'1',
    'deskripsi'=>'Uji XSS: '.$xss,'foto'=>new CURLFile($png,'image/png','x.png'),
]]);
$xid=(int)$pdo->query('SELECT MAX(id) FROM pengaduan')->fetchColumn();
check('XSS tiket dibuat',$r['status']===302,'status='.$r['status']);

foreach (['pelanggan'=>$pel,'cs'=>$cs,'admin'=>$admin] as $who=>$s) {
    $path = $who==='pelanggan' ? '/pelanggan/pengaduan/'.$xid : ($who==='cs' ? '/cs/pengaduan/'.$xid : '/admin/pengaduan/'.$xid);
    $s->get($path);
    $rawScript = str_contains($s->lastBody,'<script>alert("xss")</script>');
    $escaped = str_contains($s->lastBody,'&lt;script&gt;');
    check("XSS detail {$who} tidak mentah", !$rawScript, 'raw='.var_export($rawScript,true));
    check("XSS detail {$who} ter-escape", $escaped || !str_contains($s->lastBody,'alert(1)'), 'esc='.var_export($escaped,true));
}

// XSS pada laporan admin (pencarian deskripsi tidak boleh menjalankan skrip).
$admin->get('/admin/laporan?jenis=semua');
check('XSS laporan admin tidak mentah', !str_contains($admin->lastBody,'<script>alert("xss")</script>'));

// ---------------- Upload MIME ----------------
$pel->get('/pelanggan/pengaduan/baru'); $t=$pel->token();
$before=(int)$pdo->query('SELECT COUNT(*) FROM pengaduan')->fetchColumn();
$r=$pel->request('POST','/pelanggan/pengaduan',['fields'=>[
    '_token'=>$t,'nomor_whatsapp'=>'081200000001','kategori_id'=>'1',
    'deskripsi'=>'Uji upload file palsu','foto'=>new CURLFile($fakeImg,'image/png','evil.png'),
]]);
$after=(int)$pdo->query('SELECT COUNT(*) FROM pengaduan')->fetchColumn();
check('upload file non-gambar ditolak (tiket tidak dibuat)', $after===$before, "before={$before} after={$after}");

// Tanpa foto sama sekali -> ditolak.
$pel->get('/pelanggan/pengaduan/baru'); $t=$pel->token();
$before=(int)$pdo->query('SELECT COUNT(*) FROM pengaduan')->fetchColumn();
$pel->request('POST','/pelanggan/pengaduan',['fields'=>['_token'=>$t,'nomor_whatsapp'=>'081200000001','kategori_id'=>'1','deskripsi'=>'Tanpa foto valid panjang cukup']]);
$after=(int)$pdo->query('SELECT COUNT(*) FROM pengaduan')->fetchColumn();
check('pengaduan tanpa foto ditolak', $after===$before, "before={$before} after={$after}");

// ---------------- Transisi status ilegal ----------------
// XSS ticket (xid) statusnya menunggu_verifikasi. CS coba selesaikan langsung (ilegal).
$cs->get('/cs/pengaduan/'.$xid); $t=$cs->token();
$cs->request('POST','/cs/pengaduan/'.$xid.'/selesaikan',['fields'=>['_token'=>$t,'solusi'=>'coba ilegal']]);
$st=$pdo->query("SELECT status FROM pengaduan WHERE id={$xid}")->fetchColumn();
check('selesaikan dari menunggu_verifikasi ditolak', $st==='menunggu_verifikasi','status='.$st);
// CS coba tugaskan dari menunggu_verifikasi (harus dari diproses_cs).
$cs->get('/cs/pengaduan/'.$xid); $t=$cs->token();
$cs->request('POST','/cs/pengaduan/'.$xid.'/tugaskan',['fields'=>['_token'=>$t,'teknisi_id'=>'2']]);
$st=$pdo->query("SELECT status FROM pengaduan WHERE id={$xid}")->fetchColumn();
$asg=(int)$pdo->query("SELECT COUNT(*) FROM penugasan_teknisi WHERE pengaduan_id={$xid}")->fetchColumn();
check('tugaskan dari menunggu_verifikasi ditolak', $st==='menunggu_verifikasi' && $asg===0, "status={$st} asg={$asg}");

// Teknisi coba mulai tiket yang bukan tugasnya.
$r=$tek->request('POST','/teknisi/tugas/'.$xid.'/mulai',['fields'=>['_token'=>'x']]);
check('teknisi mulai tiket bukan miliknya ditolak', $r['status']===403 || $r['status']===419, 'status='.$r['status']);

// ---------------- Route robustness ----------------
$r=$anon->get('/tidak-ada-route');
check('route tak dikenal -> 404', $r['status']===404, 'status='.$r['status']);
foreach (['/pelanggan/pengaduan/abc','/pelanggan/pengaduan/999999','/admin/pengaduan/999999','/cs/pengaduan/999999'] as $p) {
    $s = str_contains($p,'/admin/')?$admin:(str_contains($p,'/cs/')?$cs:$pel);
    $r=$s->get($p);
    check("robust {$p}", in_array($r['status'],[200,302,403,404],true), 'status='.$r['status']);
}
$r=$admin->get('/admin/laporan?jenis=tidakada&page=99999');
check('jenis laporan tak dikenal tidak 500', $r['status']===200, 'status='.$r['status']);
$r=$admin->get('/admin/laporan?jenis=semua&page=-5');
check('page negatif tidak 500', $r['status']===200, 'status='.$r['status']);

// ---------------- Login input aneh ----------------
$r=$anon->request('POST','/login',['fields'=>['_token'=>'x','username[]'=>'a','password'=>'b']]);
check('login array injection tidak 500', $r['status']!==500, 'status='.$r['status']);

// ---------------- Rating pada tiket dibatalkan ----------------
$cancelId=(int)$pdo->query('SELECT id FROM pengaduan WHERE is_cancelled=1 LIMIT 1')->fetchColumn();
if ($cancelId>0) {
    $owner=(int)$pdo->query("SELECT pelanggan_id FROM pengaduan WHERE id={$cancelId}")->fetchColumn();
    $ownerName=(string)$pdo->query("SELECT u.username FROM users u JOIN pelanggan pl ON pl.user_id=u.id WHERE pl.id={$owner}")->fetchColumn();
    $s = $ownerName==='pppoe.rina' ? $pel : $pelB;
    $ratingPage=$s->get('/pelanggan/rating'); $t=$s->token();
    $before=(int)$pdo->query("SELECT COUNT(*) FROM rating_layanan WHERE pengaduan_id={$cancelId}")->fetchColumn();
    $ratingPost=$s->request('POST','/pelanggan/pengaduan/'.$cancelId.'/rating',['fields'=>['_token'=>$t,'rating'=>'5']]);
    $after=(int)$pdo->query("SELECT COUNT(*) FROM rating_layanan WHERE pengaduan_id={$cancelId}")->fetchColumn();
    check('halaman rating ditutup sementara (404)', $ratingPage['status']===404, 'status='.$ratingPage['status']);
    check('endpoint rating tiket dibatalkan ditutup (404)', $ratingPost['status']===404, 'status='.$ratingPost['status']);
    check('rating tiket dibatalkan tidak tersimpan', $after===$before, "before={$before} after={$after}");
}

// ---------------- Audit log & kebocoran rahasia ----------------
$r=$admin->get('/admin/audit');
check('halaman audit 200', $r['status']===200, 'status='.$r['status']);
check('audit tidak memuat hash password', !str_contains((string)($r['body'] ?? ''),'$2y$') && !str_contains((string)($r['body'] ?? ''),'password_hash'));

// Audit log tidak boleh menyimpan password.
$leakStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM audit_log
     WHERE LOCATE(:admin, metadata) > 0
        OR LOCATE(:cs, metadata) > 0
        OR LOCATE(:tek, metadata) > 0
        OR LOCATE(:pel, metadata) > 0'
);
$leakStmt->execute([
    'admin' => T10_PW_ADMIN,
    'cs' => T10_PW_CS,
    'tek' => T10_PW_TEK,
    'pel' => T10_PW_PEL,
]);
$leak = (int) $leakStmt->fetchColumn();
check('audit_log tidak menyimpan password mentah', $leak===0, 'matches='.$leak);

// ---------------- Notifikasi: tandai semua dibaca hanya milik sendiri ----------------
$r=$pel->get('/notifikasi'); $t=$pel->token();
$otherBefore=(int)$pdo->query('SELECT COUNT(*) FROM notifikasi WHERE user_id<>'.$pdo->query('SELECT id FROM users WHERE username=\'pppoe.rina\'')->fetchColumn().' AND read_at IS NULL')->fetchColumn();
$pel->request('POST','/notifikasi/baca-semua',['fields'=>['_token'=>$t]]);
$otherAfter=(int)$pdo->query('SELECT COUNT(*) FROM notifikasi WHERE user_id<>'.$pdo->query('SELECT id FROM users WHERE username=\'pppoe.rina\'')->fetchColumn().' AND read_at IS NULL')->fetchColumn();
check('baca-semua tidak menyentuh notifikasi pengguna lain', $otherBefore===$otherAfter, "before={$otherBefore} after={$otherAfter}");

// ---------------- Laporan: ekspor dengan filter tidak valid ----------------
$r=$admin->get('/admin/laporan/excel?jenis=periode&date_from=bad&date_to=bad');
check('excel filter tanggal invalid tetap 200', $r['status']===200, 'status='.$r['status']);
check('excel magic PK', str_starts_with($r['body'],'PK'), 'head='.substr($r['body'],0,4));

// ---------------- Halaman detail Admin (regresi view) ----------------
$pelId=(int)$pdo->query("SELECT p.id FROM pelanggan p JOIN users u ON u.id=p.user_id WHERE u.username='pppoe.rina' LIMIT 1")->fetchColumn();
$r=$admin->get('/admin/pelanggan/'.$pelId);
check('admin detail pelanggan 200', $r['status']===200, 'status='.$r['status']);
check('admin detail pelanggan tanpa warning/fatal', !preg_match('/Warning:|Fatal error|Parse error|Array to string/', $r['body']));
check('admin detail pelanggan memuat nama pelanggan', str_contains($r['body'],'Rina'));
$r=$admin->get('/admin/pelanggan/999999');
check('admin detail pelanggan tak ada -> 404', $r['status']===404, 'status='.$r['status']);
$pengId=(int)$pdo->query('SELECT id FROM pengaduan ORDER BY id ASC LIMIT 1')->fetchColumn();
$r=$admin->get('/admin/pengaduan/'.$pengId);
check('admin detail pengaduan 200 tanpa warning', $r['status']===200 && !preg_match('/Warning:|Fatal error/',$r['body']), 'status='.$r['status']);
$tekId=(int)$pdo->query("SELECT id FROM users WHERE role='teknisi' ORDER BY id ASC LIMIT 1")->fetchColumn();
$r=$admin->get('/admin/teknisi/'.$tekId);
check('admin detail teknisi 200 tanpa warning', $r['status']===200 && !preg_match('/Warning:|Fatal error/',$r['body']), 'status='.$r['status']);

echo "\n==== HASIL EDGE: PASS={$PASS} FAIL={$FAIL} ====\n";
if ($FAIL>0) { echo "GAGAL:\n"; foreach ($FAILED as $f) echo "  - {$f}\n"; }
