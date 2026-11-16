<?php
/**
 * Model/repository LAPORAN (Tahap 9).
 *
 * Seluruh query analitik laporan dikumpulkan di sini agar controller, service,
 * dan view tidak menulis SQL. Parameter SELALU di-bind (PDO prepared statement);
 * tidak ada input pengguna yang disisipkan langsung ke SQL.
 *
 * Definisi penting:
 * - Tiket dibatalkan (is_cancelled = 1) BUKAN tiket aktif dan BUKAN status ketujuh.
 * - Pengarsipan (archived_at) BUKAN status; hanya penanda terpisah.
 * - Waktu penyelesaian = COALESCE(laporan_teknisi.completed_at,
 *   penyelesaian_cs.completed_at). Tiket yang belum selesai tidak dihitung 0.
 * - Semua penghitungan memakai COUNT(DISTINCT p.id) agar join foto/riwayat/
 *   penugasan tidak menghitung tiket yang sama dua kali.
 */

declare(strict_types=1);

final class Laporan
{
    /**
     * JOIN bersama untuk laporan. Semua relasi bersifat 1 : 0..1 sehingga tidak
     * menggandakan baris pengaduan.
     */
    public static function joins(): string
    {
        return ' LEFT JOIN kategori_gangguan k ON k.id = p.kategori_id'
            . ' LEFT JOIN penugasan_teknisi pt ON pt.pengaduan_id = p.id AND pt.is_active = 1'
            . ' LEFT JOIN users t ON t.id = pt.teknisi_id'
            . ' LEFT JOIN laporan_teknisi l ON l.pengaduan_id = p.id'
            . ' LEFT JOIN penyelesaian_cs c ON c.pengaduan_id = p.id'
            . ' LEFT JOIN rating_layanan r ON r.pengaduan_id = p.id';
    }

    /**
     * Bangun klausa WHERE + parameter dari filter TERVALIDASI.
     *
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    public static function where(array $filters): array
    {
        $where = ['1 = 1'];
        $params = [];

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

        $teknisiId = (int) ($filters['teknisi_id'] ?? 0);
        if ($teknisiId > 0) {
            $where[] = 'pt.teknisi_id = :teknisi_id';
            $params['teknisi_id'] = $teknisiId;
        }

        $pelangganId = (int) ($filters['pelanggan_id'] ?? 0);
        if ($pelangganId > 0) {
            $where[] = 'p.pelanggan_id = :pelanggan_id';
            $params['pelanggan_id'] = $pelangganId;
        }

        $cancelled = (string) ($filters['cancelled'] ?? 'semua');
        if ($cancelled === 'ya') {
            $where[] = 'p.is_cancelled = 1';
        } elseif ($cancelled === 'tidak') {
            $where[] = 'p.is_cancelled = 0';
        }

        $arsip = (string) ($filters['arsip'] ?? 'semua');
        if ($arsip === 'aktif') {
            $where[] = 'p.archived_at IS NULL';
        } elseif ($arsip === 'arsip') {
            $where[] = 'p.archived_at IS NOT NULL';
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

        return [implode(' AND ', $where), $params];
    }

    /**
     * Ringkasan metrik utama.
     *
     * @param array<string, mixed> $filters
     * @return array{total: int, aktif: int, selesai: int, ditolak: int, dibatalkan: int}
     */
    public static function summary(array $filters): array
    {
        [$whereSql, $params] = self::where($filters);
        $activeSql = self::activePlaceholders($params, 's_aktif_');

        $sql = 'SELECT COUNT(DISTINCT p.id) AS total,
                       COUNT(DISTINCT CASE WHEN p.status IN (' . $activeSql . ') AND p.is_cancelled = 0 THEN p.id END) AS aktif,
                       COUNT(DISTINCT CASE WHEN p.status = \'selesai\' THEN p.id END) AS selesai,
                       COUNT(DISTINCT CASE WHEN p.status = \'ditolak\' THEN p.id END) AS ditolak,
                       COUNT(DISTINCT CASE WHEN p.is_cancelled = 1 THEN p.id END) AS dibatalkan'
            . ' FROM pengaduan p' . self::joins()
            . ' WHERE ' . $whereSql;

        $statement = Database::pdo()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch() ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'aktif' => (int) ($row['aktif'] ?? 0),
            'selesai' => (int) ($row['selesai'] ?? 0),
            'ditolak' => (int) ($row['ditolak'] ?? 0),
            'dibatalkan' => (int) ($row['dibatalkan'] ?? 0),
        ];
    }

    /**
     * Distribusi jumlah tiket per status (selalu enam kunci).
     *
     * @param array<string, mixed> $filters
     * @return array<string, int>
     */
    public static function statusDistribution(array $filters): array
    {
        [$whereSql, $params] = self::where($filters);

        $sql = 'SELECT p.status, COUNT(DISTINCT p.id) AS jumlah'
            . ' FROM pengaduan p' . self::joins()
            . ' WHERE ' . $whereSql
            . ' GROUP BY p.status';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute($params);

        $distribution = [];
        foreach (status_keys() as $key) {
            $distribution[$key] = 0;
        }
        foreach ($statement->fetchAll() as $row) {
            $key = (string) $row['status'];
            if (array_key_exists($key, $distribution)) {
                $distribution[$key] = (int) $row['jumlah'];
            }
        }

        return $distribution;
    }

    /**
     * Distribusi per kategori gangguan (untuk kategori dengan tiket pada lingkup filter).
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function categoryDistribution(array $filters): array
    {
        [$whereSql, $params] = self::where($filters);
        $activeSql = self::activePlaceholders($params, 'k_aktif_');

        $sql = 'SELECT k.id, k.nama,
                       COUNT(DISTINCT p.id) AS jumlah,
                       COUNT(DISTINCT CASE WHEN p.status = \'selesai\' THEN p.id END) AS selesai,
                       COUNT(DISTINCT CASE WHEN p.status IN (' . $activeSql . ') AND p.is_cancelled = 0 THEN p.id END) AS aktif'
            . ' FROM pengaduan p' . self::joins()
            . ' WHERE ' . $whereSql
            . ' GROUP BY k.id, k.nama'
            . ' ORDER BY jumlah DESC, k.nama ASC';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /**
     * Baris periode (harian/mingguan/bulanan) beserta rekap status.
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function periodRows(array $filters, string $group = 'day'): array
    {
        $expr = match ($group) {
            'month' => "DATE_FORMAT(p.created_at, '%Y-%m')",
            'week' => "DATE_FORMAT(p.created_at, '%x-W%v')",
            default => 'DATE(p.created_at)',
        };

        [$whereSql, $params] = self::where($filters);
        $activeSql = self::activePlaceholders($params, 'p_aktif_');

        $sql = 'SELECT ' . $expr . ' AS periode,
                       COUNT(DISTINCT p.id) AS jumlah,
                       COUNT(DISTINCT CASE WHEN p.status = \'selesai\' THEN p.id END) AS selesai,
                       COUNT(DISTINCT CASE WHEN p.status = \'ditolak\' THEN p.id END) AS ditolak,
                       COUNT(DISTINCT CASE WHEN p.is_cancelled = 1 THEN p.id END) AS dibatalkan,
                       COUNT(DISTINCT CASE WHEN p.status IN (' . $activeSql . ') AND p.is_cancelled = 0 THEN p.id END) AS aktif'
            . ' FROM pengaduan p' . self::joins()
            . ' WHERE ' . $whereSql
            . ' GROUP BY periode'
            . ' ORDER BY periode ASC';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /**
     * Kinerja per teknisi (jumlah tugas, selesai, rata-rata waktu, rating).
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function technicianPerformance(array $filters): array
    {
        [$whereSql, $params] = self::where($filters);
        $activeSql = self::activePlaceholders($params, 't_aktif_');

        $sql = 'SELECT t.id, t.nama, t.username,
                       COUNT(DISTINCT p.id) AS total_tugas,
                       COUNT(DISTINCT CASE WHEN p.status IN (' . $activeSql . ') AND p.is_cancelled = 0 THEN p.id END) AS aktif,
                       COUNT(DISTINCT CASE WHEN p.status = \'selesai\' THEN p.id END) AS selesai,
                       AVG(CASE WHEN p.status = \'selesai\' THEN TIMESTAMPDIFF(MINUTE, p.created_at, COALESCE(l.completed_at, c.completed_at)) END) AS avg_menit,
                       AVG(r.rating) AS rating_rata'
            . ' FROM pengaduan p' . self::joins()
            . ' WHERE ' . $whereSql . ' AND t.id IS NOT NULL'
            . ' GROUP BY t.id, t.nama, t.username'
            . ' ORDER BY total_tugas DESC, t.nama ASC';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /**
     * Rekap per pelanggan (riwayat pengaduan pelanggan).
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function customerSummary(array $filters): array
    {
        [$whereSql, $params] = self::where($filters);
        $activeSql = self::activePlaceholders($params, 'c_aktif_');

        $sql = 'SELECT pl.id, pl.username_pppoe, pu.nama AS nama,
                       COUNT(DISTINCT p.id) AS total,
                       COUNT(DISTINCT CASE WHEN p.status IN (' . $activeSql . ') AND p.is_cancelled = 0 THEN p.id END) AS aktif,
                       COUNT(DISTINCT CASE WHEN p.status = \'selesai\' THEN p.id END) AS selesai,
                       COUNT(DISTINCT CASE WHEN p.status = \'ditolak\' THEN p.id END) AS ditolak,
                       COUNT(DISTINCT CASE WHEN p.is_cancelled = 1 THEN p.id END) AS dibatalkan,
                       AVG(r.rating) AS rating_rata'
            . ' FROM pengaduan p' . self::joins()
            . ' INNER JOIN pelanggan pl ON pl.id = p.pelanggan_id'
            . ' INNER JOIN users pu ON pu.id = pl.user_id'
            . ' WHERE ' . $whereSql
            . ' GROUP BY pl.id, pl.username_pppoe, pu.nama'
            . ' ORDER BY total DESC, pu.nama ASC';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /**
     * Statistik waktu penyelesaian (hanya tiket yang benar-benar selesai).
     *
     * @param array<string, mixed> $filters
     * @return array{count: int, avg: float|null, min: int|null, max: int|null, median: float|null}
     */
    public static function completionStats(array $filters): array
    {
        [$whereSql, $params] = self::where($filters);

        $sql = 'SELECT TIMESTAMPDIFF(MINUTE, p.created_at, COALESCE(l.completed_at, c.completed_at)) AS durasi'
            . ' FROM pengaduan p' . self::joins()
            . ' WHERE ' . $whereSql
            . ' AND p.status = \'selesai\''
            . ' AND COALESCE(l.completed_at, c.completed_at) IS NOT NULL'
            . ' ORDER BY durasi ASC';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute($params);
        $durations = array_map(static fn ($row): int => (int) $row['durasi'], $statement->fetchAll());

        $count = count($durations);
        if ($count === 0) {
            return ['count' => 0, 'avg' => null, 'min' => null, 'max' => null, 'median' => null];
        }

        $middle = intdiv($count, 2);
        $median = $count % 2 === 0
            ? ($durations[$middle - 1] + $durations[$middle]) / 2
            : (float) $durations[$middle];

        return [
            'count' => $count,
            'avg' => array_sum($durations) / $count,
            'min' => $durations[0],
            'max' => $durations[$count - 1],
            'median' => $median,
        ];
    }

    /**
     * Baris tiket selesai beserta durasi penyelesaian.
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function completionRows(array $filters): array
    {
        [$whereSql, $params] = self::where($filters);

        $sql = 'SELECT p.id, p.nomor_tiket, p.nama_pelanggan_snapshot, p.username_pppoe_snapshot,
                       k.nama AS kategori, t.nama AS teknisi_nama, p.created_at,
                       COALESCE(l.completed_at, c.completed_at) AS completed_at,
                       CASE WHEN l.completed_at IS NOT NULL THEN \'Teknisi\'
                            WHEN c.completed_at IS NOT NULL THEN \'CS\'
                            ELSE \'-\' END AS jenis_penyelesaian,
                       TIMESTAMPDIFF(MINUTE, p.created_at, COALESCE(l.completed_at, c.completed_at)) AS durasi_menit'
            . ' FROM pengaduan p' . self::joins()
            . ' WHERE ' . $whereSql
            . ' AND p.status = \'selesai\''
            . ' AND COALESCE(l.completed_at, c.completed_at) IS NOT NULL'
            . ' ORDER BY durasi_menit DESC, p.id DESC';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /**
     * Statistik rating (rata-rata, jumlah, distribusi bintang).
     *
     * @param array<string, mixed> $filters
     * @return array{average: float|null, total: int, distribution: array<int, int>}
     */
    public static function ratingStats(array $filters): array
    {
        [$whereSql, $params] = self::where($filters);

        $sql = 'SELECT r.rating, COUNT(*) AS jumlah'
            . ' FROM pengaduan p' . self::joins()
            . ' WHERE ' . $whereSql . ' AND r.id IS NOT NULL'
            . ' GROUP BY r.rating';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute($params);

        $distribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        $sum = 0;
        $total = 0;
        foreach ($statement->fetchAll() as $row) {
            $star = (int) $row['rating'];
            $jumlah = (int) $row['jumlah'];
            if (array_key_exists($star, $distribution)) {
                $distribution[$star] = $jumlah;
            }
            $sum += $star * $jumlah;
            $total += $jumlah;
        }

        return [
            'average' => $total > 0 ? $sum / $total : null,
            'total' => $total,
            'distribution' => $distribution,
        ];
    }

    /**
     * Tren rating per bulan (rata-rata + jumlah).
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function ratingTrend(array $filters): array
    {
        [$whereSql, $params] = self::where($filters);

        $sql = 'SELECT DATE_FORMAT(r.created_at, \'%Y-%m\') AS periode,
                       AVG(r.rating) AS rata_rata, COUNT(*) AS jumlah'
            . ' FROM pengaduan p' . self::joins()
            . ' WHERE ' . $whereSql . ' AND r.id IS NOT NULL'
            . ' GROUP BY periode ORDER BY periode ASC';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /**
     * Baris rating & ulasan.
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function ratingRows(array $filters): array
    {
        [$whereSql, $params] = self::where($filters);

        $sql = 'SELECT r.rating, r.ulasan, r.created_at,
                       p.id, p.nomor_tiket, p.nama_pelanggan_snapshot, p.username_pppoe_snapshot,
                       k.nama AS kategori, t.nama AS teknisi_nama'
            . ' FROM pengaduan p' . self::joins()
            . ' WHERE ' . $whereSql . ' AND r.id IS NOT NULL'
            . ' ORDER BY r.created_at DESC, p.id DESC';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /**
     * Daftar tiket dengan filter & pagination (laporan "Semua Pengaduan").
     *
     * @param array<string, mixed> $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int, pages: int, page: int, per_page: int}
     */
    public static function paginateTickets(array $filters, int $page, int $perPage = 20): array
    {
        [$whereSql, $params] = self::where($filters);

        $countStatement = Database::pdo()->prepare(
            'SELECT COUNT(DISTINCT p.id) FROM pengaduan p' . self::joins() . ' WHERE ' . $whereSql
        );
        $countStatement->execute($params);
        $total = (int) $countStatement->fetchColumn();

        $perPage = max(1, min(200, $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        // $perPage/$offset integer tervalidasi → aman di-inline.
        $sql = 'SELECT p.id, p.nomor_tiket, p.nama_pelanggan_snapshot, p.username_pppoe_snapshot,
                       p.status, p.is_cancelled, p.created_at,
                       k.nama AS kategori, t.nama AS teknisi_nama,
                       COALESCE(l.completed_at, c.completed_at) AS completed_at,
                       r.rating'
            . ' FROM pengaduan p' . self::joins()
            . ' WHERE ' . $whereSql
            . ' GROUP BY p.id, p.nomor_tiket, p.nama_pelanggan_snapshot, p.username_pppoe_snapshot,
                       p.status, p.is_cancelled, p.created_at, kategori, teknisi_nama, completed_at, r.rating'
            . ' ORDER BY p.created_at DESC, p.id DESC'
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
     * Daftar tiket untuk ekspor (tanpa pagination, tetap dibatasi).
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function ticketsForExport(array $filters, int $limit = 5000): array
    {
        [$whereSql, $params] = self::where($filters);
        $limit = max(1, min(20000, $limit));

        $sql = 'SELECT p.id, p.nomor_tiket, p.nama_pelanggan_snapshot, p.username_pppoe_snapshot,
                       p.status, p.is_cancelled, p.created_at,
                       k.nama AS kategori, t.nama AS teknisi_nama,
                       COALESCE(l.completed_at, c.completed_at) AS completed_at,
                       r.rating'
            . ' FROM pengaduan p' . self::joins()
            . ' WHERE ' . $whereSql
            . ' GROUP BY p.id, p.nomor_tiket, p.nama_pelanggan_snapshot, p.username_pppoe_snapshot,
                       p.status, p.is_cancelled, p.created_at, kategori, teknisi_nama, completed_at, r.rating'
            . ' ORDER BY p.created_at DESC, p.id DESC'
            . ' LIMIT ' . $limit;

        $statement = Database::pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /**
     * Opsi filter (kategori, teknisi, pelanggan) untuk form laporan.
     *
     * @return array{kategori: array<int, array<string, mixed>>, teknisi: array<int, array<string, mixed>>, pelanggan: array<int, array<string, mixed>>}
     */
    public static function filterOptions(): array
    {
        $kategori = Database::pdo()
            ->query('SELECT id, nama FROM kategori_gangguan ORDER BY nama ASC')
            ->fetchAll();

        $teknisi = Database::pdo()
            ->query('SELECT id, nama FROM users WHERE role = \'teknisi\' ORDER BY nama ASC')
            ->fetchAll();

        $pelanggan = Database::pdo()
            ->query('SELECT pl.id, pu.nama, pl.username_pppoe
                     FROM pelanggan pl INNER JOIN users pu ON pu.id = pl.user_id
                     ORDER BY pu.nama ASC')
            ->fetchAll();

        return ['kategori' => $kategori, 'teknisi' => $teknisi, 'pelanggan' => $pelanggan];
    }

    /**
     * Bangun bagian IN (...) untuk status aktif dengan placeholder unik.
     *
     * @param array<string, mixed> $params Parameter (dimodifikasi).
     */
    private static function activePlaceholders(array &$params, string $prefix): string
    {
        $placeholders = [];
        foreach (array_values(active_statuses()) as $index => $status) {
            $key = $prefix . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $status;
        }

        return implode(', ', $placeholders);
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
