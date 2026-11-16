<?php
/**
 * Service BACA data untuk AI Assistant WiFiCare CDN.
 *
 * Arsitektur:  Floating AI Chat → PHP AI Controller → Authorization & Tool
 * Service → AiReadService → PDO (read-only) → data terfilter → Gemini.
 *
 * Prinsip keamanan:
 * - Model AI TIDAK pernah menerima kredensial MySQL dan TIDAK pernah menulis
 *   SQL. Hanya fungsi di bawah ini yang dijalankan, semuanya SELECT dengan
 *   prepared statement dan parameter terikat.
 * - Tidak ada fungsi INSERT/UPDATE/DELETE/DROP/ALTER dan tidak ada eksekusi
 *   perintah. Identitas tabel/kolom tidak pernah berasal dari pemanggil.
 * - Setiap fungsi membatasi cakupan data sesuai peran (RBAC) dan kepemilikan
 *   data (pelanggan hanya data miliknya; teknisi hanya tugasnya).
 * - Pembacaan memakai `Database::readPdo()` yang dapat diarahkan ke akun MySQL
 *   khusus dengan izin SELECT saja (lihat `DB_RO_USER`/`DB_RO_PASSWORD`).
 */

declare(strict_types=1);

final class AiReadService
{
    /**
     * Ambil id profil pelanggan dari id pengguna (null bila bukan pelanggan).
     */
    public static function pelangganIdForUser(int $userId): ?int
    {
        if ($userId <= 0) {
            return null;
        }

        $statement = Database::readPdo()->prepare('SELECT id FROM pelanggan WHERE user_id = :user_id LIMIT 1');
        $statement->execute(['user_id' => $userId]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Daftar tiket yang boleh dilihat pengguna.
     *
     * - pelanggan : tiket miliknya sendiri.
     * - teknisi   : tiket yang ditugaskan kepadanya.
     * - cs/admin  : tiket terbaru (sesuai kewenangan mereka).
     *
     * @param array<string, mixed> $user
     * @return array{tickets: array<int, array<string, mixed>>, count: int}
     */
    public static function ticketsForUser(array $user, int $limit = 10): array
    {
        $limit = max(1, min(20, $limit));
        $role = (string) ($user['role'] ?? '');
        $userId = (int) ($user['id'] ?? 0);

        if ($role === 'pelanggan') {
            $pid = self::pelangganIdForUser($userId);
            if ($pid === null) {
                return ['tickets' => [], 'count' => 0];
            }

            $sql = 'SELECT p.nomor_tiket, p.status, p.is_cancelled, p.created_at, k.nama AS kategori,
                           p.nama_pelanggan_snapshot AS pelanggan_nama,
                           (SELECT u.nama FROM penugasan_teknisi pt2
                              INNER JOIN users u ON u.id = pt2.teknisi_id
                             WHERE pt2.pengaduan_id = p.id AND pt2.is_active = 1
                             ORDER BY pt2.id DESC LIMIT 1) AS teknisi_nama
                    FROM pengaduan p
                    LEFT JOIN kategori_gangguan k ON k.id = p.kategori_id
                    -- pelanggan_nama diambil dari snapshot pengaduan
                    WHERE p.pelanggan_id = :pelanggan_id
                    ORDER BY p.created_at DESC, p.id DESC
                    LIMIT ' . $limit;
            $rows = self::fetchAll($sql, ['pelanggan_id' => $pid]);
        } elseif ($role === 'teknisi') {
            $sql = 'SELECT p.nomor_tiket, p.status, p.is_cancelled, p.created_at, k.nama AS kategori,
                           p.nama_pelanggan_snapshot AS pelanggan_nama, pt.assigned_at
                    FROM penugasan_teknisi pt
                    INNER JOIN pengaduan p ON p.id = pt.pengaduan_id
                    LEFT JOIN kategori_gangguan k ON k.id = p.kategori_id
                    -- pelanggan_nama diambil dari snapshot pengaduan
                    WHERE pt.teknisi_id = :teknisi_id AND pt.is_active = 1 AND p.is_cancelled = 0
                    ORDER BY pt.assigned_at DESC, pt.id DESC
                    LIMIT ' . $limit;
            $rows = self::fetchAll($sql, ['teknisi_id' => $userId]);
        } elseif ($role === 'cs' || $role === 'admin') {
            $sql = 'SELECT p.nomor_tiket, p.status, p.is_cancelled, p.created_at, k.nama AS kategori,
                           p.nama_pelanggan_snapshot AS pelanggan_nama,
                           (SELECT u.nama FROM penugasan_teknisi pt2
                              INNER JOIN users u ON u.id = pt2.teknisi_id
                             WHERE pt2.pengaduan_id = p.id AND pt2.is_active = 1
                             ORDER BY pt2.id DESC LIMIT 1) AS teknisi_nama
                    FROM pengaduan p
                    LEFT JOIN kategori_gangguan k ON k.id = p.kategori_id
                    -- pelanggan_nama diambil dari snapshot pengaduan
                    ORDER BY p.created_at DESC, p.id DESC
                    LIMIT ' . $limit;
            $rows = self::fetchAll($sql);
        } else {
            return ['tickets' => [], 'count' => 0];
        }

        return ['tickets' => self::mapTickets($rows, $role), 'count' => count($rows)];
    }

    /**
     * Jumlah tiket AKTIF (menunggu verifikasi/diproses/penugasan/penanganan)
     * yang boleh dilihat pengguna, beserta total tiket dalam cakupannya.
     *
     * @param array<string, mixed> $user
     * @return array{active: int, total: int, scope: string}
     */
    public static function activeTicketCountForUser(array $user): array
    {
        $counts = self::statusCountsForUser($user);
        $active = status_sum_active($counts);

        return ['active' => $active, 'total' => array_sum($counts), 'scope' => self::scopeLabel($user)];
    }

    /**
     * Daftar tiket aktif sesuai cakupan pengguna.
     *
     * @param array<string, mixed> $user
     * @return array{tickets: array<int, array<string, mixed>>, count: int, scope: string}
     */
    public static function activeTicketsForUser(array $user, int $limit = 10): array
    {
        $limit = max(1, min(20, $limit));
        $role = (string) ($user['role'] ?? '');
        $userId = (int) ($user['id'] ?? 0);
        $statuses = "('menunggu_verifikasi','diproses_cs','menunggu_teknisi','dalam_penanganan')";

        if ($role === 'pelanggan') {
            $pid = self::pelangganIdForUser($userId);
            if ($pid === null) {
                return ['tickets' => [], 'count' => 0, 'scope' => self::scopeLabel($user)];
            }
            $rows = self::fetchAll(
                'SELECT p.nomor_tiket, p.status, p.is_cancelled, p.created_at, k.nama AS kategori,
                        p.nama_pelanggan_snapshot AS pelanggan_nama,
                        (SELECT u.nama FROM penugasan_teknisi pt2
                           INNER JOIN users u ON u.id = pt2.teknisi_id
                          WHERE pt2.pengaduan_id = p.id AND pt2.is_active = 1
                          ORDER BY pt2.id DESC LIMIT 1) AS teknisi_nama
                 FROM pengaduan p
                 LEFT JOIN kategori_gangguan k ON k.id = p.kategori_id
                 WHERE p.pelanggan_id = :pelanggan_id AND p.is_cancelled = 0
                   AND p.status IN ' . $statuses . '
                 ORDER BY p.created_at DESC, p.id DESC
                 LIMIT ' . $limit,
                ['pelanggan_id' => $pid]
            );
        } elseif ($role === 'teknisi') {
            $rows = self::fetchAll(
                'SELECT p.nomor_tiket, p.status, p.is_cancelled, p.created_at, k.nama AS kategori,
                        p.nama_pelanggan_snapshot AS pelanggan_nama, pt.assigned_at,
                        (SELECT u.nama FROM penugasan_teknisi pt2
                           INNER JOIN users u ON u.id = pt2.teknisi_id
                          WHERE pt2.pengaduan_id = p.id AND pt2.is_active = 1
                          ORDER BY pt2.id DESC LIMIT 1) AS teknisi_nama
                 FROM penugasan_teknisi pt
                 INNER JOIN pengaduan p ON p.id = pt.pengaduan_id
                 LEFT JOIN kategori_gangguan k ON k.id = p.kategori_id
                 WHERE pt.teknisi_id = :teknisi_id AND pt.is_active = 1 AND p.is_cancelled = 0
                   AND p.status IN ' . $statuses . '
                 ORDER BY pt.assigned_at DESC, pt.id DESC
                 LIMIT ' . $limit,
                ['teknisi_id' => $userId]
            );
        } elseif ($role === 'cs' || $role === 'admin') {
            $rows = self::fetchAll(
                'SELECT p.nomor_tiket, p.status, p.is_cancelled, p.created_at, k.nama AS kategori,
                        p.nama_pelanggan_snapshot AS pelanggan_nama,
                        (SELECT u.nama FROM penugasan_teknisi pt2
                           INNER JOIN users u ON u.id = pt2.teknisi_id
                          WHERE pt2.pengaduan_id = p.id AND pt2.is_active = 1
                          ORDER BY pt2.id DESC LIMIT 1) AS teknisi_nama
                 FROM pengaduan p
                 LEFT JOIN kategori_gangguan k ON k.id = p.kategori_id
                 WHERE p.is_cancelled = 0 AND p.status IN ' . $statuses . '
                 ORDER BY p.created_at DESC, p.id DESC
                 LIMIT ' . $limit
            );
        } else {
            return ['tickets' => [], 'count' => 0, 'scope' => self::scopeLabel($user)];
        }

        return [
            'tickets' => self::mapTickets($rows, $role),
            'count' => count($rows),
            'scope' => self::scopeLabel($user),
        ];
    }

    /**
     * Profil pelanggan milik pengguna yang sedang masuk (tidak lintas pengguna).
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function profileForUser(array $user): array
    {
        if ((string) ($user['role'] ?? '') !== 'pelanggan') {
            return ['error' => 'not_allowed', 'message' => 'Profil pelanggan hanya tersedia untuk akun pelanggan.'];
        }

        $userId = (int) ($user['id'] ?? 0);
        $rows = self::fetchAll(
            'SELECT u.nama, u.username, p.username_pppoe, p.nomor_whatsapp, p.alamat
             FROM pelanggan p
             INNER JOIN users u ON u.id = p.user_id
             WHERE p.user_id = :user_id
             LIMIT 1',
            ['user_id' => $userId]
        );

        if ($rows === []) {
            return ['found' => false, 'message' => 'Profil pelanggan tidak ditemukan untuk akun ini.'];
        }

        $row = $rows[0];

        return [
            'found' => true,
            'profil' => [
                'nama' => (string) ($row['nama'] ?? ''),
                'username' => (string) ($row['username'] ?? ''),
                'username_pppoe' => (string) ($row['username_pppoe'] ?? ''),
                'nomor_whatsapp' => self::maskContact((string) ($row['nomor_whatsapp'] ?? '')),
                'alamat' => (string) ($row['alamat'] ?? ''),
            ],
        ];
    }

    /**
     * Statistik jumlah tiket per status (selalu enam status) sesuai cakupan.
     *
     * @param array<string, mixed> $user
     * @return array{total: int, by_status: array<string, int>, scope: string, period: string, period_label: string}
     */
    public static function statusStatisticsForUser(array $user, string $period = 'all'): array
    {
        $period = self::normalizePeriod($period, 'all');
        $counts = self::statusCountsForUser($user, $period);
        $byLabel = [];
        foreach ($counts as $key => $jumlah) {
            $byLabel[status_label($key)] = $jumlah;
        }

        return [
            'total' => array_sum($counts),
            'by_status' => $byLabel,
            'scope' => self::scopeLabel($user),
            'period' => $period,
            'period_label' => self::periodLabel($period),
        ];
    }

    /**
     * Ringkasan pengaduan untuk periode tertentu (hari ini/bulan ini/semua).
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function complaintStatisticsForUser(array $user, string $period = 'today'): array
    {
        $period = self::normalizePeriod($period, 'today');
        $counts = self::statusCountsForUser($user, $period);
        $role = (string) ($user['role'] ?? '');
        $userId = (int) ($user['id'] ?? 0);
        [$start, $end] = self::periodRange($period);

        $scopeSql = '';
        $params = [];
        if ($period !== 'all') {
            $scopeSql .= ' AND p.created_at >= :start AND p.created_at < :end';
            $params['start'] = $start;
            $params['end'] = $end;
        }

        if ($role === 'pelanggan') {
            $pid = self::pelangganIdForUser($userId);
            if ($pid === null) {
                return [
                    'total' => 0,
                    'total_pengaduan_baru' => 0,
                    'by_status' => [],
                    'completed' => 0,
                    'tiket_selesai_pada_periode' => 0,
                    'categories' => [],
                    'scope' => self::scopeLabel($user),
                    'period' => $period,
                    'period_label' => self::periodLabel($period),
                ];
            }
            $scopeSql .= ' AND p.pelanggan_id = :pelanggan_id';
            $params['pelanggan_id'] = $pid;
            $from = ' FROM pengaduan p LEFT JOIN kategori_gangguan k ON k.id = p.kategori_id
                      WHERE p.is_cancelled = 0' . $scopeSql;
        } elseif ($role === 'teknisi') {
            $from = ' FROM penugasan_teknisi pt
                      INNER JOIN pengaduan p ON p.id = pt.pengaduan_id
                      LEFT JOIN kategori_gangguan k ON k.id = p.kategori_id
                      WHERE pt.teknisi_id = :teknisi_id AND pt.is_active = 1 AND p.is_cancelled = 0' . $scopeSql;
            $params['teknisi_id'] = $userId;
        } elseif ($role === 'cs' || $role === 'admin') {
            $from = ' FROM pengaduan p LEFT JOIN kategori_gangguan k ON k.id = p.kategori_id
                      WHERE p.is_cancelled = 0' . $scopeSql;
        } else {
            return ['error' => 'not_allowed'];
        }

        // Tiket selesai memakai riwayat status 'selesai' (tanggal transisi),
        // bukan created_at, agar pertanyaan "selesai bulan ini" akurat.
        $completed = 0;
        if (in_array($role, ['admin', 'cs', 'pelanggan', 'teknisi'], true)) {
            $completedParams = [];
            $completedSql = 'SELECT COUNT(DISTINCT p.id) AS jumlah
                             FROM riwayat_status rs
                             INNER JOIN pengaduan p ON p.id = rs.pengaduan_id
                             WHERE rs.status_baru = \'selesai\' AND p.is_cancelled = 0';

            if ($role === 'pelanggan') {
                $pid = self::pelangganIdForUser($userId);
                if ($pid !== null) {
                    $completedSql .= ' AND p.pelanggan_id = :pelanggan_id';
                    $completedParams['pelanggan_id'] = $pid;
                } else {
                    $completedSql = '';
                }
            } elseif ($role === 'teknisi') {
                $completedSql .= ' AND EXISTS (
                                     SELECT 1 FROM penugasan_teknisi pt
                                     WHERE pt.pengaduan_id = p.id AND pt.teknisi_id = :teknisi_id
                                       AND pt.is_active = 1
                                   )';
                $completedParams['teknisi_id'] = $userId;
            }

            if ($completedSql !== '') {
                if ($period !== 'all') {
                    $completedSql .= ' AND rs.created_at >= :cstart AND rs.created_at < :cend';
                    $completedParams['cstart'] = $start;
                    $completedParams['cend'] = $end;
                }
                $rows = self::fetchAll($completedSql, $completedParams);
                $completed = (int) ($rows[0]['jumlah'] ?? 0);
            }
        }

        $categoryRows = self::fetchAll(
            'SELECT COALESCE(k.nama, \'Tanpa kategori\') AS nama, COUNT(DISTINCT p.id) AS jumlah' . $from
            . ' GROUP BY k.id, k.nama ORDER BY jumlah DESC, k.nama ASC LIMIT 5',
            $params
        );

        $categories = [];
        foreach ($categoryRows as $row) {
            $categories[] = ['nama' => (string) $row['nama'], 'jumlah' => (int) $row['jumlah']];
        }

        $byLabel = [];
        foreach ($counts as $key => $jumlah) {
            if ($jumlah > 0) {
                $byLabel[status_label($key)] = $jumlah;
            }
        }

        return [
            'total' => array_sum($counts),
            'total_pengaduan_baru' => array_sum($counts),
            'by_status' => $byLabel,
            'completed' => $completed,
            'tiket_selesai_pada_periode' => $completed,
            'categories' => $categories,
            'scope' => self::scopeLabel($user),
            'period' => $period,
            'period_label' => self::periodLabel($period),
            'catatan' => 'total_pengaduan_baru = tiket yang DIBUAT pada periode; '
                . 'tiket_selesai_pada_periode = tiket yang berstatus selesai pada periode (berdasarkan riwayat status). '
                . 'Jangan menjumlahkan keduanya.',
        ];
    }

    /**
     * Statistik kategori gangguan sesuai cakupan pengguna.
     *
     * @param array<string, mixed> $user
     * @return array{categories: array<int, array{nama: string, jumlah: int}>, scope: string}
     */
    public static function categoryStatisticsForUser(array $user): array
    {
        $role = (string) ($user['role'] ?? '');
        $userId = (int) ($user['id'] ?? 0);
        $rows = [];

        if ($role === 'pelanggan') {
            $pid = self::pelangganIdForUser($userId);
            if ($pid === null) {
                return ['categories' => [], 'scope' => self::scopeLabel($user)];
            }

            $rows = self::fetchAll(
                'SELECT k.nama, COUNT(p.id) AS jumlah
                 FROM kategori_gangguan k
                 LEFT JOIN pengaduan p ON p.kategori_id = k.id AND p.is_cancelled = 0 AND p.pelanggan_id = :pelanggan_id
                 GROUP BY k.id, k.nama
                 ORDER BY jumlah DESC, k.nama ASC',
                ['pelanggan_id' => $pid]
            );
        } elseif ($role === 'teknisi') {
            $rows = self::fetchAll(
                'SELECT k.nama, COUNT(DISTINCT p.id) AS jumlah
                 FROM penugasan_teknisi pt
                 INNER JOIN pengaduan p ON p.id = pt.pengaduan_id
                 INNER JOIN kategori_gangguan k ON k.id = p.kategori_id
                 WHERE pt.teknisi_id = :teknisi_id AND pt.is_active = 1 AND p.is_cancelled = 0
                 GROUP BY k.id, k.nama
                 ORDER BY jumlah DESC, k.nama ASC',
                ['teknisi_id' => $userId]
            );
        } elseif ($role === 'cs' || $role === 'admin') {
            $rows = self::fetchAll(
                'SELECT k.nama, COUNT(p.id) AS jumlah
                 FROM kategori_gangguan k
                 LEFT JOIN pengaduan p ON p.kategori_id = k.id AND p.is_cancelled = 0
                 GROUP BY k.id, k.nama
                 ORDER BY jumlah DESC, k.nama ASC'
            );
        }

        $categories = [];
        foreach ($rows as $row) {
            $categories[] = ['nama' => (string) $row['nama'], 'jumlah' => (int) $row['jumlah']];
        }

        return ['categories' => $categories, 'scope' => self::scopeLabel($user)];
    }

    /**
     * Tugas teknisi: untuk teknisi → daftar tugasnya; untuk admin → ringkasan
     * kinerja seluruh teknisi. Peran lain tidak berhak.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function technicianAssignmentsForUser(array $user): array
    {
        $role = (string) ($user['role'] ?? '');
        $userId = (int) ($user['id'] ?? 0);

        if ($role === 'teknisi') {
            $rows = self::fetchAll(
                'SELECT p.nomor_tiket, p.status, p.is_cancelled, p.created_at, k.nama AS kategori,
                        p.nama_pelanggan_snapshot AS pelanggan_nama, pt.assigned_at, pt.started_at
                 FROM penugasan_teknisi pt
                 INNER JOIN pengaduan p ON p.id = pt.pengaduan_id
                 LEFT JOIN kategori_gangguan k ON k.id = p.kategori_id
                 -- pelanggan_nama dari snapshot pengaduan
                 WHERE pt.teknisi_id = :teknisi_id AND pt.is_active = 1 AND p.is_cancelled = 0
                 ORDER BY pt.assigned_at DESC, pt.id DESC',
                ['teknisi_id' => $userId]
            );

            return ['assignments' => self::mapTickets($rows, $role), 'count' => count($rows)];
        }

        if ($role === 'admin') {
            $rows = self::fetchAll(
                'SELECT u.nama,
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
                 ORDER BY total_tugas DESC, selesai DESC, u.nama ASC'
            );

            $performance = [];
            foreach ($rows as $row) {
                $performance[] = [
                    'teknisi' => (string) $row['nama'],
                    'total_tugas' => (int) $row['total_tugas'],
                    'selesai' => (int) $row['selesai'],
                    'aktif' => (int) $row['aktif'],
                ];
            }

            return ['performance' => $performance];
        }

        return ['error' => 'not_allowed', 'message' => 'Data kinerja teknisi hanya tersedia untuk Administrator.'];
    }

    /**
     * Jumlah pelanggan aktif (khusus Administrator).
     */
    public static function customerCount(): int
    {
        $statement = Database::readPdo()->query(
            'SELECT COUNT(*) FROM users WHERE role = \'pelanggan\' AND is_active = 1'
        );

        return (int) $statement->fetchColumn();
    }

    /**
     * Jumlah tiket per status sesuai cakupan pengguna (enam kunci).
     *
     * @param array<string, mixed> $user
     * @return array<string, int>
     */
    private static function statusCountsForUser(array $user, string $period = 'all'): array
    {
        $role = (string) ($user['role'] ?? '');
        $userId = (int) ($user['id'] ?? 0);
        $period = self::normalizePeriod($period, 'all');
        [$start, $end] = self::periodRange($period);

        $counts = [];
        foreach (status_keys() as $key) {
            $counts[$key] = 0;
        }

        $dateSql = '';
        $params = [];
        if ($period !== 'all') {
            $dateSql = ' AND p.created_at >= :start AND p.created_at < :end';
            $params['start'] = $start;
            $params['end'] = $end;
        }

        if ($role === 'pelanggan') {
            $pid = self::pelangganIdForUser($userId);
            if ($pid === null) {
                return $counts;
            }
            $params['pelanggan_id'] = $pid;
            $rows = self::fetchAll(
                'SELECT p.status, COUNT(*) AS jumlah FROM pengaduan p
                 WHERE p.pelanggan_id = :pelanggan_id AND p.is_cancelled = 0' . $dateSql . '
                 GROUP BY p.status',
                $params
            );
        } elseif ($role === 'teknisi') {
            $params['teknisi_id'] = $userId;
            $rows = self::fetchAll(
                'SELECT p.status, COUNT(DISTINCT pt.pengaduan_id) AS jumlah
                 FROM penugasan_teknisi pt
                 INNER JOIN pengaduan p ON p.id = pt.pengaduan_id
                 WHERE pt.teknisi_id = :teknisi_id AND pt.is_active = 1 AND p.is_cancelled = 0'
                . $dateSql . '
                 GROUP BY p.status',
                $params
            );
        } elseif ($role === 'cs' || $role === 'admin') {
            $rows = self::fetchAll(
                'SELECT p.status, COUNT(*) AS jumlah FROM pengaduan p
                 WHERE p.is_cancelled = 0' . $dateSql . '
                 GROUP BY p.status',
                $params
            );
        } else {
            return $counts;
        }

        foreach ($rows as $row) {
            $status = (string) $row['status'];
            if (array_key_exists($status, $counts)) {
                $counts[$status] = (int) $row['jumlah'];
            }
        }

        return $counts;
    }

    /**
     * Riwayat pekerjaan teknisi yang sedang masuk (tiket selesai/ditolak).
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function technicianHistoryForUser(array $user, int $limit = 10): array
    {
        if ((string) ($user['role'] ?? '') !== 'teknisi') {
            return ['error' => 'not_allowed', 'message' => 'Riwayat pekerjaan hanya tersedia untuk teknisi.'];
        }

        $limit = max(1, min(20, $limit));
        $rows = self::fetchAll(
            'SELECT p.nomor_tiket, p.status, p.is_cancelled, p.created_at, k.nama AS kategori,
                    p.nama_pelanggan_snapshot AS pelanggan_nama, pt.assigned_at, pt.started_at
             FROM penugasan_teknisi pt
             INNER JOIN pengaduan p ON p.id = pt.pengaduan_id
             LEFT JOIN kategori_gangguan k ON k.id = p.kategori_id
             WHERE pt.teknisi_id = :teknisi_id
               AND p.status IN (\'selesai\', \'ditolak\')
             ORDER BY COALESCE(p.updated_at, p.created_at) DESC, p.id DESC
             LIMIT ' . $limit,
            ['teknisi_id' => (int) ($user['id'] ?? 0)]
        );

        return [
            'history' => self::mapTickets($rows, 'teknisi'),
            'count' => count($rows),
            'scope' => self::scopeLabel($user),
        ];
    }

    /**
     * Ringkasan rating layanan (khusus Admin/CS).
     *
     * @return array<string, mixed>
     */
    public static function ratingSummary(): array
    {
        $rows = self::fetchAll(
            'SELECT COUNT(*) AS total, COALESCE(AVG(rating), 0) AS rata_rata,
                    COALESCE(SUM(rating = 1), 0) AS bintang1,
                    COALESCE(SUM(rating = 2), 0) AS bintang2,
                    COALESCE(SUM(rating = 3), 0) AS bintang3,
                    COALESCE(SUM(rating = 4), 0) AS bintang4,
                    COALESCE(SUM(rating = 5), 0) AS bintang5
             FROM rating_layanan'
        );

        $row = $rows[0] ?? [];

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
        ];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private static function periodRange(string $period): array
    {
        $now = new DateTimeImmutable('now');
        if ($period === 'today') {
            $start = $now->setTime(0, 0, 0);
            $end = $start->modify('+1 day');
        } elseif ($period === 'this_month') {
            $start = $now->modify('first day of this month')->setTime(0, 0, 0);
            $end = $start->modify('+1 month');
        } else {
            return [null, null];
        }

        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
    }

    private static function normalizePeriod(string $period, string $default): string
    {
        $period = strtolower(trim($period));

        return in_array($period, ['today', 'this_month', 'all'], true) ? $period : $default;
    }

    private static function periodLabel(string $period): string
    {
        return match ($period) {
            'today' => 'hari ini',
            'this_month' => 'bulan ini',
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
     * @param array<int, array<string, mixed>> $rows
     * @param string $role Peran pemanggil (membatasi field yang ditampilkan).
     * @return array<int, array<string, mixed>>
     */
    private static function mapTickets(array $rows, string $role = ''): array
    {
        $map = status_map();
        $tickets = [];

        foreach ($rows as $row) {
            $created = isset($row['created_at']) ? (string) $row['created_at'] : '';
            $statusKey = (string) $row['status'];

            $card = [
                'nomor_tiket' => (string) $row['nomor_tiket'],
                'status' => (string) ($map[$statusKey]['label'] ?? $statusKey),
                'status_key' => $statusKey,
                'kategori' => (string) ($row['kategori'] ?? 'Tanpa kategori'),
                'dibuat' => $created === '' ? '—' : format_waktu($created),
                'dibuat_panjang' => $created === '' ? '—' : format_waktu_panjang($created),
                'dibatalkan' => (int) ($row['is_cancelled'] ?? 0) === 1,
            ];

            // Batasi field identitas sesuai peran (jangan bocorkan data pengguna lain).
            if (in_array($role, ['admin', 'cs', 'teknisi'], true)
                && isset($row['pelanggan_nama']) && trim((string) $row['pelanggan_nama']) !== '') {
                $card['nama_pelanggan'] = (string) $row['pelanggan_nama'];
            }
            if (in_array($role, ['admin', 'cs', 'pelanggan'], true)
                && isset($row['teknisi_nama']) && trim((string) $row['teknisi_nama']) !== '') {
                $card['teknisi'] = (string) $row['teknisi_nama'];
            }

            $tickets[] = $card;
        }

        return $tickets;
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
     * Deskripsi cakupan data untuk transparansi jawaban AI.
     *
     * @param array<string, mixed> $user
     */
    private static function scopeLabel(array $user): string
    {
        return match ((string) ($user['role'] ?? '')) {
            'pelanggan' => 'tiket milik Anda sendiri',
            'teknisi' => 'tiket yang ditugaskan kepada Anda',
            'cs' => 'seluruh tiket pengaduan',
            'admin' => 'seluruh tiket pengaduan',
            default => 'tanpa data',
        };
    }
}
