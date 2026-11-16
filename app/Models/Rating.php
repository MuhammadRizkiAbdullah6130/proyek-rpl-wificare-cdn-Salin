<?php
/**
 * Model rating & ulasan layanan (rating_layanan).
 *
 * Satu tiket maksimal satu rating (UNIQUE pengaduan_id). Rating hanya boleh
 * diberikan pelanggan pemilik tiket yang berstatus selesai; validasi bisnis
 * dilakukan oleh RatingService, model hanya menyimpan/membaca.
 */

declare(strict_types=1);

final class Rating
{
    /**
     * Simpan rating baru (WAJIB dipanggil di dalam transaksi bila digabung
     * dengan perubahan lain).
     */
    public static function create(int $pengaduanId, int $pelangganId, int $rating, ?string $ulasan): int
    {
        $sql = 'INSERT INTO rating_layanan (pengaduan_id, pelanggan_id, rating, ulasan)
                VALUES (:pengaduan_id, :pelanggan_id, :rating, :ulasan)';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute([
            'pengaduan_id' => $pengaduanId,
            'pelanggan_id' => $pelangganId,
            'rating' => $rating,
            'ulasan' => $ulasan,
        ]);

        return (int) Database::pdo()->lastInsertId();
    }

    /**
     * Apakah tiket sudah memiliki rating.
     */
    public static function existsForPengaduan(int $pengaduanId): bool
    {
        $statement = Database::pdo()->prepare(
            'SELECT 1 FROM rating_layanan WHERE pengaduan_id = :pengaduan_id LIMIT 1'
        );
        $statement->execute(['pengaduan_id' => $pengaduanId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Rating satu tiket (bila ada).
     *
     * @return array<string, mixed>|null
     */
    public static function forPengaduan(int $pengaduanId): ?array
    {
        $sql = 'SELECT r.id, r.rating, r.ulasan, r.created_at
                FROM rating_layanan r
                WHERE r.pengaduan_id = :pengaduan_id
                LIMIT 1';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['pengaduan_id' => $pengaduanId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Ringkasan rating seluruh layanan (rata-rata + distribusi + total).
     *
     * @return array{average: float, total: int, distribution: array<int, int>}
     */
    public static function summary(): array
    {
        $distribution = [];
        for ($i = 1; $i <= 5; $i++) {
            $distribution[$i] = 0;
        }

        $rows = Database::pdo()->query(
            'SELECT rating, COUNT(*) AS jumlah FROM rating_layanan GROUP BY rating'
        )->fetchAll();

        $total = 0;
        $sum = 0;
        foreach ($rows as $row) {
            $value = (int) $row['rating'];
            $count = (int) $row['jumlah'];
            if (array_key_exists($value, $distribution)) {
                $distribution[$value] = $count;
            }
            $total += $count;
            $sum += $value * $count;
        }

        return [
            'average' => $total > 0 ? round($sum / $total, 2) : 0.0,
            'total' => $total,
            'distribution' => $distribution,
        ];
    }

    /**
     * Rating terbaru beserta informasi tiket & teknisi (untuk Admin).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function recent(int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));

        $sql = 'SELECT r.id, r.rating, r.ulasan, r.created_at,
                       p.nomor_tiket, p.nama_pelanggan_snapshot,
                       t.nama AS teknisi_nama
                FROM rating_layanan r
                INNER JOIN pengaduan p ON p.id = r.pengaduan_id
                LEFT JOIN penugasan_teknisi pt ON pt.pengaduan_id = p.id AND pt.is_active = 1
                LEFT JOIN users t ON t.id = pt.teknisi_id
                ORDER BY r.created_at DESC, r.id DESC
                LIMIT ' . $limit;

        return Database::pdo()->query($sql)->fetchAll();
    }

    /**
     * Rata-rata rating untuk tiket yang ditangani seorang teknisi.
     */
    public static function averageForTeknisi(int $teknisiId): ?float
    {
        $sql = 'SELECT AVG(r.rating) AS rata
                FROM rating_layanan r
                INNER JOIN pengaduan p ON p.id = r.pengaduan_id
                INNER JOIN penugasan_teknisi pt ON pt.pengaduan_id = p.id AND pt.is_active = 1
                WHERE pt.teknisi_id = :teknisi_id';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['teknisi_id' => $teknisiId]);
        $value = $statement->fetchColumn();

        return $value === null || $value === false ? null : round((float) $value, 2);
    }

    /**
     * Jumlah rating untuk tiket yang ditangani seorang teknisi.
     */
    public static function countForTeknisi(int $teknisiId): int
    {
        $sql = 'SELECT COUNT(*)
                FROM rating_layanan r
                INNER JOIN pengaduan p ON p.id = r.pengaduan_id
                INNER JOIN penugasan_teknisi pt ON pt.pengaduan_id = p.id AND pt.is_active = 1
                WHERE pt.teknisi_id = :teknisi_id';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['teknisi_id' => $teknisiId]);

        return (int) $statement->fetchColumn();
    }
}
