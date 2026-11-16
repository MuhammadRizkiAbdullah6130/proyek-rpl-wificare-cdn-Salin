<?php
/**
 * Model/repository penugasan teknisi.
 *
 * Semua query yang melibatkan teknisi WAJIB memfilter berdasarkan
 * identitas teknisi dari session (bukan dari input pengguna), agar teknisi
 * tidak dapat melihat tugas teknisi lain.
 */

declare(strict_types=1);

final class Penugasan
{
    /**
     * Total tugas (distinct tiket) yang pernah ditugaskan kepada seorang teknisi.
     */
    public static function countDistinctForTeknisi(int $teknisiId): int
    {
        $sql = 'SELECT COUNT(DISTINCT pt.pengaduan_id)
                FROM penugasan_teknisi pt
                WHERE pt.teknisi_id = :teknisi_id';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['teknisi_id' => $teknisiId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Jumlah tiket teknisi berdasarkan status pengaduan.
     *
     * @param array<int, string> $statuses
     */
    public static function countForTeknisiByStatus(int $teknisiId, array $statuses): int
    {
        if (empty($statuses)) {
            return 0;
        }

        $placeholders = [];
        $params = ['teknisi_id' => $teknisiId];
        foreach (array_values($statuses) as $index => $status) {
            $key = 'status_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = (string) $status;
        }

        $sql = 'SELECT COUNT(DISTINCT pt.pengaduan_id)
                FROM penugasan_teknisi pt
                INNER JOIN pengaduan p ON p.id = pt.pengaduan_id
                WHERE pt.teknisi_id = :teknisi_id
                  AND pt.is_active = 1
                  AND p.is_cancelled = 0
                  AND p.status IN (' . implode(', ', $placeholders) . ')';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    /**
     * Distribusi status tugas aktif seorang teknisi (untuk grafik).
     *
     * @return array<string, int>
     */
    public static function statusDistributionForTeknisi(int $teknisiId): array
    {
        $sql = 'SELECT p.status, COUNT(DISTINCT pt.pengaduan_id) AS jumlah
                FROM penugasan_teknisi pt
                INNER JOIN pengaduan p ON p.id = pt.pengaduan_id
                WHERE pt.teknisi_id = :teknisi_id
                  AND pt.is_active = 1
                  AND p.is_cancelled = 0
                GROUP BY p.status';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['teknisi_id' => $teknisiId]);

        $distribution = [];
        foreach (status_keys() as $key) {
            $distribution[$key] = 0;
        }

        foreach ($statement->fetchAll() as $row) {
            $status = (string) $row['status'];
            if (array_key_exists($status, $distribution)) {
                $distribution[$status] = (int) $row['jumlah'];
            }
        }

        return $distribution;
    }

    /**
     * Daftar tugas terbaru seorang teknisi.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function latestForTeknisi(int $teknisiId, int $limit = 5): array
    {
        $limit = max(1, min(50, $limit));

        $sql = 'SELECT p.id, p.nomor_tiket, p.nama_pelanggan_snapshot, p.username_pppoe_snapshot,
                       p.nomor_whatsapp_snapshot, p.status, p.created_at, p.is_cancelled,
                       k.nama AS kategori, pt.assigned_at, pt.is_active
                FROM penugasan_teknisi pt
                INNER JOIN pengaduan p ON p.id = pt.pengaduan_id
                LEFT JOIN kategori_gangguan k ON k.id = p.kategori_id
                WHERE pt.teknisi_id = :teknisi_id
                  AND pt.is_active = 1
                  AND p.is_cancelled = 0
                ORDER BY pt.assigned_at DESC, pt.id DESC
                LIMIT ' . $limit;

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['teknisi_id' => $teknisiId]);

        return $statement->fetchAll();
    }

    /**
     * Distribusi tugas seorang teknisi per kategori gangguan.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function categoryBreakdownForTeknisi(int $teknisiId): array
    {
        $sql = 'SELECT k.id, k.nama, COUNT(DISTINCT p.id) AS jumlah
                FROM penugasan_teknisi pt
                INNER JOIN pengaduan p ON p.id = pt.pengaduan_id
                INNER JOIN kategori_gangguan k ON k.id = p.kategori_id
                WHERE pt.teknisi_id = :teknisi_id
                  AND pt.is_active = 1
                  AND p.is_cancelled = 0
                GROUP BY k.id, k.nama
                ORDER BY jumlah DESC, k.nama ASC';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['teknisi_id' => $teknisiId]);

        return $statement->fetchAll();
    }

    /**
     * Ringkasan kinerja seluruh teknisi (untuk Admin/CS).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function performanceSummary(int $limit = 5): array
    {
        $limit = max(1, min(50, $limit));

        $sql = 'SELECT u.id, u.nama,
                       COUNT(DISTINCT pt.pengaduan_id) AS total_tugas,
                       COUNT(DISTINCT CASE WHEN p.status = \'selesai\' AND p.is_cancelled = 0
                                           THEN pt.pengaduan_id END) AS selesai,
                       COUNT(DISTINCT CASE WHEN p.status IN (\'menunggu_teknisi\', \'dalam_penanganan\')
                                            AND p.is_cancelled = 0
                                           THEN pt.pengaduan_id END) AS aktif
                FROM users u
                LEFT JOIN penugasan_teknisi pt ON pt.teknisi_id = u.id
                LEFT JOIN pengaduan p ON p.id = pt.pengaduan_id
                WHERE u.role = \'teknisi\' AND u.is_active = 1
                GROUP BY u.id, u.nama
                ORDER BY total_tugas DESC, selesai DESC, u.nama ASC
                LIMIT ' . $limit;

        $statement = Database::pdo()->query($sql);

        return $statement->fetchAll();
    }

    /* =====================================================================
     * Penugasan oleh Customer Service (Tahap 6)
     * =================================================================== */

    /**
     * Penugasan aktif pada satu tiket (bila ada).
     *
     * @return array<string, mixed>|null
     */
    public static function activeForPengaduan(int $pengaduanId): ?array
    {
        $sql = 'SELECT pt.id, pt.pengaduan_id, pt.teknisi_id, pt.catatan, pt.assigned_at, pt.started_at,
                       u.nama AS teknisi_nama, u.username AS teknisi_username
                FROM penugasan_teknisi pt
                INNER JOIN users u ON u.id = pt.teknisi_id
                WHERE pt.pengaduan_id = :pengaduan_id AND pt.is_active = 1
                ORDER BY pt.assigned_at DESC, pt.id DESC
                LIMIT 1';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['pengaduan_id' => $pengaduanId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Nonaktifkan seluruh penugasan aktif sebuah tiket (histori tetap disimpan).
     *
     * Dipakai sebelum membuat penugasan baru atau saat tiket dibatalkan.
     */
    public static function deactivateForPengaduan(int $pengaduanId): void
    {
        $statement = Database::pdo()->prepare(
            'UPDATE penugasan_teknisi SET is_active = 0 WHERE pengaduan_id = :pengaduan_id AND is_active = 1'
        );
        $statement->execute(['pengaduan_id' => $pengaduanId]);
    }

    /**
     * Buat penugasan baru (histori penugasan sebelumnya TIDAK dihapus).
     */
    public static function create(int $pengaduanId, int $teknisiId, int $assignedBy, ?string $catatan = null): int
    {
        $sql = 'INSERT INTO penugasan_teknisi (pengaduan_id, teknisi_id, ditugaskan_oleh, catatan, is_active)
                VALUES (:pengaduan_id, :teknisi_id, :assigned_by, :catatan, 1)';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute([
            'pengaduan_id' => $pengaduanId,
            'teknisi_id' => $teknisiId,
            'assigned_by' => $assignedBy,
            'catatan' => $catatan,
        ]);

        return (int) Database::pdo()->lastInsertId();
    }

    /**
     * Seluruh riwayat penugasan satu tiket (aktif maupun lama).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function historyForPengaduan(int $pengaduanId): array
    {
        $sql = 'SELECT pt.id, pt.is_active, pt.catatan, pt.assigned_at, pt.started_at,
                       u.nama AS teknisi_nama, u.username AS teknisi_username
                FROM penugasan_teknisi pt
                INNER JOIN users u ON u.id = pt.teknisi_id
                WHERE pt.pengaduan_id = :pengaduan_id
                ORDER BY pt.assigned_at DESC, pt.id DESC';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['pengaduan_id' => $pengaduanId]);

        return $statement->fetchAll();
    }

    /* =====================================================================
     * Modul Teknisi (Tahap 7)
     * =================================================================== */

    /**
     * Penugasan aktif pada satu tiket MILIK seorang teknisi tertentu.
     *
     * Dipakai sebagai satu-satunya sumber otorisasi kepemilikan tugas; identitas
     * teknisi selalu berasal dari session, bukan input browser.
     *
     * @return array<string, mixed>|null
     */
    public static function activeForTeknisi(int $pengaduanId, int $teknisiId): ?array
    {
        $sql = 'SELECT pt.id, pt.pengaduan_id, pt.teknisi_id, pt.catatan, pt.assigned_at, pt.started_at,
                       u.nama AS teknisi_nama
                FROM penugasan_teknisi pt
                INNER JOIN users u ON u.id = pt.teknisi_id
                WHERE pt.pengaduan_id = :pengaduan_id
                  AND pt.teknisi_id = :teknisi_id
                  AND pt.is_active = 1
                ORDER BY pt.assigned_at DESC, pt.id DESC
                LIMIT 1';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['pengaduan_id' => $pengaduanId, 'teknisi_id' => $teknisiId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Tandai waktu mulai penanganan pada penugasan aktif milik teknisi.
     *
     * Hanya mengisi started_at bila masih kosong agar waktu mulai pertama
     * tidak tertimpa oleh permintaan berulang.
     */
    public static function markStarted(int $pengaduanId, int $teknisiId): void
    {
        $sql = 'UPDATE penugasan_teknisi
                SET started_at = NOW()
                WHERE pengaduan_id = :pengaduan_id
                  AND teknisi_id = :teknisi_id
                  AND is_active = 1
                  AND started_at IS NULL';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['pengaduan_id' => $pengaduanId, 'teknisi_id' => $teknisiId]);
    }

    /**
     * Daftar tugas milik seorang teknisi dengan filter & pagination.
     *
     * Hanya penugasan AKTIF milik teknisi terkait yang ditampilkan. Tiket yang
     * dibatalkan otomatis tidak muncul karena penugasannya dinonaktifkan.
     *
     * @param array{q?: string, status?: string, kategori_id?: int, date_from?: string, date_to?: string} $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int, pages: int, page: int, per_page: int}
     */
    public static function paginateForTeknisi(int $teknisiId, array $filters, int $page, int $perPage = 10): array
    {
        $where = ['pt.teknisi_id = :teknisi_id', 'pt.is_active = 1'];
        $params = ['teknisi_id' => $teknisiId];

        $keyword = trim((string) ($filters['q'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . self::escapeLike($keyword) . '%';
            $where[] = '(p.nomor_tiket LIKE :q1 OR p.nama_pelanggan_snapshot LIKE :q2 OR p.username_pppoe_snapshot LIKE :q3)';
            $params['q1'] = $like;
            $params['q2'] = $like;
            $params['q3'] = $like;
        }

        $status = (string) ($filters['status'] ?? '');
        if ($status !== '' && in_array($status, status_keys(), true)) {
            $where[] = 'p.status = :status';
            $params['status'] = $status;
        }

        $kategoriId = (int) ($filters['kategori_id'] ?? 0);
        if ($kategoriId > 0) {
            $where[] = 'p.kategori_id = :kategori_id';
            $params['kategori_id'] = $kategoriId;
        }

        $dateFrom = (string) ($filters['date_from'] ?? '');
        if (self::isValidDate($dateFrom)) {
            $where[] = 'p.created_at >= :date_from';
            $params['date_from'] = $dateFrom . ' 00:00:00';
        }

        $dateTo = (string) ($filters['date_to'] ?? '');
        if (self::isValidDate($dateTo)) {
            $where[] = 'p.created_at <= :date_to';
            $params['date_to'] = $dateTo . ' 23:59:59';
        }

        $whereSql = implode(' AND ', $where);
        $joins = ' FROM penugasan_teknisi pt'
            . ' INNER JOIN pengaduan p ON p.id = pt.pengaduan_id';

        $countStatement = Database::pdo()->prepare('SELECT COUNT(*)' . $joins . ' WHERE ' . $whereSql);
        $countStatement->execute($params);
        $total = (int) $countStatement->fetchColumn();

        $perPage = max(1, min(50, $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        // $perPage dan $offset adalah integer tervalidasi, aman di-inline.
        $sql = 'SELECT p.id, p.nomor_tiket, p.nama_pelanggan_snapshot, p.username_pppoe_snapshot,
                       p.nomor_whatsapp_snapshot, p.status, p.is_cancelled, p.created_at,
                       k.nama AS kategori, pl.alamat AS alamat,
                       pt.assigned_at, pt.started_at, l.completed_at'
            . $joins
            . ' LEFT JOIN kategori_gangguan k ON k.id = p.kategori_id'
            . ' LEFT JOIN pelanggan pl ON pl.id = p.pelanggan_id'
            . ' LEFT JOIN laporan_teknisi l ON l.pengaduan_id = p.id'
            . ' WHERE ' . $whereSql
            . " ORDER BY FIELD(p.status, 'dalam_penanganan', 'menunggu_teknisi', 'selesai', 'ditolak'),
                       pt.assigned_at DESC, pt.id DESC"
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

    /* =====================================================================
     * Monitoring kinerja teknisi (Admin — Tahap 8)
     * =================================================================== */

    /**
     * Ringkasan kinerja seluruh teknisi dengan filter & pagination (Admin).
     *
     * Perhitungan memperhitungkan pembatalan (tiket dibatalkan tidak dihitung)
     * dan penugasan ulang (hanya penugasan AKTIF yang dihitung sebagai
     * "selesai"/"aktif", sehingga tiket yang dialihkan tidak dihitung ganda).
     *
     * @param array{q?: string, status?: string} $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int, pages: int, page: int, per_page: int}
     */
    public static function paginatePerformanceForAdmin(array $filters, int $page, int $perPage = 10): array
    {
        $where = ["u.role = 'teknisi'"];
        $params = [];

        $keyword = trim((string) ($filters['q'] ?? ''));
        if ($keyword !== '') {
            $where[] = 'u.nama LIKE :q';
            $params['q'] = '%' . self::escapeLike($keyword) . '%';
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

        // Semua nilai dinamis sudah terikat parameter; LIMIT/OFFSET integer tervalidasi.
        $sql = "SELECT u.id, u.nama, u.username, u.is_active,
                       COUNT(DISTINCT CASE WHEN p.is_cancelled = 0
                                           THEN pt.pengaduan_id END) AS total_tugas,
                       COUNT(DISTINCT CASE WHEN pt.is_active = 1 AND p.is_cancelled = 0
                                            AND p.status IN ('menunggu_teknisi', 'dalam_penanganan')
                                           THEN p.id END) AS aktif,
                       COUNT(DISTINCT CASE WHEN pt.is_active = 1 AND p.is_cancelled = 0
                                            AND p.status = 'selesai'
                                           THEN p.id END) AS selesai,
                       AVG(CASE WHEN pt.is_active = 1 AND p.is_cancelled = 0 AND p.status = 'selesai'
                                 AND pt.started_at IS NOT NULL AND l.completed_at IS NOT NULL
                                THEN TIMESTAMPDIFF(MINUTE, pt.started_at, l.completed_at) END) AS avg_menit,
                       (SELECT AVG(r2.rating) FROM rating_layanan r2
                          INNER JOIN penugasan_teknisi pt2 ON pt2.pengaduan_id = r2.pengaduan_id AND pt2.is_active = 1
                          WHERE pt2.teknisi_id = u.id) AS rating_rata,
                       (SELECT COUNT(*) FROM rating_layanan r3
                          INNER JOIN penugasan_teknisi pt3 ON pt3.pengaduan_id = r3.pengaduan_id AND pt3.is_active = 1
                          WHERE pt3.teknisi_id = u.id) AS rating_jumlah
                FROM users u
                LEFT JOIN penugasan_teknisi pt ON pt.teknisi_id = u.id
                LEFT JOIN pengaduan p ON p.id = pt.pengaduan_id
                LEFT JOIN laporan_teknisi l ON l.pengaduan_id = p.id
                WHERE " . $whereSql . "
                GROUP BY u.id, u.nama, u.username, u.is_active
                ORDER BY selesai DESC, total_tugas DESC, u.nama ASC
                LIMIT " . $perPage . ' OFFSET ' . $offset;

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
     * Ringkasan kinerja satu teknisi (Admin).
     *
     * @return array<string, mixed>|null
     */
    public static function performanceForTeknisi(int $teknisiId): ?array
    {
        $sql = "SELECT u.id, u.nama, u.username, u.is_active,
                       COUNT(DISTINCT CASE WHEN p.is_cancelled = 0
                                           THEN pt.pengaduan_id END) AS total_tugas,
                       COUNT(DISTINCT CASE WHEN pt.is_active = 1 AND p.is_cancelled = 0
                                            AND p.status IN ('menunggu_teknisi', 'dalam_penanganan')
                                           THEN p.id END) AS aktif,
                       COUNT(DISTINCT CASE WHEN pt.is_active = 1 AND p.is_cancelled = 0
                                            AND p.status = 'selesai'
                                           THEN p.id END) AS selesai,
                       AVG(CASE WHEN pt.is_active = 1 AND p.is_cancelled = 0 AND p.status = 'selesai'
                                 AND pt.started_at IS NOT NULL AND l.completed_at IS NOT NULL
                                THEN TIMESTAMPDIFF(MINUTE, pt.started_at, l.completed_at) END) AS avg_menit
                FROM users u
                LEFT JOIN penugasan_teknisi pt ON pt.teknisi_id = u.id
                LEFT JOIN pengaduan p ON p.id = pt.pengaduan_id
                LEFT JOIN laporan_teknisi l ON l.pengaduan_id = p.id
                WHERE u.id = :id AND u.role = 'teknisi'
                GROUP BY u.id, u.nama, u.username, u.is_active
                LIMIT 1";

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['id' => $teknisiId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Riwayat pekerjaan seorang teknisi untuk Admin (termasuk penugasan lama).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function historyForAdmin(int $teknisiId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        $sql = 'SELECT p.id, p.nomor_tiket, p.nama_pelanggan_snapshot, p.status, p.is_cancelled,
                       p.archived_at, p.created_at,
                       k.nama AS kategori, pt.is_active AS penugasan_aktif,
                       pt.assigned_at, pt.started_at,
                       l.completed_at, r.rating
                FROM penugasan_teknisi pt
                INNER JOIN pengaduan p ON p.id = pt.pengaduan_id
                LEFT JOIN kategori_gangguan k ON k.id = p.kategori_id
                LEFT JOIN laporan_teknisi l ON l.pengaduan_id = p.id
                LEFT JOIN rating_layanan r ON r.pengaduan_id = p.id
                WHERE pt.teknisi_id = :teknisi_id
                ORDER BY p.created_at DESC, p.id DESC
                LIMIT ' . $limit;

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['teknisi_id' => $teknisiId]);

        return $statement->fetchAll();
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
