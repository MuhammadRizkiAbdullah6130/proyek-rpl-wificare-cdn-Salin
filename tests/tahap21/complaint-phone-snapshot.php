<?php
/**
 * WiFiCare CDN - Tahap 21, Tahap 1: snapshot kontak tiket pengaduan.
 *
 * Fokus pengujian:
 * - Nomor WhatsApp pada Buat Pengaduan hanya menjadi kontak tiket.
 * - Nomor WhatsApp utama pelanggan TIDAK berubah setelah tiket dibuat.
 * - Nomor kontak tersimpan pada tiket yang benar.
 * - Nomor kontak kosong memakai nomor utama sebagai snapshot.
 * - Perubahan nama profil tetap berjalan.
 * - Request manipulatif tetap tidak dapat mengubah nomor utama.
 * - Tiket dapat dibuat tanpa status verifikasi nomor.
 * - Detail tiket dan riwayat tiket tetap berjalan.
 *
 * Prasyarat:
 *   - database uji wificare_cdn_test;
 *   - server uji di http://localhost:8099 (dapat ditimpa T10_BASE).
 *
 * Jalankan:
 *   php tests/tahap21/complaint-phone-snapshot.php
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

function blocked(string $message): void
{
    echo "BLOCKED  {$message}\n";
    exit(2);
}

final class ComplaintSession
{
    private string $jar;

    public function __construct(string $name)
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_t21';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $this->jar = $dir . DIRECTORY_SEPARATOR . 'complaint_' . preg_replace('/[^a-z0-9]/i', '_', $name) . '.txt';
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
    blocked('Database uji tidak dapat dihubungi: ' . $exception->getMessage());
}

if (@file_get_contents(T10_BASE . '/login') === false) {
    blocked('Server uji ' . T10_BASE . ' tidak aktif.');
}

$categoryId = (int) $pdo->query('SELECT id FROM kategori_gangguan WHERE is_active = 1 ORDER BY id ASC LIMIT 1')->fetchColumn();
if ($categoryId <= 0) {
    blocked('Kategori gangguan aktif tidak tersedia di database uji.');
}

$suffix = strtolower(bin2hex(random_bytes(5)));
$username = 'stage1c_' . $suffix;
$password = 'Uji!' . bin2hex(random_bytes(12)) . '9';
$originalName = 'Pelanggan Snapshot ' . $suffix;
$updatedName = 'Nama Snapshot Baru ' . $suffix;
$originalPhone = '081200009999';
$ticketPhone = '081200007777';
$blockedPhone = '081200005555';

$userId = 0;
$pelangganId = 0;
$ticketIds = [];
$tempPng = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_t21_' . $suffix . '.png';

$cleanup = static function () use (&$userId, &$pelangganId, &$ticketIds, $username, $pdo, $tempPng): void {
    foreach ($ticketIds as $ticketId) {
        $photos = $pdo->prepare('SELECT file_path FROM pengaduan_foto WHERE pengaduan_id = :id');
        $photos->execute(['id' => $ticketId]);
        foreach ($photos->fetchAll(PDO::FETCH_COLUMN) as $relativePath) {
            $absolute = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $relativePath);
            if (str_starts_with(str_replace('\\', '/', $absolute), str_replace('\\', '/', dirname(__DIR__, 2) . '/storage/uploads/'))) {
                @unlink($absolute);
            }
        }

        $pdo->prepare('DELETE FROM whatsapp_outbox WHERE event_key LIKE :event_key')
            ->execute(['event_key' => 'wa_notif:%:' . $ticketId . ':%']);
        $pdo->prepare('DELETE FROM notifikasi WHERE pengaduan_id = :id')->execute(['id' => $ticketId]);
        $pdo->prepare('DELETE FROM riwayat_status WHERE pengaduan_id = :id')->execute(['id' => $ticketId]);
        $pdo->prepare('DELETE FROM pengaduan_foto WHERE pengaduan_id = :id')->execute(['id' => $ticketId]);
        $pdo->prepare('DELETE FROM pengaduan WHERE id = :id')->execute(['id' => $ticketId]);
    }

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

    @unlink($tempPng);
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
        'alamat' => 'Fixture uji snapshot tiket',
    ]);
    $pelangganId = (int) $pdo->lastInsertId();
    $pdo->commit();

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC');
    file_put_contents($tempPng, $png);

    $session = new ComplaintSession('stage1c');
    check('Login pelanggan fixture berhasil', $session->login($username, $password));

    $form = $session->get('/pelanggan/pengaduan/baru');
    check('Form Buat Pengaduan dapat dibuka', $form['status'] === 200, 'status=' . $form['status']);
    check(
        'Form menjelaskan nomor tidak mengubah nomor utama',
        str_contains($form['body'], 'tidak mengubah nomor WhatsApp utama')
    );
    $token = $session->token($form['body']);
    check('Token CSRF form tersedia', $token !== '');

    // Tiket A: nomor kontak berbeda + manipulasi pelanggan_id dari browser.
    $postA = $session->request('POST', '/pelanggan/pengaduan', [
        '_token' => $token,
        'nomor_whatsapp' => $ticketPhone,
        'kategori_id' => (string) $categoryId,
        'deskripsi' => 'Uji snapshot kontak tiket dengan nomor berbeda dari profil.',
        'pelanggan_id' => '1',
        'foto[]' => new CURLFile($tempPng, 'image/png', 'bukti-a.png'),
    ]);
    check('Tiket A berhasil dibuat', $postA['status'] === 302, 'status=' . $postA['status']);

    $ticketAStmt = $pdo->prepare('SELECT * FROM pengaduan WHERE pelanggan_id = :id ORDER BY id DESC LIMIT 1');
    $ticketAStmt->execute(['id' => $pelangganId]);
    $ticketA = $ticketAStmt->fetch();
    check('Tiket A tersimpan', $ticketA !== false);
    if ($ticketA !== false) {
        $ticketIds[] = (int) $ticketA['id'];
    }

    check('Kontak tiket A tersimpan sesuai input', (string) ($ticketA['nomor_whatsapp_snapshot'] ?? '') === $ticketPhone);
    check(
        'Tiket A tetap milik pelanggan session, bukan pelanggan_id kiriman',
        (int) ($ticketA['pelanggan_id'] ?? 0) === $pelangganId
    );

    $master = $pdo->prepare('SELECT nomor_whatsapp FROM pelanggan WHERE id = :id');
    $master->execute(['id' => $pelangganId]);
    check('Nomor utama tidak berubah setelah tiket A', (string) $master->fetchColumn() === $originalPhone);

    // Tiket B: nomor kontak dikosongkan -> snapshot memakai nomor utama.
    $form = $session->get('/pelanggan/pengaduan/baru');
    $token = $session->token($form['body']);
    $postB = $session->request('POST', '/pelanggan/pengaduan', [
        '_token' => $token,
        'kategori_id' => (string) $categoryId,
        'deskripsi' => 'Uji fallback nomor utama saat kontak tiket dikosongkan.',
        'foto[]' => new CURLFile($tempPng, 'image/png', 'bukti-b.png'),
    ]);
    check('Tiket B tanpa nomor kontak berhasil dibuat', $postB['status'] === 302, 'status=' . $postB['status']);

    $ticketB = $pdo->prepare('SELECT * FROM pengaduan WHERE pelanggan_id = :id ORDER BY id DESC LIMIT 1');
    $ticketB->execute(['id' => $pelangganId]);
    $ticketB = $ticketB->fetch();
    check('Tiket B tersimpan', $ticketB !== false);
    if ($ticketB !== false) {
        $ticketIds[] = (int) $ticketB['id'];
    }
    check(
        'Kontak tiket B memakai nomor utama sebagai snapshot',
        (string) ($ticketB['nomor_whatsapp_snapshot'] ?? '') === $originalPhone
    );

    $master->execute(['id' => $pelangganId]);
    check('Nomor utama tidak berubah setelah tiket B', (string) $master->fetchColumn() === $originalPhone);

    // Detail dan riwayat tiket tetap berjalan.
    $ticketId = (int) ($ticketA['id'] ?? 0);
    $detail = $session->get('/pelanggan/pengaduan/' . $ticketId);
    check('Detail tiket tetap dapat dibuka', $detail['status'] === 200, 'status=' . $detail['status']);
    check('Detail menampilkan kontak snapshot tiket', str_contains($detail['body'], $ticketPhone));
    check('Detail menampilkan nomor tiket', str_contains($detail['body'], (string) ($ticketA['nomor_tiket'] ?? '')));

    $history = $session->get('/pelanggan/pengaduan');
    check('Riwayat tiket tetap memuat tiket A', str_contains($history['body'], (string) ($ticketA['nomor_tiket'] ?? '')));

    // Perubahan nama profil tetap berjalan.
    $profile = $session->get('/pelanggan/profil');
    $profileToken = $session->token($profile['body']);
    $rename = $session->request('POST', '/pelanggan/profil', [
        '_token' => $profileToken,
        'nama' => $updatedName,
    ]);
    check('Update nama profil diterima', $rename['status'] === 302, 'status=' . $rename['status']);

    $userRow = $pdo->prepare('SELECT nama FROM users WHERE id = :id');
    $userRow->execute(['id' => $userId]);
    check('Nama profil berubah', (string) $userRow->fetchColumn() === $updatedName);

    $master->execute(['id' => $pelangganId]);
    check('Nomor utama tetap setelah update nama', (string) $master->fetchColumn() === $originalPhone);

    // Manipulasi ganti nomor melalui Profil harus ditolak.
    $profile = $session->get('/pelanggan/profil');
    $profileToken = $session->token($profile['body']);
    $malicious = $session->request('POST', '/pelanggan/profil', [
        '_token' => $profileToken,
        'nama' => $updatedName,
        'nomor_whatsapp' => $blockedPhone,
    ]);
    check('POST ganti nomor lewat Profil ditolak', $malicious['status'] === 302, 'status=' . $malicious['status']);

    $master->execute(['id' => $pelangganId]);
    check('Nomor utama tetap setelah manipulasi Profil', (string) $master->fetchColumn() === $originalPhone);

    $userRow->execute(['id' => $userId]);
    check('Nama tidak berubah pada request manipulatif Profil', (string) $userRow->fetchColumn() === $updatedName);

    // Tidak ada pemeriksaan status verifikasi pada Tahap 1; tiket tetap dibuat.
    check('Pengaduan tetap dibuat tanpa status verifikasi nomor', $ticketA !== false && $ticketB !== false);

    // Pastikan seluruh tiket memakai snapshot masing-masing dan bukan nomor master terbaru.
    $allTickets = $pdo->prepare('SELECT * FROM pengaduan WHERE pelanggan_id = :id ORDER BY id ASC');
    $allTickets->execute(['id' => $pelangganId]);
    $rows = $allTickets->fetchAll();
    $snapshots = array_map(static fn (array $row): string => (string) $row['nomor_whatsapp_snapshot'], $rows);
    check(
        'Snapshot historis tiap tiket tidak ditimpa nomor utama',
        in_array($ticketPhone, $snapshots, true) && in_array($originalPhone, $snapshots, true)
    );
} catch (Throwable $exception) {
    echo 'FAIL  Exception: ' . $exception->getMessage() . "\n";
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
