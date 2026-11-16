<?php
/**
 * Pembacaan data operasional untuk AI (read-only) — WiFiCare CDN Tahap 18.
 *
 * Semua query memakai PDO prepared statement dan hanya SELECT. Tidak ada SQL
 * bebas dari pengguna/model: filter, kolom urutan, dan periode divalidasi
 * terhadap allowlist. Setiap method memeriksa peran pemanggil di backend.
 *
 * Data sensitif (password_hash, password jaringan, API key, session) tidak
 * pernah dipilih dari database.
 */

declare(strict_types=1);

final class AiOperationsReadService
{
    private const EMPLOYEE_ROLES = ['admin', 'cs', 'teknisi'];

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function employeeList(array $user, array $filters = []): array
    {
        if (!self::can($user, ['admin'])) {
            return self::denied('Data karyawan hanya dapat diakses Administrator.');
        }

        $where = ["u.role IN ('admin','cs','teknisi')"];
        $params = [];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(u.nama LIKE :q_nama OR u.username LIKE :q_username)';
            $params['q_nama'] = '%' . $q . '%';
            $params['q_username'] = '%' . $q . '%';
        }

        $role = strtolower(trim((string) ($filters['role'] ?? '')));
        if (in_array($role, self::EMPLOYEE_ROLES, true)) {
            $where[] = 'u.role = :role';
            $params['role'] = $role;
        }

        $status = strtolower(trim((string) ($filters['status'] ?? 'all')));
        if ($status === 'active') {
            $where[] = 'u.is_active = 1';
        } elseif ($status === 'inactive') {
            $where[] = 'u.is_active = 0';
        }

        $limit = max(1, min(50, (int) ($filters['limit'] ?? 20)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $order = self::employeeOrder((string) ($filters['order'] ?? 'role'));

        $rows = self::fetchAll(
            'SELECT u.id, u.nama, u.username, u.role, u.is_active, u.last_login_at, u.created_at
             FROM users u
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY ' . $order . '
             LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params
        );

        $count = self::fetchScalar(
            'SELECT COUNT(*) FROM users u WHERE ' . implode(' AND ', $where),
            $params
        );

        return [
            'employees' => array_map([self::class, 'mapEmployee'], $rows),
            'count' => count($rows),
            'total_matching' => $count,
            'limit' => $limit,
            'offset' => $offset,
            'counts' => self::employeeCounts($user),
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function employeeCounts(array $user): array
    {
        if (!self::can($user, ['admin'])) {
            return self::denied('Data karyawan hanya dapat diakses Administrator.');
        }

        $rows = self::fetchAll(
            "SELECT role, COUNT(*) AS total,
                    COALESCE(SUM(is_active = 1), 0) AS aktif,
                    COALESCE(SUM(is_active = 0), 0) AS nonaktif
             FROM users
             WHERE role IN ('admin','cs','teknisi')
             GROUP BY role"
        );

        $byRole = ['admin' => 0, 'cs' => 0, 'teknisi' => 0];
        $byRoleActive = ['admin' => 0, 'cs' => 0, 'teknisi' => 0];
        $byRoleInactive = ['admin' => 0, 'cs' => 0, 'teknisi' => 0];
        $active = 0;
        $inactive = 0;
        foreach ($rows as $row) {
            $role = (string) ($row['role'] ?? '');
            if (array_key_exists($role, $byRole)) {
                $byRole[$role] = (int) ($row['total'] ?? 0);
                $byRoleActive[$role] = (int) ($row['aktif'] ?? 0);
                $byRoleInactive[$role] = (int) ($row['nonaktif'] ?? 0);
            }
            $active += (int) ($row['aktif'] ?? 0);
            $inactive += (int) ($row['nonaktif'] ?? 0);
        }

        return [
            'total' => array_sum($byRole),
            'by_role' => $byRole,
            'by_role_active' => $byRoleActive,
            'by_role_inactive' => $byRoleInactive,
            'active' => $active,
            'inactive' => $inactive,
            'roles' => self::EMPLOYEE_ROLES,
            'catatan' => 'Karyawan hanya mencakup role Admin, CS, dan Teknisi. Pelanggan tidak dihitung.',
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function employeeDetail(array $user, int $employeeId): array
    {
        if (!self::can($user, ['admin'])) {
            return self::denied('Data karyawan hanya dapat diakses Administrator.');
        }

        if ($employeeId <= 0) {
            return ['found' => false, 'message' => 'ID karyawan tidak valid.'];
        }

        $row = self::fetchOne(
            "SELECT id, nama, username, role, is_active, last_login_at, created_at, updated_at
             FROM users
             WHERE id = :id AND role IN ('admin','cs','teknisi')
             LIMIT 1",
            ['id' => $employeeId]
        );

        if ($row === null) {
            return ['found' => false, 'message' => 'Karyawan tidak ditemukan.'];
        }

        return ['found' => true, 'employee' => self::mapEmployee($row)];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function customerSearch(array $user, string $keyword, int $limit = 10, int $offset = 0): array
    {
        if (!self::can($user, ['admin', 'cs'])) {
            return self::denied('Data pelanggan hanya dapat diakses Administrator atau Customer Service.');
        }

        $keyword = trim($keyword);
        $limit = max(1, min(25, $limit));
        $offset = max(0, $offset);
        $params = [];
        $where = '1 = 1';

        if ($keyword !== '') {
            $where = '(u.nama LIKE :q_nama OR u.username LIKE :q_username OR p.username_pppoe LIKE :q_pppoe)';
            $needle = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword) . '%';
            $params['q_nama'] = $needle;
            $params['q_username'] = $needle;
            $params['q_pppoe'] = $needle;
        }

        $rows = self::fetchAll(
            'SELECT p.id, p.user_id, u.nama, u.username, u.is_active,
                    p.username_pppoe, p.nomor_whatsapp, p.alamat, p.created_at, u.last_login_at
             FROM pelanggan p
             INNER JOIN users u ON u.id = p.user_id
             WHERE ' . $where . '
             ORDER BY u.nama ASC, p.id ASC
             LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params
        );

        $total = self::fetchScalar(
            'SELECT COUNT(*) FROM pelanggan p INNER JOIN users u ON u.id = p.user_id WHERE ' . $where,
            $params
        );

        return [
            'customers' => array_map(
                static fn (array $row): array => self::mapCustomer($user, $row),
                $rows
            ),
            'count' => count($rows),
            'total_matching' => $total,
            'keyword' => $keyword,
            'limit' => $limit,
        ];
    }

    /**
     * Pelanggan yang paling baru terdaftar. Dipakai untuk pertanyaan
     * "siapa pelanggan terbaru?" dan tidak membutuhkan riwayat pengaduan.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function latestCustomer(array $user): array
    {
        if (!self::can($user, ['admin', 'cs'])) {
            return self::denied('Data pelanggan hanya dapat diakses Administrator atau Customer Service.');
        }

        $row = self::fetchOne(
            'SELECT p.id, p.user_id, u.nama, u.username, u.is_active,
                    p.username_pppoe, p.nomor_whatsapp, p.alamat, p.created_at, u.last_login_at
             FROM pelanggan p
             INNER JOIN users u ON u.id = p.user_id
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT 1'
        );

        if ($row === null) {
            return ['found' => false, 'message' => 'Belum ada data pelanggan.'];
        }

        return ['found' => true, 'customer' => self::mapCustomer($user, $row)];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function customerDetail(array $user, int $customerId): array
    {
        if (!self::can($user, ['admin', 'cs'])) {
            return self::denied('Data pelanggan hanya dapat diakses Administrator atau Customer Service.');
        }

        if ($customerId <= 0) {
            return ['found' => false, 'message' => 'ID pelanggan tidak valid.'];
        }

        $row = self::fetchOne(
            'SELECT p.id, p.user_id, u.nama, u.username, u.is_active,
                    p.username_pppoe, p.nomor_whatsapp, p.alamat, p.created_at, u.last_login_at
             FROM pelanggan p
             INNER JOIN users u ON u.id = p.user_id
             WHERE p.id = :id
             LIMIT 1',
            ['id' => $customerId]
        );

        if ($row === null) {
            return ['found' => false, 'message' => 'Pelanggan tidak ditemukan.'];
        }

        $counts = self::customerComplaintCounts($customerId);
        $customer = self::mapCustomer($user, $row);
        $customer['jumlah_pengaduan'] = $counts['total'];
        $customer['pengaduan_aktif'] = $counts['active'];
        $customer['pengaduan_selesai'] = $counts['completed'];
        $customer['pengaduan_ditolak'] = $counts['rejected'];
        $customer['pengaduan_dibatalkan'] = $counts['cancelled'];
        $customer['pengaduan_terakhir'] = $counts['last_ticket_at'];

        return ['found' => true, 'customer' => $customer];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function customerComplaints(array $user, int $customerId, array $filters = []): array
    {
        if (!self::can($user, ['admin', 'cs'])) {
            return self::denied('Riwayat pengaduan pelanggan hanya dapat diakses Administrator atau Customer Service.');
        }

        $detail = self::customerDetail($user, $customerId);
        if (empty($detail['found'])) {
            return $detail;
        }

        $filters['customer_id'] = $customerId;
        $filters['cancelled'] = $filters['cancelled'] ?? 'include';
        $list = self::complaintList($user, $filters);

        return [
            'found' => true,
            'customer' => $detail['customer'],
            'complaints' => $list['complaints'] ?? [],
            'count' => $list['count'] ?? 0,
            'total' => $list['total_matching'] ?? 0,
            'counts' => self::customerComplaintCounts($customerId),
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function complaintList(array $user, array $filters = []): array
    {
        if (!self::can($user, ['admin', 'cs'])) {
            return self::denied('Daftar seluruh pengaduan hanya dapat diakses Administrator atau Customer Service.');
        }

        [$where, $params] = self::complaintWhere($filters);
        $limit = max(1, min(50, (int) ($filters['limit'] ?? 10)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $order = self::complaintOrder((string) ($filters['order'] ?? 'newest'));

        $rows = self::fetchAll(
            self::complaintSelect() . ' WHERE ' . $where . '
             ORDER BY ' . $order . '
             LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params
        );

        $total = self::fetchScalar(
            'SELECT COUNT(*) FROM pengaduan p ' . self::complaintJoins() . ' WHERE ' . $where,
            $params
        );

        return [
            'complaints' => array_map([self::class, 'mapComplaint'], $rows),
            'count' => count($rows),
            'total_matching' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'filters' => self::publicFilters($filters),
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function complaintDetail(array $user, string $ticketNumber): array
    {
        if (!self::can($user, ['admin', 'cs'])) {
            return self::denied('Detail pengaduan ini hanya dapat diakses Administrator atau Customer Service.');
        }

        $ticketNumber = strtoupper(trim($ticketNumber));
        if (preg_match('/^WFC-\d{8}-\d{6}$/', $ticketNumber) !== 1) {
            return ['found' => false, 'message' => 'Nomor tiket tidak valid. Format: WFC-YYYYMMDD-XXXXXX.'];
        }

        $row = self::fetchOne(
            self::complaintSelect("p.deskripsi, p.alasan_penolakan, p.cancellation_reason")
            . ' WHERE p.nomor_tiket = :nomor LIMIT 1',
            ['nomor' => $ticketNumber]
        );

        if ($row === null) {
            return ['found' => false, 'message' => 'Tiket tidak ditemukan.'];
        }

        $complaint = self::mapComplaint($row);
        $complaint['deskripsi'] = (string) ($row['deskripsi'] ?? '');
        $complaint['alasan_penolakan'] = (string) ($row['alasan_penolakan'] ?? '');
        $complaint['cancellation_reason'] = (string) ($row['cancellation_reason'] ?? '');

        $historyRows = self::fetchAll(
            'SELECT rs.status_lama, rs.status_baru, rs.catatan, rs.created_at, u.nama AS actor
             FROM riwayat_status rs
             LEFT JOIN users u ON u.id = rs.actor_id
             WHERE rs.pengaduan_id = :id
             ORDER BY rs.created_at ASC, rs.id ASC',
            ['id' => (int) $row['id']]
        );
        $history = [];
        foreach ($historyRows as $entry) {
            $history[] = [
                'status_lama' => (string) ($entry['status_lama'] ?? ''),
                'status_lama_label' => (string) ($entry['status_lama'] ?? '') !== ''
                    ? status_label((string) $entry['status_lama'])
                    : '',
                'status_baru' => (string) ($entry['status_baru'] ?? ''),
                'status_baru_label' => status_label((string) ($entry['status_baru'] ?? '')),
                'catatan' => (string) ($entry['catatan'] ?? ''),
                'actor' => (string) ($entry['actor'] ?? ''),
                'waktu' => format_waktu((string) ($entry['created_at'] ?? '')),
            ];
        }

        $report = self::fetchOne(
            'SELECT lt.penyebab_gangguan, lt.tindakan_perbaikan, lt.catatan, lt.completed_at,
                    u.nama AS teknisi
             FROM laporan_teknisi lt
             LEFT JOIN users u ON u.id = lt.teknisi_id
             WHERE lt.pengaduan_id = :id LIMIT 1',
            ['id' => (int) $row['id']]
        );

        $rating = self::fetchOne(
            'SELECT rating, ulasan, created_at FROM rating_layanan WHERE pengaduan_id = :id LIMIT 1',
            ['id' => (int) $row['id']]
        );

        return [
            'found' => true,
            'complaint' => $complaint,
            'history' => $history,
            'report' => $report === null ? null : [
                'penyebab_gangguan' => (string) ($report['penyebab_gangguan'] ?? ''),
                'tindakan_perbaikan' => (string) ($report['tindakan_perbaikan'] ?? ''),
                'catatan' => (string) ($report['catatan'] ?? ''),
                'teknisi' => (string) ($report['teknisi'] ?? ''),
                'completed_at' => format_waktu((string) ($report['completed_at'] ?? '')),
            ],
            'rating' => $rating === null ? null : [
                'rating' => (int) ($rating['rating'] ?? 0),
                'ulasan' => (string) ($rating['ulasan'] ?? ''),
                'tanggal' => format_waktu((string) ($rating['created_at'] ?? '')),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function complaintStatusStatistics(array $user, array $filters = []): array
    {
        if (!self::can($user, ['admin', 'cs'])) {
            return self::denied('Statistik pengaduan tidak tersedia untuk peran ini.');
        }

        unset($filters['status']);
        [$where, $params] = self::complaintWhere($filters);
        $rows = self::fetchAll(
            'SELECT p.status, COUNT(*) AS jumlah
             FROM pengaduan p ' . self::complaintJoins() . '
             WHERE ' . $where . '
             GROUP BY p.status',
            $params
        );

        $byStatus = [];
        foreach (status_keys() as $key) {
            $byStatus[$key] = 0;
        }
        foreach ($rows as $row) {
            $key = (string) ($row['status'] ?? '');
            if (array_key_exists($key, $byStatus)) {
                $byStatus[$key] = (int) ($row['jumlah'] ?? 0);
            }
        }

        $byLabel = [];
        foreach ($byStatus as $key => $count) {
            $byLabel[status_label($key)] = $count;
        }

        $period = self::normalizePeriod((string) ($filters['period'] ?? 'all'));
        [$start, $end] = self::periodRange($period);

        // Hitung tiket yang SELESAI pada periode berdasarkan riwayat status,
        // tetapi tetap terapkan filter non-periode (pelanggan, kategori, teknisi,
        // pencarian, arsip, pembatalan) agar angka tidak bocor lintas cakupan.
        $completedFilters = $filters;
        $completedFilters['period'] = 'all';
        unset($completedFilters['status'], $completedFilters['status_group']);
        [$completedWhere, $completedParams] = self::complaintWhere($completedFilters);
        $completedSql = 'SELECT COUNT(DISTINCT rs.pengaduan_id)
                         FROM riwayat_status rs
                         INNER JOIN pengaduan p ON p.id = rs.pengaduan_id
                         WHERE rs.status_baru = \'selesai\' AND ' . $completedWhere;
        if (strtolower(trim((string) ($completedFilters['cancelled'] ?? 'exclude'))) === 'only') {
            $completed = 0;
        } else {
            if ($start !== null && $end !== null) {
                $completedSql .= ' AND rs.created_at >= :completed_start AND rs.created_at < :completed_end';
                $completedParams['completed_start'] = $start;
                $completedParams['completed_end'] = $end;
            }
            $completed = self::fetchScalar($completedSql, $completedParams);
        }

        $cancelled = self::fetchScalar(
            'SELECT COUNT(*) FROM pengaduan p ' . self::complaintJoins()
            . ' WHERE ' . str_replace('p.is_cancelled = 0', 'p.is_cancelled = 1', $where),
            $params
        );

        $active = 0;
        foreach (['menunggu_verifikasi', 'diproses_cs', 'menunggu_teknisi', 'dalam_penanganan'] as $key) {
            $active += $byStatus[$key] ?? 0;
        }

        return [
            'total' => array_sum($byStatus),
            'by_status' => $byLabel,
            'by_status_key' => $byStatus,
            'active' => $active,
            'completed' => $completed,
            'completed_by_current_status' => $byStatus['selesai'] ?? 0,
            'rejected' => $byStatus['ditolak'] ?? 0,
            'cancelled' => $cancelled,
            'period' => $period,
            'period_label' => self::periodLabel($period),
            'catatan' => 'Total tidak memasukkan tiket yang dibatalkan. Tiket dibatalkan dihitung terpisah.',
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function technicianPerformance(array $user, array $filters = []): array
    {
        if (!self::can($user, ['admin'])) {
            return self::denied('Kinerja seluruh teknisi hanya dapat diakses Administrator.');
        }

        [$start, $end] = self::periodRange(self::normalizePeriod((string) ($filters['period'] ?? 'all')));
        $dateSql = '';
        $params = [];
        if ($start !== null && $end !== null) {
            $dateSql = ' AND pt.assigned_at >= :start AND pt.assigned_at < :end';
            $params = ['start' => $start, 'end' => $end];
        }

        $rows = self::fetchAll(
            'SELECT u.id, u.nama, u.username, u.is_active,
                    COUNT(DISTINCT CASE WHEN pt.is_active = 1 AND p.is_cancelled = 0 THEN pt.pengaduan_id END) AS total_tugas,
                    COUNT(DISTINCT CASE WHEN p.is_cancelled = 0 THEN pt.pengaduan_id END) AS total_penugasan,
                    COUNT(DISTINCT CASE WHEN p.status = \'selesai\' AND p.is_cancelled = 0 THEN pt.pengaduan_id END) AS selesai,
                    COUNT(DISTINCT CASE WHEN p.status IN (\'menunggu_teknisi\',\'dalam_penanganan\')
                                             AND p.is_cancelled = 0 THEN pt.pengaduan_id END) AS aktif
             FROM users u
             LEFT JOIN penugasan_teknisi pt ON pt.teknisi_id = u.id' . $dateSql . '
             LEFT JOIN pengaduan p ON p.id = pt.pengaduan_id
             WHERE u.role = \'teknisi\'
             GROUP BY u.id, u.nama, u.username, u.is_active
             ORDER BY total_tugas DESC, selesai DESC, u.nama ASC',
            $params
        );

        $performance = [];
        foreach ($rows as $row) {
            $performance[] = [
                'teknisi_id' => (int) ($row['id'] ?? 0),
                'teknisi' => (string) ($row['nama'] ?? ''),
                'username' => (string) ($row['username'] ?? ''),
                'is_active' => (int) ($row['is_active'] ?? 0) === 1,
                'total_tugas' => (int) ($row['total_tugas'] ?? 0),
                'total_penugasan' => (int) ($row['total_penugasan'] ?? 0),
                'selesai' => (int) ($row['selesai'] ?? 0),
                'aktif' => (int) ($row['aktif'] ?? 0),
            ];
        }

        usort($performance, static function (array $a, array $b): int {
            return [$b['total_tugas'], $b['selesai'], $a['teknisi']]
                <=> [$a['total_tugas'], $a['selesai'], $b['teknisi']];
        });

        return [
            'performance' => $performance,
            'count' => count($performance),
            'period' => self::normalizePeriod((string) ($filters['period'] ?? 'all')),
            'period_label' => self::periodLabel(self::normalizePeriod((string) ($filters['period'] ?? 'all'))),
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function categoryStatistics(array $user, array $filters = []): array
    {
        if (!self::can($user, ['admin', 'cs'])) {
            return self::denied('Statistik kategori tidak tersedia untuk peran ini.');
        }

        [$where, $params] = self::complaintWhere($filters);
        $rows = self::fetchAll(
            'SELECT COALESCE(k.nama, \'Tanpa kategori\') AS nama, COUNT(DISTINCT p.id) AS jumlah
             FROM pengaduan p
             LEFT JOIN kategori_gangguan k ON k.id = p.kategori_id
             WHERE ' . $where . '
             GROUP BY k.id, k.nama
             ORDER BY jumlah DESC, nama ASC
             LIMIT 20',
            $params
        );

        $categories = [];
        foreach ($rows as $row) {
            $categories[] = [
                'nama' => (string) ($row['nama'] ?? 'Tanpa kategori'),
                'jumlah' => (int) ($row['jumlah'] ?? 0),
            ];
        }

        return [
            'categories' => $categories,
            'count' => count($categories),
            'period' => self::normalizePeriod((string) ($filters['period'] ?? 'all')),
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function ratingStatistics(array $user, array $filters = []): array
    {
        if (!self::can($user, ['admin', 'cs'])) {
            return self::denied('Statistik rating tidak tersedia untuk peran ini.');
        }

        $period = self::normalizePeriod((string) ($filters['period'] ?? 'all'));
        [$start, $end] = self::periodRange($period);
        $dateSql = '';
        $params = [];
        if ($start !== null && $end !== null) {
            $dateSql = ' WHERE r.created_at >= :start AND r.created_at < :end';
            $params = ['start' => $start, 'end' => $end];
        }

        $row = self::fetchOne(
            'SELECT COUNT(*) AS total, COALESCE(AVG(r.rating), 0) AS rata_rata,
                    COALESCE(SUM(r.rating = 1), 0) AS bintang1,
                    COALESCE(SUM(r.rating = 2), 0) AS bintang2,
                    COALESCE(SUM(r.rating = 3), 0) AS bintang3,
                    COALESCE(SUM(r.rating = 4), 0) AS bintang4,
                    COALESCE(SUM(r.rating = 5), 0) AS bintang5
             FROM rating_layanan r' . $dateSql,
            $params
        ) ?? [];

        return [
            'total_rating' => (int) ($row['total'] ?? 0),
            'rata_rata' => round((float) ($row['rata_rata'] ?? 0), 2),
            'distribusi' => [
                '1' => (int) ($row['bintang1'] ?? 0),
                '2' => (int) ($row['bintang2'] ?? 0),
                '3' => (int) ($row['bintang3'] ?? 0),
                '4' => (int) ($row['bintang4'] ?? 0),
                '5' => (int) ($row['bintang5'] ?? 0),
            ],
            'period' => $period,
            'period_label' => self::periodLabel($period),
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function operationalSummary(array $user): array
    {
        if (!self::can($user, ['admin'])) {
            return self::denied('Ringkasan operasional hanya dapat diakses Administrator.');
        }

        $employees = self::employeeCounts($user);
        $customers = self::customerCounts($user);
        $status = self::complaintStatusStatistics($user, ['period' => 'all']);
        $rating = self::ratingStatistics($user, ['period' => 'all']);

        return [
            'employees' => $employees,
            'customers' => $customers,
            'complaints' => $status,
            'rating' => $rating,
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function complaintTrend(array $user, array $filters = []): array
    {
        if (!self::can($user, ['admin', 'cs'])) {
            return self::denied('Tren pengaduan tidak tersedia untuk peran ini.');
        }

        $granularity = strtolower(trim((string) ($filters['granularity'] ?? 'daily')));
        if (!in_array($granularity, ['daily', 'monthly'], true)) {
            $granularity = 'daily';
        }

        $points = max(7, min(30, (int) ($filters['points'] ?? ($granularity === 'daily' ? 14 : 12))));
        if ($granularity === 'monthly') {
            $points = max(3, min(12, $points));
        }

        $now = new DateTimeImmutable('now');
        if ($granularity === 'daily') {
            $start = $now->modify('-' . ($points - 1) . ' days')->setTime(0, 0, 0);
            $end = $now->setTime(0, 0, 0)->modify('+1 day');
            $group = 'DATE(p.created_at)';
            $label = 'DATE_FORMAT(p.created_at, \'%Y-%m-%d\')';
        } else {
            $start = $now->modify('first day of this month')->setTime(0, 0, 0)->modify('-' . ($points - 1) . ' months');
            $end = $now->modify('first day of next month')->setTime(0, 0, 0);
            $group = 'DATE_FORMAT(p.created_at, \'%Y-%m\')';
            $label = 'DATE_FORMAT(p.created_at, \'%Y-%m\')';
        }

        $rows = self::fetchAll(
            'SELECT ' . $label . ' AS periode, COUNT(*) AS jumlah
             FROM pengaduan p
             WHERE p.is_cancelled = 0 AND p.created_at >= :start AND p.created_at < :end
             GROUP BY ' . $group . '
             ORDER BY periode ASC',
            ['start' => $start->format('Y-m-d H:i:s'), 'end' => $end->format('Y-m-d H:i:s')]
        );

        return [
            'granularity' => $granularity,
            'points' => $points,
            'trend' => array_map(static fn (array $row): array => [
                'periode' => (string) ($row['periode'] ?? ''),
                'jumlah' => (int) ($row['jumlah'] ?? 0),
            ], $rows),
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function customerCounts(array $user): array
    {
        if (!self::can($user, ['admin', 'cs'])) {
            return self::denied('Jumlah pelanggan tidak tersedia untuk peran ini.');
        }

        $row = self::fetchOne(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(u.is_active = 1), 0) AS aktif,
                    COALESCE(SUM(u.is_active = 0), 0) AS nonaktif
             FROM pelanggan p INNER JOIN users u ON u.id = p.user_id"
        ) ?? [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['aktif'] ?? 0),
            // Kompatibilitas tool lama get_customer_count.
            'pelanggan_aktif' => (int) ($row['aktif'] ?? 0),
            'inactive' => (int) ($row['nonaktif'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function customerByName(array $user, string $name): array
    {
        if (!self::can($user, ['admin', 'cs'])) {
            return self::denied('Data pelanggan hanya dapat diakses Administrator atau Customer Service.');
        }

        $name = trim($name);
        if ($name === '') {
            return ['found' => false, 'message' => 'Nama pelanggan belum disebutkan.'];
        }

        $rows = self::fetchAll(
            'SELECT p.id, p.user_id, u.nama, u.username, u.is_active,
                    p.username_pppoe, p.nomor_whatsapp, p.alamat, p.created_at, u.last_login_at
             FROM pelanggan p INNER JOIN users u ON u.id = p.user_id
             WHERE u.nama LIKE :q_nama OR p.username_pppoe LIKE :q_pppoe OR u.username LIKE :q_username
             ORDER BY u.nama ASC, p.id ASC
             LIMIT 10',
            [
                'q_nama' => '%' . $name . '%',
                'q_pppoe' => '%' . $name . '%',
                'q_username' => '%' . $name . '%',
            ]
        );

        if ($rows === []) {
            return ['found' => false, 'message' => 'Pelanggan dengan nama tersebut tidak ditemukan.'];
        }

        return [
            'found' => true,
            'multiple' => count($rows) > 1,
            'customers' => array_map(static fn (array $row): array => self::mapCustomer($user, $row), $rows),
        ];
    }

    /**
     * Cari satu kategori berdasarkan nama (untuk filter percakapan).
     *
     * @param array<string, mixed> $user
     * @return array{id: int, nama: string}|null
     */
    public static function findCategoryByName(array $user, string $keyword): ?array
    {
        if (!self::can($user, ['admin', 'cs'])) {
            return null;
        }

        $keyword = trim($keyword);
        if ($keyword === '') {
            return null;
        }

        $row = self::fetchOne(
            'SELECT id, nama FROM kategori_gangguan
             WHERE is_active = 1 AND nama LIKE :q
             ORDER BY nama ASC LIMIT 1',
            ['q' => '%' . $keyword . '%']
        );

        return $row === null ? null : ['id' => (int) $row['id'], 'nama' => (string) $row['nama']];
    }

    /**
     * Cari satu teknisi berdasarkan nama/username (untuk filter percakapan).
     *
     * @param array<string, mixed> $user
     * @return array{id: int, nama: string}|null
     */
    public static function findTechnicianByName(array $user, string $keyword): ?array
    {
        if (!self::can($user, ['admin', 'cs'])) {
            return null;
        }

        $keyword = trim($keyword);
        if ($keyword === '') {
            return null;
        }

        $row = self::fetchOne(
            "SELECT id, nama FROM users
             WHERE role = 'teknisi' AND (nama LIKE :q_nama OR username LIKE :q_username)
             ORDER BY nama ASC LIMIT 1",
            [
                'q_nama' => '%' . $keyword . '%',
                'q_username' => '%' . $keyword . '%',
            ]
        );

        return $row === null ? null : ['id' => (int) $row['id'], 'nama' => (string) $row['nama']];
    }

    /**
     * ID pelanggan berdasarkan nama/PPPoE yang dipakai percakapan.
     *
     * @param array<string, mixed> $user
     */
    public static function findCustomerId(array $user, string $keyword): ?int
    {
        if (!self::can($user, ['admin', 'cs'])) {
            return null;
        }

        $row = self::fetchOne(
            'SELECT p.id
             FROM pelanggan p INNER JOIN users u ON u.id = p.user_id
             WHERE u.nama = :exact_nama OR p.username_pppoe = :exact_pppoe
             ORDER BY CASE WHEN u.nama = :order_nama THEN 0 ELSE 1 END, p.id ASC
             LIMIT 1',
            [
                'exact_nama' => $keyword,
                'exact_pppoe' => $keyword,
                'order_nama' => $keyword,
            ]
        );

        if ($row !== null) {
            return (int) $row['id'];
        }

        $rows = self::fetchAll(
            'SELECT p.id FROM pelanggan p INNER JOIN users u ON u.id = p.user_id
             WHERE u.nama LIKE :q_nama OR p.username_pppoe LIKE :q_pppoe
             ORDER BY u.nama ASC, p.id ASC LIMIT 2',
            [
                'q_nama' => '%' . $keyword . '%',
                'q_pppoe' => '%' . $keyword . '%',
            ]
        );

        return count($rows) === 1 ? (int) $rows[0]['id'] : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function mapEmployee(array $row): array
    {
        $role = (string) ($row['role'] ?? '');
        $active = (int) ($row['is_active'] ?? 0) === 1;

        return [
            'id' => (int) ($row['id'] ?? 0),
            'nama' => (string) ($row['nama'] ?? ''),
            'username' => (string) ($row['username'] ?? ''),
            'role' => $role,
            'role_label' => role_label($role),
            'is_active' => $active,
            'status' => $active ? 'Aktif' : 'Nonaktif',
            'created_at' => format_waktu((string) ($row['created_at'] ?? '')),
            'last_login_at' => ($row['last_login_at'] ?? null) === null
                ? 'Belum pernah'
                : format_waktu((string) $row['last_login_at']),
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function mapCustomer(array $user, array $row): array
    {
        $phone = trim((string) ($row['nomor_whatsapp'] ?? ''));
        $isAdmin = (string) ($user['role'] ?? '') === 'admin';

        return [
            'id' => (int) ($row['id'] ?? 0),
            'nama' => (string) ($row['nama'] ?? ''),
            'username' => (string) ($row['username'] ?? ''),
            'username_pppoe' => (string) ($row['username_pppoe'] ?? ''),
            'nomor_whatsapp' => $isAdmin ? $phone : self::maskContact($phone),
            'alamat' => (string) ($row['alamat'] ?? ''),
            'is_active' => (int) ($row['is_active'] ?? 0) === 1,
            'status' => (int) ($row['is_active'] ?? 0) === 1 ? 'Aktif' : 'Nonaktif',
            'created_at' => format_waktu((string) ($row['created_at'] ?? '')),
            'last_login_at' => ($row['last_login_at'] ?? null) === null
                ? 'Belum pernah'
                : format_waktu((string) $row['last_login_at']),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function mapComplaint(array $row): array
    {
        $statusKey = (string) ($row['status'] ?? '');
        $created = (string) ($row['created_at'] ?? '');
        $completed = $row['completed_at'] ?? null;

        return [
            'id' => (int) ($row['id'] ?? 0),
            'nomor_tiket' => (string) ($row['nomor_tiket'] ?? ''),
            'status' => status_label($statusKey),
            'status_key' => $statusKey,
            'kategori' => (string) ($row['kategori'] ?? 'Tanpa kategori'),
            'pelanggan_id' => (int) ($row['pelanggan_id'] ?? 0),
            'nama_pelanggan' => (string) ($row['nama_pelanggan_snapshot'] ?? ''),
            'username_pppoe' => (string) ($row['username_pppoe_snapshot'] ?? ''),
            'teknisi' => trim((string) ($row['teknisi'] ?? '')) !== '' ? (string) $row['teknisi'] : null,
            'dibuat' => $created !== '' ? format_waktu($created) : '—',
            'dibuat_panjang' => $created !== '' ? format_waktu_panjang($created) : '—',
            'selesai' => $completed === null ? null : format_waktu((string) $completed),
            'dibatalkan' => (int) ($row['is_cancelled'] ?? 0) === 1,
            'cancelled_at' => ($row['cancelled_at'] ?? null) === null
                ? null
                : format_waktu((string) $row['cancelled_at']),
            'diarsipkan' => ($row['archived_at'] ?? null) !== null,
            'archived_at' => ($row['archived_at'] ?? null) === null
                ? null
                : format_waktu((string) $row['archived_at']),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function complaintWhere(array $filters): array
    {
        $where = ['1 = 1'];
        $params = [];

        $cancelled = strtolower(trim((string) ($filters['cancelled'] ?? 'exclude')));
        if ($cancelled === 'only') {
            $where[] = 'p.is_cancelled = 1';
        } elseif ($cancelled !== 'include') {
            $where[] = 'p.is_cancelled = 0';
        }

        $archived = strtolower(trim((string) ($filters['archived'] ?? 'include')));
        if ($archived === 'only') {
            $where[] = 'p.archived_at IS NOT NULL';
        } elseif ($archived === 'exclude') {
            $where[] = 'p.archived_at IS NULL';
        }

        $status = strtolower(trim((string) ($filters['status'] ?? '')));
        if ($status !== '' && in_array($status, status_keys(), true)) {
            $where[] = 'p.status = :status';
            $params['status'] = $status;
        }

        $statusGroup = strtolower(trim((string) ($filters['status_group'] ?? '')));
        if ($statusGroup === 'active') {
            $where[] = "p.status IN ('menunggu_verifikasi','diproses_cs','menunggu_teknisi','dalam_penanganan')";
        }

        $categoryId = (int) ($filters['category_id'] ?? 0);
        if ($categoryId > 0) {
            $where[] = 'p.kategori_id = :category_id';
            $params['category_id'] = $categoryId;
        }

        $technicianId = (int) ($filters['technician_id'] ?? 0);
        if ($technicianId > 0) {
            $where[] = 'EXISTS (
                SELECT 1 FROM penugasan_teknisi ptx
                WHERE ptx.pengaduan_id = p.id AND ptx.teknisi_id = :technician_id AND ptx.is_active = 1
            )';
            $params['technician_id'] = $technicianId;
        }

        $customerId = (int) ($filters['customer_id'] ?? 0);
        if ($customerId > 0) {
            $where[] = 'p.pelanggan_id = :customer_id';
            $params['customer_id'] = $customerId;
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(p.nomor_tiket LIKE :q_ticket OR p.nama_pelanggan_snapshot LIKE :q_name
                         OR p.username_pppoe_snapshot LIKE :q_pppoe)';
            $params['q_ticket'] = '%' . $q . '%';
            $params['q_name'] = '%' . $q . '%';
            $params['q_pppoe'] = '%' . $q . '%';
        }

        $period = self::normalizePeriod((string) ($filters['period'] ?? 'all'));
        [$start, $end] = self::periodRange($period);
        if ($start !== null && $end !== null) {
            $where[] = 'p.created_at >= :start AND p.created_at < :end';
            $params['start'] = $start;
            $params['end'] = $end;
        }

        return [implode(' AND ', $where), $params];
    }

    private static function complaintSelect(string $extra = ''): string
    {
        $columns = 'p.id, p.nomor_tiket, p.pelanggan_id, p.kategori_id, p.status, p.is_cancelled,
                    p.cancelled_at, p.archived_at, p.created_at,
                    p.nama_pelanggan_snapshot, p.username_pppoe_snapshot,
                    COALESCE(k.nama, \'Tanpa kategori\') AS kategori,
                    (SELECT u2.nama FROM penugasan_teknisi pt2
                       INNER JOIN users u2 ON u2.id = pt2.teknisi_id
                      WHERE pt2.pengaduan_id = p.id AND pt2.is_active = 1
                      ORDER BY pt2.id DESC LIMIT 1) AS teknisi,
                    COALESCE(
                        (SELECT l.completed_at FROM laporan_teknisi l WHERE l.pengaduan_id = p.id LIMIT 1),
                        (SELECT c.completed_at FROM penyelesaian_cs c WHERE c.pengaduan_id = p.id LIMIT 1)
                    ) AS completed_at';
        if ($extra !== '') {
            $columns .= ', ' . $extra;
        }

        return 'SELECT ' . $columns . ' FROM pengaduan p ' . self::complaintJoins();
    }

    private static function complaintJoins(): string
    {
        return 'LEFT JOIN kategori_gangguan k ON k.id = p.kategori_id';
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private static function publicFilters(array $filters): array
    {
        return [
            'status' => (string) ($filters['status'] ?? ''),
            'status_group' => (string) ($filters['status_group'] ?? ''),
            'category_id' => (int) ($filters['category_id'] ?? 0),
            'technician_id' => (int) ($filters['technician_id'] ?? 0),
            'customer_id' => (int) ($filters['customer_id'] ?? 0),
            'period' => self::normalizePeriod((string) ($filters['period'] ?? 'all')),
            'archived' => (string) ($filters['archived'] ?? 'include'),
            'cancelled' => (string) ($filters['cancelled'] ?? 'exclude'),
        ];
    }

    /**
     * @return array{total: int, active: int, completed: int, rejected: int, cancelled: int, last_ticket_at: ?string}
     */
    private static function customerComplaintCounts(int $customerId): array
    {
        $row = self::fetchOne(
            'SELECT COUNT(*) AS total,
                    COALESCE(SUM(status IN (\'menunggu_verifikasi\',\'diproses_cs\',\'menunggu_teknisi\',\'dalam_penanganan\')), 0) AS aktif,
                    COALESCE(SUM(status = \'selesai\'), 0) AS selesai,
                    COALESCE(SUM(status = \'ditolak\'), 0) AS ditolak,
                    MAX(created_at) AS terakhir
             FROM pengaduan
             WHERE pelanggan_id = :id AND is_cancelled = 0',
            ['id' => $customerId]
        ) ?? [];

        $cancelled = self::fetchScalar(
            'SELECT COUNT(*) FROM pengaduan WHERE pelanggan_id = :id AND is_cancelled = 1',
            ['id' => $customerId]
        );

        return [
            'total' => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['aktif'] ?? 0),
            'completed' => (int) ($row['selesai'] ?? 0),
            'rejected' => (int) ($row['ditolak'] ?? 0),
            'cancelled' => $cancelled,
            'last_ticket_at' => ($row['terakhir'] ?? null) === null
                ? null
                : format_waktu((string) $row['terakhir']),
        ];
    }

    private static function employeeOrder(string $order): string
    {
        return match (strtolower(trim($order))) {
            'name' => 'u.nama ASC',
            'created' => 'u.created_at DESC, u.id DESC',
            'last_login' => 'u.last_login_at DESC, u.nama ASC',
            default => "FIELD(u.role, 'admin', 'cs', 'teknisi'), u.nama ASC",
        };
    }

    private static function complaintOrder(string $order): string
    {
        return match (strtolower(trim($order))) {
            'oldest' => 'p.created_at ASC, p.id ASC',
            'status' => "FIELD(p.status, 'menunggu_verifikasi','diproses_cs','menunggu_teknisi','dalam_penanganan','selesai','ditolak'), p.created_at DESC",
            default => 'p.created_at DESC, p.id DESC',
        };
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private static function periodRange(string $period): array
    {
        $now = new DateTimeImmutable('now');
        $start = match ($period) {
            'today' => $now->setTime(0, 0, 0),
            'yesterday' => $now->setTime(0, 0, 0)->modify('-1 day'),
            'this_week' => $now->modify('monday this week')->setTime(0, 0, 0),
            'last_7_days' => $now->setTime(0, 0, 0)->modify('-6 days'),
            'this_month' => $now->modify('first day of this month')->setTime(0, 0, 0),
            'last_30_days' => $now->setTime(0, 0, 0)->modify('-29 days'),
            'this_year' => $now->setTime(0, 0, 0)->modify('first day of January'),
            default => null,
        };

        if ($start === null) {
            return [null, null];
        }

        $end = match ($period) {
            'today' => $start->modify('+1 day'),
            'yesterday' => $start->modify('+1 day'),
            'this_week' => $start->modify('+1 week'),
            'last_7_days' => $now->setTime(0, 0, 0)->modify('+1 day'),
            'this_month' => $start->modify('+1 month'),
            'last_30_days' => $now->setTime(0, 0, 0)->modify('+1 day'),
            'this_year' => $start->modify('+1 year'),
            default => $now->modify('+1 day'),
        };

        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
    }

    private static function normalizePeriod(string $period): string
    {
        $period = strtolower(trim($period));

        return in_array($period, [
            'today', 'yesterday', 'this_week', 'last_7_days', 'this_month',
            'last_30_days', 'this_year', 'all',
        ], true) ? $period : 'all';
    }

    private static function periodLabel(string $period): string
    {
        return match ($period) {
            'today' => 'hari ini',
            'yesterday' => 'kemarin',
            'this_week' => 'minggu ini',
            'last_7_days' => '7 hari terakhir',
            'this_month' => 'bulan ini',
            'last_30_days' => '30 hari terakhir',
            'this_year' => 'tahun ini',
            default => 'seluruh periode',
        };
    }

    private static function maskContact(string $value): string
    {
        $value = trim($value);
        $length = strlen($value);
        if ($length <= 4) {
            return $value === '' ? '' : str_repeat('*', $length);
        }

        return substr($value, 0, 3) . str_repeat('*', max(2, $length - 7)) . substr($value, -4);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<int, string> $roles
     */
    private static function can(array $user, array $roles): bool
    {
        return in_array((string) ($user['role'] ?? ''), $roles, true);
    }

    /**
     * @return array<string, mixed>
     */
    private static function denied(string $message): array
    {
        return ['error' => 'forbidden', 'message' => $message];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    private static function fetchAll(string $sql, array $params = []): array
    {
        $statement = Database::readPdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private static function fetchOne(string $sql, array $params = []): ?array
    {
        $statement = Database::readPdo()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function fetchScalar(string $sql, array $params = []): int
    {
        $statement = Database::readPdo()->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }
}
