<?php
/**
 * Model notifikasi in-app.
 *
 * Setiap notifikasi milik SATU pengguna. Semua query baca/menandai selalu
 * difilter `user_id` agar pengguna tidak pernah membaca notifikasi pengguna
 * lain (pencegahan IDOR).
 *
 * `create()` hanya melakukan INSERT dan TIDAK membuka transaksi sendiri, agar
 * pemanggil dapat menuliskannya di dalam transaksi perubahan tiket sehingga
 * status tiket dan notifikasi konsisten.
 */

declare(strict_types=1);

final class Notifikasi
{
    /**
     * Simpan satu notifikasi.
     */
    public static function create(
        int $userId,
        ?int $pengaduanId,
        string $judul,
        string $pesan
    ): int {
        $sql = 'INSERT INTO notifikasi (user_id, pengaduan_id, judul, pesan)
                VALUES (:user_id, :pengaduan_id, :judul, :pesan)';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute([
            'user_id' => $userId,
            'pengaduan_id' => $pengaduanId,
            'judul' => mb_substr($judul, 0, 150),
            'pesan' => $pesan,
        ]);

        return (int) Database::pdo()->lastInsertId();
    }

    /**
     * Jumlah notifikasi belum dibaca milik seorang pengguna.
     */
    public static function unreadCountFor(int $userId): int
    {
        $statement = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM notifikasi WHERE user_id = :user_id AND read_at IS NULL'
        );
        $statement->execute(['user_id' => $userId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Notifikasi terbaru milik seorang pengguna (untuk dropdown lonceng).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function latestFor(int $userId, int $limit = 8): array
    {
        $limit = max(1, min(30, $limit));

        $sql = 'SELECT n.id, n.pengaduan_id, n.judul, n.pesan, n.read_at, n.created_at,
                       p.nomor_tiket
                FROM notifikasi n
                LEFT JOIN pengaduan p ON p.id = n.pengaduan_id
                WHERE n.user_id = :user_id
                ORDER BY n.created_at DESC, n.id DESC
                LIMIT ' . $limit;

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchAll();
    }

    /**
     * Halaman daftar notifikasi milik seorang pengguna.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int, pages: int, page: int, per_page: int}
     */
    public static function paginateFor(int $userId, int $page, int $perPage = 15): array
    {
        $countStatement = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM notifikasi WHERE user_id = :user_id'
        );
        $countStatement->execute(['user_id' => $userId]);
        $total = (int) $countStatement->fetchColumn();

        $perPage = max(1, min(50, $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        // $perPage dan $offset adalah integer tervalidasi, aman di-inline.
        $sql = 'SELECT n.id, n.pengaduan_id, n.judul, n.pesan, n.read_at, n.created_at,
                       p.nomor_tiket
                FROM notifikasi n
                LEFT JOIN pengaduan p ON p.id = n.pengaduan_id
                WHERE n.user_id = :user_id
                ORDER BY n.created_at DESC, n.id DESC
                LIMIT ' . $perPage . ' OFFSET ' . $offset;

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['user_id' => $userId]);

        return [
            'rows' => $statement->fetchAll(),
            'total' => $total,
            'pages' => $pages,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Ambil satu notifikasi MILIK pengguna (null bila bukan miliknya).
     *
     * @return array<string, mixed>|null
     */
    public static function findForUser(int $id, int $userId): ?array
    {
        $sql = 'SELECT n.id, n.user_id, n.pengaduan_id, n.judul, n.pesan, n.read_at, n.created_at
                FROM notifikasi n
                WHERE n.id = :id AND n.user_id = :user_id
                LIMIT 1';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['id' => $id, 'user_id' => $userId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Tandai satu notifikasi sebagai dibaca (hanya milik pengguna).
     */
    public static function markRead(int $id, int $userId): void
    {
        $sql = 'UPDATE notifikasi SET read_at = NOW()
                WHERE id = :id AND user_id = :user_id AND read_at IS NULL';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['id' => $id, 'user_id' => $userId]);
    }

    /**
     * Tandai semua notifikasi milik pengguna sebagai dibaca.
     */
    public static function markAllRead(int $userId): void
    {
        $statement = Database::pdo()->prepare(
            'UPDATE notifikasi SET read_at = NOW() WHERE user_id = :user_id AND read_at IS NULL'
        );
        $statement->execute(['user_id' => $userId]);
    }
}
