<?php
/**
 * Service manajemen akun pengguna oleh Admin (Tahap 8).
 *
 * Aturan penting:
 * - Password selalu di-hash dengan password_hash() (bcrypt). Plaintext tidak
 *   pernah disimpan.
 * - Username unik (dicek di server, selain UNIQUE KEY di database).
 * - Admin aktif terakhir tidak boleh dinonaktifkan atau diubah perannya.
 * - Setiap perubahan penting dicatat ke audit_log.
 */

declare(strict_types=1);

final class UserService
{
    private const ROLES = ['admin', 'cs', 'teknisi'];

    private const MIN_PASSWORD = 8;

    /**
     * Buat akun pengguna baru (admin/cs/teknisi).
     *
     * @param array<string, mixed> $actor Pengguna aktif (dari session).
     * @param array<string, mixed> $input Data formulir.
     */
    public static function create(array $actor, array $input): int
    {
        $username = trim((string) ($input['username'] ?? ''));
        $nama = trim((string) ($input['nama'] ?? ''));
        $role = (string) ($input['role'] ?? '');
        $password = (string) ($input['password'] ?? '');
        $confirm = (string) ($input['password_confirm'] ?? '');
        $isActive = (int) ($input['is_active'] ?? 1) === 1 ? 1 : 0;

        $errors = self::validateIdentity($username, $nama, $role);
        $errors += self::validatePassword($password, $confirm, $username);

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            if (User::usernameExists($username)) {
                throw new ValidationException(['username' => 'Username sudah digunakan.']);
            }

            $id = User::create([
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'nama' => $nama,
                'role' => $role,
                'is_active' => $isActive,
            ]);

            AuditLog::record((int) ($actor['id'] ?? 0), 'pengguna_dibuat', 'users', $id, [
                'username' => $username,
                'nama' => $nama,
                'role' => $role,
                'is_active' => $isActive,
            ]);

            $pdo->commit();

            return $id;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Perbarui data dasar pengguna.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input
     */
    public static function update(array $actor, int $id, array $input): void
    {
        $target = User::findById($id);
        if ($target === null) {
            throw new ValidationException(['pengguna' => 'Pengguna tidak ditemukan.']);
        }

        $username = trim((string) ($input['username'] ?? ''));
        $nama = trim((string) ($input['nama'] ?? ''));
        $role = (string) ($input['role'] ?? '');

        // Akun pelanggan dikelola melalui menu Data Pelanggan.
        if ((string) $target['role'] === 'pelanggan' && $role !== 'pelanggan') {
            throw new ValidationException(['role' => 'Akun pelanggan tidak dapat diubah perannya di sini.']);
        }
        if ($role === 'pelanggan') {
            throw new ValidationException(['role' => 'Akun pelanggan dikelola melalui menu Data Pelanggan.']);
        }

        $errors = self::validateIdentity($username, $nama, $role, true);
        if (User::usernameExistsExcept($username, $id)) {
            $errors['username'] = 'Username sudah digunakan akun lain.';
        }

        // Jangan biarkan admin aktif terakhir kehilangan hak admin.
        if ((string) $target['role'] === 'admin'
            && (int) $target['is_active'] === 1
            && $role !== 'admin'
            && User::countActiveAdmins($id) === 0
        ) {
            $errors['role'] = 'Tidak dapat mengubah peran satu-satunya Admin aktif.';
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            User::update($id, $username, $nama, $role);

            // Sinkronkan username PPPoE bila ini akun pelanggan (username = pppoe).
            if ((string) $target['role'] === 'pelanggan' && isset($target['pelanggan_id'])) {
                Pelanggan::update(
                    (int) $target['pelanggan_id'],
                    $username,
                    (string) ($target['nomor_whatsapp'] ?? ''),
                    $target['alamat'] !== null ? (string) $target['alamat'] : null
                );
            }

            AuditLog::record((int) ($actor['id'] ?? 0), 'pengguna_diperbarui', 'users', $id, [
                'username' => $username,
                'nama' => $nama,
                'role_lama' => (string) $target['role'],
                'role_baru' => $role,
            ]);

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Aktifkan / nonaktifkan akun.
     *
     * @param array<string, mixed> $actor
     */
    public static function setActive(array $actor, int $id, int $isActive): void
    {
        $target = User::findById($id);
        if ($target === null) {
            throw new ValidationException(['pengguna' => 'Pengguna tidak ditemukan.']);
        }

        $isActive = $isActive === 1 ? 1 : 0;

        if ($isActive === 0) {
            if ($id === (int) ($actor['id'] ?? 0)) {
                throw new ValidationException(['status' => 'Anda tidak dapat menonaktifkan akun Anda sendiri.']);
            }
            if ((string) $target['role'] === 'admin' && User::countActiveAdmins($id) === 0) {
                throw new ValidationException(['status' => 'Tidak dapat menonaktifkan satu-satunya Admin aktif.']);
            }
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            User::setActive($id, $isActive);

            AuditLog::record(
                (int) ($actor['id'] ?? 0),
                $isActive === 1 ? 'pengguna_diaktifkan' : 'pengguna_dinonaktifkan',
                'users',
                $id,
                ['username' => (string) $target['username'], 'role' => (string) $target['role']]
            );

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Atur ulang password pengguna (hash baru, password lama tidak ditampilkan).
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input
     */
    public static function resetPassword(array $actor, int $id, array $input): void
    {
        $target = User::findById($id);
        if ($target === null) {
            throw new ValidationException(['pengguna' => 'Pengguna tidak ditemukan.']);
        }

        $password = (string) ($input['password'] ?? '');
        $confirm = (string) ($input['password_confirm'] ?? '');

        $errors = self::validatePassword($password, $confirm, (string) $target['username']);
        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            User::updatePassword($id, password_hash($password, PASSWORD_DEFAULT));

            // Jangan pernah mencatat password pada audit log.
            AuditLog::record((int) ($actor['id'] ?? 0), 'password_direset', 'users', $id, [
                'username' => (string) $target['username'],
                'role' => (string) $target['role'],
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
    private static function validateIdentity(string $username, string $nama, string $role, bool $allowPelanggan = false): array
    {
        $errors = [];

        if ($username === '') {
            $errors['username'] = 'Username wajib diisi.';
        } elseif (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
            $errors['username'] = 'Username 3–64 karakter (huruf, angka, titik, garis bawah, strip).';
        }

        if ($nama === '') {
            $errors['nama'] = 'Nama lengkap wajib diisi.';
        } elseif (mb_strlen($nama) > 120) {
            $errors['nama'] = 'Nama maksimal 120 karakter.';
        }

        $allowed = $allowPelanggan ? array_merge(self::ROLES, ['pelanggan']) : self::ROLES;
        if (!in_array($role, $allowed, true)) {
            $errors['role'] = 'Peran tidak valid.';
        }

        return $errors;
    }

    /**
     * @return array<string, string>
     */
    private static function validatePassword(string $password, string $confirm, string $username): array
    {
        $errors = [];

        if ($password === '') {
            $errors['password'] = 'Password wajib diisi.';
        } elseif (mb_strlen($password) < self::MIN_PASSWORD) {
            $errors['password'] = 'Password minimal ' . self::MIN_PASSWORD . ' karakter.';
        } elseif ($username !== '' && strtolower($password) === strtolower($username)) {
            $errors['password'] = 'Password tidak boleh sama dengan username.';
        }

        if ($confirm !== '' && $password !== $confirm) {
            $errors['password_confirm'] = 'Konfirmasi password tidak sama.';
        } elseif ($confirm === '' && $password !== '') {
            $errors['password_confirm'] = 'Konfirmasi password wajib diisi.';
        }

        return $errors;
    }
}
