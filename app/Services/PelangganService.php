<?php
/**
 * Service manajemen data pelanggan oleh Admin (Tahap 8).
 *
 * Identitas login pelanggan = Username PPPoE. Pembuatan pelanggan sekaligus
 * membuat akun login (users) dengan role `pelanggan`. Password WiFiCare
 * di-hash; password jaringan PPPoE TIDAK pernah disimpan.
 */

declare(strict_types=1);

final class PelangganService
{
    private const MIN_PASSWORD = 8;

    /**
     * Buat pelanggan baru beserta akun login-nya.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input
     * @return int ID pelanggan.
     */
    public static function create(array $actor, array $input): int
    {
        $nama = trim((string) ($input['nama'] ?? ''));
        $pppoe = trim((string) ($input['username_pppoe'] ?? ''));
        $whatsapp = trim((string) ($input['nomor_whatsapp'] ?? ''));
        $alamat = trim((string) ($input['alamat'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $confirm = (string) ($input['password_confirm'] ?? '');
        $isActive = (int) ($input['is_active'] ?? 1) === 1 ? 1 : 0;

        $errors = self::validateProfile($nama, $pppoe, $whatsapp, $alamat);
        $errors += self::validatePassword($password, $confirm);

        if (User::usernameExists($pppoe) || Pelanggan::pppoeExists($pppoe)) {
            $errors['username_pppoe'] = 'Username PPPoE sudah digunakan.';
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $userId = User::create([
                'username' => $pppoe,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'nama' => $nama,
                'role' => 'pelanggan',
                'is_active' => $isActive,
            ]);

            $pelangganId = Pelanggan::create([
                'user_id' => $userId,
                'username_pppoe' => $pppoe,
                'nomor_whatsapp' => $whatsapp,
                'alamat' => $alamat === '' ? null : $alamat,
            ]);

            AuditLog::record((int) ($actor['id'] ?? 0), 'pelanggan_dibuat', 'pelanggan', $pelangganId, [
                'nama' => $nama,
                'username_pppoe' => $pppoe,
                'user_id' => $userId,
            ]);

            $pdo->commit();

            return $pelangganId;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Perbarui profil pelanggan (nama, Username PPPoE, WhatsApp, alamat).
     *
     * Username PPPoE disinkronkan dengan users.username agar login tetap valid.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input
     */
    public static function update(array $actor, int $pelangganId, array $input): void
    {
        $existing = Pelanggan::findForAdmin($pelangganId);
        if ($existing === null) {
            throw new ValidationException(['pelanggan' => 'Pelanggan tidak ditemukan.']);
        }

        $nama = trim((string) ($input['nama'] ?? ''));
        $pppoe = trim((string) ($input['username_pppoe'] ?? ''));
        $whatsapp = trim((string) ($input['nomor_whatsapp'] ?? ''));
        $alamat = trim((string) ($input['alamat'] ?? ''));

        $errors = self::validateProfile($nama, $pppoe, $whatsapp, $alamat);

        if (Pelanggan::pppoeExistsExcept($pppoe, $pelangganId)
            || User::usernameExistsExcept($pppoe, (int) $existing['user_id'])
        ) {
            $errors['username_pppoe'] = 'Username PPPoE sudah digunakan.';
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            Pelanggan::update($pelangganId, $pppoe, $whatsapp, $alamat === '' ? null : $alamat);

            // Sinkronkan akun login (username & nama) agar tidak menyimpang.
            User::update((int) $existing['user_id'], $pppoe, $nama, 'pelanggan');

            AuditLog::record((int) ($actor['id'] ?? 0), 'pelanggan_diperbarui', 'pelanggan', $pelangganId, [
                'nama' => $nama,
                'username_pppoe' => $pppoe,
                'username_pppoe_lama' => (string) $existing['username_pppoe'],
            ]);

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /* ---------------------------------------------------------------------
     * Validasi
     * ------------------------------------------------------------------- */

    /**
     * @return array<string, string>
     */
    private static function validateProfile(string $nama, string $pppoe, string $whatsapp, string $alamat): array
    {
        $errors = [];

        if ($nama === '') {
            $errors['nama'] = 'Nama lengkap wajib diisi.';
        } elseif (mb_strlen($nama) > 120) {
            $errors['nama'] = 'Nama maksimal 120 karakter.';
        }

        if ($pppoe === '') {
            $errors['username_pppoe'] = 'Username PPPoE wajib diisi.';
        } elseif (!preg_match('/^[A-Za-z0-9._@-]{3,64}$/', $pppoe)) {
            $errors['username_pppoe'] = 'Username PPPoE 3–64 karakter (huruf, angka, titik, @, garis bawah, strip).';
        }

        if ($whatsapp === '') {
            $errors['nomor_whatsapp'] = 'Nomor WhatsApp wajib diisi.';
        } elseif (!preg_match('/^[0-9+\-\s]{9,20}$/', $whatsapp)) {
            $errors['nomor_whatsapp'] = 'Format nomor WhatsApp tidak valid (9–20 digit).';
        }

        if (mb_strlen($alamat) > 255) {
            $errors['alamat'] = 'Alamat maksimal 255 karakter.';
        }

        return $errors;
    }

    /**
     * @return array<string, string>
     */
    private static function validatePassword(string $password, string $confirm): array
    {
        $errors = [];

        if ($password === '') {
            $errors['password'] = 'Password WiFiCare wajib diisi.';
        } elseif (mb_strlen($password) < self::MIN_PASSWORD) {
            $errors['password'] = 'Password minimal ' . self::MIN_PASSWORD . ' karakter.';
        }

        if ($confirm === '') {
            $errors['password_confirm'] = 'Konfirmasi password wajib diisi.';
        } elseif ($password !== $confirm) {
            $errors['password_confirm'] = 'Konfirmasi password tidak sama.';
        }

        return $errors;
    }
}
