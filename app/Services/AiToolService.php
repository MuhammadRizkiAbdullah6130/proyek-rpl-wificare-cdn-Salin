<?php
/**
 * Authorization & Tool Service untuk WiFiCare AI (Tahap 9 + database read-only).
 *
 * Model TIDAK pernah mengakses database secara langsung dan tidak pernah
 * menjalankan SQL. AI hanya boleh memanggil tool pada allowlist di bawah;
 * setiap argumen divalidasi dan setiap hasil dibatasi otorisasi berbasis
 * session (RBAC) di sisi backend. Seluruh tool bersifat BACA (read-only) dan
 * delegasi pembacaan data ke `AiReadService` (SELECT prepared statement).
 *
 * - Tidak ada tool yang menulis/mengubah/menghapus data.
 * - Tool data hanya tersedia untuk peran yang berhak (lihat `roles`).
 * - Otorisasi tiket: admin/CS tiket apa pun; teknisi tiket yang ditugaskan
 *   kepadanya; pelanggan tiket miliknya. Bila tidak berhak, hasil dibuat
 *   seragam "tidak ditemukan" agar keberadaan tiket tidak bocor.
 */

declare(strict_types=1);

final class AiToolService
{
    /**
     * Spesifikasi tool (sumber tunggal untuk format OpenAI & Gemini).
     *
     * @return array<string, array<string, mixed>>
     */
    private static function specs(): array
    {
        $noArgs = ['type' => 'object', 'properties' => new stdClass()];

        return [
            'get_ticket_status' => [
                'description' => 'Ambil status sebuah tiket pengaduan berdasarkan nomor tiket. '
                    . 'Hanya untuk tiket yang boleh dilihat pengguna yang sedang masuk.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'ticket_number' => [
                            'type' => 'string',
                            'description' => 'Nomor tiket berformat WFC-YYYYMMDD-XXXXXX.',
                        ],
                    ],
                    'required' => ['ticket_number'],
                ],
                'roles' => ['admin', 'cs', 'teknisi', 'pelanggan'],
            ],
            'get_my_tickets' => [
                'description' => 'Daftar tiket yang boleh dilihat pengguna: pelanggan → tiket miliknya; '
                    . 'teknisi → tiket yang ditugaskan kepadanya; CS/Admin → tiket terbaru. '
                    . 'Data asli dari database, bukan perkiraan.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'description' => 'Jumlah maksimum tiket (1-20, default 10).'],
                    ],
                ],
                'roles' => ['admin', 'cs', 'teknisi', 'pelanggan'],
            ],
            'get_my_active_tickets' => [
                'description' => 'Daftar tiket AKTIF (belum selesai/ditolak) sesuai cakupan pengguna. '
                    . 'Data asli database dengan otorisasi RBAC.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'description' => 'Jumlah maksimum tiket (1-20, default 10).'],
                    ],
                ],
                'roles' => ['admin', 'cs', 'teknisi', 'pelanggan'],
            ],
            'get_my_profile' => [
                'description' => 'Profil pelanggan milik pengguna yang sedang masuk (nama, username PPPoE, '
                    . 'kontak tersamarkan). Hanya untuk pelanggan.',
                'parameters' => $noArgs,
                'roles' => ['pelanggan'],
            ],
            'get_my_active_ticket_count' => [
                'description' => 'Jumlah tiket AKTIF (belum selesai/ditolak) yang boleh dilihat pengguna, '
                    . 'beserta total tiket dalam cakupannya.',
                'parameters' => $noArgs,
                'roles' => ['admin', 'cs', 'teknisi', 'pelanggan'],
            ],
            'get_ticket_statistics' => [
                'description' => 'Statistik jumlah tiket per status (enam status resmi) sesuai cakupan '
                    . 'pengguna. Angka berasal langsung dari database.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'period' => [
                            'type' => 'string',
                            'enum' => ['today', 'this_month', 'all'],
                            'description' => 'Periode: today (hari ini), this_month (bulan ini), all (semua). Default all.',
                        ],
                    ],
                ],
                'roles' => ['admin', 'cs', 'teknisi', 'pelanggan'],
            ],
            'get_complaint_statistics' => [
                'description' => 'Ringkasan pengaduan sesuai cakupan pengguna untuk periode tertentu: total tiket, '
                    . 'jumlah per status, tiket selesai (berdasarkan tanggal selesai), dan kategori terbanyak. '
                    . 'Gunakan untuk pertanyaan "berapa pengaduan hari ini/bulan ini".',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'period' => [
                            'type' => 'string',
                            'enum' => ['today', 'this_month', 'all'],
                            'description' => 'Periode perhitungan. Default today.',
                        ],
                    ],
                ],
                'roles' => ['admin', 'cs', 'teknisi', 'pelanggan'],
            ],
            'get_complaint_category_statistics' => [
                'description' => 'Statistik kategori gangguan yang paling sering muncul sesuai cakupan '
                    . 'pengguna (diurutkan dari yang terbanyak).',
                'parameters' => $noArgs,
                'roles' => ['admin', 'cs', 'teknisi', 'pelanggan'],
            ],
            'get_technician_assignments' => [
                'description' => 'Teknisi → daftar tugas yang ditugaskan kepadanya. '
                    . 'Administrator → ringkasan kinerja seluruh teknisi.',
                'parameters' => $noArgs,
                'roles' => ['teknisi', 'admin'],
            ],
            'get_technician_history' => [
                'description' => 'Riwayat pekerjaan teknisi yang sedang masuk (tugas selesai/ditutup).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'description' => 'Jumlah maksimum (1-20, default 10).'],
                    ],
                ],
                'roles' => ['teknisi'],
            ],
            'get_service_rating_summary' => [
                'description' => 'Ringkasan rating layanan pelanggan (rata-rata, jumlah rating, distribusi bintang). '
                    . 'Khusus Administrator dan Customer Service.',
                'parameters' => $noArgs,
                'roles' => ['admin', 'cs'],
            ],
            'get_customer_count' => [
                'description' => 'Jumlah pelanggan aktif (khusus Administrator).',
                'parameters' => $noArgs,
                'roles' => ['admin'],
            ],
            'search_employees' => [
                'description' => 'Cari/daftar karyawan (Admin, CS, Teknisi) beserta role dan status akun. '
                    . 'Khusus Administrator. Pelanggan TIDAK dihitung sebagai karyawan. '
                    . 'Tidak pernah mengembalikan password/hash.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'q' => ['type' => 'string', 'description' => 'Nama atau username opsional.'],
                        'role' => ['type' => 'string', 'enum' => ['admin', 'cs', 'teknisi'], 'description' => 'Filter role.'],
                        'status' => ['type' => 'string', 'enum' => ['all', 'active', 'inactive'], 'description' => 'Status akun.'],
                        'limit' => ['type' => 'integer', 'description' => 'Jumlah maksimum 1-50.'],
                    ],
                ],
                'roles' => ['admin'],
            ],
            'get_employee_count' => [
                'description' => 'Jumlah karyawan (Admin/CS/Teknisi) total dan per role, aktif/nonaktif. '
                    . 'Khusus Administrator. Pelanggan tidak termasuk.',
                'parameters' => $noArgs,
                'roles' => ['admin'],
            ],
            'get_employee_detail' => [
                'description' => 'Detail satu karyawan berdasarkan employee_id (tanpa password/hash). Khusus Administrator.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => ['employee_id' => ['type' => 'integer', 'description' => 'ID pengguna karyawan.']],
                    'required' => ['employee_id'],
                ],
                'roles' => ['admin'],
            ],
            'search_customers' => [
                'description' => 'Cari pelanggan berdasarkan nama, username aplikasi, atau username PPPoE. '
                    . 'Administrator/CS. Nomor WhatsApp CS disamarkan. Tidak pernah mengembalikan password.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'keyword' => ['type' => 'string', 'description' => 'Nama/username/PPPoE. Boleh kosong untuk daftar terbaru.'],
                        'limit' => ['type' => 'integer', 'description' => 'Jumlah maksimum 1-25.'],
                    ],
                ],
                'roles' => ['admin', 'cs'],
            ],
            'get_customer_detail' => [
                'description' => 'Detail satu pelanggan: kontak (disamarkan untuk CS), alamat, jumlah pengaduan. '
                    . 'Administrator/CS. Tidak pernah mengembalikan password.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => ['customer_id' => ['type' => 'integer', 'description' => 'ID pelanggan.']],
                    'required' => ['customer_id'],
                ],
                'roles' => ['admin', 'cs'],
            ],
            'get_customer_complaints' => [
                'description' => 'Riwayat pengaduan satu pelanggan berdasarkan customer_id, dengan periode opsional. '
                    . 'Administrator/CS. Angka berasal dari database.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'customer_id' => ['type' => 'integer', 'description' => 'ID pelanggan.'],
                        'period' => [
                            'type' => 'string',
                            'enum' => ['today', 'yesterday', 'this_week', 'last_7_days', 'this_month', 'last_30_days', 'this_year', 'all'],
                        ],
                        'limit' => ['type' => 'integer', 'description' => 'Jumlah maksimum 1-50.'],
                    ],
                    'required' => ['customer_id'],
                ],
                'roles' => ['admin', 'cs'],
            ],
            'search_complaints' => [
                'description' => 'Cari/daftar pengaduan dengan filter periode, status, kategori, teknisi, pelanggan, '
                    . 'atau kata kunci nomor tiket/nama. Administrator/CS. Default mengecualikan tiket dibatalkan; '
                    . 'tiket arsip dapat difilter. Angka dan baris berasal dari database.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'period' => [
                            'type' => 'string',
                            'enum' => ['today', 'yesterday', 'this_week', 'last_7_days', 'this_month', 'last_30_days', 'this_year', 'all'],
                        ],
                        'status' => [
                            'type' => 'string',
                            'enum' => ['menunggu_verifikasi', 'diproses_cs', 'menunggu_teknisi', 'dalam_penanganan', 'selesai', 'ditolak'],
                        ],
                        'category_id' => ['type' => 'integer'],
                        'technician_id' => ['type' => 'integer'],
                        'customer_id' => ['type' => 'integer'],
                        'q' => ['type' => 'string'],
                        'cancelled' => ['type' => 'string', 'enum' => ['exclude', 'include', 'only']],
                        'archived' => ['type' => 'string', 'enum' => ['include', 'exclude', 'only']],
                        'order' => ['type' => 'string', 'enum' => ['newest', 'oldest', 'status']],
                        'limit' => ['type' => 'integer', 'description' => 'Jumlah maksimum 1-50.'],
                    ],
                ],
                'roles' => ['admin', 'cs'],
            ],
            'get_complaint_detail' => [
                'description' => 'Detail pengaduan (admin/CS) berdasarkan nomor tiket: status, pelanggan, kategori, '
                    . 'teknisi, deskripsi, riwayat status, laporan perbaikan, dan rating. '
                    . 'Tidak memberi akses ke tiket di luar kewenangan.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => ['ticket_number' => ['type' => 'string', 'description' => 'Nomor tiket WFC-YYYYMMDD-XXXXXX.']],
                    'required' => ['ticket_number'],
                ],
                'roles' => ['admin', 'cs'],
            ],
            'get_complaint_status_summary' => [
                'description' => 'Ringkasan jumlah pengaduan per status resmi untuk periode/filter tertentu, '
                    . 'termasuk tiket selesai berdasarkan riwayat status dan tiket dibatalkan terpisah. '
                    . 'Administrator/CS. Gunakan untuk pertanyaan "berapa yang selesai/ditolak".',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'period' => [
                            'type' => 'string',
                            'enum' => ['today', 'yesterday', 'this_week', 'last_7_days', 'this_month', 'last_30_days', 'this_year', 'all'],
                        ],
                        'category_id' => ['type' => 'integer'],
                        'technician_id' => ['type' => 'integer'],
                        'customer_id' => ['type' => 'integer'],
                    ],
                ],
                'roles' => ['admin', 'cs'],
            ],
            'get_technician_performance' => [
                'description' => 'Kinerja seluruh teknisi: total tugas, selesai, aktif, dengan periode opsional. '
                    . 'Khusus Administrator. Data dari penugasan dan status pengaduan.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'period' => [
                            'type' => 'string',
                            'enum' => ['today', 'yesterday', 'this_week', 'last_7_days', 'this_month', 'last_30_days', 'this_year', 'all'],
                        ],
                    ],
                ],
                'roles' => ['admin'],
            ],
            'get_operational_summary' => [
                'description' => 'Ringkasan operasional Admin: jumlah karyawan per role, pelanggan aktif/nonaktif, '
                    . 'pengaduan per status, dan rating. Khusus Administrator. Angka asli database.',
                'parameters' => $noArgs,
                'roles' => ['admin'],
            ],
            'get_complaint_trend' => [
                'description' => 'Tren jumlah pengaduan harian/bulanan yang DIBUAT pada periode tersebut. '
                    . 'Administrator/CS. Bukan jumlah tiket selesai.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'granularity' => ['type' => 'string', 'enum' => ['daily', 'monthly']],
                        'points' => ['type' => 'integer', 'description' => 'Jumlah titik data (harian 7-30, bulanan 3-12).'],
                    ],
                ],
                'roles' => ['admin', 'cs'],
            ],
            'get_app_usage_guide' => [
                'description' => 'Panduan penggunaan aplikasi WiFiCare CDN sesuai peran pengguna.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => ['topic' => ['type' => 'string', 'description' => 'Topik opsional.']],
                ],
                'roles' => ['admin', 'cs', 'teknisi', 'pelanggan'],
            ],
            'get_wifi_troubleshooting_guide' => [
                'description' => 'Panduan langkah aman troubleshooting gangguan WiFi dasar.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => ['symptom' => ['type' => 'string', 'description' => 'Gejala opsional.']],
                ],
                'roles' => ['admin', 'cs', 'teknisi', 'pelanggan'],
            ],
            'get_faq' => [
                'description' => 'Daftar pertanyaan yang sering diajukan beserta jawabannya.',
                'parameters' => $noArgs,
                'roles' => ['admin', 'cs', 'teknisi', 'pelanggan'],
            ],
        ];
    }

    /**
     * Definisi tool format OpenAI (semua tool).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function definitions(): array
    {
        $definitions = [];
        foreach (self::specs() as $name => $spec) {
            $definitions[] = [
                'type' => 'function',
                'function' => [
                    'name' => $name,
                    'description' => $spec['description'],
                    'parameters' => $spec['parameters'],
                ],
            ];
        }

        return $definitions;
    }

    /**
     * Definisi tool format OpenAI yang disaring sesuai peran.
     *
     * @param array<string, mixed> $user
     * @return array<int, array<string, mixed>>
     */
    public static function definitionsFor(array $user): array
    {
        $role = (string) ($user['role'] ?? '');
        $definitions = [];
        foreach (self::specs() as $name => $spec) {
            if (!in_array($role, $spec['roles'], true)) {
                continue;
            }
            $definitions[] = [
                'type' => 'function',
                'function' => [
                    'name' => $name,
                    'description' => $spec['description'],
                    'parameters' => $spec['parameters'],
                ],
            ];
        }

        return $definitions;
    }

    /**
     * Deklarasi tool format Gemini, disaring sesuai peran.
     *
     * @param array<string, mixed> $user
     * @return array<int, array<string, mixed>> Siap dikirim sebagai "tools".
     */
    public static function geminiDeclarations(array $user): array
    {
        $role = (string) ($user['role'] ?? '');
        $declarations = [];

        foreach (self::specs() as $name => $spec) {
            if (!in_array($role, $spec['roles'], true)) {
                continue;
            }
            $declarations[] = [
                'name' => $name,
                'description' => $spec['description'],
                'parameters' => $spec['parameters'],
            ];
        }

        if (empty($declarations)) {
            return [];
        }

        return [['functionDeclarations' => $declarations]];
    }

    /**
     * Daftar nama tool yang diizinkan untuk sebuah peran.
     *
     * @return array<int, string>
     */
    public static function allowedNames(string $role = ''): array
    {
        if ($role === '') {
            return array_keys(self::specs());
        }

        $names = [];
        foreach (self::specs() as $name => $spec) {
            if (in_array($role, $spec['roles'], true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Jalankan tool read-only. Tool di luar allowlist peran ditolak.
     * Audit hanya mencatat metadata akses (tanpa kata kunci/isi data).
     *
     * @param array<string, mixed> $args
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function execute(string $name, array $args, array $user): array
    {
        $role = (string) ($user['role'] ?? '');

        if (!in_array($name, self::allowedNames($role), true)) {
            return ['error' => 'tool_not_allowed'];
        }

        $result = self::run($name, $args, $user);
        self::logAccess($name, $user, $result);

        return $result;
    }

    /**
     * @param array<string, mixed> $args
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private static function run(string $name, array $args, array $user): array
    {
        $role = (string) ($user['role'] ?? '');

        switch ($name) {
            case 'get_ticket_status':
                return self::getTicketStatus($user, (string) ($args['ticket_number'] ?? ''));
            case 'get_my_tickets':
                $limit = isset($args['limit']) ? (int) $args['limit'] : 10;

                return AiReadService::ticketsForUser($user, $limit);
            case 'get_my_active_tickets':
                $limit = isset($args['limit']) ? (int) $args['limit'] : 10;

                return AiReadService::activeTicketsForUser($user, $limit);
            case 'get_my_profile':
                return AiReadService::profileForUser($user);
            case 'get_my_active_ticket_count':
                return AiReadService::activeTicketCountForUser($user);
            case 'get_ticket_statistics':
                return AiReadService::statusStatisticsForUser($user, (string) ($args['period'] ?? 'all'));
            case 'get_complaint_statistics':
                return AiReadService::complaintStatisticsForUser($user, (string) ($args['period'] ?? 'today'));
            case 'get_complaint_category_statistics':
                return AiReadService::categoryStatisticsForUser($user);
            case 'get_technician_assignments':
                return AiReadService::technicianAssignmentsForUser($user);
            case 'get_technician_history':
                $limit = isset($args['limit']) ? (int) $args['limit'] : 10;

                return AiReadService::technicianHistoryForUser($user, $limit);
            case 'get_service_rating_summary':
                return AiReadService::ratingSummary();
            case 'get_customer_count':
                return AiOperationsReadService::customerCounts($user);
            case 'search_employees':
                return AiOperationsReadService::employeeList($user, [
                    'q' => (string) ($args['q'] ?? ''),
                    'role' => (string) ($args['role'] ?? ''),
                    'status' => (string) ($args['status'] ?? 'all'),
                    'limit' => isset($args['limit']) ? (int) $args['limit'] : 20,
                ]);
            case 'get_employee_count':
                return AiOperationsReadService::employeeCounts($user);
            case 'get_employee_detail':
                return AiOperationsReadService::employeeDetail($user, (int) ($args['employee_id'] ?? 0));
            case 'search_customers':
                return AiOperationsReadService::customerSearch(
                    $user,
                    (string) ($args['keyword'] ?? ''),
                    isset($args['limit']) ? (int) $args['limit'] : 10
                );
            case 'get_customer_detail':
                return AiOperationsReadService::customerDetail($user, (int) ($args['customer_id'] ?? 0));
            case 'get_customer_complaints':
                return AiOperationsReadService::customerComplaints(
                    $user,
                    (int) ($args['customer_id'] ?? 0),
                    [
                        'period' => (string) ($args['period'] ?? 'all'),
                        'limit' => isset($args['limit']) ? (int) $args['limit'] : 10,
                    ]
                );
            case 'search_complaints':
                return AiOperationsReadService::complaintList($user, [
                    'period' => (string) ($args['period'] ?? 'all'),
                    'status' => (string) ($args['status'] ?? ''),
                    'category_id' => (int) ($args['category_id'] ?? 0),
                    'technician_id' => (int) ($args['technician_id'] ?? 0),
                    'customer_id' => (int) ($args['customer_id'] ?? 0),
                    'q' => (string) ($args['q'] ?? ''),
                    'cancelled' => (string) ($args['cancelled'] ?? 'exclude'),
                    'archived' => (string) ($args['archived'] ?? 'include'),
                    'order' => (string) ($args['order'] ?? 'newest'),
                    'limit' => isset($args['limit']) ? (int) $args['limit'] : 10,
                ]);
            case 'get_complaint_detail':
                return AiOperationsReadService::complaintDetail($user, (string) ($args['ticket_number'] ?? ''));
            case 'get_complaint_status_summary':
                return AiOperationsReadService::complaintStatusStatistics($user, [
                    'period' => (string) ($args['period'] ?? 'all'),
                    'category_id' => (int) ($args['category_id'] ?? 0),
                    'technician_id' => (int) ($args['technician_id'] ?? 0),
                    'customer_id' => (int) ($args['customer_id'] ?? 0),
                ]);
            case 'get_technician_performance':
                return AiOperationsReadService::technicianPerformance($user, [
                    'period' => (string) ($args['period'] ?? 'all'),
                ]);
            case 'get_operational_summary':
                return AiOperationsReadService::operationalSummary($user);
            case 'get_complaint_trend':
                return AiOperationsReadService::complaintTrend($user, [
                    'granularity' => (string) ($args['granularity'] ?? 'daily'),
                    'points' => isset($args['points']) ? (int) $args['points'] : 14,
                ]);
            case 'get_app_usage_guide':
                return ['guide' => AiKnowledge::appGuide($role)];
            case 'get_wifi_troubleshooting_guide':
                return ['guide' => AiKnowledge::troubleshooting()];
            case 'get_faq':
                try {
                    $faqEntries = AiFaq::active(50);
                } catch (Throwable $exception) {
                    $faqEntries = [];
                }

                return [
                    'guide' => AiKnowledge::faq(),
                    'faq_resmi' => array_map(static function (array $faq): array {
                        return [
                            'pertanyaan' => (string) ($faq['pertanyaan'] ?? ''),
                            'jawaban' => (string) ($faq['jawaban'] ?? ''),
                            'kategori' => (string) ($faq['kategori'] ?? ''),
                        ];
                    }, $faqEntries),
                ];
        }

        return ['error' => 'tool_not_allowed'];
    }

    /**
     * Catat metadata akses tool (tanpa isi pencarian/data sensitif).
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $result
     */
    private static function logAccess(string $name, array $user, array $result): void
    {
        $dataset = match ($name) {
            'search_employees', 'get_employee_count', 'get_employee_detail' => 'karyawan',
            'search_customers', 'get_customer_detail', 'get_customer_count' => 'pelanggan',
            'get_customer_complaints', 'search_complaints', 'get_complaint_detail',
            'get_complaint_status_summary', 'get_complaint_statistics',
            'get_ticket_statistics', 'get_complaint_trend' => 'pengaduan',
            'get_technician_assignments', 'get_technician_history', 'get_technician_performance' => 'teknisi',
            'get_service_rating_summary' => 'rating',
            'get_operational_summary' => 'operasional',
            default => '',
        };
        if ($dataset === '') {
            return;
        }

        $count = 0;
        foreach (['employees', 'customers', 'complaints', 'performance', 'categories'] as $key) {
            if (isset($result[$key]) && is_array($result[$key])) {
                $count = count($result[$key]);
                break;
            }
        }
        if ($count === 0 && isset($result['count'])) {
            $count = (int) $result['count'];
        } elseif ($count === 0 && isset($result['total'])) {
            $count = (int) $result['total'];
        }

        $success = !isset($result['error']) && (($result['found'] ?? true) !== false);
        AiDataAccessLog::record($user, 'tool', $name, $dataset, $count, $success);
    }

    /**
     * Ambil status tiket dengan pemeriksaan hak akses.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function getTicketStatus(array $user, string $nomor): array
    {
        $nomor = strtoupper(trim($nomor));

        if (!preg_match('/^WFC-\d{8}-\d{6}$/', $nomor)) {
            return [
                'found' => false,
                'message' => 'Nomor tiket tidak valid. Format yang benar: WFC-YYYYMMDD-XXXXXX.',
            ];
        }

        $ticket = Pengaduan::findByNumber($nomor);
        if ($ticket === null || !self::canView($user, $ticket)) {
            // Pesan seragam: tidak membocorkan keberadaan tiket.
            return [
                'found' => false,
                'message' => 'Tiket tidak ditemukan atau Anda tidak memiliki akses ke tiket tersebut.',
            ];
        }

        $map = status_map();

        $ticket = [
            'nomor_tiket' => (string) $ticket['nomor_tiket'],
            'status' => (string) ($map[(string) $ticket['status']]['label'] ?? $ticket['status']),
            'status_key' => (string) $ticket['status'],
            'kategori' => (string) ($ticket['kategori'] ?? 'Tanpa kategori'),
            'dibuat' => format_waktu((string) $ticket['created_at']),
            'dibuat_panjang' => format_waktu_panjang((string) $ticket['created_at']),
            'selesai' => $ticket['completed_at'] === null ? null : format_waktu((string) $ticket['completed_at']),
            'teknisi' => $ticket['teknisi_nama'] === null ? null : (string) $ticket['teknisi_nama'],
            'dibatalkan' => (int) $ticket['is_cancelled'] === 1,
            'diarsipkan' => $ticket['archived_at'] !== null,
        ];

        // Nama pelanggan hanya untuk peran yang berhak (bukan pelanggan itu sendiri).
        $role = (string) ($user['role'] ?? '');
        $namaPelanggan = trim((string) ($ticket['nama_pelanggan_snapshot'] ?? ''));
        if ($namaPelanggan !== '' && in_array($role, ['admin', 'cs', 'teknisi'], true)) {
            $ticket['nama_pelanggan'] = $namaPelanggan;
        }

        return [
            'found' => true,
            'ticket' => $ticket,
        ];
    }

    /**
     * Apakah pengguna berhak melihat tiket.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $ticket
     */
    public static function canView(array $user, array $ticket): bool
    {
        $role = (string) ($user['role'] ?? '');
        $userId = (int) ($user['id'] ?? 0);

        if ($role === 'admin' || $role === 'cs') {
            return true;
        }

        if ($role === 'teknisi') {
            return $userId > 0 && Pengaduan::assignedToAny((int) $ticket['id'], $userId);
        }

        if ($role === 'pelanggan') {
            $pelanggan = Pelanggan::findByUserId($userId);

            return $pelanggan !== null && (int) $pelanggan['id'] === (int) $ticket['pelanggan_id'];
        }

        return false;
    }
}
