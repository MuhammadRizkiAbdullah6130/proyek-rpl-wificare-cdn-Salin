<?php
/**
 * Model/repository pengaduan.
 *
 * Seluruh query agregasi untuk dashboard dikumpulkan di sini agar controller
 * dan view tidak menulis SQL. Tiket yang dibatalkan (is_cancelled = 1) tidak
 * dihitung sebagai tiket aktif kecuali diminta eksplisit.
 */

declare(strict_types=1);

final class Pengaduan
{
    /**
     * Jumlah seluruh pengaduan.
     */
    public static function countTotal(bool $excludeCancelled = true, ?int $pelangganId = null): int
    {
        $sql = 'SELECT COUNT(*) FROM pengaduan p WHERE 1 = 1';
        $params = [];

        if ($excludeCancelled) {
            $sql .= ' AND p.is_cancelled = 0';
        }

        if ($pelangganId !== null) {
            $sql .= ' AND p.pelanggan_id = :pelanggan_id';
            $params['pelanggan_id'] = $pelangganId;
        }

        $statement = Database::pdo()->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    /**
     * Distribusi jumlah tiket per status (selalu enam kunci, termasuk 0).
     *
     * @return array<string, int>
     */
    public static function statusDistribution(bool $excludeCancelled = true, ?int $pelangganId = null): array
    {
        $sql = 'SELECT p.status, COUNT(*) AS jumlah FROM pengaduan p WHERE 1 = 1';
        $params = [];

        if ($excludeCancelled) {
            $sql .= ' AND p.is_cancelled = 0';
        }

        if ($pelangganId !== null) {
            $sql .= ' AND p.pelanggan_id = :pelanggan_id';
            $params['pelanggan_id'] = $pelangganId;
        }

        $sql .= ' GROUP BY p.status';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute($params);

        // Inisialisasi semua status dengan 0 agar grafik/list tetap konsisten.
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
     * Tren jumlah pengaduan harian untuk N hari terakhir (termasuk hari kosong).
     *
     * @return array<string, int> Tanggal (Y-m-d) => jumlah.
     */
    public static function dailyTrend(int $days = 14, bool $excludeCancelled = true, ?int $pelangganId = null): array
    {
        $days = max(1, min(90, $days));

        $sql = 'SELECT DATE(p.created_at) AS tanggal, COUNT(*) AS jumlah
                FROM pengaduan p
                WHERE p.created_at >= (CURDATE() - INTERVAL ' . $days . ' DAY)';

        $params = [];

        if ($excludeCancelled) {
            $sql .= ' AND p.is_cancelled = 0';
        }

        if ($pelangganId !== null) {
            $sql .= ' AND p.pelanggan_id = :pelanggan_id';
            $params['pelanggan_id'] = $pelangganId;
        }

        $sql .= ' GROUP BY DATE(p.created_at)';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute($params);

        $counts = [];
        foreach ($statement->fetchAll() as $row) {
            $counts[(string) $row['tanggal']] = (int) $row['jumlah'];
        }

        // Lengkapi seluruh rentang tanggal agar garis tren tidak terputus.
        $trend = [];
        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $date = date('Y-m-d', strtotime('-' . $offset . ' days'));
            $trend[$date] = $counts[$date] ?? 0;
        }

        return $trend;
    }

    /**
     * Daftar pengaduan terbaru dengan filter opsional.
     *
     * @param array{limit?: int, status?: string|array<int, string>, pelanggan_id?: int, include_cancelled?: bool} $options
     * @return array<int, array<string, mixed>>
     */
    public static function latest(array $options = []): array
    {
        $limit = max(1, min(50, (int) ($options['limit'] ?? 5)));

        $sql = 'SELECT p.id, p.nomor_tiket, p.nama_pelanggan_snapshot, p.username_pppoe_snapshot,
                       p.status, p.created_at, p.is_cancelled, p.pelanggan_id,
                       k.nama AS kategori'
                . ' FROM pengaduan p'
                . ' LEFT JOIN kategori_gangguan k ON k.id = p.kategori_id'
                . ' WHERE 1 = 1';

        $params = [];

        if (empty($options['include_cancelled'])) {
            $sql .= ' AND p.is_cancelled = 0';
        }

        if (isset($options['pelanggan_id'])) {
            $sql .= ' AND p.pelanggan_id = :pelanggan_id';
            $params['pelanggan_id'] = (int) $options['pelanggan_id'];
        }

        if (!empty($options['status'])) {
            $statuses = is_array($options['status']) ? $options['status'] : [$options['status']];
            $placeholders = [];
            foreach (array_values($statuses) as $index => $status) {
                $key = 'status_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = (string) $status;
            }
            if (!empty($placeholders)) {
                $sql .= ' AND p.status IN (' . implode(', ', $placeholders) . ')';
            }
        }

        // $limit adalah integer tervalidasi, aman di-inline.
        $sql .= ' ORDER BY p.created_at DESC, p.id DESC LIMIT ' . $limit;

        $statement = Database::pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /**
     * Ambil satu pengaduan milik pelanggan tertentu (isolasi hak akses).
     *
     * @return array<string, mixed>|null
     */
    public static function findForPelanggan(int $id, int $pelangganId): ?array
    {
        $sql = 'SELECT p.*, k.nama AS kategori
                FROM pengaduan p
                LEFT JOIN kategori_gangguan k ON k.id = p.kategori_id
                WHERE p.id = :id AND p.pelanggan_id = :pelanggan_id
                LIMIT 1';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['id' => $id, 'pelanggan_id' => $pelangganId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Ambil satu pengaduan berdasarkan ID (tanpa filter kepemilikan).
     *
     * Dipakai untuk pemeriksaan hak akses foto; otorisasi dilakukan pemanggil.
     *
     * @return array<string, mixed>|null
     */
    public static function findById(int $id): ?array
    {
        $sql = 'SELECT p.*, k.nama AS kategori
                FROM pengaduan p
                LEFT JOIN kategori_gangguan k ON k.id = p.kategori_id
                WHERE p.id = :id
                LIMIT 1';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Bentuk nomor tiket unik dari ID database (aman dari benturan).
     *
     * Format: WFC-YYYYMMDD-XXXXXX (XXXXXX = ID, minimal 6 digit).
     * Unik karena ID bersifat unik dan ada UNIQUE KEY pada nomor_tiket.
     */
    public static function ticketNumber(int $id): string
    {
        return 'WFC-' . date('Ymd') . '-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Simpan pengaduan baru beserta nomor tiketnya.
     *
     * Nomor tiket dibentuk dari ID yang baru dibuat. WAJIB dipanggil di dalam
     * transaksi (lihat PengaduanService) agar pembentukan nomor konsisten.
     *
     * @param array<string, mixed> $data
     * @return array{id: int, nomor_tiket: string}
     */
    public static function createWithTicket(array $data): array
    {
        $pdo = Database::pdo();

        // Nomor sementara unik; segera diganti nomor tiket final setelah ID didapat.
        $placeholder = 'TMP-' . bin2hex(random_bytes(12));

        $sql = 'INSERT INTO pengaduan
                    (nomor_tiket, pelanggan_id, nama_pelanggan_snapshot, username_pppoe_snapshot,
                     nomor_whatsapp_snapshot, kategori_id, deskripsi, status)
                VALUES
                    (:nomor_tiket, :pelanggan_id, :nama, :pppoe, :whatsapp, :kategori_id, :deskripsi, :status)';

        $statement = $pdo->prepare($sql);
        $statement->execute([
            'nomor_tiket' => $placeholder,
            'pelanggan_id' => (int) $data['pelanggan_id'],
            'nama' => (string) $data['nama_pelanggan_snapshot'],
            'pppoe' => (string) $data['username_pppoe_snapshot'],
            'whatsapp' => (string) $data['nomor_whatsapp_snapshot'],
            'kategori_id' => (int) $data['kategori_id'],
            'deskripsi' => (string) $data['deskripsi'],
            'status' => (string) ($data['status'] ?? 'menunggu_verifikasi'),
        ]);

        $id = (int) $pdo->lastInsertId();
        $nomor = self::ticketNumber($id);

        $update = $pdo->prepare('UPDATE pengaduan SET nomor_tiket = :nomor WHERE id = :id');
        $update->execute(['nomor' => $nomor, 'id' => $id]);

        return ['id' => $id, 'nomor_tiket' => $nomor];
    }

    /**
     * Simpan referensi foto pengaduan.
     *
     * @param array{path: string, original_name: string, mime_type: string, file_size: int} $foto
     */
    public static function insertFoto(int $pengaduanId, array $foto): void
    {
        $sql = 'INSERT INTO pengaduan_foto (pengaduan_id, file_path, original_name, mime_type, file_size)
                VALUES (:pengaduan_id, :file_path, :original_name, :mime_type, :file_size)';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute([
            'pengaduan_id' => $pengaduanId,
            'file_path' => (string) $foto['path'],
            'original_name' => (string) $foto['original_name'],
            'mime_type' => (string) $foto['mime_type'],
            'file_size' => (int) $foto['file_size'],
        ]);
    }

    /**
     * Catat perubahan status pada riwayat_status.
     */
    public static function insertRiwayat(
        int $pengaduanId,
        ?string $statusLama,
        string $statusBaru,
        ?int $actorId,
        ?string $catatan = null
    ): void {
        $sql = 'INSERT INTO riwayat_status (pengaduan_id, status_lama, status_baru, actor_id, catatan)
                VALUES (:pengaduan_id, :status_lama, :status_baru, :actor_id, :catatan)';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute([
            'pengaduan_id' => $pengaduanId,
            'status_lama' => $statusLama,
            'status_baru' => $statusBaru,
            'actor_id' => $actorId,
            'catatan' => $catatan,
        ]);
    }

    /**
     * Tiket aktif milik pelanggan (mengecualikan selesai/ditolak & dibatalkan).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function activeForPelanggan(int $pelangganId): array
    {
        $params = ['pelanggan_id' => $pelangganId];
        $placeholders = [];
        foreach (array_values(active_statuses()) as $index => $status) {
            $key = 'status_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $status;
        }

        $sql = 'SELECT id, nomor_tiket, status, created_at
                FROM pengaduan
                WHERE pelanggan_id = :pelanggan_id
                  AND is_cancelled = 0
                  AND status IN (' . implode(', ', $placeholders) . ')
                ORDER BY created_at DESC, id DESC';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /**
     * Daftar riwayat pengaduan pelanggan dengan filter & pagination.
     *
     * @param array{q?: string, status?: string, date_from?: string, date_to?: string} $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int, pages: int, page: int, per_page: int}
     */
    public static function paginateForPelanggan(int $pelangganId, array $filters, int $page, int $perPage = 10): array
    {
        $where = ['p.pelanggan_id = :pelanggan_id'];
        $params = ['pelanggan_id' => $pelangganId];

        $keyword = trim((string) ($filters['q'] ?? ''));
        if ($keyword !== '') {
            $where[] = 'p.nomor_tiket LIKE :keyword';
            $params['keyword'] = '%' . self::escapeLike($keyword) . '%';
        }

        $status = (string) ($filters['status'] ?? '');
        if ($status !== '' && in_array($status, status_keys(), true)) {
            $where[] = 'p.status = :status';
            $params['status'] = $status;
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

        $countStatement = Database::pdo()->prepare('SELECT COUNT(*) FROM pengaduan p WHERE ' . $whereSql);
        $countStatement->execute($params);
        $total = (int) $countStatement->fetchColumn();

        $perPage = max(1, min(50, $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        // $perPage dan $offset adalah integer tervalidasi, aman di-inline.
        $sql = 'SELECT p.id, p.nomor_tiket, p.status, p.is_cancelled, p.created_at,
                       k.nama AS kategori, l.completed_at
                FROM pengaduan p
                LEFT JOIN kategori_gangguan k ON k.id = p.kategori_id
                LEFT JOIN laporan_teknisi l ON l.pengaduan_id = p.id
                WHERE ' . $whereSql . '
                ORDER BY p.created_at DESC, p.id DESC
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
     * Daftar foto milik satu pengaduan.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function fotosFor(int $pengaduanId): array
    {
        $sql = 'SELECT id, file_path, original_name, mime_type, file_size, created_at
                FROM pengaduan_foto
                WHERE pengaduan_id = :pengaduan_id
                ORDER BY id ASC';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['pengaduan_id' => $pengaduanId]);

        return $statement->fetchAll();
    }

    /**
     * Riwayat perubahan status satu pengaduan (dari database).
     *
     * Catatan internal tidak diambil agar tidak pernah tampil ke pelanggan.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function riwayatFor(int $pengaduanId): array
    {
        $sql = 'SELECT r.id, r.status_lama, r.status_baru, r.created_at,
                       u.nama AS actor_nama, u.role AS actor_role
                FROM riwayat_status r
                LEFT JOIN users u ON u.id = r.actor_id
                WHERE r.pengaduan_id = :pengaduan_id
                ORDER BY r.created_at ASC, r.id ASC';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['pengaduan_id' => $pengaduanId]);

        return $statement->fetchAll();
    }

    /**
     * Data foto tunggal beserta pemilik tiketnya (untuk pemeriksaan akses).
     *
     * @return array<string, mixed>|null
     */
    public static function findFoto(int $fotoId): ?array
    {
        $sql = 'SELECT f.id, f.pengaduan_id, f.file_path, f.original_name, f.mime_type, f.file_size,
                       p.pelanggan_id
                FROM pengaduan_foto f
                INNER JOIN pengaduan p ON p.id = f.pengaduan_id
                WHERE f.id = :id
                LIMIT 1';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['id' => $fotoId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Apakah tiket ditugaskan aktif kepada seorang teknisi.
     */
    public static function isAssignedTo(int $pengaduanId, int $teknisiId): bool
    {
        $sql = 'SELECT 1 FROM penugasan_teknisi
                WHERE pengaduan_id = :pengaduan_id AND teknisi_id = :teknisi_id AND is_active = 1
                LIMIT 1';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['pengaduan_id' => $pengaduanId, 'teknisi_id' => $teknisiId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Teknisi aktif yang ditugaskan pada satu tiket (bila ada).
     *
     * @return array<string, mixed>|null
     */
    public static function assignedTeknisiFor(int $pengaduanId): ?array
    {
        $sql = 'SELECT u.nama, u.username, pt.assigned_at, pt.started_at
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

    /* =====================================================================
     * Query untuk modul Teknisi (Tahap 7)
     * =================================================================== */

    /**
     * Ambil satu pengaduan HANYA bila ditugaskan aktif kepada teknisi tertentu.
     *
     * Otorisasi kepemilikan dilakukan di level query sehingga data tiket milik
     * teknisi lain tidak pernah dimuat. Mengembalikan null bila tidak berhak.
     *
     * @return array<string, mixed>|null
     */
    public static function findForTeknisi(int $id, int $teknisiId): ?array
    {
        $sql = 'SELECT p.*, k.nama AS kategori, pl.alamat AS alamat
                FROM pengaduan p
                INNER JOIN penugasan_teknisi pt
                    ON pt.pengaduan_id = p.id AND pt.teknisi_id = :teknisi_id AND pt.is_active = 1
                LEFT JOIN kategori_gangguan k ON k.id = p.kategori_id
                LEFT JOIN pelanggan pl ON pl.id = p.pelanggan_id
                WHERE p.id = :id
                LIMIT 1';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['id' => $id, 'teknisi_id' => $teknisiId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Laporan perbaikan satu tiket (bila sudah ada).
     *
     * @return array<string, mixed>|null
     */
    public static function laporanFor(int $pengaduanId): ?array
    {
        $sql = 'SELECT l.penyebab_gangguan, l.tindakan_perbaikan, l.catatan, l.completed_at,
                       u.nama AS teknisi_nama
                FROM laporan_teknisi l
                LEFT JOIN users u ON u.id = l.teknisi_id
                WHERE l.pengaduan_id = :pengaduan_id
                LIMIT 1';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['pengaduan_id' => $pengaduanId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /* =====================================================================
     * Query & mutasi untuk modul Customer Service (Tahap 6)
     * =================================================================== */

    /**
     * Kunci satu baris pengaduan untuk transisi status (SELECT ... FOR UPDATE).
     *
     * WAJIB dipanggil di dalam transaksi agar perubahan status tidak balapan
     * saat ada dua permintaan bersamaan.
     *
     * @return array<string, mixed>|null
     */
    public static function lockRow(int $id): ?array
    {
        $sql = 'SELECT id, nomor_tiket, status, is_cancelled, pelanggan_id,
                       nama_pelanggan_snapshot
                FROM pengaduan
                WHERE id = :id
                LIMIT 1
                FOR UPDATE';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Ubah status pengaduan (tanpa menulis riwayat; lihat PengaduanFlowService).
     */
    public static function setStatus(int $id, string $status): void
    {
        $statement = Database::pdo()->prepare('UPDATE pengaduan SET status = :status WHERE id = :id');
        $statement->execute(['status' => $status, 'id' => $id]);
    }

    /**
     * Tandai pengaduan ditolak beserta alasannya.
     */
    public static function setRejected(int $id, string $reason): void
    {
        $statement = Database::pdo()->prepare(
            'UPDATE pengaduan SET status = :status, alasan_penolakan = :reason WHERE id = :id'
        );
        $statement->execute(['status' => 'ditolak', 'reason' => $reason, 'id' => $id]);
    }

    /**
     * Batalkan pengaduan (atribut terpisah; status utama TIDAK berubah).
     *
     * Tiket tidak dihapus — hanya ditandai dibatalkan.
     */
    public static function markCancelled(int $id, int $byUserId, string $reason): void
    {
        $sql = 'UPDATE pengaduan
                SET is_cancelled = 1, cancelled_at = NOW(), cancelled_by = :by, cancellation_reason = :reason
                WHERE id = :id';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['by' => $byUserId, 'reason' => $reason, 'id' => $id]);
    }

    /**
     * Daftar pengaduan untuk Customer Service dengan filter & pagination.
     *
     * Menyertakan teknisi aktif yang ditugaskan (bila ada) dan waktu selesai
     * dari laporan teknisi ATAU penyelesaian langsung CS.
     *
     * @param array{q?: string, status?: string, kategori_id?: int, teknisi_id?: int,
     *              date_from?: string, date_to?: string, exclude_cancelled?: bool,
     *              only_cancelled?: bool, only_active?: bool} $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int, pages: int, page: int, per_page: int}
     */
    public static function paginateForCs(array $filters, int $page, int $perPage = 10): array
    {
        [$whereSql, $params] = self::buildCsFilters($filters);

        $joins = self::csJoins();

        $countStatement = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM pengaduan p' . $joins . ' WHERE ' . $whereSql
        );
        $countStatement->execute($params);
        $total = (int) $countStatement->fetchColumn();

        $perPage = max(1, min(50, $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        // $perPage dan $offset adalah integer tervalidasi, aman di-inline.
        $sql = 'SELECT p.id, p.nomor_tiket, p.nama_pelanggan_snapshot, p.username_pppoe_snapshot,
                       p.nomor_whatsapp_snapshot, p.status, p.is_cancelled, p.created_at,
                       k.nama AS kategori, t.nama AS teknisi_nama,
                       COALESCE(l.completed_at, c.completed_at) AS completed_at'
            . ' FROM pengaduan p' . $joins
            . ' WHERE ' . $whereSql
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
     * Jumlah tiket yang dibatalkan.
     */
    public static function countCancelled(): int
    {
        return (int) Database::pdo()->query('SELECT COUNT(*) FROM pengaduan WHERE is_cancelled = 1')->fetchColumn();
    }

    /* =====================================================================
     * Monitoring pengaduan & pengarsipan (Admin — Tahap 8)
     * =================================================================== */

    /**
     * Daftar seluruh pengaduan untuk Admin dengan filter & pagination.
     *
     * @param array{q?: string, status?: string, kategori_id?: int, teknisi_id?: int,
     *              date_from?: string, date_to?: string, cancelled?: string, arsip?: string} $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int, pages: int, page: int, per_page: int}
     */
    public static function paginateForAdmin(array $filters, int $page, int $perPage = 10): array
    {
        [$whereSql, $params] = self::buildAdminFilters($filters);
        $joins = self::csJoins();

        $countStatement = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM pengaduan p' . $joins . ' WHERE ' . $whereSql
        );
        $countStatement->execute($params);
        $total = (int) $countStatement->fetchColumn();

        $perPage = max(1, min(50, $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        // $perPage dan $offset adalah integer tervalidasi, aman di-inline.
        $sql = 'SELECT p.id, p.nomor_tiket, p.nama_pelanggan_snapshot, p.username_pppoe_snapshot,
                       p.status, p.is_cancelled, p.cancelled_at, p.cancellation_reason,
                       p.archived_at, p.created_at,
                       k.nama AS kategori, t.nama AS teknisi_nama,
                       COALESCE(l.completed_at, c.completed_at) AS completed_at,
                       r.rating
                FROM pengaduan p' . $joins
            . ' LEFT JOIN rating_layanan r ON r.pengaduan_id = p.id'
            . ' WHERE ' . $whereSql
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
     * Detail lengkap satu pengaduan untuk Admin (termasuk hasil, rating, arsip).
     *
     * @return array<string, mixed>|null
     */
    public static function findForAdmin(int $id): ?array
    {
        $sql = 'SELECT p.*, k.nama AS kategori,
                       t.nama AS teknisi_nama, t.username AS teknisi_username,
                       l.penyebab_gangguan, l.tindakan_perbaikan, l.catatan AS laporan_catatan,
                       l.completed_at AS laporan_completed_at,
                       c.solusi, c.completed_at AS cs_completed_at,
                       r.rating, r.ulasan, r.created_at AS rating_at,
                       au.nama AS arsip_oleh, cu.nama AS batal_oleh
                FROM pengaduan p
                LEFT JOIN kategori_gangguan k ON k.id = p.kategori_id
                LEFT JOIN penugasan_teknisi pt ON pt.pengaduan_id = p.id AND pt.is_active = 1
                LEFT JOIN users t ON t.id = pt.teknisi_id
                LEFT JOIN laporan_teknisi l ON l.pengaduan_id = p.id
                LEFT JOIN penyelesaian_cs c ON c.pengaduan_id = p.id
                LEFT JOIN rating_layanan r ON r.pengaduan_id = p.id
                LEFT JOIN users au ON au.id = p.archived_by
                LEFT JOIN users cu ON cu.id = p.cancelled_by
                WHERE p.id = :id
                LIMIT 1';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Tandai tiket sebagai diarsipkan (hanya Admin). Riwayat tetap tersimpan.
     *
     * @return bool true bila baru saja diarsipkan, false bila sudah diarsipkan.
     */
    public static function markArchived(int $id, int $byUserId): bool
    {
        $statement = Database::pdo()->prepare(
            'UPDATE pengaduan SET archived_at = NOW(), archived_by = :by
             WHERE id = :id AND archived_at IS NULL'
        );
        $statement->execute(['by' => $byUserId, 'id' => $id]);

        return $statement->rowCount() > 0;
    }

    /**
     * Jumlah tiket yang diarsipkan.
     */
    public static function countArchived(): int
    {
        return (int) Database::pdo()->query('SELECT COUNT(*) FROM pengaduan WHERE archived_at IS NOT NULL')->fetchColumn();
    }

    /**
     * Distribusi tiket per kategori (untuk laporan Admin).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function categoryDistribution(bool $excludeCancelled = true): array
    {
        $sql = 'SELECT k.id, k.nama, COUNT(p.id) AS jumlah
                FROM kategori_gangguan k
                LEFT JOIN pengaduan p ON p.kategori_id = k.id';
        if ($excludeCancelled) {
            $sql .= ' AND p.is_cancelled = 0';
        }
        $sql .= ' GROUP BY k.id, k.nama ORDER BY jumlah DESC, k.nama ASC';

        return Database::pdo()->query($sql)->fetchAll();
    }

    /**
     * Tiket selesai milik pelanggan beserta status ratingnya (Tahap 8).
     *
     * Tiket yang dibatalkan tidak diikutkan (tidak dapat diberi rating).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function completedForPelanggan(int $pelangganId): array
    {
        $sql = 'SELECT p.id, p.nomor_tiket, p.created_at,
                       COALESCE(l.completed_at, c.completed_at) AS completed_at,
                       r.rating, r.ulasan, r.created_at AS rating_at
                FROM pengaduan p
                LEFT JOIN laporan_teknisi l ON l.pengaduan_id = p.id
                LEFT JOIN penyelesaian_cs c ON c.pengaduan_id = p.id
                LEFT JOIN rating_layanan r ON r.pengaduan_id = p.id
                WHERE p.pelanggan_id = :pelanggan_id
                  AND p.status = \'selesai\'
                  AND p.is_cancelled = 0
                ORDER BY p.created_at DESC, p.id DESC';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['pelanggan_id' => $pelangganId]);

        return $statement->fetchAll();
    }

    /* =====================================================================
     * Query pendukung AI Assistant (Tahap 9) — pembacaan aman & terbatas
     * =================================================================== */

    /**
     * Ambil tiket berdasarkan nomor tiket (untuk pengecekan status via AI).
     *
     * HANYA memuat kolom non-sensitif. Keputusan boleh/tidaknya pengguna
     * melihat tiket dilakukan di service setelah membandingkan pelaku.
     *
     * @return array<string, mixed>|null
     */
    public static function findByNumber(string $nomorTiket): ?array
    {
        $sql = 'SELECT p.id, p.nomor_tiket, p.pelanggan_id, p.status, p.is_cancelled,
                       p.created_at, p.updated_at, p.archived_at,
                       p.nama_pelanggan_snapshot,
                       k.nama AS kategori,
                       COALESCE(l.completed_at, c.completed_at) AS completed_at,
                       t.nama AS teknisi_nama
                FROM pengaduan p
                LEFT JOIN kategori_gangguan k ON k.id = p.kategori_id
                LEFT JOIN penugasan_teknisi pt ON pt.pengaduan_id = p.id AND pt.is_active = 1
                LEFT JOIN users t ON t.id = pt.teknisi_id
                LEFT JOIN laporan_teknisi l ON l.pengaduan_id = p.id
                LEFT JOIN penyelesaian_cs c ON c.pengaduan_id = p.id
                WHERE p.nomor_tiket = :nomor_tiket
                LIMIT 1';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['nomor_tiket' => $nomorTiket]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Apakah tiket pernah ditugaskan kepada teknisi (aktif maupun riwayat).
     */
    public static function assignedToAny(int $pengaduanId, int $teknisiId): bool
    {
        $statement = Database::pdo()->prepare(
            'SELECT 1 FROM penugasan_teknisi
             WHERE pengaduan_id = :pengaduan_id AND teknisi_id = :teknisi_id
             LIMIT 1'
        );
        $statement->execute(['pengaduan_id' => $pengaduanId, 'teknisi_id' => $teknisiId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Bangun WHERE + parameter untuk monitoring Admin dari filter tervalidasi.
     *
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function buildAdminFilters(array $filters): array
    {
        $where = ['1 = 1'];
        $params = [];

        // Arsip: default hanya yang belum diarsipkan.
        $arsip = (string) ($filters['arsip'] ?? 'aktif');
        if ($arsip === 'arsip') {
            $where[] = 'p.archived_at IS NOT NULL';
        } elseif ($arsip !== 'semua') {
            $where[] = 'p.archived_at IS NULL';
        }

        // Status pembatalan.
        $cancelled = (string) ($filters['cancelled'] ?? '');
        if ($cancelled === 'ya') {
            $where[] = 'p.is_cancelled = 1';
        } elseif ($cancelled === 'tidak') {
            $where[] = 'p.is_cancelled = 0';
        }

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

        $teknisiId = (int) ($filters['teknisi_id'] ?? 0);
        if ($teknisiId > 0) {
            $where[] = 'pt.teknisi_id = :teknisi_id';
            $params['teknisi_id'] = $teknisiId;
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
     * Klausa JOIN bersama untuk daftar CS (kategori, teknisi aktif, hasil).
     */
    private static function csJoins(): string
    {
        return ' LEFT JOIN kategori_gangguan k ON k.id = p.kategori_id'
            . ' LEFT JOIN penugasan_teknisi pt ON pt.pengaduan_id = p.id AND pt.is_active = 1'
            . ' LEFT JOIN users t ON t.id = pt.teknisi_id'
            . ' LEFT JOIN laporan_teknisi l ON l.pengaduan_id = p.id'
            . ' LEFT JOIN penyelesaian_cs c ON c.pengaduan_id = p.id';
    }

    /**
     * Bangun WHERE + parameter untuk daftar CS dari filter tervalidasi.
     *
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function buildCsFilters(array $filters): array
    {
        $where = ['1 = 1'];
        $params = [];

        if (!empty($filters['exclude_cancelled'])) {
            $where[] = 'p.is_cancelled = 0';
        }
        if (!empty($filters['only_cancelled'])) {
            $where[] = 'p.is_cancelled = 1';
        }

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

        $teknisiId = (int) ($filters['teknisi_id'] ?? 0);
        if ($teknisiId > 0) {
            $where[] = 'pt.teknisi_id = :teknisi_id';
            $params['teknisi_id'] = $teknisiId;
        }

        if (!empty($filters['only_active'])) {
            $placeholders = [];
            foreach (array_values(active_statuses()) as $index => $activeStatus) {
                $key = 'active_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $activeStatus;
            }
            $where[] = 'p.status IN (' . implode(', ', $placeholders) . ')';
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
