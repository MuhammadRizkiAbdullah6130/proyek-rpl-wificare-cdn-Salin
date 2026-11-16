<?php
/** WiFiCare CDN — Tahap 10 auth tests (CLI). */
declare(strict_types=1);
require_once __DIR__ . '/_config.php';
t10_require_passwords(['T10_PW_TEK', 'T10_PW_PEL']);
define('BASE', T10_BASE);
define('TMP', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_t10');
if (!is_dir(TMP)) { @mkdir(TMP, 0775, true); }
$PASS=0;$FAIL=0;$FAILED=[];
function check(string $n,bool $ok,string $d=''):void{global $PASS,$FAIL,$FAILED;if($ok){$PASS++;echo "PASS  {$n}\n";}else{$FAIL++;$FAILED[]=$n.($d!==''?" :: {$d}":'');echo "FAIL  {$n}".($d!==''?" :: {$d}":'')."\n";}}

final class Sess{
  public string $jar; public string $lastBody=''; public int $lastStatus=0; public array $lastHeaders=[];
  public function __construct(public string $name){$this->jar=TMP.'/auth_'.preg_replace('/[^a-z0-9]/i','_',$name).'.txt';@unlink($this->jar);}
  public function req(string $m,string $p,array $o=[]):array{
    $ch=curl_init();$url=BASE.$p;
    curl_setopt_array($ch,[CURLOPT_URL=>$url,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIEJAR=>$this->jar,CURLOPT_COOKIEFILE=>$this->jar,CURLOPT_TIMEOUT=>30]);
    if($m==='POST')curl_setopt($ch,CURLOPT_POST,true);
    if(isset($o['fields']))curl_setopt($ch,CURLOPT_POSTFIELDS,$o['fields']);
    $raw=curl_exec($ch); if($raw===false){curl_close($ch);return ['status'=>0,'body'=>'','headers'=>[]];}
    $hs=curl_getinfo($ch,CURLINFO_HEADER_SIZE);$this->lastStatus=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $ht=substr($raw,0,$hs);$this->lastBody=substr($raw,$hs);$this->lastHeaders=[];
    foreach(explode("\r\n",$ht) as $l){if(str_contains($l,':')){[$k,$v]=explode(':',$l,2);$this->lastHeaders[strtolower(trim($k))]=trim($v);}}
    curl_close($ch); return ['status'=>$this->lastStatus,'body'=>$this->lastBody,'headers'=>$this->lastHeaders];
  }
  public function get(string $p):array{return $this->req('GET',$p);}
  public function token():string{if(preg_match('/name="_token"\s+value="([a-f0-9]{64})"/',$this->lastBody,$m))return $m[1];if(preg_match('/name="csrf-token"\s+content="([a-f0-9]{64})"/',$this->lastBody,$m))return $m[1];return '';}
  public function loginTry(string $u,string $p):array{$this->get('/login');$t=$this->token();return $this->req('POST','/login',['fields'=>['_token'=>$t,'username'=>$u,'password'=>$p]]);}
  public function login(string $u,string $p):bool{$r=$this->loginTry($u,$p);return $r['status']===302&&!str_contains($r['headers']['location']??'','/login');}
  public function flashAfterRedirect():string{$this->get('/login');return $this->lastBody;}
}

$pdo=new PDO(T10_DB_DSN, T10_DB_USER, T10_DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
echo "==== TAHAP 10 — AUTH ====\n";

// ---- 1. Brute force throttle ----
$pdo->exec("DELETE FROM login_attempts WHERE username='tek.bayu'");
$s=new Sess('bf');
for($i=0;$i<5;$i++){ $s->loginTry('tek.bayu','SALAH'.$i); }
$r=$s->loginTry('tek.bayu',T10_PW_TEK); // ke-6: harus terkunci walau password benar
check('brute force: percobaan ke-6 terkunci walau password benar', $r['status']===302 && str_contains($r['headers']['location']??'','/login'), 'status='.$r['status'].' loc='.($r['headers']['location']??''));
$msg=$s->flashAfterRedirect();
check('brute force: pesan terkunci tampil', str_contains($msg,'Terlalu banyak'), 'cek body');
$pdo->exec("DELETE FROM login_attempts WHERE username='tek.bayu'");
// Setelah dibersihkan, login benar berhasil.
$s2=new Sess('bf2');
check('setelah reset percobaan, login benar berhasil', $s2->login('tek.bayu',T10_PW_TEK));

// ---- 2. Akun nonaktif tidak dapat login ----
$agusUserId=(int)$pdo->query("SELECT id FROM users WHERE username='pppoe.agus'")->fetchColumn();
$pdo->exec("UPDATE users SET is_active=0 WHERE id={$agusUserId}");
$s3=new Sess('inactive');
$r=$s3->loginTry('pppoe.agus',T10_PW_PEL);
check('akun nonaktif tidak dapat login', $r['status']===302 && str_contains($r['headers']['location']??'','/login'), 'status='.$r['status']);
check('akun nonaktif: pesan dinonaktifkan', str_contains($s3->flashAfterRedirect(),'dinonaktifkan'));
$pdo->exec("UPDATE users SET is_active=1 WHERE id={$agusUserId}");

// ---- 3. Sesi diakhiri bila akun dinonaktifkan setelah login ----
$s4=new Sess('sess');
check('login sebelum dinonaktifkan',$s4->login('pppoe.agus',T10_PW_PEL));
$r=$s4->get('/pelanggan/dashboard'); check('dashboard aktif 200 sebelumnya',$r['status']===200,'status='.$r['status']);
$pdo->exec("UPDATE users SET is_active=0 WHERE id={$agusUserId}");
$r=$s4->get('/pelanggan/dashboard');
check('setelah dinonaktifkan, sesi dipaksa keluar (302 login)', $r['status']===302 && str_contains($r['headers']['location']??'','/login'), 'status='.$r['status'].' loc='.($r['headers']['location']??''));
$pdo->exec("UPDATE users SET is_active=1 WHERE id={$agusUserId}");

// ---- 4. Logout menghapus sesi ----
$s5=new Sess('logout');
check('login untuk logout',$s5->login('pppoe.rina',T10_PW_PEL));
$s5->get('/pelanggan/dashboard'); $t=$s5->token();
$r=$s5->req('POST','/logout',['fields'=>['_token'=>$t]]);
check('logout -> 302 login',$r['status']===302 && str_contains($r['headers']['location']??'','/login'),'status='.$r['status']);
$r=$s5->get('/pelanggan/dashboard');
check('setelah logout tidak bisa akses dashboard',$r['status']===302 && str_contains($r['headers']['location']??'','/login'),'status='.$r['status']);

// ---- 5. Session fixation: ID berubah setelah login ----
$s6=new Sess('fixation');
$s6->get('/login');
$jarBefore=@file_get_contents($s6->jar);
$s6->login('pppoe.rina',T10_PW_PEL);
$jarAfter=@file_get_contents($s6->jar);
check('session ID berubah setelah login (anti fixation)', $jarBefore!==$jarAfter);

// ---- 6. Tidak ada registrasi publik ----
$anon=new Sess('anon2');
foreach(['/register','/daftar','/signup'] as $p){ $r=$anon->get($p); check("tidak ada route registrasi {$p}", $r['status']===404, 'status='.$r['status']); }

echo "\n==== HASIL AUTH: PASS={$PASS} FAIL={$FAIL} ====\n";
if($FAIL>0){echo "GAGAL:\n";foreach($FAILED as $f)echo "  - {$f}\n";}
