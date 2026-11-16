<?php
/**
 * Local Database Assistant — WiFiCare CDN Tahap 18.
 *
 * Menjawab pertanyaan operasional yang sudah didukung TANPA API key/model AI.
 * Semua data berasal dari MySQL melalui AiOperationsReadService (SELECT
 * prepared statement). Tidak ada SQL bebas, tidak ada angka karangan, dan
 * setiap akses diperiksa terhadap role pada session server.
 *
 * Mode ini BUKAN model generatif penuh. Untuk pertanyaan di luar intent yang
 * didukung, method answer() mengembalikan null agar pemanggil memakai provider
 * AI, atau pesan keterbatasan yang jujur bila provider tidak tersedia.
 */

declare(strict_types=1);

final class LocalDatabaseAssistant
{
    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>|null
     */
    public static function answer(array $user, string $message, bool $logAccess = true): ?array
    {
        $message = trim($message);
        if ($message === '') {
            return null;
        }

        $identity = AiIdentity::resolve($user);
        if ($identity === null) {
            return [
                'ok' => false,
                'code' => 'identity_invalid',
                'error' => 'Sesi Anda tidak valid atau telah berakhir. Silakan masuk kembali.',
            ];
        }
        $user = array_merge($user, $identity);
        $userId = (int) $user['id'];
        $role = (string) $user['role'];
        $context = AiContext::get($userId);
        $normalized = self::normalize($message);

        // Identitas selalu dijawab dari session/database, bukan dari model.
        $identityReply = AiIdentity::directAnswer($identity, $message);
        if ($identityReply !== null) {
            return self::finish(
                $user,
                'identity.me',
                'identitas',
                $identityReply,
                [],
                [],
                1,
                [],
                $logAccess
            );
        }

        // Permintaan kredensial dan SQL bebas ditolak sebelum menyentuh
        // database. Pesan tidak disimpan ke audit; hanya metadata intent.
        if (self::isSecretDisclosureRequest($normalized)) {
            return self::finish(
                $user,
                'security.credentials_refused',
                'none',
                'Saya tidak dapat menampilkan API key, kata sandi, token, hash, sesi, atau kredensial lain.',
                [],
                [],
                0,
                [],
                $logAccess
            );
        }
        if (self::isFreeSqlRequest($normalized)) {
            return self::finish(
                $user,
                'security.sql_refused',
                'none',
                'Saya tidak dapat menjalankan SQL bebas atau mengubah database. Saya hanya dapat membaca data melalui intent yang diizinkan.',
                [],
                [],
                0,
                [],
                $logAccess
            );
        }

        $ticketNumber = self::extractTicketNumber($message);
        $intent = self::detectIntent($normalized, $context, $role, $ticketNumber);
        if ($intent === null) {
            return null;
        }

        try {
            if ($intent === 'access.denied') {
                return self::denied($user, self::denialMessage($normalized, $role), $logAccess);
            }

            return self::dispatch($intent, $user, $message, $normalized, $context, $ticketNumber, $logAccess);
        } catch (Throwable $exception) {
            // Jangan bocorkan detail teknis/kredensial dan jangan mengarang angka.
            app_log('Local Database Assistant gagal membaca data: ' . $exception->getMessage());
            if ($logAccess) {
                AiDataAccessLog::record($user, 'local', $intent, 'database_error', 0, false);
            }

            return [
                'ok' => false,
                'code' => 'database_unavailable',
                'error' => 'Data belum dapat diakses saat ini. Data aplikasi tidak dapat dibaca tanpa koneksi database.',
                'provider' => 'local',
                'model' => 'database-assistant',
                'request_type' => 'database',
                'intent' => $intent,
                'usage' => ['prompt' => 0, 'completion' => 0, 'total' => 0],
            ];
        }
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function dispatch(
        string $intent,
        array $user,
        string $message,
        string $text,
        array $context,
        string $ticketNumber,
        bool $logAccess
    ): array {
        $period = self::extractPeriod($text, (string) ($context['last_period'] ?? 'all'));
        $statusInfo = self::extractStatus($text);

        switch ($intent) {
            case 'employees.count_by_role':
                $counts = AiOperationsReadService::employeeCounts($user);
                if (isset($counts['error'])) {
                    return self::denied($user, (string) $counts['message'], $logAccess);
                }
                $byRole = is_array($counts['by_role'] ?? null) ? $counts['by_role'] : [];
                $rows = [
                    self::badgeRow('Admin', 'admin', (int) ($byRole['admin'] ?? 0)),
                    self::badgeRow('Customer Service', 'cs', (int) ($byRole['cs'] ?? 0)),
                    self::badgeRow('Teknisi', 'teknisi', (int) ($byRole['teknisi'] ?? 0)),
                ];
                $reply = 'Total karyawan: ' . (int) $counts['total'] . ' akun. '
                    . 'Admin: ' . (int) ($byRole['admin'] ?? 0) . ', '
                    . 'Customer Service: ' . (int) ($byRole['cs'] ?? 0) . ', '
                    . 'Teknisi: ' . (int) ($byRole['teknisi'] ?? 0) . '. '
                    . 'Aktif: ' . (int) $counts['active'] . ', nonaktif: ' . (int) $counts['inactive'] . '.';

                return self::finish(
                    $user,
                    $intent,
                    'karyawan',
                    $reply,
                    [self::table('Jumlah Karyawan per Role', [
                        ['key' => 'role', 'label' => 'Role', 'type' => 'badge', 'tone_key' => 'role_key'],
                        ['key' => 'jumlah', 'label' => 'Jumlah', 'type' => 'number'],
                    ], $rows)],
                    [],
                    (int) $counts['total'],
                    [],
                    $logAccess
                );

            case 'employees.count':
                $counts = AiOperationsReadService::employeeCounts($user);
                if (isset($counts['error'])) {
                    return self::denied($user, (string) $counts['message'], $logAccess);
                }
                $singleRole = self::singleRole($text);
                if ($singleRole !== null) {
                    $statusFilter = self::accountStatusFilter($text);
                    $total = $statusFilter === 'active'
                        ? (int) ($counts['by_role_active'][$singleRole] ?? 0)
                        : ($statusFilter === 'inactive'
                            ? (int) ($counts['by_role_inactive'][$singleRole] ?? 0)
                            : (int) ($counts['by_role'][$singleRole] ?? 0));
                    $label = role_label($singleRole);
                    $statusLabel = $statusFilter === 'active'
                        ? ' aktif'
                        : ($statusFilter === 'inactive' ? ' nonaktif' : '');
                    $reply = 'Jumlah ' . $label . $statusLabel . ': ' . $total . ' akun.';

                    return self::finish($user, $intent, 'karyawan', $reply, [], [], $total, [], $logAccess);
                }
                $reply = 'Total karyawan: ' . (int) $counts['total'] . ' akun, terdiri dari '
                    . (int) ($counts['by_role']['admin'] ?? 0) . ' Admin, '
                    . (int) ($counts['by_role']['cs'] ?? 0) . ' Customer Service, dan '
                    . (int) ($counts['by_role']['teknisi'] ?? 0) . ' Teknisi. '
                    . 'Aktif: ' . (int) $counts['active'] . ', nonaktif: ' . (int) $counts['inactive'] . '.';

                return self::finish($user, $intent, 'karyawan', $reply, [], [], (int) $counts['total'], [], $logAccess);

            case 'employees.list':
                $singleRole = self::singleRole($text);
                $filters = [
                    'limit' => self::extractLimit($text, 25),
                    'status' => self::accountStatusFilter($text),
                ];
                if ($singleRole !== null) {
                    $filters['role'] = $singleRole;
                }
                $result = AiOperationsReadService::employeeList($user, $filters);
                if (isset($result['error'])) {
                    return self::denied($user, (string) $result['message'], $logAccess);
                }
                $employees = is_array($result['employees'] ?? null) ? $result['employees'] : [];
                if ($employees === []) {
                    return self::finish($user, $intent, 'karyawan', 'Belum ada data karyawan yang cocok.', [], [], 0, [], $logAccess);
                }
                $rows = [];
                foreach ($employees as $employee) {
                    $rows[] = [
                        'nama' => $employee['nama'],
                        'username' => $employee['username'],
                        'role' => $employee['role_label'],
                        'role_key' => $employee['role'],
                        'status' => $employee['status'],
                        'status_key' => $employee['is_active'] ? 'aktif' : 'nonaktif',
                        'dibuat' => $employee['created_at'],
                        'login' => $employee['last_login_at'],
                    ];
                }
                $label = $singleRole !== null ? role_label($singleRole) : 'karyawan';
                $reply = 'Ditemukan ' . count($employees) . ' ' . $label . '.';

                return self::finish(
                    $user,
                    $intent,
                    'karyawan',
                    $reply,
                    [self::table('Daftar Karyawan', [
                        ['key' => 'nama', 'label' => 'Nama'],
                        ['key' => 'username', 'label' => 'Username'],
                        ['key' => 'role', 'label' => 'Role', 'type' => 'badge', 'tone_key' => 'role_key'],
                        ['key' => 'status', 'label' => 'Status', 'type' => 'badge', 'tone_key' => 'status_key'],
                        ['key' => 'dibuat', 'label' => 'Dibuat'],
                        ['key' => 'login', 'label' => 'Terakhir Login'],
                    ], $rows)],
                    [],
                    count($employees),
                    [],
                    $logAccess
                );

            case 'customers.count':
                $counts = AiOperationsReadService::customerCounts($user);
                if (isset($counts['error'])) {
                    return self::denied($user, (string) $counts['message'], $logAccess);
                }
                $customerStatus = self::accountStatusFilter($text);
                if ($customerStatus === 'active') {
                    $total = (int) $counts['active'];
                    $reply = 'Jumlah pelanggan aktif: ' . $total . '.';
                } elseif ($customerStatus === 'inactive') {
                    $total = (int) $counts['inactive'];
                    $reply = 'Jumlah pelanggan nonaktif: ' . $total . '.';
                } else {
                    $total = (int) $counts['total'];
                    $reply = 'Total pelanggan: ' . $total . '. '
                        . 'Aktif: ' . (int) $counts['active'] . ', nonaktif: ' . (int) $counts['inactive'] . '.';
                }

                return self::finish($user, $intent, 'pelanggan', $reply, [], [], $total, [], $logAccess);

            case 'customers.latest':
                $latestCustomer = AiOperationsReadService::latestCustomer($user);
                if (isset($latestCustomer['error'])) {
                    return self::denied($user, (string) $latestCustomer['message'], $logAccess);
                }
                $customer = is_array($latestCustomer['customer'] ?? null) ? $latestCustomer['customer'] : [];
                if (empty($latestCustomer['found']) || $customer === []) {
                    return self::finish($user, $intent, 'pelanggan', 'Belum ada data pelanggan.', [], [], 0, [], $logAccess);
                }
                $reply = 'Pelanggan terbaru: ' . (string) ($customer['nama'] ?? '')
                    . ' (PPPoE ' . (string) ($customer['username_pppoe'] ?? '-')
                    . ', status ' . (string) ($customer['status'] ?? '-') . ').';

                return self::finish(
                    $user,
                    $intent,
                    'pelanggan',
                    $reply,
                    [self::customerLookupTable([$customer])],
                    [],
                    1,
                    [
                        'last_customer_id' => (int) ($customer['id'] ?? 0),
                        'last_customer_name' => (string) ($customer['nama'] ?? ''),
                        'last_customer_pppoe' => (string) ($customer['username_pppoe'] ?? ''),
                        'last_period' => 'all',
                        'awaiting_customer_name' => null,
                        'pending_intent' => null,
                    ],
                    $logAccess
                );

            case 'customers.list':
                $limit = self::extractLimit($text, 20);
                $result = AiOperationsReadService::customerSearch($user, '', $limit);
                if (isset($result['error'])) {
                    return self::denied($user, (string) $result['message'], $logAccess);
                }
                $customers = is_array($result['customers'] ?? null) ? $result['customers'] : [];
                $totalMatching = (int) ($result['total_matching'] ?? count($customers));
                if ($customers === []) {
                    return self::finish($user, $intent, 'pelanggan', 'Belum ada data pelanggan.', [], [], 0, [], $logAccess);
                }
                $reply = $totalMatching > count($customers)
                    ? 'Menampilkan ' . count($customers) . ' dari ' . $totalMatching . ' pelanggan.'
                    : 'Ditemukan ' . count($customers) . ' pelanggan.';

                return self::finish(
                    $user,
                    $intent,
                    'pelanggan',
                    $reply,
                    [self::customerTable($customers)],
                    [],
                    $totalMatching,
                    [
                        'last_period' => 'all',
                        'awaiting_customer_name' => true,
                        'pending_intent' => 'customers.search',
                    ],
                    $logAccess
                );

            case 'customers.search':
            case 'customers.detail':
                return self::customerAnswer($user, $text, $context, $intent === 'customers.detail', $logAccess);

            case 'customers.complaints':
                return self::customerComplaintsAnswer($user, $text, $context, $logAccess);

            case 'customers.complaint_summary':
                return self::customerComplaintSummaryAnswer($user, $text, $context, $period, $statusInfo, $logAccess);

            case 'complaints.latest_customer':
            case 'complaints.latest_ticket':
                return self::latestComplaintAnswer($user, $intent, $text, $logAccess);

            case 'complaints.latest':
                $limit = self::extractLimit($text, 5);
                $result = AiOperationsReadService::complaintList($user, [
                    'limit' => $limit,
                    'order' => 'newest',
                    'period' => 'all',
                    'cancelled' => 'exclude',
                ]);
                if (isset($result['error'])) {
                    return self::denied($user, (string) $result['message'], $logAccess);
                }
                $complaints = is_array($result['complaints'] ?? null) ? $result['complaints'] : [];
                $reply = count($complaints) === 0
                    ? 'Belum ada pengaduan yang dapat ditampilkan.'
                    : count($complaints) . ' pengaduan terbaru.';

                return self::finish(
                    $user,
                    $intent,
                    'pengaduan',
                    $reply,
                    [],
                    self::ticketsFromComplaints($complaints),
                    count($complaints),
                    ['last_period' => 'all'],
                    $logAccess
                );

            case 'complaints.search':
                $filters = self::complaintFilters($user, $text, $context, $period, $statusInfo);
                $filters['limit'] = self::extractLimit($text, 10);
                $result = AiOperationsReadService::complaintList($user, $filters);
                if (isset($result['error'])) {
                    return self::denied($user, (string) $result['message'], $logAccess);
                }
                $complaints = is_array($result['complaints'] ?? null) ? $result['complaints'] : [];
                $periodLabel = self::periodLabel((string) ($filters['period'] ?? 'all'));
                $reply = count($complaints) === 0
                    ? 'Tidak ada pengaduan yang cocok untuk filter tersebut (' . $periodLabel . ').'
                    : 'Menampilkan ' . count($complaints) . ' dari ' . (int) ($result['total_matching'] ?? count($complaints))
                        . ' pengaduan (' . $periodLabel . ').';

                return self::finish(
                    $user,
                    $intent,
                    'pengaduan',
                    $reply,
                    [],
                    self::ticketsFromComplaints($complaints),
                    (int) ($result['total_matching'] ?? count($complaints)),
                    ['last_period' => (string) ($filters['period'] ?? 'all')],
                    $logAccess
                );

            case 'complaints.count_by_status':
                $stats = AiOperationsReadService::complaintStatusStatistics($user, [
                    'period' => $period,
                    'status' => $statusInfo['status'] !== '' ? $statusInfo['status'] : null,
                    'cancelled' => $statusInfo['cancelled'] ? 'only' : 'exclude',
                    'archived' => $statusInfo['archived'] ? 'only' : 'include',
                ]);
                if (isset($stats['error'])) {
                    return self::denied($user, (string) $stats['message'], $logAccess);
                }

                $periodLabel = self::periodLabel($period);
                if ($statusInfo['cancelled']) {
                    $reply = 'Pengaduan dibatalkan ' . $periodLabel . ': ' . (int) $stats['cancelled'] . ' tiket.';
                } elseif ($statusInfo['status'] === 'selesai' && $period !== 'all') {
                    $reply = 'Pengaduan selesai pada ' . $periodLabel . ': ' . (int) $stats['completed'] . ' tiket.';
                } elseif ($statusInfo['status'] !== '') {
                    $count = (int) ($stats['by_status_key'][$statusInfo['status']] ?? 0);
                    $reply = 'Pengaduan dengan status ' . status_label($statusInfo['status']) . ' '
                        . $periodLabel . ': ' . $count . ' tiket.';
                } elseif ($statusInfo['group'] === 'active') {
                    $reply = 'Pengaduan aktif ' . $periodLabel . ': ' . (int) $stats['active'] . ' tiket.';
                } else {
                    $reply = 'Total pengaduan ' . $periodLabel . ': ' . (int) $stats['total'] . ' tiket. '
                        . 'Aktif: ' . (int) $stats['active'] . ', selesai: ' . (int) $stats['completed'] . ', '
                        . 'ditolak: ' . (int) $stats['rejected'] . ', dibatalkan: ' . (int) $stats['cancelled'] . '.';
                }

                $rows = [];
                foreach ($stats['by_status'] as $label => $count) {
                    $rows[] = ['status' => $label, 'jumlah' => (int) $count];
                }
                $rows[] = ['status' => 'Dibatalkan', 'jumlah' => (int) $stats['cancelled']];

                return self::finish(
                    $user,
                    $intent,
                    'pengaduan',
                    $reply,
                    [self::table('Distribusi Status Pengaduan', [
                        ['key' => 'status', 'label' => 'Status'],
                        ['key' => 'jumlah', 'label' => 'Jumlah', 'type' => 'number'],
                    ], $rows)],
                    [],
                    (int) $stats['total'],
                    ['last_period' => $period, 'last_status' => $statusInfo['status']],
                    $logAccess
                );

            case 'complaints.count':
                $stats = AiOperationsReadService::complaintStatusStatistics($user, ['period' => $period]);
                if (isset($stats['error'])) {
                    return self::denied($user, (string) $stats['message'], $logAccess);
                }
                $reply = 'Total pengaduan ' . self::periodLabel($period) . ': ' . (int) $stats['total'] . ' tiket. '
                    . 'Aktif: ' . (int) $stats['active'] . ', selesai: ' . (int) $stats['completed'] . ', '
                    . 'ditolak: ' . (int) $stats['rejected'] . ', dibatalkan: ' . (int) $stats['cancelled'] . '.';

                return self::finish(
                    $user,
                    $intent,
                    'pengaduan',
                    $reply,
                    [],
                    [],
                    (int) $stats['total'],
                    ['last_period' => $period],
                    $logAccess
                );

            case 'complaints.detail':
                if ($ticketNumber === '' && !empty($context['last_ticket'])) {
                    $ticketNumber = (string) $context['last_ticket'];
                }

                return self::ticketDetailAnswer($user, $ticketNumber, $logAccess);

            case 'technicians.count':
                $counts = AiOperationsReadService::employeeCounts($user);
                if (isset($counts['error'])) {
                    return self::denied($user, (string) $counts['message'], $logAccess);
                }
                $statusFilter = self::accountStatusFilter($text);
                $total = $statusFilter === 'active'
                    ? (int) ($counts['by_role_active']['teknisi'] ?? 0)
                    : ($statusFilter === 'inactive'
                        ? (int) ($counts['by_role_inactive']['teknisi'] ?? 0)
                        : (int) ($counts['by_role']['teknisi'] ?? 0));
                $statusLabel = $statusFilter === 'active'
                    ? ' aktif'
                    : ($statusFilter === 'inactive' ? ' nonaktif' : '');
                $reply = 'Jumlah teknisi' . $statusLabel . ': ' . $total . ' akun.';

                return self::finish($user, $intent, 'teknisi', $reply, [], [], $total, [], $logAccess);

            case 'technicians.list':
                $filters = [
                    'role' => 'teknisi',
                    'status' => self::accountStatusFilter($text),
                    'limit' => self::extractLimit($text, 25),
                ];
                $result = AiOperationsReadService::employeeList($user, $filters);
                if (isset($result['error'])) {
                    return self::denied($user, (string) $result['message'], $logAccess);
                }
                $employees = is_array($result['employees'] ?? null) ? $result['employees'] : [];
                $rows = [];
                foreach ($employees as $employee) {
                    $rows[] = [
                        'nama' => $employee['nama'],
                        'username' => $employee['username'],
                        'status' => $employee['status'],
                        'status_key' => $employee['is_active'] ? 'aktif' : 'nonaktif',
                    ];
                }
                $reply = $employees === []
                    ? 'Belum ada data teknisi yang cocok.'
                    : 'Ditemukan ' . count($employees) . ' teknisi.';

                return self::finish(
                    $user,
                    $intent,
                    'teknisi',
                    $reply,
                    $rows === [] ? [] : [self::table('Daftar Teknisi', [
                        ['key' => 'nama', 'label' => 'Nama'],
                        ['key' => 'username', 'label' => 'Username'],
                        ['key' => 'status', 'label' => 'Status', 'type' => 'badge', 'tone_key' => 'status_key'],
                    ], $rows)],
                    [],
                    count($employees),
                    [],
                    $logAccess
                );

            case 'technicians.most_assignments':
            case 'technicians.least_assignments':
            case 'technicians.most_completed':
            case 'technicians.most_active':
            case 'technicians.never_assigned':
                return self::technicianRankingAnswer($user, $intent, $period, $logAccess);

            case 'technicians.performance':
                $performance = AiOperationsReadService::technicianPerformance($user, ['period' => $period]);
                if (isset($performance['error'])) {
                    return self::denied($user, (string) $performance['message'], $logAccess);
                }
                $rowsData = is_array($performance['performance'] ?? null) ? $performance['performance'] : [];
                $rows = [];
                foreach ($rowsData as $row) {
                    $rows[] = [
                        'teknisi' => $row['teknisi'],
                        'total_tugas' => (int) $row['total_tugas'],
                        'selesai' => (int) $row['selesai'],
                        'aktif' => (int) $row['aktif'],
                    ];
                }
                usort($rows, static fn (array $a, array $b): int => $b['total_tugas'] <=> $a['total_tugas']);
                $top = $rows[0] ?? null;
                $reply = $top === null || (int) $top['total_tugas'] === 0
                    ? 'Belum ada data penugasan teknisi.'
                    : 'Teknisi dengan penugasan terbanyak: ' . $top['teknisi'] . ' (' . (int) $top['total_tugas'] . ' penugasan).';

                return self::finish(
                    $user,
                    $intent,
                    'teknisi',
                    $reply,
                    [self::table('Kinerja Teknisi', [
                        ['key' => 'teknisi', 'label' => 'Teknisi'],
                        ['key' => 'total_tugas', 'label' => 'Penugasan', 'type' => 'number'],
                        ['key' => 'selesai', 'label' => 'Selesai', 'type' => 'number'],
                        ['key' => 'aktif', 'label' => 'Aktif', 'type' => 'number'],
                    ], $rows)],
                    [],
                    count($rows),
                    ['last_period' => $period],
                    $logAccess
                );

            case 'ratings.summary':
                $rating = AiOperationsReadService::ratingStatistics($user, ['period' => $period]);
                if (isset($rating['error'])) {
                    return self::denied($user, (string) $rating['message'], $logAccess);
                }
                $rows = [];
                foreach ($rating['distribusi'] as $star => $count) {
                    $rows[] = ['bintang' => $star . ' bintang', 'jumlah' => (int) $count];
                }
                $reply = 'Rating layanan ' . self::periodLabel($period) . ': rata-rata '
                    . number_format((float) $rating['rata_rata'], 2, ',', '.')
                    . ' dari ' . (int) $rating['total_rating'] . ' rating.';

                return self::finish(
                    $user,
                    $intent,
                    'rating',
                    $reply,
                    [self::table('Distribusi Rating', [
                        ['key' => 'bintang', 'label' => 'Rating'],
                        ['key' => 'jumlah', 'label' => 'Jumlah', 'type' => 'number'],
                    ], $rows)],
                    [],
                    (int) $rating['total_rating'],
                    ['last_period' => $period],
                    $logAccess
                );

            case 'complaints.trend':
                $granularity = preg_match('/\bbulan(?:an)?\b/u', $text) === 1 ? 'monthly' : 'daily';
                $trend = AiOperationsReadService::complaintTrend($user, [
                    'granularity' => $granularity,
                    'points' => $granularity === 'daily' ? 14 : 12,
                ]);
                if (isset($trend['error'])) {
                    return self::denied($user, (string) $trend['message'], $logAccess);
                }
                $rows = [];
                foreach ($trend['trend'] as $point) {
                    $rows[] = ['periode' => $point['periode'], 'jumlah' => (int) $point['jumlah']];
                }

                return self::finish(
                    $user,
                    $intent,
                    'pengaduan',
                    'Tren pengaduan ' . ($granularity === 'daily' ? '14 hari terakhir' : '12 bulan terakhir') . '.',
                    [self::table('Tren Pengaduan', [
                        ['key' => 'periode', 'label' => 'Periode'],
                        ['key' => 'jumlah', 'label' => 'Jumlah', 'type' => 'number'],
                    ], $rows)],
                    [],
                    count($rows),
                    [],
                    $logAccess
                );

            case 'stats.overview':
                $summary = AiOperationsReadService::operationalSummary($user);
                if (isset($summary['error'])) {
                    return self::denied($user, (string) $summary['message'], $logAccess);
                }
                $employees = $summary['employees'];
                $customers = $summary['customers'];
                $complaints = $summary['complaints'];
                $rating = $summary['rating'];
                $rows = [
                    ['metrik' => 'Total karyawan', 'nilai' => (int) $employees['total']],
                    ['metrik' => 'Admin', 'nilai' => (int) ($employees['by_role']['admin'] ?? 0)],
                    ['metrik' => 'Customer Service', 'nilai' => (int) ($employees['by_role']['cs'] ?? 0)],
                    ['metrik' => 'Teknisi', 'nilai' => (int) ($employees['by_role']['teknisi'] ?? 0)],
                    ['metrik' => 'Total pelanggan', 'nilai' => (int) $customers['total']],
                    ['metrik' => 'Pelanggan aktif', 'nilai' => (int) $customers['active']],
                    ['metrik' => 'Total pengaduan', 'nilai' => (int) $complaints['total']],
                    ['metrik' => 'Pengaduan aktif', 'nilai' => (int) $complaints['active']],
                    ['metrik' => 'Pengaduan selesai', 'nilai' => (int) $complaints['completed']],
                    ['metrik' => 'Pengaduan ditolak', 'nilai' => (int) $complaints['rejected']],
                    ['metrik' => 'Pengaduan dibatalkan', 'nilai' => (int) $complaints['cancelled']],
                    ['metrik' => 'Rating rata-rata', 'nilai' => number_format((float) $rating['rata_rata'], 2, ',', '.')],
                ];
                $reply = 'Ringkasan operasional: ' . (int) $employees['total'] . ' karyawan, '
                    . (int) $customers['total'] . ' pelanggan, ' . (int) $complaints['total']
                    . ' pengaduan, rating rata-rata '
                    . number_format((float) $rating['rata_rata'], 2, ',', '.') . '.';

                return self::finish(
                    $user,
                    $intent,
                    'operasional',
                    $reply,
                    [self::table('Ringkasan Operasional', [
                        ['key' => 'metrik', 'label' => 'Metrik'],
                        ['key' => 'nilai', 'label' => 'Nilai', 'type' => 'number'],
                    ], $rows)],
                    [],
                    1,
                    [],
                    $logAccess
                );

            case 'personal.active_tickets':
                $count = AiReadService::activeTicketCountForUser($user);
                $reply = 'Tiket aktif Anda: ' . (int) ($count['active'] ?? 0)
                    . ' dari total ' . (int) ($count['total'] ?? 0) . ' tiket dalam cakupan Anda.';

                return self::finish($user, $intent, 'pengaduan', $reply, [], [], (int) ($count['active'] ?? 0), [], $logAccess);

            case 'personal.tickets':
                $list = AiReadService::ticketsForUser($user, self::extractLimit($text, 10));
                $tickets = is_array($list['tickets'] ?? null) ? $list['tickets'] : [];

                return self::finish(
                    $user,
                    $intent,
                    'pengaduan',
                    count($tickets) === 0 ? 'Belum ada tiket dalam cakupan Anda.' : count($tickets) . ' tiket dalam cakupan Anda.',
                    [],
                    $tickets,
                    count($tickets),
                    [],
                    $logAccess
                );

            case 'personal.profile':
                $profile = AiReadService::profileForUser($user);
                if (empty($profile['found'])) {
                    return self::finish($user, $intent, 'pelanggan', (string) ($profile['message'] ?? 'Profil tidak ditemukan.'), [], [], 0, [], $logAccess);
                }
                $rows = [
                    ['atribut' => 'Nama', 'nilai' => $profile['profil']['nama']],
                    ['atribut' => 'Username', 'nilai' => $profile['profil']['username']],
                    ['atribut' => 'Username PPPoE', 'nilai' => $profile['profil']['username_pppoe']],
                    ['atribut' => 'Nomor WhatsApp', 'nilai' => $profile['profil']['nomor_whatsapp']],
                    ['atribut' => 'Alamat', 'nilai' => $profile['profil']['alamat']],
                ];

                return self::finish(
                    $user,
                    $intent,
                    'pelanggan',
                    'Profil pelanggan Anda.',
                    [self::table('Profil Saya', [
                        ['key' => 'atribut', 'label' => 'Atribut'],
                        ['key' => 'nilai', 'label' => 'Nilai'],
                    ], $rows)],
                    [],
                    1,
                    [],
                    $logAccess
                );
        }

        return self::finish(
            $user,
            'unsupported',
            'none',
            'Pertanyaan itu belum didukung oleh asisten database lokal. Saya tidak akan menebak jawabannya.',
            [],
            [],
            0,
            [],
            $logAccess
        );
    }

    /**
     * Jawaban peringkat teknisi. Metrik sengaja dipisah agar "kerjaannya
     * banyak" tidak dianggap jumlah akun teknisi, dan "paling sedikit" tidak
     * dianggap daftar karyawan.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private static function technicianRankingAnswer(array $user, string $intent, string $period, bool $logAccess): array
    {
        $performance = AiOperationsReadService::technicianPerformance($user, ['period' => $period]);
        if (isset($performance['error'])) {
            return self::denied($user, (string) $performance['message'], $logAccess);
        }

        $rows = is_array($performance['performance'] ?? null) ? $performance['performance'] : [];
        if ($rows === []) {
            return self::finish(
                $user,
                $intent,
                'teknisi',
                'Belum ada data teknisi yang dapat ditampilkan.',
                [],
                [],
                0,
                ['last_period' => $period],
                $logAccess
            );
        }

        if ($intent === 'technicians.never_assigned') {
            $matches = array_values(array_filter(
                $rows,
                static fn (array $row): bool => (int) ($row['total_penugasan'] ?? 0) === 0
            ));
            if ($matches === []) {
                return self::finish(
                    $user,
                    $intent,
                    'teknisi',
                    'Semua teknisi sudah pernah mendapatkan penugasan.',
                    [],
                    [],
                    0,
                    ['last_period' => $period],
                    $logAccess
                );
            }

            $names = array_map(static fn (array $row): string => (string) ($row['teknisi'] ?? ''), $matches);
            $reply = 'Teknisi yang belum pernah mendapatkan tugas: ' . self::joinNames($names) . '.';

            return self::finish(
                $user,
                $intent,
                'teknisi',
                $reply,
                [],
                [],
                count($matches),
                ['last_period' => $period],
                $logAccess
            );
        }

        $metric = match ($intent) {
            'technicians.most_completed' => 'selesai',
            'technicians.most_active' => 'aktif',
            default => 'total_tugas',
        };
        $isLeast = $intent === 'technicians.least_assignments';
        $values = array_map(static fn (array $row): int => (int) ($row[$metric] ?? 0), $rows);
        $target = $isLeast ? min($values) : max($values);
        $matches = array_values(array_filter(
            $rows,
            static fn (array $row): bool => (int) ($row[$metric] ?? 0) === $target
        ));

        $phrase = match ($intent) {
            'technicians.most_completed' => 'tiket selesai terbanyak',
            'technicians.most_active' => 'tugas aktif terbanyak',
            default => $isLeast ? 'penugasan paling sedikit' : 'penugasan terbanyak',
        };
        $unit = match ($intent) {
            'technicians.most_completed' => 'tiket selesai',
            'technicians.most_active' => 'tugas aktif',
            default => 'penugasan',
        };

        if (!$isLeast && $target === 0) {
            return self::finish(
                $user,
                $intent,
                'teknisi',
                'Belum ada ' . $unit . ' teknisi pada periode yang diminta.',
                [],
                [],
                0,
                ['last_period' => $period],
                $logAccess
            );
        }

        $names = array_map(static fn (array $row): string => (string) ($row['teknisi'] ?? ''), $matches);
        $reply = self::joinNames($names) . ' memiliki ' . $phrase . ', yaitu ' . $target . ' ' . $unit . '.';

        return self::finish(
            $user,
            $intent,
            'teknisi',
            $reply,
            [],
            [],
            count($matches),
            ['last_period' => $period],
            $logAccess
        );
    }

    /**
     * Pengaduan terbaru (opsional: pelanggan yang membuatnya).
     *
     * Urutan selalu created_at DESC lalu id DESC (tie-breaker) di
     * AiOperationsReadService. Tidak ada nama pelanggan yang diminta bila
     * pengguna bertanya tentang pengaduan terbaru secara global.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private static function latestComplaintAnswer(array $user, string $intent, string $text, bool $logAccess): array
    {
        $wantsCustomer = $intent === 'complaints.latest_customer';
        $limit = $wantsCustomer ? 1 : self::extractLimit($text, 5);

        $result = AiOperationsReadService::complaintList($user, [
            'limit' => $limit,
            'order' => 'newest',
            'period' => 'all',
            'cancelled' => 'include',
        ]);
        if (isset($result['error'])) {
            return self::denied($user, (string) $result['message'], $logAccess);
        }

        $complaints = is_array($result['complaints'] ?? null) ? $result['complaints'] : [];
        if ($complaints === []) {
            return self::finish(
                $user,
                $intent,
                'pengaduan',
                'Belum ada pengaduan yang dapat ditampilkan.',
                [],
                [],
                0,
                ['last_period' => 'all'],
                $logAccess
            );
        }

        $first = $complaints[0];
        $ticketNumber = (string) ($first['nomor_tiket'] ?? '');
        $customerName = trim((string) ($first['nama_pelanggan'] ?? ''));
        $pppoe = trim((string) ($first['username_pppoe'] ?? ''));
        $createdAt = (string) ($first['dibuat_panjang'] ?? $first['dibuat'] ?? '');

        if ($wantsCustomer) {
            if ($customerName === '') {
                return self::finish(
                    $user,
                    $intent,
                    'pengaduan',
                    'Pengaduan terbaru adalah tiket ' . $ticketNumber
                        . ' pada ' . $createdAt . ', tetapi nama pelanggan tidak tercatat pada tiket tersebut.',
                    [],
                    self::ticketsFromComplaints([$first]),
                    1,
                    ['last_period' => 'all', 'last_ticket' => $ticketNumber],
                    $logAccess
                );
            }

            return self::finish(
                $user,
                $intent,
                'pengaduan',
                'Pelanggan terbaru yang membuat pengaduan: ' . $customerName
                    . '. Tiket ' . $ticketNumber . ' pada ' . $createdAt . '.',
                [self::table('Pelanggan Terbaru', [
                    ['key' => 'nama', 'label' => 'Nama'],
                    ['key' => 'pppoe', 'label' => 'PPPoE'],
                    ['key' => 'tiket', 'label' => 'Nomor Tiket'],
                    ['key' => 'dibuat', 'label' => 'Dibuat'],
                ], [[
                    'nama' => $customerName,
                    'pppoe' => $pppoe !== '' ? $pppoe : 'Tidak tersedia',
                    'tiket' => $ticketNumber,
                    'dibuat' => $createdAt,
                ]])],
                self::ticketsFromComplaints([$first]),
                1,
                [
                    'last_customer_id' => (int) ($first['pelanggan_id'] ?? 0),
                    'last_customer_name' => $customerName,
                    'last_customer_pppoe' => $pppoe,
                    'last_ticket' => $ticketNumber,
                    'last_period' => 'all',
                    'pending_intent' => null,
                    'awaiting_customer_name' => null,
                ],
                $logAccess
            );
        }

        $reply = count($complaints) === 1
            ? 'Pengaduan terbaru: tiket ' . $ticketNumber . ' pada ' . $createdAt
                . ($customerName !== '' ? ' oleh ' . $customerName : '') . '.'
            : count($complaints) . ' pengaduan terbaru.';

        return self::finish(
            $user,
            $intent,
            'pengaduan',
            $reply,
            [],
            self::ticketsFromComplaints($complaints),
            count($complaints),
            [
                'last_period' => 'all',
                'last_ticket' => $ticketNumber,
                // Pelanggan dari daftar global tidak dijadikan konteks aktif
                // agar follow-up angka tetap global. Namun nama pendek tetap
                // boleh dicari tanpa memanggil cloud.
                'awaiting_customer_name' => $intent === 'complaints.latest_ticket',
                'pending_intent' => $intent === 'complaints.latest_ticket' ? 'customers.search' : null,
            ],
            $logAccess
        );
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function customerAnswer(array $user, string $text, array $context, bool $detailIntent, bool $logAccess): array
    {
        $name = self::extractCustomerName($text);
        if ($name === '' && self::isBareCustomerText($text)
            && (!empty($context['awaiting_customer_name']) || !empty($context['last_customer_id']))) {
            $name = self::cleanName($text);
        }
        $wantsList = self::hasAny($text, ['semua', 'daftar', 'tampilkan']);
        $useContext = $name === '' && !$wantsList && !empty($context['last_customer_id']);
        $lookupOnly = !$detailIntent
            && preg_match('/\b(?:apakah|ada|terdaftar|tercatat|cek|periksa)\b/u', $text) === 1
            && !self::hasAny($text, [
                'detail', 'alamat', 'whatsapp', 'nomor', 'pppoe', 'username',
                'profil', 'riwayat', 'pengaduan', 'tiket', 'status',
            ]);

        if ($name === '' && !$useContext && $wantsList) {
            $result = AiOperationsReadService::customerSearch($user, '', self::extractLimit($text, 20));
            if (isset($result['error'])) {
                return self::denied($user, (string) $result['message'], $logAccess);
            }
            $customers = is_array($result['customers'] ?? null) ? $result['customers'] : [];
            if ($customers === []) {
                return self::finish($user, 'customers.search', 'pelanggan', 'Belum ada data pelanggan.', [], [], 0, [], $logAccess);
            }

            return self::finish(
                $user,
                'customers.search',
                'pelanggan',
                'Ditemukan ' . count($customers) . ' pelanggan.',
                [self::customerTable($customers)],
                [],
                count($customers),
                ['last_period' => 'all'],
                $logAccess
            );
        }

        if ($name === '' && !$useContext) {
            return self::finish(
                $user,
                'customers.search',
                'pelanggan',
                'Pelanggan mana yang Anda maksud? Sebutkan nama atau username PPPoE pelanggan.',
                [],
                [],
                0,
                ['awaiting_customer_name' => true, 'pending_intent' => 'customers.search'],
                $logAccess
            );
        }

        if ($useContext) {
            $customerId = (int) $context['last_customer_id'];
            $detail = AiOperationsReadService::customerDetail($user, $customerId);
            if (empty($detail['found'])) {
                return self::finish($user, 'customers.detail', 'pelanggan', 'Data pelanggan pada konteks percakapan tidak ditemukan.', [], [], 0, [], $logAccess);
            }

            return self::customerDetailResult($user, $detail, $logAccess, 'customers.detail');
        }

        $result = AiOperationsReadService::customerSearch($user, $name, 10);
        if (isset($result['error'])) {
            return self::denied($user, (string) $result['message'], $logAccess);
        }
        $customers = is_array($result['customers'] ?? null) ? $result['customers'] : [];
        if ($customers === []) {
            return self::finish(
                $user,
                'customers.search',
                'pelanggan',
                'Pelanggan bernama ' . self::displayName($name) . ' tidak ditemukan.',
                [],
                [],
                0,
                [],
                $logAccess
            );
        }

        if (count($customers) > 1 && !$detailIntent) {
            return self::finish(
                $user,
                'customers.search',
                'pelanggan',
                'Ditemukan ' . count($customers) . ' pelanggan yang cocok. Sebutkan nama lengkap atau username PPPoE yang lebih spesifik untuk memilih pelanggan yang benar.',
                [$lookupOnly ? self::customerLookupTable($customers) : self::customerTable($customers)],
                [],
                count($customers),
                ['awaiting_customer_name' => true, 'pending_intent' => 'customers.search'],
                $logAccess
            );
        }

        if (count($customers) === 1 && $detailIntent) {
            $detail = AiOperationsReadService::customerDetail($user, (int) $customers[0]['id']);
            if (!empty($detail['found'])) {
                return self::customerDetailResult($user, $detail, $logAccess, 'customers.detail');
            }
        }

        $single = $customers[0] ?? null;
        $contextUpdate = [];
        if ($single !== null) {
            $contextUpdate = [
                'last_customer_id' => (int) $single['id'],
                'last_customer_name' => (string) $single['nama'],
                'last_customer_pppoe' => (string) $single['username_pppoe'],
                'awaiting_customer_name' => null,
                'pending_intent' => null,
            ];
        }

        $reply = count($customers) . ' pelanggan ditemukan.';
        if ($lookupOnly && count($customers) === 1) {
            $reply = 'Pelanggan bernama ' . (string) ($customers[0]['nama'] ?? $name) . ' ditemukan.';
        }

        return self::finish(
            $user,
            'customers.search',
            'pelanggan',
            $reply,
            [$lookupOnly ? self::customerLookupTable($customers) : self::customerTable($customers)],
            [],
            count($customers),
            $contextUpdate,
            $logAccess
        );
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $detail
     * @return array<string, mixed>
     */
    private static function customerDetailResult(array $user, array $detail, bool $logAccess, string $intent): array
    {
        $customer = is_array($detail['customer'] ?? null) ? $detail['customer'] : [];
        $rows = [
            ['atribut' => 'Nama', 'nilai' => (string) ($customer['nama'] ?? '')],
            ['atribut' => 'Username aplikasi', 'nilai' => (string) ($customer['username'] ?? '')],
            ['atribut' => 'Username PPPoE', 'nilai' => (string) ($customer['username_pppoe'] ?? '')],
            ['atribut' => 'Nomor WhatsApp', 'nilai' => (string) ($customer['nomor_whatsapp'] ?? '')],
            ['atribut' => 'Alamat', 'nilai' => (string) ($customer['alamat'] ?? '')],
            ['atribut' => 'Status akun', 'nilai' => (string) ($customer['status'] ?? '')],
            ['atribut' => 'Jumlah pengaduan (tanpa batal)', 'nilai' => (string) ($customer['jumlah_pengaduan'] ?? 0)],
            ['atribut' => 'Pengaduan aktif', 'nilai' => (string) ($customer['pengaduan_aktif'] ?? 0)],
            ['atribut' => 'Pengaduan selesai', 'nilai' => (string) ($customer['pengaduan_selesai'] ?? 0)],
            ['atribut' => 'Pengaduan ditolak', 'nilai' => (string) ($customer['pengaduan_ditolak'] ?? 0)],
            ['atribut' => 'Pengaduan dibatalkan', 'nilai' => (string) ($customer['pengaduan_dibatalkan'] ?? 0)],
        ];
        $reply = (string) ($customer['nama'] ?? 'Pelanggan') . ' — PPPoE '
            . (string) ($customer['username_pppoe'] ?? '—') . ', status '
            . (string) ($customer['status'] ?? '—') . '. Pengaduan (tanpa batal): '
            . (int) ($customer['jumlah_pengaduan'] ?? 0) . ' — aktif '
            . (int) ($customer['pengaduan_aktif'] ?? 0) . ', selesai '
            . (int) ($customer['pengaduan_selesai'] ?? 0) . ', ditolak '
            . (int) ($customer['pengaduan_ditolak'] ?? 0) . ', dibatalkan '
            . (int) ($customer['pengaduan_dibatalkan'] ?? 0) . '.';

        return self::finish(
            $user,
            $intent,
            'pelanggan',
            $reply,
            [self::table('Detail Pelanggan', [
                ['key' => 'atribut', 'label' => 'Atribut'],
                ['key' => 'nilai', 'label' => 'Nilai'],
            ], $rows)],
            [],
            1,
            [
                'last_customer_id' => (int) ($customer['id'] ?? 0),
                'last_customer_name' => (string) ($customer['nama'] ?? ''),
                'last_customer_pppoe' => (string) ($customer['username_pppoe'] ?? ''),
                'awaiting_customer_name' => null,
                'pending_intent' => null,
            ],
            $logAccess
        );
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function customerComplaintsAnswer(array $user, string $text, array $context, bool $logAccess): array
    {
        $name = self::extractCustomerName($text);
        if ($name === '' && self::isBareCustomerText($text)
            && (!empty($context['awaiting_customer_name']) || !empty($context['last_customer_id']))) {
            $name = self::cleanName($text);
        }

        $customerId = 0;
        if ($name !== '') {
            $matches = self::customerMatchesForName($user, $name, $logAccess);
            if (is_array($matches) && isset($matches['error'])) {
                return $matches['error'];
            }
            if (is_array($matches) && count($matches['customers'] ?? []) > 1) {
                $customers = $matches['customers'];

                return self::finish(
                    $user,
                    'customers.complaints',
                    'pengaduan',
                    'Ditemukan ' . count($customers) . ' pelanggan dengan nama yang sama. Sebutkan username PPPoE yang tepat agar riwayat tidak tertukar.',
                    [self::customerTable($customers)],
                    [],
                    count($customers),
                    [
                        'last_customer_id' => null,
                        'last_customer_name' => null,
                        'last_customer_pppoe' => null,
                        'awaiting_customer_name' => true,
                        'pending_intent' => 'customers.complaints',
                    ],
                    $logAccess
                );
            }
            $customerId = (int) ($matches['customers'][0]['id'] ?? 0);
            if ($customerId <= 0) {
                return self::finish($user, 'customers.complaints', 'pengaduan', 'Pelanggan dengan nama atau PPPoE "' . $name . '" tidak ditemukan.', [], [], 0, [], $logAccess);
            }
        } elseif (!empty($context['last_customer_id'])) {
            $customerId = (int) $context['last_customer_id'];
        }

        if ($customerId <= 0) {
            return self::finish(
                $user,
                'customers.complaints',
                'pengaduan',
                'Pelanggan mana yang Anda maksud? Sebutkan nama atau username PPPoE pelanggan terlebih dahulu.',
                [],
                [],
                0,
                ['awaiting_customer_name' => true, 'pending_intent' => 'customers.complaints'],
                $logAccess
            );
        }

        $period = self::extractPeriod($text, (string) ($context['last_period'] ?? 'all'));
        $result = AiOperationsReadService::customerComplaints($user, $customerId, [
            'limit' => self::extractLimit($text, 10),
            'period' => $period,
            'cancelled' => 'include',
        ]);
        if (isset($result['error'])) {
            return self::denied($user, (string) $result['message'], $logAccess);
        }
        $customer = is_array($result['customer'] ?? null) ? $result['customer'] : [];
        $complaints = is_array($result['complaints'] ?? null) ? $result['complaints'] : [];
        $counts = is_array($result['counts'] ?? null) ? $result['counts'] : [];
        $reply = 'Riwayat pengaduan ' . (string) ($customer['nama'] ?? 'pelanggan') . ': '
            . (int) ($counts['total'] ?? 0) . ' tiket tanpa batal (aktif '
            . (int) ($counts['active'] ?? 0) . ', selesai '
            . (int) ($counts['completed'] ?? 0) . ', ditolak '
            . (int) ($counts['rejected'] ?? 0) . '); '
            . (int) ($counts['cancelled'] ?? 0) . ' tiket dibatalkan.';

        return self::finish(
            $user,
            'customers.complaints',
            'pengaduan',
            $reply,
            [],
            self::ticketsFromComplaints($complaints),
            (int) ($counts['total'] ?? count($complaints)),
            [
                'last_customer_id' => (int) ($customer['id'] ?? 0),
                'last_customer_name' => (string) ($customer['nama'] ?? ''),
                'last_customer_pppoe' => (string) ($customer['username_pppoe'] ?? ''),
                'last_period' => $period,
                'awaiting_customer_name' => null,
                'pending_intent' => null,
            ],
            $logAccess
        );
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $context
     * @param array{status: string, group: string, cancelled: bool, archived: bool} $statusInfo
     * @return array<string, mixed>
     */
    private static function customerComplaintSummaryAnswer(
        array $user,
        string $text,
        array $context,
        string $period,
        array $statusInfo,
        bool $logAccess
    ): array {
        $customerId = (int) ($context['last_customer_id'] ?? 0);
        if ($customerId <= 0) {
            return self::finish(
                $user,
                'customers.complaint_summary',
                'pengaduan',
                'Pelanggan mana yang Anda maksud? Sebutkan nama atau username PPPoE pelanggan terlebih dahulu.',
                [],
                [],
                0,
                ['awaiting_customer_name' => true, 'pending_intent' => 'customers.complaints'],
                $logAccess
            );
        }

        $detail = AiOperationsReadService::customerDetail($user, $customerId);
        if (empty($detail['found'])) {
            return self::finish($user, 'customers.complaint_summary', 'pengaduan', 'Data pelanggan pada konteks percakapan tidak ditemukan.', [], [], 0, [], $logAccess);
        }

        $stats = AiOperationsReadService::complaintStatusStatistics($user, [
            'period' => $period,
            'customer_id' => $customerId,
            'cancelled' => $statusInfo['cancelled'] ? 'only' : 'exclude',
        ]);
        if (isset($stats['error'])) {
            return self::denied($user, (string) $stats['message'], $logAccess);
        }

        $customer = is_array($detail['customer'] ?? null) ? $detail['customer'] : [];
        $name = (string) ($customer['nama'] ?? 'Pelanggan');
        $periodLabel = self::periodLabel($period);
        if ($statusInfo['cancelled']) {
            $count = (int) $stats['cancelled'];
            $reply = 'Pengaduan ' . $name . ' yang dibatalkan ' . $periodLabel . ': ' . $count . ' tiket.';
        } elseif ($statusInfo['status'] === 'selesai') {
            $count = (int) $stats['completed'];
            $reply = 'Pengaduan ' . $name . ' yang selesai ' . $periodLabel . ': ' . $count . ' tiket.';
        } elseif ($statusInfo['status'] === 'ditolak') {
            $count = (int) $stats['rejected'];
            $reply = 'Pengaduan ' . $name . ' yang ditolak ' . $periodLabel . ': ' . $count . ' tiket.';
        } elseif ($statusInfo['group'] === 'active') {
            $count = (int) $stats['active'];
            $reply = 'Pengaduan aktif ' . $name . ' ' . $periodLabel . ': ' . $count . ' tiket.';
        } elseif ($statusInfo['status'] !== '') {
            $count = (int) ($stats['by_status_key'][$statusInfo['status']] ?? 0);
            $reply = 'Pengaduan ' . $name . ' dengan status ' . status_label($statusInfo['status'])
                . ' ' . $periodLabel . ': ' . $count . ' tiket.';
        } else {
            $count = (int) $stats['total'];
            $reply = 'Total pengaduan ' . $name . ' ' . $periodLabel . ': ' . $count . ' tiket.';
        }

        return self::finish(
            $user,
            'customers.complaint_summary',
            'pengaduan',
            $reply,
            [self::table('Ringkasan Pengaduan Pelanggan', [
                ['key' => 'metrik', 'label' => 'Metrik'],
                ['key' => 'nilai', 'label' => 'Jumlah', 'type' => 'number'],
            ], [
                ['metrik' => 'Total', 'nilai' => (int) $stats['total']],
                ['metrik' => 'Aktif', 'nilai' => (int) $stats['active']],
                ['metrik' => 'Selesai', 'nilai' => (int) $stats['completed']],
                ['metrik' => 'Ditolak', 'nilai' => (int) $stats['rejected']],
                ['metrik' => 'Dibatalkan', 'nilai' => (int) $stats['cancelled']],
            ])],
            [],
            $count,
            [
                'last_period' => $period,
                'last_customer_id' => $customerId,
                'awaiting_customer_name' => null,
                'pending_intent' => null,
            ],
            $logAccess
        );
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private static function ticketDetailAnswer(array $user, string $ticketNumber, bool $logAccess): array
    {
        $ticketNumber = strtoupper(trim($ticketNumber));
        if (preg_match('/^WFC-\d{8}-\d{6}$/', $ticketNumber) !== 1) {
            return self::finish($user, 'complaints.detail', 'pengaduan', 'Nomor tiket tidak valid. Format: WFC-YYYYMMDD-XXXXXX.', [], [], 0, [], $logAccess);
        }

        $role = (string) ($user['role'] ?? '');
        if (in_array($role, ['admin', 'cs'], true)) {
            $detail = AiOperationsReadService::complaintDetail($user, $ticketNumber);
            if (empty($detail['found'])) {
                return self::finish($user, 'complaints.detail', 'pengaduan', (string) ($detail['message'] ?? 'Tiket tidak ditemukan.'), [], [], 0, [], $logAccess);
            }
            $complaint = is_array($detail['complaint'] ?? null) ? $detail['complaint'] : [];
            $rows = [
                ['atribut' => 'Nomor tiket', 'nilai' => (string) ($complaint['nomor_tiket'] ?? '')],
                ['atribut' => 'Pelanggan', 'nilai' => (string) ($complaint['nama_pelanggan'] ?? '')],
                ['atribut' => 'Username PPPoE', 'nilai' => (string) ($complaint['username_pppoe'] ?? '')],
                ['atribut' => 'Kategori', 'nilai' => (string) ($complaint['kategori'] ?? '')],
                ['atribut' => 'Status', 'nilai' => (string) ($complaint['status'] ?? '')],
                ['atribut' => 'Teknisi', 'nilai' => (string) ($complaint['teknisi'] ?? '—')],
                ['atribut' => 'Dibuat', 'nilai' => (string) ($complaint['dibuat_panjang'] ?? '')],
                ['atribut' => 'Selesai', 'nilai' => (string) ($complaint['selesai'] ?? '—')],
                ['atribut' => 'Deskripsi', 'nilai' => (string) ($complaint['deskripsi'] ?? '')],
            ];
            if (!empty($complaint['dibatalkan'])) {
                $rows[] = ['atribut' => 'Dibatalkan', 'nilai' => (string) ($complaint['cancellation_reason'] ?? 'Ya')];
            }
            if (!empty($complaint['alasan_penolakan'])) {
                $rows[] = ['atribut' => 'Alasan penolakan', 'nilai' => (string) $complaint['alasan_penolakan']];
            }
            $tables = [self::table('Detail Tiket', [
                ['key' => 'atribut', 'label' => 'Atribut'],
                ['key' => 'nilai', 'label' => 'Nilai'],
            ], $rows)];
            $history = is_array($detail['history'] ?? null) ? $detail['history'] : [];
            if ($history !== []) {
                $historyRows = [];
                foreach ($history as $entry) {
                    $historyRows[] = [
                        'waktu' => $entry['waktu'],
                        'status' => $entry['status_baru_label'],
                        'catatan' => $entry['catatan'],
                        'actor' => $entry['actor'],
                    ];
                }
                $tables[] = self::table('Riwayat Status', [
                    ['key' => 'waktu', 'label' => 'Waktu'],
                    ['key' => 'status', 'label' => 'Status'],
                    ['key' => 'catatan', 'label' => 'Catatan'],
                    ['key' => 'actor', 'label' => 'Oleh'],
                ], $historyRows);
            }
            $report = is_array($detail['report'] ?? null) ? $detail['report'] : null;
            if ($report !== null) {
                $tables[] = self::table('Ringkasan Laporan Perbaikan', [
                    ['key' => 'atribut', 'label' => 'Atribut'],
                    ['key' => 'nilai', 'label' => 'Nilai'],
                ], [
                    ['atribut' => 'Penyebab', 'nilai' => (string) ($report['penyebab_gangguan'] ?? '')],
                    ['atribut' => 'Tindakan', 'nilai' => (string) ($report['tindakan_perbaikan'] ?? '')],
                    ['atribut' => 'Teknisi', 'nilai' => (string) ($report['teknisi'] ?? '')],
                    ['atribut' => 'Selesai', 'nilai' => (string) ($report['completed_at'] ?? '')],
                ]);
            }

            return self::finish(
                $user,
                'complaints.detail',
                'pengaduan',
                'Detail tiket ' . $ticketNumber . ' berstatus ' . (string) ($complaint['status'] ?? '—') . '.',
                $tables,
                self::ticketsFromComplaints([$complaint]),
                1,
                ['last_ticket' => $ticketNumber],
                $logAccess
            );
        }

        $ticket = AiToolService::getTicketStatus($user, $ticketNumber);
        if (empty($ticket['found'])) {
            return self::finish($user, 'complaints.detail', 'pengaduan', (string) ($ticket['message'] ?? 'Tiket tidak ditemukan.'), [], [], 0, [], $logAccess);
        }
        $data = is_array($ticket['ticket'] ?? null) ? $ticket['ticket'] : [];
        $reply = 'Tiket ' . $ticketNumber . ' berstatus ' . (string) ($data['status'] ?? '—') . '.';

        return self::finish(
            $user,
            'complaints.detail',
            'pengaduan',
            $reply,
            [],
            self::ticketsFromComplaints([$data]),
            1,
            ['last_ticket' => $ticketNumber],
            $logAccess
        );
    }

    /**
     * Tentukan intent berdasarkan teks, konteks, role, dan nomor tiket.
     */
    private static function detectIntent(string $text, array $context, string $role, string $ticketNumber): ?string
    {
        if ($ticketNumber !== '') {
            return 'complaints.detail';
        }

        $hasEmployee = self::hasAny($text, ['karyawan', 'staf', 'staff', 'pegawai', 'employee', 'petugas']);
        $hasCustomer = self::hasAny($text, ['pelanggan', 'customer', 'langganan'])
            || self::extractCustomerName($text) !== '';
        $hasComplaint = self::hasAny($text, [
            'pengaduan', 'aduan', 'tiket', 'laporan', 'komplain', 'complaint',
            'mengadu', 'mengadukan', 'melapor', 'mengeluh',
        ]);
        // "banyak" bukan sinonim "berapa": penting agar "kerjaannya banyak"
        // tidak jatuh ke jumlah akun teknisi.
        $hasCount = self::hasAny($text, ['berapa', 'jumlah', 'total', 'hitung']);
        $hasMany = self::hasAny($text, ['banyak']);
        $hasList = self::hasAny($text, ['siapa', 'daftar', 'tampilkan', 'lihat', 'sebutkan', 'semua', 'seluruh', 'apa saja']);
        $hasLatest = self::hasAny($text, ['terbaru', 'terakhir', 'paling baru']);
        $hasMine = self::hasAny($text, ['saya', 'aku']);
        $statusInfo = self::extractStatus($text);

        $roleWords = [];
        if (preg_match('/\badmin(?:istrator)?\b/u', $text) === 1) {
            $roleWords[] = 'admin';
        }
        if (preg_match('/\b(cs|customer service|layanan pelanggan)\b/u', $text) === 1) {
            $roleWords[] = 'cs';
        }
        if (preg_match('/\b(teknisi|tehnisi|technician)\b/u', $text) === 1) {
            $roleWords[] = 'teknisi';
        }

        $technicianTopic = in_array('teknisi', $roleWords, true)
            || preg_match('/\b(menangani|mengurus|mengerjakan|menyelesaikan)\b.{0,40}\b(tiket|pengaduan|tugas)\b/u', $text) === 1;
        $hasMost = self::hasAny($text, [
            'paling banyak', 'terbanyak', 'paling sibuk', 'tersibuk', 'paling produktif',
            'paling sering', 'beban terbanyak', 'paling tinggi',
        ]) || preg_match('/\b(kerjaan|kerja|pekerjaan|tugas|beban)(?:nya)?\b.{0,30}\b(banyak|sibuk|produktif)\b/u', $text) === 1;
        $hasLeast = self::hasAny($text, [
            'paling sedikit', 'tersedikit', 'paling jarang', 'terjarang', 'paling rendah',
            'paling sepi',
        ]) || preg_match('/\b(kerjaan|kerja|pekerjaan|tugas|beban)(?:nya)?\b.{0,30}\b(sedikit|jarang|sepi)\b/u', $text) === 1;
        $hasNever = self::hasAny($text, [
            'belum pernah', 'tidak pernah', 'belum mendapatkan', 'belum mendapat',
            'belum memiliki tugas', 'tanpa tugas', '0 tugas', 'nol tugas',
        ]);
        $hasCompletedMetric = self::hasAny($text, ['selesai', 'menyelesaikan', 'diselesaikan']);
        $hasActiveMetric = self::hasAny($text, ['tugas aktif', 'pekerjaan aktif', 'sedang dikerjakan', 'sedang berjalan']);

        // Permintaan data teknisi lain oleh teknisi sendiri harus ditolak,
        // bukan dijawab dengan tugas miliknya.
        if ($role === 'teknisi' && $hasMine
            && self::hasAny($text, ['teknisi lain', 'semua teknisi', 'seluruh teknisi', 'tugas teknisi lain'])) {
            return 'access.denied';
        }

        // Peringkat penugasan teknisi diproses sebelum daftar/jumlah karyawan.
        // Definisi metrik dibedakan: penugasan aktif, tugas aktif, tiket selesai,
        // dan riwayat penugasan.
        if ($technicianTopic && ($hasMost || $hasLeast || $hasNever)) {
            if ($role !== 'admin') {
                return 'access.denied';
            }
            if ($hasNever) {
                return 'technicians.never_assigned';
            }
            if ($hasLeast) {
                return 'technicians.least_assignments';
            }
            if ($hasCompletedMetric) {
                return 'technicians.most_completed';
            }
            if ($hasActiveMetric) {
                return 'technicians.most_active';
            }

            return 'technicians.most_assignments';
        }

        // Pertanyaan kinerja/daftar/jumlah teknisi. Tidak boleh menangkap
        // pertanyaan jumlah tiket yang ditangani teknisi tertentu.
        if ($technicianTopic && !$hasComplaint && !$hasCustomer) {
            if (self::hasAny($text, ['kinerja', 'performa', 'beban kerja']) && !$hasCount) {
                return $role === 'admin' ? 'technicians.performance' : 'access.denied';
            }
            if (count(array_unique($roleWords)) === 1 && in_array('teknisi', $roleWords, true)) {
                if ($hasCount) {
                    return $role === 'admin' ? 'technicians.count' : 'access.denied';
                }
                if ($hasList || $hasMany) {
                    return $role === 'admin' ? 'technicians.list' : 'access.denied';
                }
            }
        }

        if ($hasEmployee && $hasCustomer) {
            return 'unsupported';
        }

        if ($hasEmployee || (($hasCount || $hasList || $hasMany) && $roleWords !== [] && !$hasCustomer && !$hasComplaint)) {
            if ($role !== 'admin') {
                return 'access.denied';
            }
            $countByRole = self::hasAny($text, ['berdasarkan role', 'per role', 'masing masing role', 'tiap role', 'per peran', 'berdasarkan peran'])
                || (count(array_unique($roleWords)) > 1);
            if ($hasCount) {
                if ($countByRole) {
                    return 'employees.count_by_role';
                }

                return 'employees.count';
            }

            return 'employees.list';
        }

        // Jawaban singkat berupa nama pelanggan (mis. "Heri") hanya dipakai
        // bila percakapan sebelumnya memang sedang menunggu/membahas pelanggan.
        // Ini mencegah sapaan atau pertanyaan umum dianggap nama orang.
        if (self::isBareCustomerText($text)) {
            $pendingIntent = (string) ($context['pending_intent'] ?? '');
            if (!empty($context['awaiting_customer_name'])
                || !empty($context['last_customer_id'])
                || in_array($pendingIntent, ['customers.search', 'customers.detail', 'customers.complaints'], true)) {
                if (!in_array($role, ['admin', 'cs'], true)) {
                    return 'access.denied';
                }

                return $pendingIntent === 'customers.complaints'
                    ? 'customers.complaints'
                    : 'customers.search';
            }
        }

        // Referensi pendek ke pelanggan/tiket yang sudah dibahas.
        if (!empty($context['last_customer_id']) && !$hasComplaint
            && self::extractCustomerName($text) === ''
            && self::hasAny($text, ['pelanggan itu', 'orang itu', 'pelanggan tersebut', 'dia', 'nya'])) {
            return 'customers.detail';
        }
        if (!empty($context['last_customer_id'])
            && in_array(trim($text), ['yang terakhir', 'yang pertama', 'yang tadi'], true)) {
            return 'customers.detail';
        }

        // "Berapa pengaduannya?" setelah pelanggan tertentu dibahas harus
        // menjadi ringkasan pelanggan itu, bukan daftar/global.
        $referencesKnownCustomer = self::hasAny($text, ['nya', 'dia', 'tersebut', 'itu']);
        $hasExplicitPeriod = self::extractPeriod($text, '') !== '';
        if (!empty($context['last_customer_id']) && $hasCount
            && ($hasComplaint || $referencesKnownCustomer)
            && (!$hasCustomer || $referencesKnownCustomer)
            && (!$hasExplicitPeriod || $referencesKnownCustomer)) {
            return 'customers.complaint_summary';
        }

        // Pertanyaan "siapa pelanggan terbaru..." dan "siapa yang terakhir
        // membuat tiket..." dipisahkan dari daftar pengaduan biasa. Tanpa
        // pemisahan ini frasa "pelanggan + pengaduan" salah masuk ke
        // customers.complaints dan meminta nama pelanggan yang tidak disebut.
        $hasNamedCustomer = self::extractCustomerName($text) !== '';
        $referencesContextCustomer = !empty($context['last_customer_id'])
            && self::hasAny($text, ['nya', 'dia', 'tersebut', 'itu']);
        if ($hasComplaint && $hasLatest && !$hasCount && !$hasNamedCustomer && !$referencesContextCustomer) {
            if (!in_array($role, ['admin', 'cs'], true)) {
                return $hasMine ? 'personal.tickets' : 'access.denied';
            }

            $asksWho = $hasCustomer
                || preg_match('/\b(siapa|pelanggan|orang)\b/u', $text) === 1;

            return $asksWho ? 'complaints.latest_customer' : 'complaints.latest_ticket';
        }

        if ($hasCustomer) {
            if (!in_array($role, ['admin', 'cs'], true)) {
                return 'access.denied';
            }
            if ($hasComplaint) {
                return 'customers.complaints';
            }
            if ($hasCount) {
                return 'customers.count';
            }
            if ($hasLatest && self::extractCustomerName($text) === '') {
                return 'customers.latest';
            }
            if (self::hasAny($text, ['detail', 'alamat', 'whatsapp', 'nomor', 'pppoe', 'username', 'profil', 'status'])) {
                return 'customers.detail';
            }
            if (self::extractCustomerName($text) !== '') {
                return 'customers.search';
            }
            if (self::isCustomerListRequest($text)) {
                return 'customers.list';
            }

            return 'customers.search';
        }

        // Referensi "nya" / atribut tanpa kata pelanggan, memakai konteks.
        if (!empty($context['last_customer_id'])
            && self::hasAny($text, ['pppoe', 'whatsapp', 'alamat', 'nomor', 'profil', 'detail', 'nya'])
            && !$hasComplaint) {
            return 'customers.detail';
        }
        if (!empty($context['last_customer_id']) && $hasComplaint && self::hasAny($text, ['nya', 'dia', 'tersebut'])) {
            return 'customers.complaints';
        }
        if (!empty($context['last_ticket'])
            && (self::hasAny($text, ['status', 'tiket', 'detail', 'nya'])
                || in_array(trim($text), ['yang terakhir', 'yang tadi'], true))) {
            return 'complaints.detail';
        }

        // Follow-up angka setelah detail/riwayat pelanggan, mis. "Berapa yang selesai?"
        if (!empty($context['last_customer_id']) && $hasCount
            && ($statusInfo['status'] !== '' || $statusInfo['group'] !== '' || $statusInfo['cancelled'])
            && !$hasCustomer && !$hasComplaint && !$hasMine) {
            return 'customers.complaint_summary';
        }

        // Pertanyaan penjelasan umum tentang pengaduan bukan permintaan angka.
        // Biarkan provider/FAQ menjawab, jangan memaksa COUNT seluruh data.
        if ($hasComplaint && !$hasCount && !$hasLatest && $ticketNumber === ''
            && preg_match('/\b(kenapa|mengapa|cara|alur|prosedur)\b/u', $text) === 1) {
            return null;
        }

        // \"Bagaimana status pengaduan saya?\" tetap permintaan data dalam
        // cakupan pengguna, bukan pertanyaan umum.
        if ($hasComplaint && $hasMine && !$hasCount && !$hasLatest
            && self::hasAny($text, ['status', 'progres', 'perkembangan'])) {
            return 'personal.tickets';
        }

        if ($hasComplaint) {
            if (!in_array($role, ['admin', 'cs'], true)) {
                if ($hasMine && !$hasCount) {
                    return 'personal.tickets';
                }
                if ($hasCount) {
                    return 'personal.active_tickets';
                }

                return 'access.denied';
            }

            if (self::hasAny($text, ['tren', 'grafik', 'per hari', 'per bulan']) && !$hasCount) {
                return 'complaints.trend';
            }
            if ($hasCount && ($statusInfo['status'] !== '' || $statusInfo['group'] !== '' || $statusInfo['cancelled'])) {
                return 'complaints.count_by_status';
            }
            if ($hasCount && !empty($context['last_period']) && $context['last_period'] !== 'all'
                && self::hasAny($text, ['selesai', 'ditolak', 'aktif', 'dibatalkan'])) {
                return 'complaints.count_by_status';
            }
            if ($hasCount) {
                return 'complaints.count';
            }
            if ($hasLatest) {
                return 'complaints.latest';
            }
            if ($hasList || self::hasAny($text, ['bulan ini', 'hari ini', 'minggu ini', 'tahun ini', 'terakhir'])) {
                return 'complaints.search';
            }

            return 'complaints.count';
        }

        // Pertanyaan angka/status tanpa kata "pengaduan", mis. "Berapa yang selesai?"
        if ($hasCount && ($statusInfo['status'] !== '' || $statusInfo['group'] !== '' || $statusInfo['cancelled'])) {
            return in_array($role, ['admin', 'cs'], true) ? 'complaints.count_by_status' : 'access.denied';
        }

        if (self::hasAny($text, ['rating', 'ulasan', 'kepuasan'])) {
            return in_array($role, ['admin', 'cs'], true) ? 'ratings.summary' : 'access.denied';
        }

        if (self::hasAny($text, ['statistik', 'ringkasan operasional', 'ringkasan data', 'ikhtisar', 'dashboard'])) {
            return $role === 'admin' ? 'stats.overview' : 'access.denied';
        }

        if ($role === 'teknisi' && $hasMine
            && self::hasAny($text, ['tugas', 'penugasan', 'pekerjaan'])) {
            return 'personal.tickets';
        }

        if ($hasMine && $hasComplaint) {
            return 'personal.tickets';
        }

        if ($role === 'pelanggan' && self::hasAny($text, ['profil saya', 'data saya', 'akun saya'])) {
            return 'personal.profile';
        }

        // Pertanyaan lanjutan yang hanya menyebut status dan angka.
        if ($hasCount && self::hasAny($text, ['selesai', 'ditolak', 'dibatalkan', 'aktif'])
            && !empty($context['last_period'])) {
            return in_array($role, ['admin', 'cs'], true) ? 'complaints.count_by_status' : 'access.denied';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function complaintFilters(array $user, string $text, array $context, string $period, array $statusInfo): array
    {
        $filters = [
            'period' => $period,
            'limit' => 10,
            'cancelled' => $statusInfo['cancelled'] ? 'only' : 'exclude',
            'archived' => $statusInfo['archived'] ? 'only' : 'include',
        ];
        if ($statusInfo['status'] !== '') {
            $filters['status'] = $statusInfo['status'];
        } elseif ($statusInfo['group'] !== '') {
            $filters['status_group'] = $statusInfo['group'];
        }

        $customerName = self::extractCustomerName($text);
        if ($customerName !== '') {
            $customerId = AiOperationsReadService::findCustomerId($user, $customerName);
            if ($customerId !== null) {
                $filters['customer_id'] = $customerId;
            }
        } elseif (!empty($context['last_customer_id']) && self::hasAny($text, ['nya', 'dia', 'tersebut'])) {
            $filters['customer_id'] = (int) $context['last_customer_id'];
        }

        if (preg_match('/\bkategori\s+([a-z0-9._ ]{2,60})/u', $text, $match) === 1) {
            $category = AiOperationsReadService::findCategoryByName($user, trim($match[1]));
            if ($category !== null) {
                $filters['category_id'] = $category['id'];
            }
        }

        if (preg_match('/\bteknisi\s+([a-z0-9._ ]{2,60})/u', $text, $match) === 1) {
            $candidate = self::cleanName($match[1]);
            if (!in_array($candidate, ['paling banyak', 'terbanyak', 'aktif'], true)) {
                $technician = AiOperationsReadService::findTechnicianByName($user, $candidate);
                if ($technician !== null) {
                    $filters['technician_id'] = $technician['id'];
                }
            }
        }

        return $filters;
    }

    /**
     * @param array<int, array<string, mixed>> $customers
     * @return array<string, mixed>
     */
    /**
     * Tabel ringkas untuk pertanyaan keberadaan pelanggan. Tidak memuat
     * WhatsApp/alamat karena pengguna hanya menanyakan apakah pelanggan ada.
     *
     * @param array<int, array<string, mixed>> $customers
     * @return array<string, mixed>
     */
    private static function customerLookupTable(array $customers): array
    {
        $rows = [];
        foreach ($customers as $customer) {
            $rows[] = [
                'nama' => $customer['nama'],
                'pppoe' => $customer['username_pppoe'],
                'status' => $customer['status'],
                'status_key' => !empty($customer['is_active']) ? 'aktif' : 'nonaktif',
            ];
        }

        return self::table('Data Pelanggan', [
            ['key' => 'nama', 'label' => 'Nama'],
            ['key' => 'pppoe', 'label' => 'PPPoE'],
            ['key' => 'status', 'label' => 'Status', 'type' => 'badge', 'tone_key' => 'status_key'],
        ], $rows);
    }

    private static function customerTable(array $customers): array
    {
        $rows = [];
        foreach ($customers as $customer) {
            $rows[] = [
                'nama' => $customer['nama'],
                'pppoe' => $customer['username_pppoe'],
                'whatsapp' => $customer['nomor_whatsapp'],
                'status' => $customer['status'],
                'status_key' => !empty($customer['is_active']) ? 'aktif' : 'nonaktif',
                'alamat' => $customer['alamat'],
            ];
        }

        return self::table('Data Pelanggan', [
            ['key' => 'nama', 'label' => 'Nama'],
            ['key' => 'pppoe', 'label' => 'PPPoE'],
            ['key' => 'whatsapp', 'label' => 'WhatsApp'],
            ['key' => 'status', 'label' => 'Status', 'type' => 'badge', 'tone_key' => 'status_key'],
            ['key' => 'alamat', 'label' => 'Alamat'],
        ], $rows);
    }

    /**
     * @param array<int, array<string, mixed>> $complaints
     * @return array<int, array<string, mixed>>
     */
    private static function ticketsFromComplaints(array $complaints): array
    {
        $tickets = [];
        foreach ($complaints as $complaint) {
            if (empty($complaint['nomor_tiket'])) {
                continue;
            }
            $tickets[] = [
                'nomor_tiket' => (string) $complaint['nomor_tiket'],
                'status' => (string) ($complaint['status'] ?? ''),
                'status_key' => (string) ($complaint['status_key'] ?? ''),
                'kategori' => (string) ($complaint['kategori'] ?? ''),
                'nama_pelanggan' => (string) ($complaint['nama_pelanggan'] ?? ''),
                'dibuat' => (string) ($complaint['dibuat'] ?? ''),
                'teknisi' => $complaint['teknisi'] ?? null,
                'dibatalkan' => !empty($complaint['dibatalkan']),
            ];
        }

        return $tickets;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<int, array<string, mixed>> $tables
     * @param array<int, array<string, mixed>> $tickets
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function finish(
        array $user,
        string $intent,
        string $dataset,
        string $reply,
        array $tables,
        array $tickets,
        int $count,
        array $context,
        bool $logAccess
    ): array {
        // Jangan biarkan referensi entitas lama mencemari follow-up ketika
        // pengguna berpindah dataset (mis. dari detail pelanggan ke daftar
        // pengaduan global). Nilai null menghapus referensi pada konteks.
        $clears = [];
        if (in_array($intent, [
            'complaints.latest', 'complaints.latest_ticket', 'complaints.search',
            'complaints.count', 'complaints.count_by_status', 'complaints.trend',
            'stats.overview', 'technicians.performance', 'ratings.summary',
        ], true) || str_starts_with($intent, 'technicians.')) {
            $clears = [
                'last_customer_id' => null,
                'last_customer_name' => null,
                'last_customer_pppoe' => null,
                'last_ticket' => null,
                'awaiting_customer_name' => null,
                'pending_intent' => null,
            ];
        }
        if (str_starts_with($intent, 'employees.')) {
            $clears = [
                'last_customer_id' => null,
                'last_customer_name' => null,
                'last_customer_pppoe' => null,
                'last_ticket' => null,
                'last_period' => null,
                'awaiting_customer_name' => null,
                'pending_intent' => null,
            ];
        }
        if (in_array($intent, ['customers.list', 'customers.count'], true)) {
            $clears = [
                'last_customer_id' => null,
                'last_customer_name' => null,
                'last_customer_pppoe' => null,
                'last_ticket' => null,
                'awaiting_customer_name' => null,
                'pending_intent' => null,
            ];
        }
        if ($intent === 'customers.latest') {
            $clears['last_ticket'] = null;
        }
        if ($intent === 'customers.search') {
            $clears = [
                'last_ticket' => null,
            ];
            if (empty($context['last_customer_id'])) {
                $clears = array_merge($clears, [
                    'last_customer_id' => null,
                    'last_customer_name' => null,
                    'last_customer_pppoe' => null,
                    'awaiting_customer_name' => null,
                    'pending_intent' => null,
                ]);
            }
        }
        if (in_array($intent, ['customers.detail', 'customers.complaints'], true)) {
            $clears['last_ticket'] = null;
        }
        if ($clears !== []) {
            $context = array_merge($clears, $context);
        }
        $context['last_intent'] = $intent;

        if ($context !== []) {
            AiContext::remember((int) $user['id'], $context);
        }
        if ($logAccess) {
            AiDataAccessLog::record($user, 'local', $intent, $dataset, $count, true);
        }

        return [
            'ok' => true,
            'reply' => $reply,
            'tables' => array_values($tables),
            'tickets' => array_values($tickets),
            'provider' => 'local',
            'model' => 'database-assistant',
            'request_type' => 'database',
            'intent' => $intent,
            'usage' => ['prompt' => 0, 'completion' => 0, 'total' => 0],
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private static function denied(array $user, string $message, bool $logAccess): array
    {
        if ($logAccess) {
            AiDataAccessLog::record($user, 'local', 'access.denied', 'denied', 0, false);
        }

        return [
            'ok' => true,
            'reply' => $message,
            'tables' => [],
            'tickets' => [],
            'provider' => 'local',
            'model' => 'database-assistant',
            'request_type' => 'database',
            'intent' => 'access.denied',
            'usage' => ['prompt' => 0, 'completion' => 0, 'total' => 0],
        ];
    }

    private static function denialMessage(string $text, string $role): string
    {
        if (self::hasAny($text, ['karyawan', 'staf', 'staff', 'pegawai', 'employee', 'petugas'])) {
            return 'Data karyawan hanya dapat diakses Administrator.';
        }
        if (self::hasAny($text, ['pelanggan', 'customer', 'langganan'])) {
            return 'Data pelanggan hanya dapat diakses Administrator atau Customer Service.';
        }
        if (self::hasAny($text, ['teknisi', 'tehnisi', 'technician'])) {
            if ($role === 'teknisi') {
                return 'Anda hanya dapat melihat tugas yang ditugaskan kepada Anda.';
            }

            return 'Data kinerja seluruh teknisi hanya dapat diakses Administrator.';
        }

        return 'Anda tidak memiliki izin untuk melihat data tersebut.';
    }

    /**
     * @return array{status: string, group: string, cancelled: bool, archived: bool}
     */
    private static function extractStatus(string $text): array
    {
        $result = ['status' => '', 'group' => '', 'cancelled' => false, 'archived' => false];
        $map = [
            'menunggu_verifikasi' => ['menunggu verifikasi', 'belum diverifikasi'],
            'diproses_cs' => ['diproses cs', 'diproses customer service', 'sedang diproses cs'],
            'menunggu_teknisi' => ['menunggu teknisi', 'menunggu penugasan'],
            'dalam_penanganan' => ['dalam penanganan', 'sedang ditangani', 'dikerjakan teknisi'],
            'selesai' => ['selesai', 'sudah selesai', 'tuntas'],
            'ditolak' => ['ditolak', 'ditolak cs', 'tidak disetujui'],
        ];
        foreach ($map as $key => $phrases) {
            foreach ($phrases as $phrase) {
                if (str_contains($text, $phrase)) {
                    $result['status'] = $key;
                    break 2;
                }
            }
        }
        if (str_contains($text, 'dibatalkan') || str_contains($text, 'batal')) {
            $result['cancelled'] = true;
        }
        if (str_contains($text, 'arsip') || str_contains($text, 'diarsipkan')) {
            $result['archived'] = true;
        }
        if ($result['status'] === '' && self::hasAny($text, ['aktif', 'berjalan', 'belum selesai'])) {
            $result['group'] = 'active';
        }

        return $result;
    }

    private static function extractPeriod(string $text, string $fallback = 'all'): string
    {
        $map = [
            'today' => ['hari ini', 'hariini'],
            'yesterday' => ['kemarin'],
            'this_week' => ['minggu ini', 'pekan ini'],
            'last_7_days' => ['7 hari terakhir', 'tujuh hari terakhir', 'seminggu terakhir'],
            'this_month' => ['bulan ini'],
            'last_30_days' => ['30 hari terakhir', 'tiga puluh hari terakhir'],
            'this_year' => ['tahun ini'],
        ];
        foreach ($map as $period => $phrases) {
            foreach ($phrases as $phrase) {
                if (str_contains($text, $phrase)) {
                    return $period;
                }
            }
        }

        return $fallback !== '' ? $fallback : 'all';
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

    private static function singleRole(string $text): ?string
    {
        $roles = [];
        if (preg_match('/\badmin(?:istrator)?\b/u', $text) === 1) {
            $roles[] = 'admin';
        }
        if (preg_match('/\b(cs|customer service)\b/u', $text) === 1) {
            $roles[] = 'cs';
        }
        if (preg_match('/\b(teknisi|tehnisi|technician)\b/u', $text) === 1) {
            $roles[] = 'teknisi';
        }

        return count($roles) === 1 ? $roles[0] : null;
    }

    private static function extractLimit(string $text, int $default): int
    {
        if (preg_match('/\b(?:tampilkan|ambil|berikan|lihat|daftar)\s+(\d{1,2})\b/u', $text, $match) === 1) {
            return max(1, min(50, (int) $match[1]));
        }
        if (preg_match('/\b(\d{1,2})\s+(?:pengaduan|tiket|data|pelanggan|karyawan|terbaru|terakhir)\b/u', $text, $match) === 1) {
            return max(1, min(50, (int) $match[1]));
        }

        return max(1, min(50, $default));
    }

    private static function extractTicketNumber(string $message): string
    {
        return preg_match('/WFC-\d{8}-\d{6}/i', $message, $match) === 1
            ? strtoupper($match[0])
            : '';
    }

    /**
     * Permintaan daftar pelanggan, bukan pencarian nama. Contoh yang harus
     * dikenali: "pelanggan siapa aja", "daftar pelanggan", "tampilkan semua
     * pelanggan". Frasa tanya tidak pernah dianggap nama orang.
     */
    private static function isCustomerListRequest(string $text): bool
    {
        if (preg_match('/\b(pelanggan|customer|langganan)(?:nya)?\b/u', $text) !== 1) {
            return false;
        }

        return preg_match('/\b(siapa|daftar|list|semua|seluruh|terdaftar|sebutkan|tampilkan|lihat)\b/u', $text) === 1
            || preg_match('/\bapa\s+saja\b/u', $text) === 1;
    }

    /**
     * Deteksi permintaan menampilkan kredensial. Tidak membaca database dan
     * tidak pernah mengembalikan nilai rahasia.
     */
    private static function isSecretDisclosureRequest(string $text): bool
    {
        $credential = '(?:api\s*key|api\s*rahasia|password|kata\s+sandi|sandi|passwd|token|access\s+token|kredensial|credential|hash|session\s*id|master\s*key|private\s*key)';
        $request = '(?:tampilkan|lihat|berikan|kasih|sebutkan|bocorkan|kirim|minta|apa|berapa|cek|ambil)';

        return preg_match('/\b' . $request . '\b.{0,60}\b' . $credential . '\b/u', $text) === 1;
    }

    /**
     * Deteksi teks yang meminta eksekusi SQL bebas. Asisten hanya menerima
     * intent tetap, bukan SQL dari pengguna/model.
     */
    private static function isFreeSqlRequest(string $text): bool
    {
        if (preg_match('/\b(select|insert|update|delete|drop|alter|truncate)\b/u', $text) !== 1) {
            return false;
        }

        return preg_match('/\b(from|table|users|pengaduan|pelanggan|into|set|where)\b/u', $text) === 1
            || str_contains($text, 'sql');
    }

    /**
     * Filter status akun dari bahasa sehari-hari.
     */
    private static function accountStatusFilter(string $text): string
    {
        if (preg_match('/\b(nonaktif|tidak\s+aktif|inactive)\b/u', $text) === 1) {
            return 'inactive';
        }
        if (preg_match('/\b(aktif|active)\b/u', $text) === 1) {
            return 'active';
        }

        return 'all';
    }

    /**
     * @param array<int, string> $names
     */
    private static function joinNames(array $names): string
    {
        $names = array_values(array_filter($names, static fn (string $name): bool => trim($name) !== ''));
        $count = count($names);
        if ($count === 0) {
            return '';
        }
        if ($count === 1) {
            return $names[0];
        }
        if ($count === 2) {
            return $names[0] . ' dan ' . $names[1];
        }

        $last = array_pop($names);

        return implode(', ', $names) . ', dan ' . $last;
    }

    /**
     * Apakah teks berbentuk nama/username PPPoE pendek, bukan pertanyaan.
     * Hanya dipakai bila konteks percakapan memang menunggu/membahas pelanggan.
     */
    private static function isBareCustomerText(string $text): bool
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) < 2 || mb_strlen($text) > 80) {
            return false;
        }
        if (preg_match('/^[a-z0-9][a-z0-9._ -]{1,79}$/u', $text) !== 1
            || preg_match('/[a-z]/u', $text) !== 1) {
            return false;
        }

        return preg_match(
            '/\b(pelanggan|customer|langganan|pengaduan|aduan|tiket|laporan|komplain|'
            . 'admin|administrator|cs|teknisi|tehnisi|karyawan|staf|pegawai|'
            . 'hari|bulan|tahun|minggu|selesai|ditolak|dibatalkan|aktif|nonaktif|'
            . 'profil|detail|status|alamat|whatsapp|nomor|tiket|'
            . 'semua|seluruh|daftar|tampilkan|lihat|sebutkan|cari|carikan|temukan|'
            . 'terbaru|terakhir|pertama|paling|baru|yang|itu|tersebut|nya|dia|'
            . 'saya|aku|kami|kita|berapa|jumlah|total|hitung|siapa|apa|mana|'
            . 'halo|hai|pagi|siang|malam|terima|kasih|makasih|tolong|bisa|'
            . 'ok|oke|ya|tidak|bukan|belum|sudah)\b/u',
            $text
        ) === 1 ? false : true;
    }

    /**
     * Cari kandidat pelanggan dengan nama/PPPoE.
     *
     * Hasil exact (nama/PPPoE sama) diprioritaskan. Bila tidak ada exact dan
     * hanya ada SATU kandidat partial, kandidat itu aman dipakai. Bila ada
     * beberapa kandidat, pemanggil wajib meminta klarifikasi (mis. PPPoE).
     *
     * @param array<string, mixed> $user
     * @return array{error?: array<string, mixed>, customers: array<int, array<string, mixed>>}
     */
    private static function customerMatchesForName(array $user, string $name, bool $logAccess): array
    {
        $result = AiOperationsReadService::customerSearch($user, $name, 25);
        if (isset($result['error'])) {
            return [
                'error' => self::denied($user, (string) ($result['message'] ?? 'Akses ditolak.'), $logAccess),
                'customers' => [],
            ];
        }

        $customers = (array) ($result['customers'] ?? []);
        $exact = [];
        foreach ($customers as $customer) {
            $nama = trim((string) ($customer['nama'] ?? ''));
            $pppoe = trim((string) ($customer['username_pppoe'] ?? ''));
            if ((strcasecmp($nama, $name) === 0) || (strcasecmp($pppoe, $name) === 0)) {
                $exact[] = $customer;
            }
        }

        if ($exact !== []) {
            return ['customers' => $exact];
        }

        return ['customers' => $customers];
    }

    private static function extractCustomerName(string $text): string
    {
        $patterns = [
            // "apakah agus terdaftar sebagai pelanggan?"
            '/\b(?:apakah(?:\s+ada)?|ada)\s+(.+?)\s+(?:terdaftar|tercatat)\s+(?:sebagai\s+)?pelanggan\b/u',
            // "cari/cek pelanggan [atas nama/bernama/dengan nama] <nama>"
            '/\b(?:cari|carikan|temukan|cek|periksa)\s+(?:data\s+)?pelanggan\b(?:\s+(?:yang\s+)?(?:bernama|namanya|dengan\s+nama|atas\s+nama))?\s+(.+)$/u',
            // "pelanggan [yang] [atas nama/bernama/namanya/dengan nama] <nama>"
            // Frasa pengantar nama dikonsumsi di sini, bukan ikut menjadi nama.
            '/\bpelanggan\b(?:\s+(?:yang\s+)?(?:bernama|namanya|dengan\s+nama|atas\s+nama))?\s+(.+)$/u',
            '/\b(?:data|detail|profil)\s+pelanggan\s+(.+)$/u',
            '/\briwayat\s+(?:pengaduan|tiket|aduan)\s+(?:pelanggan\s+)?(.+)$/u',
            '/\bpengaduan\s+pelanggan\s+(.+)$/u',
            '/\b(?:pengaduan|aduan|tiket|laporan|komplain)\s+(?:milik|dari|untuk|atas\s+nama)?\s*(.+)$/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $match) === 1) {
                $name = self::cleanName($match[1]);
                if ($name !== '' && !in_array($name, self::stopNames(), true)) {
                    return $name;
                }
            }
        }

        return '';
    }

    private static function cleanName(string $name): string
    {
        $name = trim($name);

        // Frasa pengantar nama bukan bagian dari nama. Hapus hanya frasa yang
        // memang berfungsi sebagai pengantar, bukan kata "nama" secara umum.
        $name = preg_replace(
            '/\b(?:yang\s+namanya|yang\s+bernama|atas\s+nama|dengan\s+nama|bernama|namanya)\b/iu',
            ' ',
            $name
        ) ?? $name;

        $name = preg_replace(
            '/\b(dong|ya|itu|tersebut|tadi|terbaru|terakhir|aktif|nonaktif|saya|aku|kami|nya|siapa|saja|aja|berapa|orang|terdaftar|daftar|semua|seluruh|list|mana|yang|status|selesai|ditolak|dibatalkan|diproses|menunggu|dalam|penanganan|sudah|belum|hari|bulan|tahun|periode|tanggal|sebagai)\b.*$/u',
            '',
            $name
        ) ?? $name;
        $name = trim((string) preg_replace('/\s+/u', ' ', $name), " \t\n\r\0\x0B.,!?;:");

        return mb_strlen($name) >= 2 && mb_strlen($name) <= 60 ? $name : '';
    }

    private static function displayName(string $name): string
    {
        return mb_convert_case(trim($name), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * @return array<int, string>
     */
    private static function stopNames(): array
    {
        return [
            'aktif', 'nonaktif', 'baru', 'terbaru', 'semua', 'seluruh', 'saya', 'aku',
            'ini', 'itu', 'tersebut', 'tadi', 'siapa', 'saja', 'aja', 'daftar', 'terdaftar',
            'pelanggan', 'customer', 'yang', 'mana', 'berapa', 'orang', 'dong', 'list',
            'status', 'selesai', 'ditolak', 'dibatalkan', 'diproses', 'menunggu',
            'penanganan', 'sudah', 'belum', 'periode', 'tanggal',
        ];
    }

    private static function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s._-]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * @param array<int, string> $needles
     */
    private static function hasAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private static function table(string $title, array $columns, array $rows): array
    {
        return ['title' => $title, 'columns' => $columns, 'rows' => array_values($rows)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function badgeRow(string $label, string $tone, int $value): array
    {
        return ['role' => $label, 'role_key' => $tone, 'jumlah' => $value];
    }
}
