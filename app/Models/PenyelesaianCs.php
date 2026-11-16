<?php
/**
 * Model penyelesaian langsung oleh Customer Service (tanpa teknisi).
 *
 * Satu tiket hanya memiliki satu penyelesaian (UNIQUE pengaduan_id).
 */

declare(strict_types=1);

final class PenyelesaianCs
{
    /**
     * Simpan solusi penyelesaian langsung oleh CS.
     */
    public static function create(int $pengaduanId, int $csId, string $solusi): int
    {
        $sql = 'INSERT INTO penyelesaian_cs (pengaduan_id, cs_id, solusi)
                VALUES (:pengaduan_id, :cs_id, :solusi)';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute([
            'pengaduan_id' => $pengaduanId,
            'cs_id' => $csId,
            'solusi' => $solusi,
        ]);

        return (int) Database::pdo()->lastInsertId();
    }

    /**
     * Penyelesaian CS satu tiket (bila ada).
     *
     * @return array<string, mixed>|null
     */
    public static function forPengaduan(int $pengaduanId): ?array
    {
        $sql = 'SELECT c.solusi, c.completed_at, u.nama AS cs_nama
                FROM penyelesaian_cs c
                LEFT JOIN users u ON u.id = c.cs_id
                WHERE c.pengaduan_id = :pengaduan_id
                LIMIT 1';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['pengaduan_id' => $pengaduanId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }
}
