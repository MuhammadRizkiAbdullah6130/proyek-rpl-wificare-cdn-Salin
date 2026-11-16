<?php
/**
 * WiFiCare CDN - Tahap 21, Tahap 1: kunci edit nomor WhatsApp di Profil.
 *
 * Fokus pengujian:
 * - Form Profil tidak lagi mengirim field nomor_whatsapp yang dapat diedit.
 * - Perubahan nama tetap berhasil.
 * - POST manipulatif yang mencoba mengganti nomor ditolak.
 * - Nomor active TIDAK berubah, baik pada request normal maupun request palsu.
 * - Form lama yang mengirim nomor sama (format berbeda) tetap kompatibel.
 * - CSRF dan RBAC dasar tetap berlaku.
 *
 * Prasyarat:
 *   - database uji wificare_cdn_test;
 *   - server uji di http://localhost:8099 (dapat ditimpa T10_BASE).
 *
 * Jalankan:
 *   php tests/tahap21/profile-phone-lock.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../tahap10/_config.php';

$PASS = 0;
$FAIL = 0;
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

function fail_blocked(string $message): void
{
    echo "BLOCKED  {$message}\n";
    exit(2);
}

final class ProfileSession
{
    private string $jar;

    public function __construct(string $name)
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_t21';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $this->jar = $dir . DIRECTORY_SEPARATOR . 'profile_' . preg_replace('/[^a-z0-9]/i', '_', $name) . '.txt';
        @unlink($this->jar);
    }

    /** @return array{status:int,body:string,headers:array<string,string>} */
    public function request(string $method, string $path, array $fields = []): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => T10_BASE . $path,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return ['status' => 0, 'body' => 'CURL_ERROR: ' . $error, 'headers' => []];
        }

        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerText = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        curl_close($ch);

        $headers = [];
        foreach (explode("\r\n", $headerText) as $line) {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($value);
            }
        }

        return ['status' => $status, 'body' => $body, 'headers' => $headers];
    }

    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    public function token(string $html): string
    {
        if (preg_match('/name="_token"\s+value="([a-f0-9]{64})"/', $html, $matches) === 1) {
            return $matches[1];
        }
        if (preg_match('/name="csrf-token"\s+content="([a-f0-9]{64})"/', $html, $matches) === 1) {
            return $matches[1];
        }

        return '';
    }

    public function login(string $username, string $password): bool
    {
        $login = $this->get('/login');
        $token = $this->token($login['body']);
        if ($token === '') {
            return false;
        }

        $result = $this->request('POST', '/login', [
            '_token' => $token,
            'username' => $username,
            'password' => $password,
        ]);

        $location = $result['headers']['location'] ?? '';

        return $result['status'] === 302 && str_contains($location, '/pelanggan/dashboard');
    }
}

try {
    $pdo = new PDO(T10_DB_DSN, T10_DB_USER, T10_DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $exception) {
    fail_blocked('Database uji tidak dapat dihubungi: ' . $exception->getMessage());
}

$probe = @file_get_contents(T10_BASE . '/login');
if ($probe === false) {
    fail_blocked('Server uji ' . T10_BASE . ' tidak aktif.');
}

$suffix = strtolower(bin2hex(random_bytes(5)));
$username = 'stage1_' . $suffix;
$password = 'Uji!' . bin2hex(random_bytes(12)) . '9';
$originalName = 'Pelanggan Stage1 ' . $suffix;
$updatedName = 'Nama Stage1 Baru ' . $suffix;
$rejectedName = 'Nama Ditolak ' . $suffix;
$originalPhone = '081200009999';
$attemptedPhone = '081200008888';

$userId = 0;
$pelangganId = 0;

$cleanup = static function () use (&$userId, &$pelangganId, $username, $pdo): void {
    if ($userId > 0) {
        $pdo->prepare('DELETE FROM audit_log WHERE actor_id = :id')->execute(['id' => $userId]);
        $pdo->prepare('DELETE FROM login_attempts WHERE username = :username')->execute(['username' => $username]);
        $pdo->prepare('DELETE FROM notifikasi WHERE user_id = :id')->execute(['id' => $userId]);
    }
    if ($pelangganId > 0) {
        $pdo->prepare('DELETE FROM pelanggan WHERE id = :id')->execute(['id' => $pelangganId]);
    }
    if ($userId > 0) {
        $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
    }
};

try {
    $pdo->beginTransaction();
    $insertUser = $pdo->prepare(
        'INSERT INTO users (username, password_hash, nama, role, is_active)
         VALUES (:username, :password_hash, :nama, :role, 1)'
    );
    $insertUser->execute([
        'username' => $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'nama' => $originalName,
        'role' => 'pelanggan',
    ]);
    $userId = (int) $pdo->lastInsertId();

    $insertPelanggan = $pdo->prepare(
        'INSERT INTO pelanggan (user_id, username_pppoe, nomor_whatsapp, alamat)
         VALUES (:user_id, :username_pppoe, :nomor_whatsapp, :alamat)'
    );
    $insertPelanggan->execute([
        'user_id' => $userId,
        'username_pppoe' => $username,
        'nomor_whatsapp' => $originalPhone,
        'alamat' => 'Fixture uji Tahap 1',
    ]);
    $pelangganId = (int) $pdo->lastInsertId();
    $pdo->commit();

    $session = new ProfileSession('stage1');
    check('Login pelanggan fixture berhasil', $session->login($username, $password));

    $profile = $session->get('/pelanggan/profil');
    check('Profil dapat dibuka', $profile['status'] === 200, 'status=' . $profile['status']);
    check(
        'Form Profil tidak memiliki input editable nomor_whatsapp',
        !preg_match('/<input[^>]+name="nomor_whatsapp"/i', $profile['body']),
        'input name="nomor_whatsapp" masih ada'
    );
    check(
        'Nomor WhatsApp ditampilkan sebagai field readonly',
        preg_match('/<input[^>]+id="profil-whatsapp"[^>]*readonly/i', $profile['body']) === 1
    );
    check('Nomor aktif tampil di Profil', str_contains($profile['body'], $originalPhone));

    // Perubahan nama normal tanpa field nomor.
    $token = $session->token($profile['body']);
    $nameOnly = $session->request('POST', '/pelanggan/profil', [
        '_token' => $token,
        'nama' => $updatedName,
    ]);
    check('POST nama saja diterima dan redirect', $nameOnly['status'] === 302, 'status=' . $nameOnly['status']);

    $row = $pdo->prepare('SELECT u.nama, p.nomor_whatsapp FROM users u INNER JOIN pelanggan p ON p.user_id = u.id WHERE u.id = :id');
    $row->execute(['id' => $userId]);
    $afterNameOnly = $row->fetch();
    check('Nama berhasil diperbarui', (string) ($afterNameOnly['nama'] ?? '') === $updatedName);
    check('Nomor WhatsApp tetap setelah update nama', (string) ($afterNameOnly['nomor_whatsapp'] ?? '') === $originalPhone);

    $profile = $session->get('/pelanggan/profil');
    check('Pesan sukses perubahan nama tampil', str_contains($profile['body'], 'Nama profil berhasil diperbarui'));

    // Form lama yang masih mengirim nomor sama (format berbeda) tetap kompatibel.
    $token = $session->token($profile['body']);
    $samePhone = $session->request('POST', '/pelanggan/profil', [
        '_token' => $token,
        'nama' => $updatedName,
        'nomor_whatsapp' => '0812-0000-9999',
    ]);
    check('Form lama dengan nomor sama tetap diterima', $samePhone['status'] === 302, 'status=' . $samePhone['status']);

    $row->execute(['id' => $userId]);
    $afterSamePhone = $row->fetch();
    check('Nomor tetap saat form lama mengirim nomor sama', (string) ($afterSamePhone['nomor_whatsapp'] ?? '') === $originalPhone);

    // Manipulasi: ganti nama + nomor baru. Seluruh request harus ditolak.
    $profile = $session->get('/pelanggan/profil');
    $token = $session->token($profile['body']);
    $malicious = $session->request('POST', '/pelanggan/profil', [
        '_token' => $token,
        'nama' => $rejectedName,
        'nomor_whatsapp' => $attemptedPhone,
    ]);
    check('POST ganti nomor ditolak dengan redirect', $malicious['status'] === 302, 'status=' . $malicious['status']);

    $row->execute(['id' => $userId]);
    $afterMalicious = $row->fetch();
    check('Nomor aktif tidak berubah oleh POST manipulatif', (string) ($afterMalicious['nomor_whatsapp'] ?? '') === $originalPhone);
    check('Nama juga tidak berubah pada request yang ditolak', (string) ($afterMalicious['nama'] ?? '') === $updatedName);

    $profile = $session->get('/pelanggan/profil');
    check(
        'Pesan penolakan nomor ditampilkan',
        str_contains($profile['body'], 'Nomor WhatsApp tidak dapat diubah langsung')
    );

    // CSRF tetap berlaku.
    $noCsrf = $session->request('POST', '/pelanggan/profil', [
        'nama' => 'Nama Tanpa CSRF',
        'nomor_whatsapp' => $attemptedPhone,
    ]);
    check('POST tanpa CSRF ditolak 419', $noCsrf['status'] === 419, 'status=' . $noCsrf['status']);

    $row->execute(['id' => $userId]);
    $afterNoCsrf = $row->fetch();
    check('Data tetap aman setelah POST tanpa CSRF', (string) ($afterNoCsrf['nama'] ?? '') === $updatedName
        && (string) ($afterNoCsrf['nomor_whatsapp'] ?? '') === $originalPhone);

    // RBAC: pengunjung tanpa sesi tidak dapat memakai endpoint pelanggan.
    $anonymous = new ProfileSession('stage1_anon');
    $anonPost = $anonymous->request('POST', '/pelanggan/profil', [
        '_token' => str_repeat('a', 64),
        'nama' => 'Anon',
        'nomor_whatsapp' => $attemptedPhone,
    ]);
    check('Pengunjung tanpa sesi ditolak', in_array($anonPost['status'], [302, 401], true), 'status=' . $anonPost['status']);

    $audit = $pdo->prepare(
        "SELECT metadata FROM audit_log WHERE actor_id = :id AND aksi = 'profil_diperbarui' ORDER BY id DESC LIMIT 1"
    );
    $audit->execute(['id' => $userId]);
    $metadata = (string) ($audit->fetchColumn() ?: '');
    check(
        'Audit profil menandai nomor tidak diubah',
        str_contains($metadata, '"nomor_whatsapp_diubah":false')
    );
    check(
        'Audit tidak menyimpan nomor WhatsApp',
        !str_contains($metadata, $originalPhone) && !str_contains($metadata, $attemptedPhone)
    );
} catch (Throwable $exception) {
    echo "FAIL  Exception: " . $exception->getMessage() . "\n";
    $FAIL++;
    $FAILED[] = 'Exception: ' . $exception->getMessage();
} finally {
    $cleanup();
}

echo "\nTOTAL {$PASS} PASS | {$FAIL} FAIL\n";
if ($FAILED !== []) {
    echo "FAILED:\n";
    foreach ($FAILED as $item) {
        echo " - {$item}\n";
    }
}

exit($FAIL === 0 ? 0 : 1);
