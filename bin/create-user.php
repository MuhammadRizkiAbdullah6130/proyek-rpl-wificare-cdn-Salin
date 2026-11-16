<?php
/**
 * WiFiCare CDN — Skrip pembuatan akun (termasuk Admin pertama).
 *
 * Tidak ada registrasi publik. Semua akun dibuat melalui CLI ini oleh
 * administrator server. Password tidak pernah ditulis di dalam kode sumber.
 *
 * Penggunaan:
 *   php bin/create-user.php --role=admin --username=admin.budi --nama="Budi Santoso"
 *   php bin/create-user.php --role=cs --username=cs.dewi --nama="Dewi Lestari"
 *   php bin/create-user.php --role=teknisi --username=tek.andi --nama="Andi Pratama"
 *   php bin/create-user.php --role=pelanggan --username=pppoe123 --nama="Rina" \
 *       --pppoe=pppoe123 --whatsapp=08123456789
 *
 * Opsi:
 *   --role=admin|cs|teknisi|pelanggan   (default: admin)
 *   --username=...                      (wajib)
 *   --nama="..."                        (default: username)
 *   --password=...                      (opsional; bila kosong dibuat otomatis & dicetak sekali)
 *   --pppoe=...                         (khusus pelanggan; default: username)
 *   --whatsapp=...                      (khusus pelanggan; wajib)
 *   --alamat="..."                      (khusus pelanggan; opsional)
 *   --force                             (izinkan membuat admin tambahan)
 *
 * Hanya dapat dijalankan dari CLI.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Skrip ini hanya dapat dijalankan melalui CLI.');
}

require __DIR__ . '/../app/bootstrap.php';

/**
 * Baca opsi baris perintah sederhana (--key=value atau --flag).
 *
 * @param array<int, string> $argv
 * @return array<string, string|bool>
 */
function wificare_parse_options(array $argv): array
{
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (!str_starts_with($argument, '--')) {
            continue;
        }

        $argument = substr($argument, 2);
        $equals = strpos($argument, '=');
        if ($equals === false) {
            $options[$argument] = true;
        } else {
            $options[substr($argument, 0, $equals)] = substr($argument, $equals + 1);
        }
    }

    return $options;
}

/**
 * Hasilkan password acak yang kuat (dibuang karakter ambigu).
 */
function wificare_generate_password(int $length = 16): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#%*-_';
    $max = strlen($alphabet) - 1;
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, $max)];
    }

    return $password;
}

$options = wificare_parse_options($argv);

$validRoles = ['admin', 'cs', 'teknisi', 'pelanggan'];
$role = isset($options['role']) && is_string($options['role']) ? strtolower($options['role']) : 'admin';

if (!in_array($role, $validRoles, true)) {
    fwrite(STDERR, "Peran tidak valid: {$role}. Pilihan: " . implode(', ', $validRoles) . "\n");
    exit(1);
}

$username = isset($options['username']) && is_string($options['username']) ? trim($options['username']) : '';
if ($username === '') {
    fwrite(STDERR, "Opsi --username wajib diisi.\n");
    exit(1);
}

if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
    fwrite(STDERR, "Username hanya boleh huruf, angka, titik, garis bawah, atau strip (3-64 karakter).\n");
    exit(1);
}

$nama = isset($options['nama']) && is_string($options['nama']) ? trim($options['nama']) : $username;
if ($nama === '') {
    $nama = $username;
}

$password = isset($options['password']) && is_string($options['password']) ? $options['password'] : '';
$generated = false;
if ($password === '') {
    $password = wificare_generate_password();
    $generated = true;
}

if (strlen($password) < 8) {
    fwrite(STDERR, "Password minimal 8 karakter.\n");
    exit(1);
}

$whatsapp = isset($options['whatsapp']) && is_string($options['whatsapp']) ? trim($options['whatsapp']) : '';
$pppoe = isset($options['pppoe']) && is_string($options['pppoe']) ? trim($options['pppoe']) : '';
$alamat = isset($options['alamat']) && is_string($options['alamat']) ? trim($options['alamat']) : null;
$force = !empty($options['force']);

echo "WiFiCare CDN — Pembuatan Akun\n";
echo "==========================================\n";

try {
    // Pengaman: hanya satu admin pertama, kecuali --force.
    if ($role === 'admin' && !$force && User::countByRole('admin') > 0) {
        fwrite(STDERR, "[batal] Sudah ada akun admin. Gunakan --force bila benar-benar ingin menambah admin baru.\n");
        exit(1);
    }

    if (User::usernameExists($username)) {
        fwrite(STDERR, "[batal] Username sudah dipakai: {$username}\n");
        exit(1);
    }

    if ($role === 'pelanggan') {
        if ($pppoe === '') {
            $pppoe = $username;
        }
        if ($whatsapp === '') {
            fwrite(STDERR, "[batal] Akun pelanggan wajib memiliki --whatsapp.\n");
            exit(1);
        }
        if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $pppoe)) {
            fwrite(STDERR, "Username PPPoE tidak valid.\n");
            exit(1);
        }
        if (Pelanggan::pppoeExists($pppoe)) {
            fwrite(STDERR, "[batal] Username PPPoE sudah dipakai: {$pppoe}\n");
            exit(1);
        }
    }

    $userId = Database::transaction(function (PDO $pdo) use ($username, $password, $nama, $role, $pppoe, $whatsapp, $alamat): int {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $statement = $pdo->prepare(
            'INSERT INTO users (username, password_hash, nama, role, is_active)
             VALUES (:username, :password_hash, :nama, :role, 1)'
        );
        $statement->execute([
            'username' => $username,
            'password_hash' => $passwordHash,
            'nama' => $nama,
            'role' => $role,
        ]);

        $newUserId = (int) $pdo->lastInsertId();

        if ($role === 'pelanggan') {
            $pelangganStatement = $pdo->prepare(
                'INSERT INTO pelanggan (user_id, username_pppoe, nomor_whatsapp, alamat)
                 VALUES (:user_id, :pppoe, :whatsapp, :alamat)'
            );
            $pelangganStatement->execute([
                'user_id' => $newUserId,
                'pppoe' => $pppoe,
                'whatsapp' => $whatsapp,
                'alamat' => $alamat,
            ]);
        }

        return $newUserId;
    });

    AuditLog::record($userId, 'akun_dibuat', 'users', $userId, [
        'role' => $role,
        'via' => 'cli',
    ]);

    echo "[ok]   Akun dibuat: #{$userId}\n";
    echo "       Peran      : {$role}\n";
    echo "       Username   : {$username}\n";
    if ($role === 'pelanggan') {
        echo "       PPPoE      : {$pppoe}\n";
        echo "       WhatsApp   : {$whatsapp}\n";
    }
    echo "       Nama       : {$nama}\n";
    echo "       Password   : {$password}" . ($generated ? "  (dibuat otomatis — simpan sekarang, tidak akan ditampilkan lagi)\n" : "\n");
    echo "==========================================\n";
    echo "Catatan: password jaringan PPPoE tidak pernah disimpan oleh sistem ini.\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, "[error] " . $exception->getMessage() . "\n");
    exit(1);
}
