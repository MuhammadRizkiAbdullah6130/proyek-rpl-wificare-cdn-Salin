<?php
/**
 * Controller area Administrator — Laporan & Analitik (Tahap 9).
 *
 * Menyediakan sembilan jenis laporan (on-screen), ekspor PDF, dan ekspor Excel
 * (.xlsx). Semua ekspor memakai filter yang sama dengan tampilan karena
 * keduanya bersumber dari LaporanService yang sama.
 */

declare(strict_types=1);

require_once __DIR__ . '/AdminBaseController.php';

final class AdminLaporanController extends AdminBaseController
{
    private const PER_HALAMAN = 20;

    public function index(): void
    {
        $jenis = $this->readJenis();
        [$filters, $errors] = $this->readFilters();
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $dataset = LaporanService::dataset($jenis, $filters, $page, self::PER_HALAMAN);
        $jenisList = LaporanService::jenisList();
        if (!rating_layanan_enabled()) {
            unset($jenisList['rating']);
        }

        $this->renderRole('admin/laporan', 'admin', 'laporan', 'Laporan & Analitik', [
            ['label' => 'Admin', 'url' => url('/admin/dashboard')],
            ['label' => 'Laporan & Analitik'],
        ], [
            'includeCharts' => true,
            'jenis' => $jenis,
            'jenisList' => $jenisList,
            'dataset' => $dataset,
            'filters' => $filters,
            'filterErrors' => $errors,
            'filterLabels' => LaporanService::filterLabels($filters),
            'filterOptions' => Laporan::filterOptions(),
            'query' => $this->buildQuery($jenis, $filters),
        ]);
    }

    public function pdf(): void
    {
        $jenis = $this->readJenis();
        [$filters] = $this->readFilters();

        $table = LaporanService::exportTable($jenis, $filters);
        $context = $this->documentContext($jenis, $filters);

        $pdf = new PdfReport('Laporan ' . $table['title']);
        $pdf->setBrand($context['brand']);
        $pdf->setGeneratedAt(format_waktu(date('Y-m-d H:i:s')));
        $metaLines = [];
        foreach ($context['meta'] as $item) {
            $metaLines[] = $item['label'] . ': ' . $item['value'];
        }
        $pdf->setMeta($metaLines);
        $pdf->setSummary($this->summaryPairs($table['metrik']));

        $columns = [];
        foreach ($table['columns'] as $column) {
            $columns[] = [
                'label' => (string) $column['label'],
                'weight' => $this->columnWeight((string) ($column['type'] ?? 'text')),
                'align' => (string) ($column['align'] ?? 'left'),
            ];
        }

        $rows = [];
        foreach ($table['rows'] as $row) {
            $cells = [];
            foreach ($table['columns'] as $index => $column) {
                $cells[] = LaporanService::cellText($row[$index] ?? '', (string) ($column['type'] ?? 'text'));
            }
            $rows[] = $cells;
        }

        $pdf->setTable($columns, $rows);

        $filename = 'laporan-' . $jenis . '-' . date('Ymd-His') . '.pdf';
        $binary = $pdf->output();

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($binary));
        header('Cache-Control: private, no-store');

        echo $binary;
    }

    public function excel(): void
    {
        $jenis = $this->readJenis();
        [$filters] = $this->readFilters();

        $table = LaporanService::exportTable($jenis, $filters);
        $context = $this->documentContext($jenis, $filters);

        $writer = new XlsxWriter('Laporan ' . mb_substr($table['title'], 0, 20));
        $writer->setTitle('Laporan ' . $table['title']);

        $meta = $context['meta'];
        $meta[] = ['label' => 'Dibuat', 'value' => format_waktu(date('Y-m-d H:i:s'))];
        foreach ($this->summaryPairs($table['metrik']) as $pair) {
            $meta[] = ['label' => (string) $pair['label'], 'value' => (string) $pair['value']];
        }
        $writer->setMeta($meta);

        $writer->setColumns(array_map(static function (array $column): array {
            return [
                'label' => (string) $column['label'],
                'type' => (string) ($column['type'] ?? 'text'),
                'width' => self::columnWidth((string) ($column['type'] ?? 'text')),
            ];
        }, $table['columns']));

        foreach ($table['rows'] as $row) {
            $writer->addRow($row);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'wificare_xlsx_');
        if ($tmp === false || !$writer->save($tmp)) {
            if (is_string($tmp) && is_file($tmp)) {
                @unlink($tmp);
            }

            render_error('Ekspor gagal', 'Berkas Excel tidak dapat dibuat. Silakan coba lagi.', 500);
            return;
        }

        $filename = 'laporan-' . $jenis . '-' . date('Ymd-His') . '.xlsx';
        $content = (string) file_get_contents($tmp);
        @unlink($tmp);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($content));
        header('Cache-Control: private, no-store');

        echo $content;
    }

    private function readJenis(): string
    {
        $jenis = (string) ($_GET['jenis'] ?? 'semua');
        if (!LaporanService::isValidJenis($jenis)) {
            return 'semua';
        }

        // Rating Layanan dinonaktifkan sementara: jangan tampilkan dataset rating.
        if (!rating_layanan_enabled() && $jenis === 'rating') {
            return 'semua';
        }

        return $jenis;
    }

    /**
     * Query string yang dipertahankan untuk pagination & tautan ekspor.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function buildQuery(string $jenis, array $filters): array
    {
        $query = ['jenis' => $jenis];

        $rentang = (string) ($filters['rentang'] ?? 'bulan_ini');
        $query['rentang'] = $rentang;

        if ($rentang === 'pilih_tanggal') {
            if (($filters['date_from'] ?? '') !== '') {
                $query['date_from'] = $filters['date_from'];
            }
            if (($filters['date_to'] ?? '') !== '') {
                $query['date_to'] = $filters['date_to'];
            }
        }

        return $query;
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<int, string>}
     */
    private function readFilters(): array
    {
        $result = LaporanService::buildFilters($_GET);

        return [$result['filters'], $result['errors']];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{brand: string, meta: array<int, array{label: string, value: string}>}
     */
    private function documentContext(string $jenis, array $filters): array
    {
        $appName = (string) config('app.name', 'WiFiCare CDN');
        $ispName = (string) (Pengaturan::get('isp_name', 'WiFiCare Network') ?? 'WiFiCare Network');
        $contact = (string) (Pengaturan::get('contact_email', '') ?? '');

        $meta = [];
        $meta[] = ['label' => 'Nama ISP', 'value' => $ispName];
        $meta[] = ['label' => 'Jenis Laporan', 'value' => LaporanService::jenisList()[$jenis]['label'] ?? $jenis];
        foreach (LaporanService::filterLabels($filters) as $item) {
            $meta[] = ['label' => (string) $item['label'], 'value' => (string) $item['value']];
        }
        if ($contact !== '') {
            $meta[] = ['label' => 'Kontak', 'value' => $contact];
        }

        return [
            'brand' => $appName . ' — ' . $ispName,
            'meta' => $meta,
        ];
    }

    /**
     * @param array<string, mixed> $metrik
     * @return array<int, array{label: string, value: string}>
     */
    private function summaryPairs(array $metrik): array
    {
        $persen = $metrik['persen_selesai'] === null ? '—' : $metrik['persen_selesai'] . '%';

        $pairs = [
            ['label' => 'Total Pengaduan', 'value' => (string) $metrik['total']],
            ['label' => 'Pengaduan Aktif', 'value' => (string) $metrik['aktif']],
            ['label' => 'Selesai', 'value' => (string) $metrik['selesai']],
            ['label' => 'Ditolak', 'value' => (string) $metrik['ditolak']],
            ['label' => 'Dibatalkan', 'value' => (string) $metrik['dibatalkan']],
            ['label' => 'Persentase Selesai', 'value' => $persen],
            ['label' => 'Rata-rata Penyelesaian', 'value' => LaporanService::formatMinutes($metrik['avg_durasi'])],
        ];

        if (rating_layanan_enabled()) {
            $pairs[] = [
                'label' => 'Rata-rata Rating',
                'value' => $metrik['avg_rating'] === null ? '—' : number_format((float) $metrik['avg_rating'], 1) . '/5',
            ];
        }

        return $pairs;
    }

    private function columnWeight(string $type): float
    {
        return match ($type) {
            'text' => 2.4,
            'datetime' => 1.7,
            'status' => 1.5,
            'minutes', 'minutes_number' => 1.3,
            'percent' => 1.1,
            'periode' => 1.7,
            default => 1.0,
        };
    }

    private static function columnWidth(string $type): float
    {
        return match ($type) {
            'text' => 28.0,
            'datetime' => 20.0,
            'status' => 20.0,
            'minutes', 'minutes_number' => 16.0,
            'percent' => 12.0,
            'rating' => 10.0,
            'rating_float' => 12.0,
            'periode' => 16.0,
            default => 14.0,
        };
    }
}
