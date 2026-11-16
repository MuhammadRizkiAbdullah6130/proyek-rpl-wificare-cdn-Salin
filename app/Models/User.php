<?php
/**
 * Model pengguna.
 *
 * Satu tabel `users` melayani keempat peran. Untuk pelanggan, kolom
 * `username` berisi Username PPPoE (sama dengan pelanggan.username_pppoe)
 * sehingga satu jalur autentikasi melayani semua peran.
 */

declare(strict_types=1);

final class User
{
    /**
     * Cari pengguna berdasarkan username atau username PPPoE pelanggan.
     *
     * @return array<string, mixed>|null
     */
    public static function findByLogin(string $identifier): ?array
    {
        $sql = 'SELECT u.*, p.id AS pelanggan_id, p.username_pppoe, p.nomor_whatsapp, p.alamat
                FROM users u
                LEFT JOIN pelanggan p ON p.user_id = u.id
                WHERE u.username = :username OR p.username_pppoe = :pppoe
                LIMIT 1';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute([
            'username' => $identifier,
            'pppoe' => $identifier,
        ]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Cari pengguna berdasarkan ID.
     *
     * @return array<string, mixed>|null
     */
    public static function findById(int $id): ?array
    {
        $sql = 'SELECT u.*, p.id AS pelanggan_id, p.username_pppoe, p.nomor_whatsapp, p.alamat
                FROM users u
                LEFT JOIN pelanggan p ON p.user_id = u.id
                WHERE u.id = :id
                LIMIT 1';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Apakah username sudah dipakai.
     */
    public static function usernameExists(string $username): bool
    {
        $statement = Database::pdo()->prepare('SELECT 1 FROM users WHERE username = :username LIMIT 1');
        $statement->execute(['username' => $username]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Perbarui waktu login terakhir.
     */
    public static function updateLastLogin(int $id): void
    {
        $statement = Database::pdo()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    /**
     * Buat pengguna baru dan kembalikan ID-nya.
     *
     * @param array{username: string, password_hash: string, nama: string, role: string, is_active?: int} $data
     */
    public static function create(array $data): int
    {
        $sql = 'INSERT INTO users (username, password_hash, nama, role, is_active)
                VALUES (:username, :password_hash, :nama, :role, :is_active)';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute([
            'username' => $data['username'],
            'password_hash' => $data['password_hash'],
            'nama' => $data['nama'],
            'role' => $data['role'],
            'is_active' => $data['is_active'] ?? 1,
        ]);

        return (int) Database::pdo()->lastInsertId();
    }

    /**
     * Hitung jumlah pengguna dengan peran tertentu.
     */
    public static function countByRole(string $role): int
    {
        $statement = Database::pdo()->prepare('SELECT COUNT(*) FROM users WHERE role = :role');
        $statement->execute(['role' => $role]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Daftar pengguna aktif dengan peran tertentu (mis. teknisi untuk penugasan).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function activeByRole(string $role): array
    {
        $sql = 'SELECT id, nama, username FROM users
                WHERE role = :role AND is_active = 1
                ORDER BY nama ASC';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['role' => $role]);

        return $statement->fetchAll();
    }

    /* =====================================================================
     * Query & mutasi manajemen pengguna (Admin — Tahap 8)
     * =================================================================== */

    /**
     * Daftar pengguna dengan filter & pagination (Admin).
     *
     * @param array{q?: string, role?: string, status?: string} $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int, pages: int, page: int, per_page: int}
     */
    public static function paginateForAdmin(array $filters, int $page, int $perPage = 10): array
    {
        $where = ['1 = 1'];
        $params = [];

        $keyword = trim((string) ($filters['q'] ?? ''));
        if ($keyword !== '') {
            // Native prepared statements (EMULATE_PREPARES=false) tidak
            // mengizinkan nama placeholder yang sama dipakai berulang, jadi
            // setiap kolom memakai placeholder unik dengan nilai yang sama.
            $like = '%' . self::escapeLike($keyword) . '%';
            $where[] = '(u.username LIKE :q1 OR u.nama LIKE :q2)';
            $params['q1'] = $like;
            $params['q2'] = $like;
        }

        // Data Karyawan: halaman ini hanya untuk staf (admin/cs/teknisi).
        // Pelanggan dikelola terpisah pada menu Data Pelanggan.
        $where[] = "u.role IN ('admin', 'cs', 'teknisi')";

        $role = (string) ($filters['role'] ?? '');
        if (in_array($role, ['admin', 'cs', 'teknisi'], true)) {
            $where[] = 'u.role = :role';
            $params['role'] = $role;
        }

        $status = (string) ($filters['status'] ?? '');
        if ($status === 'aktif') {
            $where[] = 'u.is_active = 1';
        } elseif ($status === 'nonaktif') {
            $where[] = 'u.is_active = 0';
        }

        $whereSql = implode(' AND ', $where);

        $countStatement = Database::pdo()->prepare('SELECT COUNT(*) FROM users u WHERE ' . $whereSql);
        $countStatement->execute($params);
        $total = (int) $countStatement->fetchColumn();

        $perPage = max(1, min(50, $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        // $perPage dan $offset adalah integer tervalidasi, aman di-inline.
        $sql = 'SELECT u.id, u.username, u.nama, u.role, u.is_active, u.last_login_at, u.created_at,
                       p.username_pppoe
                FROM users u
                LEFT JOIN pelanggan p ON p.user_id = u.id
                WHERE ' . $whereSql . '
                ORDER BY u.role ASC, u.nama ASC
                LIMIT ' . $perPage . ' OFFSET ' . $offset;

        $statement = Database::pdo()->prepare($sql);
        $statement->execute($params);

        return [
            'rows' => $statement->fetchAll(),
            'total' => $total,
            'pages' => $pages,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Apakah username sudah dipakai akun LAIN (bukan $exceptId).
     */
    public static function usernameExistsExcept(string $username, int $exceptId): bool
    {
        $statement = Database::pdo()->prepare(
            'SELECT 1 FROM users WHERE username = :username AND id <> :id LIMIT 1'
        );
        $statement->execute(['username' => $username, 'id' => $exceptId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Apakah pengguna dengan ID tersebut ada.
     */
    public static function exists(int $id): bool
    {
        $statement = Database::pdo()->prepare('SELECT 1 FROM users WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Perbarui data dasar pengguna (username, nama, role).
     */
    public static function update(int $id, string $username, string $nama, string $role): void
    {
        $statement = Database::pdo()->prepare(
            'UPDATE users SET username = :username, nama = :nama, role = :role WHERE id = :id'
        );
        $statement->execute([
            'username' => $username,
            'nama' => $nama,
            'role' => $role,
            'id' => $id,
        ]);
    }

    /**
     * Aktifkan / nonaktifkan akun.
     */
    public static function setActive(int $id, int $isActive): void
    {
        $statement = Database::pdo()->prepare('UPDATE users SET is_active = :is_active WHERE id = :id');
        $statement->execute(['is_active' => $isActive, 'id' => $id]);
    }

    /**
     * Ganti hash password pengguna.
     */
    public static function updatePassword(int $id, string $passwordHash): void
    {
        $statement = Database::pdo()->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $statement->execute(['hash' => $passwordHash, 'id' => $id]);
    }

    /**
     * Simpan password hasil reset mandiri dan cabut semua sesi login lama.
     *
     * Kenaikan session_version dilakukan dalam UPDATE yang sama dengan
     * password_hash sehingga tidak ada jendela waktu sesi lama tetap valid.
     */
    public static function resetPasswordAndInvalidateSessions(int $id, string $passwordHash): bool
    {
        if ($id <= 0 || $passwordHash === '') {
            return false;
        }

        $statement = Database::pdo()->prepare(
            'UPDATE users
             SET password_hash = :hash, session_version = session_version + 1
             WHERE id = :id'
        );
        $statement->execute(['hash' => $passwordHash, 'id' => $id]);

        return $statement->rowCount() === 1;
    }

    /**
     * Perbarui hanya nama tampilan pengguna (dipakai pelanggan pada Profil Saya).
     *
     * Peran, username, dan status TIDAK diubah di sini.
     */
    public static function updateName(int $id, string $nama): void
    {
        $statement = Database::pdo()->prepare('UPDATE users SET nama = :nama WHERE id = :id');
        $statement->execute(['nama' => $nama, 'id' => $id]);
    }

    /**
     * Jumlah akun admin yang masih aktif (opsional mengecualikan satu ID).
     */
    public static function countActiveAdmins(?int $exceptId = null): int
    {
        $sql = "SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1";
        $params = [];
        if ($exceptId !== null && $exceptId > 0) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }

        $statement = Database::pdo()->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    /**
     * Escape karakter khusus untuk pencarian LIKE.
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
