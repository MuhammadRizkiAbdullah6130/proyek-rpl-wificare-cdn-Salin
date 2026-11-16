<?php
/**
 * Model/repository kategori gangguan.
 *
 * Kategori tidak pernah dihapus secara destruktif (tiket lama mereferensikannya);
 * gunakan soft-disable (`is_active = 0`).
 */

declare(strict_types=1);

final class Kategori
{
    /**
     * Seluruh kategori aktif.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function allActive(): array
    {
        $sql = 'SELECT id, nama FROM kategori_gangguan WHERE is_active = 1 ORDER BY nama ASC';

        return Database::pdo()->query($sql)->fetchAll();
    }

    /**
     * Cari kategori aktif berdasarkan ID (validasi input pelanggan).
     *
     * @return array<string, mixed>|null
     */
    public static function findActive(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $statement = Database::pdo()->prepare(
            'SELECT id, nama FROM kategori_gangguan WHERE id = :id AND is_active = 1 LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /* =====================================================================
     * Manajemen kategori (Admin — Tahap 8)
     * =================================================================== */

    /**
     * Daftar kategori dengan filter & pagination beserta jumlah pemakaian.
     *
     * @param array{q?: string, status?: string} $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int, pages: int, page: int, per_page: int}
     */
    public static function paginateForAdmin(array $filters, int $page, int $perPage = 10): array
    {
        $where = ['1 = 1'];
        $params = [];

        $keyword = trim((string) ($filters['q'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . self::escapeLike($keyword) . '%';
            $where[] = '(k.nama LIKE :q1 OR k.deskripsi LIKE :q2)';
            $params['q1'] = $like;
            $params['q2'] = $like;
        }

        $status = (string) ($filters['status'] ?? '');
        if ($status === 'aktif') {
            $where[] = 'k.is_active = 1';
        } elseif ($status === 'nonaktif') {
            $where[] = 'k.is_active = 0';
        }

        $whereSql = implode(' AND ', $where);

        $countStatement = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM kategori_gangguan k WHERE ' . $whereSql
        );
        $countStatement->execute($params);
        $total = (int) $countStatement->fetchColumn();

        $perPage = max(1, min(50, $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        // $perPage dan $offset adalah integer tervalidasi, aman di-inline.
        $sql = 'SELECT k.id, k.nama, k.deskripsi, k.is_active, k.created_at,
                       (SELECT COUNT(*) FROM pengaduan p WHERE p.kategori_id = k.id) AS jumlah_tiket
                FROM kategori_gangguan k
                WHERE ' . $whereSql . '
                ORDER BY k.is_active DESC, k.nama ASC
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
     * Semua kategori (untuk filter) tanpa pagination.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        return Database::pdo()->query(
            'SELECT id, nama, deskripsi, is_active FROM kategori_gangguan ORDER BY is_active DESC, nama ASC'
        )->fetchAll();
    }

    /**
     * Cari kategori berdasarkan ID (aktif maupun nonaktif).
     *
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        $statement = Database::pdo()->prepare(
            'SELECT id, nama, deskripsi, is_active FROM kategori_gangguan WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Apakah nama kategori sudah dipakai (kecuali ID tertentu).
     */
    public static function nameExists(string $nama, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM kategori_gangguan WHERE nama = :nama';
        $params = ['nama' => $nama];
        if ($exceptId !== null && $exceptId > 0) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }
        $sql .= ' LIMIT 1';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Buat kategori baru.
     */
    public static function create(string $nama, ?string $deskripsi): int
    {
        $statement = Database::pdo()->prepare(
            'INSERT INTO kategori_gangguan (nama, deskripsi, is_active) VALUES (:nama, :deskripsi, 1)'
        );
        $statement->execute(['nama' => $nama, 'deskripsi' => $deskripsi]);

        return (int) Database::pdo()->lastInsertId();
    }

    /**
     * Perbarui nama & deskripsi kategori.
     */
    public static function update(int $id, string $nama, ?string $deskripsi): void
    {
        $statement = Database::pdo()->prepare(
            'UPDATE kategori_gangguan SET nama = :nama, deskripsi = :deskripsi WHERE id = :id'
        );
        $statement->execute(['nama' => $nama, 'deskripsi' => $deskripsi, 'id' => $id]);
    }

    /**
     * Aktifkan / nonaktifkan kategori (soft-disable, tidak dihapus).
     */
    public static function setActive(int $id, int $isActive): void
    {
        $statement = Database::pdo()->prepare(
            'UPDATE kategori_gangguan SET is_active = :is_active WHERE id = :id'
        );
        $statement->execute(['is_active' => $isActive, 'id' => $id]);
    }

    /**
     * Escape karakter khusus untuk pencarian LIKE.
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
