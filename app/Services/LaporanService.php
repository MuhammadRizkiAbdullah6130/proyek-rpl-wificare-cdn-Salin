<?php
/**
 * Service LAPORAN & ANALITIK (Tahap 9).
 *
 * Menyediakan satu sumber data untuk SEMUA jenis laporan. Struktur hasil yang
 * sama dipakai oleh tampilan on-screen, ekspor PDF, dan ekspor Excel sehingga
 * "ekspor memakai filter yang sama dengan tampilan" dijamin secara desain.
 *
 * Definisi rumus (lihat juga method metrik()):
 * - total        = COUNT semua tiket dalam lingkup filter (termasuk dibatalkan).
 * - aktif        = tiket dengan salah satu empat status aktif DAN tidak dibatalkan.
 * - selesai      = tiket berstatus `selesai`.
 * - ditolak      = tiket berstatus `ditolak`.
 * - dibatalkan   = tiket dengan is_cancelled = 1 (bukan status ketujuh).
 * - rata durasi  = AVG menit dari created_at ke waktu penyelesaian (hanya tiket
 *                  selesai; tiket belum selesai TIDAK dihitung 0).
 * - persen selesai = selesai / (total - dibatalkan) * 100.
 * - rata rating  = AVG bintang rating_layanan dalam lingkup filter.
 *
 * Tidak ada angka dummy: seluruh nilai berasal dari MySQL.
 */

declare(strict_types=1);

final class LaporanService
{
    /** Batas baris tabel laporan on-screen. */
    private const TABLE_LIMIT = 200;

    /** Batas baris ekspor. */
    public const EXPORT_LIMIT = 5000;

    /**
     * Daftar sembilan jenis laporan.
     *
     * @return array<string, array{label: string, description: string, icon: string}>
     */
    public static function jenisList(): array
    {
        return [
            'semua' => [
                'label' => 'Seluruh Pengaduan',
                'description' => 'Daftar seluruh tiket pengaduan beserta status dan penanganannya.',
                'icon' => 'ticket',
            ],
            'periode' => [
                'label' => 'Pengaduan per Periode',
                'description' => 'Rekap jumlah pengaduan harian, mingguan, atau bulanan.',
                'icon' => 'calendar',
            ],
            'status' => [
                'label' => 'Pengaduan per Status',
                'description' => 'Distribusi tiket untuk keenam status resmi.',
                'icon' => 'activity',
            ],
            'teknisi' => [
                'label' => 'Kinerja Teknisi',
                'description' => 'Beban tugas, penyelesaian, durasi, dan rating per teknisi.',
                'icon' => 'tool',
            ],
            'kategori' => [
                'label' => 'Kategori Gangguan',
                'description' => 'Sebaran pengaduan per kategori gangguan.',
                'icon' => 'tag',
            ],
            'pelanggan' => [
                'label' => 'Riwayat Pelanggan',
                'description' => 'Riwayat pengaduan per pelanggan beserta rekapnya.',
                'icon' => 'users',
            ],
            'durasi' => [
                'label' => 'Waktu Penyelesaian',
                'description' => 'Analisis durasi penyelesaian tiket yang telah selesai.',
                'icon' => 'clock',
            ],
            'rating' => [
                'label' => 'Rating & Ulasan',
                'description' => 'Penilaian dan ulasan pelanggan atas layanan.',
                'icon' => 'star',
            ],
            'analitik' => [
                'label' => 'Grafik & Ringkasan',
                'description' => 'Ringkasan metrik utama dan grafik analitik.',
                'icon' => 'report',
            ],
        ];
    }

    /**
     * Apakah kode jenis laporan valid.
     */
    public static function isValidJenis(string $jenis): bool
    {
        return array_key_exists($jenis, self::jenisList());
    }

    /**
     * Validasi + normalisasi filter dari input GET/POST.
     *
     * @param array<string, mixed> $input
     * @return array{filters: array<string, mixed>, errors: array<int, string>}
     */
    public static function buildFilters(array $input): array
    {
        $errors = [];

        // Revisi 6: filter laporan disederhanakan menjadi rentang periode.
        // Default "Bulan Ini" bila tidak ada pilihan lain.
        $rentang = (string) ($input['rentang'] ?? 'bulan_ini');
        $allowedRentang = ['hari_ini', '7_hari', 'bulan_ini', 'bulan_lalu', 'tahun_ini', 'semua', 'pilih_tanggal'];
        if (!in_array($rentang, $allowedRentang, true)) {
            $rentang = 'bulan_ini';
        }

        $dateFrom = self::normalizeDate((string) ($input['date_from'] ?? ''));
        $dateTo = self::normalizeDate((string) ($input['date_to'] ?? ''));

        if ($rentang === 'pilih_tanggal') {
            // Tanggal kustom hanya dipakai ketika pengguna benar-benar memilihnya.
            if ($dateFrom === '' && $dateTo === '') {
                $rentang = 'bulan_ini';
                [$dateFrom, $dateTo] = periode_range($rentang);
            }
        } else {
            [$dateFrom, $dateTo] = periode_range($rentang);
        }

        if ($dateFrom !== '' && $dateTo !== '' && strcmp($dateFrom, $dateTo) > 0) {
            $errors[] = 'Tanggal awal tidak boleh melewati tanggal akhir. Filter tanggal diabaikan.';
            $dateFrom = '';
            $dateTo = '';
        }

        $status = (string) ($input['status'] ?? '');
        if ($status !== '' && !in_array($status, status_keys(), true)) {
            $status = '';
        }

        $cancelled = (string) ($input['cancelled'] ?? 'semua');
        if (!in_array($cancelled, ['semua', 'ya', 'tidak'], true)) {
            $cancelled = 'semua';
        }

        $arsip = (string) ($input['arsip'] ?? 'semua');
        if (!in_array($arsip, ['semua', 'aktif', 'arsip'], true)) {
            $arsip = 'semua';
        }

        // Pengelompokan (harian/mingguan/bulanan) tetap internal untuk laporan
        // "Pengaduan per Periode"; tidak ditampilkan sebagai filter utama.
        $periode = (string) ($input['periode'] ?? 'day');
        if (!in_array($periode, ['day', 'week', 'month'], true)) {
            $periode = 'day';
        }

        $filters = [
            'rentang' => $rentang,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'status' => $status,
            'kategori_id' => max(0, (int) ($input['kategori_id'] ?? 0)),
            'teknisi_id' => max(0, (int) ($input['teknisi_id'] ?? 0)),
            'pelanggan_id' => max(0, (int) ($input['pelanggan_id'] ?? 0)),
            'cancelled' => $cancelled,
            'arsip' => $arsip,
            'periode' => $periode,
        ];

        return ['filters' => $filters, 'errors' => $errors];
    }

    /**
     * Label preset rentang periode yang mudah dibaca.
     *
     * @return array<string, string>
     */
    public static function rentangLabels(): array
    {
        return [
            'hari_ini' => 'Hari Ini',
            '7_hari' => '7 Hari Terakhir',
            'bulan_ini' => 'Bulan Ini',
            'bulan_lalu' => 'Bulan Lalu',
            'tahun_ini' => 'Tahun Ini',
            'semua' => 'Semua Waktu',
            'pilih_tanggal' => 'Pilih Tanggal Sendiri',
        ];
    }

    /**
     * Metrik analitik utama sesuai lingkup filter.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public static function metrik(array $filters): array
    {
        $summary = Laporan::summary($filters);
        $completion = Laporan::completionStats($filters);
        $rating = Laporan::ratingStats($filters);

        $nonCancelled = $summary['total'] - $summary['dibatalkan'];
        $persenSelesai = $nonCancelled > 0
            ? round(($summary['selesai'] / $nonCancelled) * 100, 1)
            : null;

        return [
            'total' => $summary['total'],
            'aktif' => $summary['aktif'],
            'selesai' => $summary['selesai'],
            'ditolak' => $summary['ditolak'],
            'dibatalkan' => $summary['dibatalkan'],
            'persen_selesai' => $persenSelesai,
            'avg_durasi' => $completion['avg'],
            'min_durasi' => $completion['min'],
            'max_durasi' => $completion['max'],
            'median_durasi' => $completion['median'],
            'completion_count' => $completion['count'],
            'avg_rating' => $rating['average'],
            'rating_total' => $rating['total'],
        ];
    }

    /**
     * Susun dataset lengkap untuk sebuah jenis laporan.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public static function dataset(string $jenis, array $filters, int $page = 1, int $perPage = 20): array
    {
        $list = self::jenisList();
        if (!self::isValidJenis($jenis)) {
            $jenis = 'semua';
        }

        $metrik = self::metrik($filters);

        $dataset = [
            'jenis' => $jenis,
            'title' => $list[$jenis]['label'],
            'description' => $list[$jenis]['description'],
            'metrik' => $metrik,
            'summary' => self::summaryCards($metrik),
            'columns' => [],
            'rows' => [],
            'row_count' => 0,
            'page' => 1,
            'pages' => 1,
            'total' => 0,
            'charts' => [],
            'notes' => [],
        ];

        switch ($jenis) {
            case 'periode':
                $dataset = self::datasetPeriode($dataset, $filters);
                break;
            case 'status':
                $dataset = self::datasetStatus($dataset, $filters);
                break;
            case 'teknisi':
                $dataset = self::datasetTeknisi($dataset, $filters);
                break;
            case 'kategori':
                $dataset = self::datasetKategori($dataset, $filters);
                break;
            case 'pelanggan':
                $dataset = self::datasetPelanggan($dataset, $filters);
                break;
            case 'durasi':
                $dataset = self::datasetDurasi($dataset, $filters);
                break;
            case 'rating':
                $dataset = self::datasetRating($dataset, $filters);
                break;
            case 'analitik':
                $dataset = self::datasetAnalitik($dataset, $filters);
                break;
            case 'semua':
            default:
                $dataset = self::datasetSemua($dataset, $filters, $page, $perPage);
                break;
        }

        return $dataset;
    }

    /**
     * Tabel lengkap untuk ekspor (filter sama dengan tampilan, tanpa pagination
     * tabel on-screen; tetap dibatasi EXPORT_LIMIT).
     *
     * @param array<string, mixed> $filters
     * @return array{title: string, columns: array<int, array<string, string>>, rows: array<int, array<int, mixed>>, metrik: array<string, mixed>}
     */
    public static function exportTable(string $jenis, array $filters): array
    {
        if (!self::isValidJenis($jenis)) {
            $jenis = 'semua';
        }

        $dataset = self::dataset($jenis, $filters, 1, self::EXPORT_LIMIT);

        if ($jenis === 'semua') {
            $dataset['columns'] = self::columnsSemua();
            $dataset['rows'] = self::mapTickets(array_slice(Laporan::ticketsForExport($filters, self::EXPORT_LIMIT), 0, self::EXPORT_LIMIT));
        } elseif ($jenis === 'durasi') {
            $dataset['columns'] = self::columnsDurasi();
            $dataset['rows'] = self::mapCompletion(array_slice(Laporan::completionRows($filters), 0, self::EXPORT_LIMIT));
        } elseif ($jenis === 'rating') {
            $dataset['columns'] = self::columnsRating();
            $dataset['rows'] = self::mapRating(array_slice(Laporan::ratingRows($filters), 0, self::EXPORT_LIMIT));
        }

        return [
            'title' => $dataset['title'],
            'columns' => $dataset['columns'],
            'rows' => $dataset['rows'],
            'metrik' => self::metrik($filters),
        ];
    }

    /**
     * Ringkasan filter yang dipakai (untuk header PDF/Excel dan info di layar).
     *
     * @param array<string, mixed> $filters
     * @return array<int, array{label: string, value: string}>
     */
    public static function filterLabels(array $filters): array
    {
        $labels = [];

        $rentang = (string) ($filters['rentang'] ?? '');
        if ($rentang !== '' && $rentang !== 'pilih_tanggal') {
            $labels[] = ['label' => 'Periode', 'value' => self::rentangLabels()[$rentang] ?? $rentang];
        }

        if (($filters['date_from'] ?? '') !== '' || ($filters['date_to'] ?? '') !== '') {
            $from = $filters['date_from'] !== '' ? format_tanggal((string) $filters['date_from']) : 'Awal';
            $to = $filters['date_to'] !== '' ? format_tanggal((string) $filters['date_to']) : 'Sekarang';
            $labels[] = ['label' => 'Rentang Tanggal', 'value' => $from . ' s.d. ' . $to];
        }

        if (($filters['status'] ?? '') !== '') {
            $map = status_map();
            $labels[] = ['label' => 'Status', 'value' => (string) ($map[$filters['status']]['label'] ?? $filters['status'])];
        }

        if ((int) ($filters['kategori_id'] ?? 0) > 0) {
            $labels[] = ['label' => 'Kategori', 'value' => self::nameById('kategori_gangguan', (int) $filters['kategori_id'])];
        }

        if ((int) ($filters['teknisi_id'] ?? 0) > 0) {
            $labels[] = ['label' => 'Teknisi', 'value' => self::nameById('users', (int) $filters['teknisi_id'])];
        }

        if ((int) ($filters['pelanggan_id'] ?? 0) > 0) {
            $labels[] = ['label' => 'Pelanggan', 'value' => self::pelangganLabel((int) $filters['pelanggan_id'])];
        }

        $cancelledMap = ['semua' => 'Semua', 'ya' => 'Hanya dibatalkan', 'tidak' => 'Tanpa tiket dibatalkan'];
        if (($filters['cancelled'] ?? 'semua') !== 'semua') {
            $labels[] = ['label' => 'Pembatalan', 'value' => $cancelledMap[$filters['cancelled']] ?? 'Semua'];
        }

        $arsipMap = ['semua' => 'Semua', 'aktif' => 'Hanya aktif', 'arsip' => 'Hanya diarsipkan'];
        if (($filters['arsip'] ?? 'semua') !== 'semua') {
            $labels[] = ['label' => 'Arsip', 'value' => $arsipMap[$filters['arsip']] ?? 'Semua'];
        }

        if (empty($labels)) {
            $labels[] = ['label' => 'Filter', 'value' => 'Tanpa filter (seluruh data)'];
        }

        return $labels;
    }

    /**
     * Format durasi menit menjadi teks ringkas.
     */
    public static function formatMinutes(null|int|float $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }

        $total = (int) round((float) $minutes);
        if ($total < 60) {
            return $total . ' menit';
        }

        $hours = intdiv($total, 60);
        $restMinutes = $total % 60;
        if ($hours < 24) {
            return $hours . 'j ' . $restMinutes . 'm';
        }

        $days = intdiv($hours, 24);
        $restHours = $hours % 24;

        return $days . 'h ' . $restHours . 'j';
    }

    /**
     * Format satu sel untuk tampilan teks (dipakai view, PDF, dan fallback).
     *
     * @param mixed $value
     */
    public static function cellText(mixed $value, string $type): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        switch ($type) {
            case 'number':
                return number_format((float) $value, 0, ',', '.');
            case 'percent':
                return number_format((float) $value, 1, ',', '.') . '%';
            case 'rating':
                return (string) (int) $value . ' / 5';
            case 'rating_float':
                return number_format((float) $value, 1, ',', '.');
            case 'minutes':
                return self::formatMinutes((float) $value);
            case 'minutes_number':
                return number_format((float) $value, 0, ',', '.') . ' menit';
            case 'datetime':
                return format_waktu((string) $value);
            case 'date':
                return format_tanggal((string) $value);
            case 'status':
                $map = status_map();

                return (string) ($map[(string) $value]['label'] ?? $value);
            case 'periode':
                return self::periodeLabel((string) $value);
            default:
                return (string) $value;
        }
    }

    /**
     * Label periode yang mudah dibaca (harian/mingguan/bulanan).
     */
    private static function periodeLabel(string $value): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return format_tanggal($value);
        }

        if (preg_match('/^(\d{4})-(\d{2})$/', $value, $m)) {
            $bulan = bulan_singkat();

            return ($bulan[(int) $m[2]] ?? $m[2]) . ' ' . $m[1];
        }

        return $value;
    }

    /**
     * Kartu ringkasan standar (metrik utama).
     *
     * @param array<string, mixed> $metrik
     * @return array<int, array<string, mixed>>
     */
    private static function summaryCards(array $metrik): array
    {
        return [
            ['label' => 'Total Pengaduan', 'value' => (string) $metrik['total'], 'icon' => 'ticket', 'tone' => 'primary', 'hint' => 'Dalam lingkup filter'],
            ['label' => 'Selesai', 'value' => (string) $metrik['selesai'], 'icon' => 'check', 'tone' => 'success', 'hint' => $metrik['persen_selesai'] === null ? 'Persentase belum tersedia' : $metrik['persen_selesai'] . '% dari non-batal'],
            ['label' => 'Dalam Proses', 'value' => (string) $metrik['aktif'], 'icon' => 'activity', 'tone' => 'info', 'hint' => 'Tiket yang belum selesai'],
            ['label' => 'Rata-rata Rating', 'value' => $metrik['avg_rating'] === null ? '—' : number_format((float) $metrik['avg_rating'], 1) . '/5', 'icon' => 'star', 'tone' => 'warning', 'hint' => $metrik['rating_total'] . ' penilaian', 'empty' => $metrik['avg_rating'] === null],
        ];
    }

    /**
     * @param array<string, mixed> $dataset
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private static function datasetSemua(array $dataset, array $filters, int $page, int $perPage): array
    {
        $result = Laporan::paginateTickets($filters, $page, $perPage);

        $dataset['columns'] = self::columnsSemua();
        $dataset['rows'] = self::mapTickets($result['rows']);
        $dataset['row_count'] = count($dataset['rows']);
        $dataset['total'] = $result['total'];
        $dataset['page'] = $result['page'];
        $dataset['pages'] = $result['pages'];
        $dataset['page_size'] = $result['per_page'];
        $dataset['charts'] = ['status' => status_chart_data(Laporan::statusDistribution($filters))];

        return $dataset;
    }

    /**
     * @return array<int, array{label: string, align: string, type: string}>
     */
    private static function columnsSemua(): array
    {
        return [
            ['label' => 'No. Tiket', 'align' => 'left', 'type' => 'text'],
            ['label' => 'Tanggal Dibuat', 'align' => 'left', 'type' => 'datetime'],
            ['label' => 'Pelanggan', 'align' => 'left', 'type' => 'text'],
            ['label' => 'PPPoE', 'align' => 'left', 'type' => 'text'],
            ['label' => 'Kategori', 'align' => 'left', 'type' => 'text'],
            ['label' => 'Status', 'align' => 'left', 'type' => 'status'],
            ['label' => 'Teknisi', 'align' => 'left', 'type' => 'text'],
            ['label' => 'Selesai', 'align' => 'left', 'type' => 'datetime'],
            ['label' => 'Rating', 'align' => 'center', 'type' => 'rating'],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<int, mixed>>
     */
    private static function mapTickets(array $rows): array
    {
        $mapped = [];
        foreach ($rows as $row) {
            $mapped[] = [
                (string) $row['nomor_tiket'],
                (string) $row['created_at'],
                (string) $row['nama_pelanggan_snapshot'],
                (string) $row['username_pppoe_snapshot'],
                (string) ($row['kategori'] ?? 'Tanpa kategori'),
                (string) $row['status'],
                (string) ($row['teknisi_nama'] ?? '—'),
                (string) ($row['completed_at'] ?? ''),
                $row['rating'] === null ? '' : (string) $row['rating'],
            ];
        }

        return $mapped;
    }

    /**
     * @param array<string, mixed> $dataset
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private static function datasetPeriode(array $dataset, array $filters): array
    {
        $group = (string) ($filters['periode'] ?? 'day');
        $rowsRaw = Laporan::periodRows($filters, $group);

        $rows = [];
        $trend = [];
        foreach ($rowsRaw as $row) {
            $periode = (string) $row['periode'];
            $rows[] = [
                $periode,
                (int) $row['jumlah'],
                (int) $row['aktif'],
                (int) $row['selesai'],
                (int) $row['ditolak'],
                (int) $row['dibatalkan'],
            ];
            $trend[$periode] = (int) $row['jumlah'];
        }

        $dataset['columns'] = [
            ['label' => 'Periode', 'align' => 'left', 'type' => 'periode'],
            ['label' => 'Jumlah', 'align' => 'right', 'type' => 'number'],
            ['label' => 'Aktif', 'align' => 'right', 'type' => 'number'],
            ['label' => 'Selesai', 'align' => 'right', 'type' => 'number'],
            ['label' => 'Ditolak', 'align' => 'right', 'type' => 'number'],
            ['label' => 'Dibatalkan', 'align' => 'right', 'type' => 'number'],
        ];
        $dataset['rows'] = $rows;
        $dataset['row_count'] = count($rows);
        $dataset['total'] = count($rows);
        $dataset['charts'] = [
            'trend' => $group === 'day' ? trend_chart_data($trend) : self::chartFromMap($trend),
        ];

        return $dataset;
    }

    /**
     * @param array<string, mixed> $dataset
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private static function datasetStatus(array $dataset, array $filters): array
    {
        $distribution = Laporan::statusDistribution($filters);
        $total = array_sum($distribution);
        $map = status_map();

        $rows = [];
        foreach ($map as $key => $meta) {
            $count = (int) ($distribution[$key] ?? 0);
            $rows[] = [
                $key,
                $count,
                $total > 0 ? round(($count / $total) * 100, 1) : 0.0,
            ];
        }

        $dataset['columns'] = [
            ['label' => 'Status', 'align' => 'left', 'type' => 'status'],
            ['label' => 'Jumlah', 'align' => 'right', 'type' => 'number'],
            ['label' => 'Persentase', 'align' => 'right', 'type' => 'percent'],
        ];
        $dataset['rows'] = $rows;
        $dataset['row_count'] = count($rows);
        $dataset['total'] = $total;
        $dataset['charts'] = ['status' => status_chart_data($distribution)];

        return $dataset;
    }

    /**
     * @param array<string, mixed> $dataset
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private static function datasetTeknisi(array $dataset, array $filters): array
    {
        $rowsRaw = Laporan::technicianPerformance($filters);

        $rows = [];
        $labels = [];
        $values = [];
        foreach ($rowsRaw as $row) {
            $rows[] = [
                (string) $row['nama'],
                (int) $row['total_tugas'],
                (int) $row['aktif'],
                (int) $row['selesai'],
                $row['avg_menit'] === null ? '' : (float) $row['avg_menit'],
                $row['rating_rata'] === null ? '' : (float) $row['rating_rata'],
            ];
            $labels[] = (string) $row['nama'];
            $values[] = (int) $row['total_tugas'];
        }

        $dataset['columns'] = [
            ['label' => 'Teknisi', 'align' => 'left', 'type' => 'text'],
            ['label' => 'Total Tugas', 'align' => 'right', 'type' => 'number'],
            ['label' => 'Aktif', 'align' => 'right', 'type' => 'number'],
            ['label' => 'Selesai', 'align' => 'right', 'type' => 'number'],
            ['label' => 'Rata-rata Durasi', 'align' => 'right', 'type' => 'minutes'],
            ['label' => 'Rating Rata-rata', 'align' => 'right', 'type' => 'rating_float'],
        ];
        $dataset['rows'] = $rows;
        $dataset['row_count'] = count($rows);
        $dataset['total'] = count($rows);
        $dataset['charts'] = ['teknisi' => self::chartFromMap(array_combine($labels ?: [''], $values ?: [0]) ?: [])];

        return $dataset;
    }

    /**
     * @param array<string, mixed> $dataset
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private static function datasetKategori(array $dataset, array $filters): array
    {
        $rowsRaw = Laporan::categoryDistribution($filters);
        $total = 0;
        foreach ($rowsRaw as $row) {
            $total += (int) $row['jumlah'];
        }

        $rows = [];
        $labels = [];
        $values = [];
        foreach ($rowsRaw as $row) {
            $jumlah = (int) $row['jumlah'];
            $rows[] = [
                (string) $row['nama'],
                $jumlah,
                (int) $row['aktif'],
                (int) $row['selesai'],
                $total > 0 ? round(($jumlah / $total) * 100, 1) : 0.0,
            ];
            $labels[] = (string) $row['nama'];
            $values[] = $jumlah;
        }

        $dataset['columns'] = [
            ['label' => 'Kategori', 'align' => 'left', 'type' => 'text'],
            ['label' => 'Jumlah', 'align' => 'right', 'type' => 'number'],
            ['label' => 'Aktif', 'align' => 'right', 'type' => 'number'],
            ['label' => 'Selesai', 'align' => 'right', 'type' => 'number'],
            ['label' => 'Persentase', 'align' => 'right', 'type' => 'percent'],
        ];
        $dataset['rows'] = $rows;
        $dataset['row_count'] = count($rows);
        $dataset['total'] = $total;
        $dataset['charts'] = ['kategori' => self::chartFromMap(self::pairMap($labels, $values))];

        return $dataset;
    }

    /**
     * @param array<string, mixed> $dataset
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private static function datasetPelanggan(array $dataset, array $filters): array
    {
        $rowsRaw = Laporan::customerSummary($filters);

        $rows = [];
        foreach ($rowsRaw as $row) {
            $rows[] = [
                (string) $row['nama'],
                (string) $row['username_pppoe'],
                (int) $row['total'],
                (int) $row['aktif'],
                (int) $row['selesai'],
                (int) $row['ditolak'],
                (int) $row['dibatalkan'],
                $row['rating_rata'] === null ? '' : (float) $row['rating_rata'],
            ];
        }

        $dataset['columns'] = [
            ['label' => 'Pelanggan', 'align' => 'left', 'type' => 'text'],
            ['label' => 'PPPoE', 'align' => 'left', 'type' => 'text'],
            ['label' => 'Total', 'align' => 'right', 'type' => 'number'],
            ['label' => 'Aktif', 'align' => 'right', 'type' => 'number'],
            ['label' => 'Selesai', 'align' => 'right', 'type' => 'number'],
            ['label' => 'Ditolak', 'align' => 'right', 'type' => 'number'],
            ['label' => 'Dibatalkan', 'align' => 'right', 'type' => 'number'],
            ['label' => 'Rating', 'align' => 'right', 'type' => 'rating_float'],
        ];
        $dataset['rows'] = $rows;
        $dataset['row_count'] = count($rows);
        $dataset['total'] = count($rows);

        return $dataset;
    }

    /**
     * @param array<string, mixed> $dataset
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private static function datasetDurasi(array $dataset, array $filters): array
    {
        $rowsRaw = Laporan::completionRows($filters);
        $shown = array_slice($rowsRaw, 0, self::TABLE_LIMIT);

        if (count($rowsRaw) > self::TABLE_LIMIT) {
            $dataset['notes'][] = 'Menampilkan ' . self::TABLE_LIMIT . ' dari ' . count($rowsRaw) . ' tiket selesai. Gunakan ekspor untuk data lengkap.';
        }

        $dataset['columns'] = self::columnsDurasi();
        $dataset['rows'] = self::mapCompletion($shown);
        $dataset['row_count'] = count($rowsRaw);
        $dataset['total'] = count($rowsRaw);
        $dataset['extra_summary'] = [
            ['label' => 'Tercepat', 'value' => self::formatMinutes($dataset['metrik']['min_durasi']), 'empty' => $dataset['metrik']['min_durasi'] === null],
            ['label' => 'Terlama', 'value' => self::formatMinutes($dataset['metrik']['max_durasi']), 'empty' => $dataset['metrik']['max_durasi'] === null],
            ['label' => 'Median', 'value' => self::formatMinutes($dataset['metrik']['median_durasi']), 'empty' => $dataset['metrik']['median_durasi'] === null],
        ];

        return $dataset;
    }

    /**
     * @return array<int, array{label: string, align: string, type: string}>
     */
    private static function columnsDurasi(): array
    {
        return [
            ['label' => 'No. Tiket', 'align' => 'left', 'type' => 'text'],
            ['label' => 'Pelanggan', 'align' => 'left', 'type' => 'text'],
            ['label' => 'Kategori', 'align' => 'left', 'type' => 'text'],
            ['label' => 'Dibuat', 'align' => 'left', 'type' => 'datetime'],
            ['label' => 'Selesai', 'align' => 'left', 'type' => 'datetime'],
            ['label' => 'Durasi (menit)', 'align' => 'right', 'type' => 'minutes_number'],
            ['label' => 'Penyelesaian', 'align' => 'left', 'type' => 'text'],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<int, mixed>>
     */
    private static function mapCompletion(array $rows): array
    {
        $mapped = [];
        foreach ($rows as $row) {
            $mapped[] = [
                (string) $row['nomor_tiket'],
                (string) $row['nama_pelanggan_snapshot'],
                (string) ($row['kategori'] ?? 'Tanpa kategori'),
                (string) $row['created_at'],
                (string) ($row['completed_at'] ?? ''),
                (int) $row['durasi_menit'],
                (string) $row['jenis_penyelesaian'],
            ];
        }

        return $mapped;
    }

    /**
     * @param array<string, mixed> $dataset
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private static function datasetRating(array $dataset, array $filters): array
    {
        $rowsRaw = Laporan::ratingRows($filters);
        $shown = array_slice($rowsRaw, 0, self::TABLE_LIMIT);

        if (count($rowsRaw) > self::TABLE_LIMIT) {
            $dataset['notes'][] = 'Menampilkan ' . self::TABLE_LIMIT . ' dari ' . count($rowsRaw) . ' ulasan. Gunakan ekspor untuk data lengkap.';
        }

        $stats = Laporan::ratingStats($filters);
        $dist = $stats['distribution'];

        $dataset['columns'] = self::columnsRating();
        $dataset['rows'] = self::mapRating($shown);
        $dataset['row_count'] = count($rowsRaw);
        $dataset['total'] = count($rowsRaw);
        $dataset['charts'] = [
            'ratingDist' => self::chartFromMap([
                '1 Bintang' => (int) $dist[1],
                '2 Bintang' => (int) $dist[2],
                '3 Bintang' => (int) $dist[3],
                '4 Bintang' => (int) $dist[4],
                '5 Bintang' => (int) $dist[5],
            ]),
            'ratingTren' => self::chartRatingTrend(Laporan::ratingTrend($filters)),
        ];

        return $dataset;
    }

    /**
     * @return array<int, array{label: string, align: string, type: string}>
     */
    private static function columnsRating(): array
    {
        return [
            ['label' => 'Tanggal', 'align' => 'left', 'type' => 'datetime'],
            ['label' => 'No. Tiket', 'align' => 'left', 'type' => 'text'],
            ['label' => 'Pelanggan', 'align' => 'left', 'type' => 'text'],
            ['label' => 'Rating', 'align' => 'center', 'type' => 'rating'],
            ['label' => 'Ulasan', 'align' => 'left', 'type' => 'text'],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<int, mixed>>
     */
    private static function mapRating(array $rows): array
    {
        $mapped = [];
        foreach ($rows as $row) {
            $mapped[] = [
                (string) $row['created_at'],
                (string) $row['nomor_tiket'],
                (string) $row['nama_pelanggan_snapshot'],
                (int) $row['rating'],
                (string) ($row['ulasan'] ?? ''),
            ];
        }

        return $mapped;
    }

    /**
     * @param array<string, mixed> $dataset
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private static function datasetAnalitik(array $dataset, array $filters): array
    {
        $distribution = Laporan::statusDistribution($filters);
        $statusRows = [];
        $total = array_sum($distribution);
        foreach (status_map() as $key => $meta) {
            $count = (int) ($distribution[$key] ?? 0);
            $statusRows[] = [
                $key,
                $count,
                $total > 0 ? round(($count / $total) * 100, 1) : 0.0,
            ];
        }

        $dataset['columns'] = [
            ['label' => 'Status', 'align' => 'left', 'type' => 'status'],
            ['label' => 'Jumlah', 'align' => 'right', 'type' => 'number'],
            ['label' => 'Persentase', 'align' => 'right', 'type' => 'percent'],
        ];
        $dataset['rows'] = $statusRows;
        $dataset['row_count'] = count($statusRows);
        $dataset['total'] = $total;

        $periode = Laporan::periodRows($filters, 'day');
        $trend = [];
        foreach ($periode as $row) {
            $trend[(string) $row['periode']] = (int) $row['jumlah'];
        }

        $kategori = Laporan::categoryDistribution($filters);
        $katLabels = [];
        $katValues = [];
        foreach ($kategori as $row) {
            $katLabels[] = (string) $row['nama'];
            $katValues[] = (int) $row['jumlah'];
        }

        $teknisi = Laporan::technicianPerformance($filters);
        $tekLabels = [];
        $tekValues = [];
        foreach ($teknisi as $row) {
            $tekLabels[] = (string) $row['nama'];
            $tekValues[] = (int) $row['total_tugas'];
        }

        $dataset['charts'] = [
            'status' => status_chart_data($distribution),
            'trend' => trend_chart_data($trend),
            'kategori' => self::chartFromMap(self::pairMap($katLabels, $katValues)),
            'teknisi' => self::chartFromMap(self::pairMap($tekLabels, $tekValues)),
            'ratingTren' => self::chartRatingTrend(Laporan::ratingTrend($filters)),
        ];

        return $dataset;
    }

    /**
     * @param array<string, int|float> $map
     * @return array{labels: array<int, string>, values: array<int, int|float>}
     */
    private static function chartFromMap(array $map): array
    {
        $labels = [];
        $values = [];
        foreach ($map as $label => $value) {
            $labels[] = (string) $label;
            $values[] = $value;
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * @param array<int, string> $labels
     * @param array<int, int|float> $values
     * @return array<string, int|float>
     */
    private static function pairMap(array $labels, array $values): array
    {
        $map = [];
        foreach ($labels as $index => $label) {
            $map[$label] = $values[$index] ?? 0;
        }

        return $map;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{labels: array<int, string>, values: array<int, float>}
     */
    private static function chartRatingTrend(array $rows): array
    {
        $labels = [];
        $values = [];
        foreach ($rows as $row) {
            $labels[] = (string) $row['periode'];
            $values[] = round((float) $row['rata_rata'], 2);
        }

        return ['labels' => $labels, 'values' => $values];
    }

    private static function normalizeDate(string $value): string
    {
        $value = trim($value);
        if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return '';
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year) ? $value : '';
    }

    private static function nameById(string $table, int $id): string
    {
        $allowed = ['kategori_gangguan' => true, 'users' => true];
        if (!isset($allowed[$table]) || $id <= 0) {
            return '#';
        }

        $statement = Database::pdo()->prepare('SELECT nama FROM ' . $table . ' WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $name = $statement->fetchColumn();

        return $name === false ? '#' . $id : (string) $name;
    }

    private static function pelangganLabel(int $id): string
    {
        if ($id <= 0) {
            return '#';
        }

        $statement = Database::pdo()->prepare(
            'SELECT pu.nama, pl.username_pppoe FROM pelanggan pl
             INNER JOIN users pu ON pu.id = pl.user_id WHERE pl.id = :id LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row === false ? '#' . $id : (string) $row['nama'] . ' (' . (string) $row['username_pppoe'] . ')';
    }
}
