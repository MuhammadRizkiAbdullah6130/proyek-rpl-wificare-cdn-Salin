<?php
/**
 * Model catatan internal Customer Service.
 *
 * Catatan internal HANYA boleh dibaca petugas (CS/Admin). Tidak pernah
 * dimuat oleh view pelanggan, dan tidak mengubah status tiket.
 */

declare(strict_types=1);

final class CatatanCs
{
    /**
     * Simpan satu catatan internal pada tiket.
     *
     * @return int ID catatan baru.
     */
    public static function create(int $pengaduanId, int $csId, string $catatan): int
    {
        $sql = 'INSERT INTO catatan_cs (pengaduan_id, cs_id, catatan)
                VALUES (:pengaduan_id, :cs_id, :catatan)';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute([
            'pengaduan_id' => $pengaduanId,
            'cs_id' => $csId,
            'catatan' => $catatan,
        ]);

        return (int) Database::pdo()->lastInsertId();
    }

    /**
     * Riwayat catatan internal satu tiket (terbaru di bawah).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forPengaduan(int $pengaduanId): array
    {
        $sql = 'SELECT c.id, c.catatan, c.created_at, u.nama AS cs_nama, u.username AS cs_username
                FROM catatan_cs c
                LEFT JOIN users u ON u.id = c.cs_id
                WHERE c.pengaduan_id = :pengaduan_id
                ORDER BY c.created_at ASC, c.id ASC';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['pengaduan_id' => $pengaduanId]);

        return $statement->fetchAll();
    }

    /**
     * Catatan internal terbaru lintas tiket (untuk halaman Catatan Internal).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function recent(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        $sql = 'SELECT c.id, c.pengaduan_id, c.catatan, c.created_at,
                       u.nama AS cs_nama, p.nomor_tiket, p.status, p.is_cancelled
                FROM catatan_cs c
                INNER JOIN pengaduan p ON p.id = c.pengaduan_id
                LEFT JOIN users u ON u.id = c.cs_id
                ORDER BY c.created_at DESC, c.id DESC
                LIMIT ' . $limit;

        return Database::pdo()->query($sql)->fetchAll();
    }

    /**
     * Jumlah catatan internal pada sebuah tiket.
     */
    public static function countForPengaduan(int $pengaduanId): int
    {
        $statement = Database::pdo()->prepare('SELECT COUNT(*) FROM catatan_cs WHERE pengaduan_id = :id');
        $statement->execute(['id' => $pengaduanId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Jumlah seluruh catatan internal (ringkasan).
     */
    public static function countAll(): int
    {
        return (int) Database::pdo()->query('SELECT COUNT(*) FROM catatan_cs')->fetchColumn();
    }
}
