<?php
/**
 * Model/repository laporan perbaikan teknisi.
 */

declare(strict_types=1);

final class LaporanTeknisi
{
    /**
     * Laporan terbaru milik seorang teknisi.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function recentForTeknisi(int $teknisiId, int $limit = 5): array
    {
        $limit = max(1, min(50, $limit));

        $sql = 'SELECT l.id, l.pengaduan_id, l.penyebab_gangguan, l.tindakan_perbaikan,
                       l.completed_at, p.nomor_tiket, p.nama_pelanggan_snapshot
                FROM laporan_teknisi l
                INNER JOIN pengaduan p ON p.id = l.pengaduan_id
                WHERE l.teknisi_id = :teknisi_id
                ORDER BY l.completed_at DESC, l.id DESC
                LIMIT ' . $limit;

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['teknisi_id' => $teknisiId]);

        return $statement->fetchAll();
    }

    /**
     * Jumlah laporan selesai pada hari ini untuk seorang teknisi.
     */
    public static function countCompletedToday(int $teknisiId): int
    {
        $sql = 'SELECT COUNT(*) FROM laporan_teknisi
                WHERE teknisi_id = :teknisi_id AND DATE(completed_at) = CURDATE()';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['teknisi_id' => $teknisiId]);

        return (int) $statement->fetchColumn();
    }

    /* =====================================================================
     * Modul Teknisi (Tahap 7)
     * =================================================================== */

    /**
     * Apakah sebuah tiket sudah memiliki laporan perbaikan.
     */
    public static function existsForPengaduan(int $pengaduanId): bool
    {
        $statement = Database::pdo()->prepare(
            'SELECT 1 FROM laporan_teknisi WHERE pengaduan_id = :pengaduan_id LIMIT 1'
        );
        $statement->execute(['pengaduan_id' => $pengaduanId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Laporan perbaikan satu tiket beserta identitas teknisi (bila ada).
     *
     * @return array<string, mixed>|null
     */
    public static function findByPengaduan(int $pengaduanId): ?array
    {
        $sql = 'SELECT l.id, l.pengaduan_id, l.teknisi_id, l.penyebab_gangguan, l.tindakan_perbaikan,
                       l.catatan, l.completed_at, u.nama AS teknisi_nama
                FROM laporan_teknisi l
                LEFT JOIN users u ON u.id = l.teknisi_id
                WHERE l.pengaduan_id = :pengaduan_id
                LIMIT 1';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['pengaduan_id' => $pengaduanId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Simpan laporan perbaikan baru (WAJIB dipanggil di dalam transaksi).
     *
     * @return int ID laporan baru.
     */
    public static function create(
        int $pengaduanId,
        int $teknisiId,
        string $penyebab,
        string $tindakan,
        ?string $catatan
    ): int {
        $sql = 'INSERT INTO laporan_teknisi
                    (pengaduan_id, teknisi_id, penyebab_gangguan, tindakan_perbaikan, catatan, completed_at)
                VALUES
                    (:pengaduan_id, :teknisi_id, :penyebab, :tindakan, :catatan, NOW())';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute([
            'pengaduan_id' => $pengaduanId,
            'teknisi_id' => $teknisiId,
            'penyebab' => $penyebab,
            'tindakan' => $tindakan,
            'catatan' => $catatan,
        ]);

        return (int) Database::pdo()->lastInsertId();
    }

    /**
     * Simpan referensi satu foto hasil perbaikan.
     *
     * @param array{path: string, original_name: string, mime_type: string, file_size: int} $foto
     */
    public static function insertFoto(int $laporanId, array $foto): void
    {
        $sql = 'INSERT INTO laporan_foto (laporan_id, file_path, original_name, mime_type, file_size)
                VALUES (:laporan_id, :file_path, :original_name, :mime_type, :file_size)';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute([
            'laporan_id' => $laporanId,
            'file_path' => (string) $foto['path'],
            'original_name' => (string) $foto['original_name'],
            'mime_type' => (string) $foto['mime_type'],
            'file_size' => (int) $foto['file_size'],
        ]);
    }

    /**
     * Daftar foto hasil perbaikan milik satu laporan.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function fotosFor(int $laporanId): array
    {
        $sql = 'SELECT id, file_path, original_name, mime_type, file_size, created_at
                FROM laporan_foto
                WHERE laporan_id = :laporan_id
                ORDER BY id ASC';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['laporan_id' => $laporanId]);

        return $statement->fetchAll();
    }

    /**
     * Data satu foto laporan beserta pemilik tiket & teknisi (untuk pemeriksaan akses).
     *
     * @return array<string, mixed>|null
     */
    public static function findFoto(int $fotoId): ?array
    {
        $sql = 'SELECT f.id, f.laporan_id, f.file_path, f.original_name, f.mime_type, f.file_size,
                       l.pengaduan_id, l.teknisi_id, p.pelanggan_id
                FROM laporan_foto f
                INNER JOIN laporan_teknisi l ON l.id = f.laporan_id
                INNER JOIN pengaduan p ON p.id = l.pengaduan_id
                WHERE f.id = :id
                LIMIT 1';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['id' => $fotoId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Riwayat pekerjaan seorang teknisi dengan filter & pagination.
     *
     * @param array{q?: string, kategori_id?: int, date_from?: string, date_to?: string} $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int, pages: int, page: int, per_page: int}
     */
    public static function paginateHistoryForTeknisi(int $teknisiId, array $filters, int $page, int $perPage = 10): array
    {
        $where = ['l.teknisi_id = :teknisi_id'];
        $params = ['teknisi_id' => $teknisiId];

        $keyword = trim((string) ($filters['q'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . self::escapeLike($keyword) . '%';
            $where[] = '(p.nomor_tiket LIKE :q1 OR p.nama_pelanggan_snapshot LIKE :q2)';
            $params['q1'] = $like;
            $params['q2'] = $like;
        }

        $kategoriId = (int) ($filters['kategori_id'] ?? 0);
        if ($kategoriId > 0) {
            $where[] = 'p.kategori_id = :kategori_id';
            $params['kategori_id'] = $kategoriId;
        }

        $dateFrom = (string) ($filters['date_from'] ?? '');
        if (self::isValidDate($dateFrom)) {
            $where[] = 'l.completed_at >= :date_from';
            $params['date_from'] = $dateFrom . ' 00:00:00';
        }

        $dateTo = (string) ($filters['date_to'] ?? '');
        if (self::isValidDate($dateTo)) {
            $where[] = 'l.completed_at <= :date_to';
            $params['date_to'] = $dateTo . ' 23:59:59';
        }

        $whereSql = implode(' AND ', $where);
        $joins = ' FROM laporan_teknisi l'
            . ' INNER JOIN pengaduan p ON p.id = l.pengaduan_id';

        $countStatement = Database::pdo()->prepare('SELECT COUNT(*)' . $joins . ' WHERE ' . $whereSql);
        $countStatement->execute($params);
        $total = (int) $countStatement->fetchColumn();

        $perPage = max(1, min(50, $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        // $perPage dan $offset adalah integer tervalidasi, aman di-inline.
        $sql = 'SELECT l.id AS laporan_id, l.pengaduan_id, l.penyebab_gangguan, l.tindakan_perbaikan,
                       l.catatan, l.completed_at, p.nomor_tiket, p.nama_pelanggan_snapshot, p.status,
                       k.nama AS kategori, pt.assigned_at, pt.started_at'
            . $joins
            . ' LEFT JOIN kategori_gangguan k ON k.id = p.kategori_id'
            . ' LEFT JOIN penugasan_teknisi pt ON pt.pengaduan_id = p.id AND pt.teknisi_id = l.teknisi_id AND pt.is_active = 1'
            . ' WHERE ' . $whereSql
            . ' ORDER BY l.completed_at DESC, l.id DESC'
            . ' LIMIT ' . $perPage . ' OFFSET ' . $offset;

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
     * Escape karakter khusus untuk pencarian LIKE.
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * Validasi format tanggal (Y-m-d).
     */
    private static function isValidDate(string $value): bool
    {
        if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year);
    }
}
