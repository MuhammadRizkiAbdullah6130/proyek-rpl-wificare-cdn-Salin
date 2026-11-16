<?php
/**
 * Admin — Laporan & Analitik (Tahap 9 / Revisi 6 & 7).
 *
 * Layout ringkas: header, filter (Jenis Laporan + Periode), empat kartu
 * statistik, grafik proporsional dengan empty state, dan tabel detail dengan
 * tombol ekspor di bagian atas. Seluruh angka berasal dari MySQL (tanpa dummy).
 */

declare(strict_types=1);

$jenis = (string) ($jenis ?? 'semua');
$jenisList = $jenisList ?? LaporanService::jenisList();
$dataset = $dataset ?? [];
$filters = $filters ?? [];
$filterErrors = $filterErrors ?? [];
$filterLabels = $filterLabels ?? [];
$query = $query ?? ['jenis' => $jenis];

$columns = $dataset['columns'] ?? [];
$rows = $dataset['rows'] ?? [];
$summary = $dataset['summary'] ?? [];
$charts = $dataset['charts'] ?? [];
$notes = $dataset['notes'] ?? [];

// Rating Layanan dinonaktifkan sementara: sembunyikan ringkasan/kolom/grafik
// rating tanpa menyentuh dataset historis di database atau rumus laporan lain.
if (!rating_layanan_enabled()) {
    $summary = array_values(array_filter(
        is_array($summary) ? $summary : [],
        static fn (array $card): bool => stripos((string) ($card['label'] ?? ''), 'rating') === false
    ));

    if (is_array($charts)) {
        foreach (array_keys($charts) as $chartKey) {
            if (stripos((string) $chartKey, 'rating') !== false) {
                unset($charts[$chartKey]);
            }
        }
    }

    if (is_array($columns) && $columns !== []) {
        $keptIndexes = [];
        $keptColumns = [];
        foreach ($columns as $index => $column) {
            $type = (string) ($column['type'] ?? 'text');
            if (in_array($type, ['rating', 'rating_float'], true)) {
                continue;
            }
            $keptIndexes[] = $index;
            $keptColumns[] = $column;
        }

        if (count($keptColumns) !== count($columns)) {
            $columns = $keptColumns;
            $rows = is_array($rows) ? array_map(
                static fn (array $row): array => array_values(array_intersect_key($row, array_flip($keptIndexes))),
                $rows
            ) : [];
        }
    }
}

$baseUrl = url('/admin/laporan');
$exportQuery = http_build_query($query);
$pdfUrl = url('/admin/laporan/pdf') . '?' . $exportQuery;
$excelUrl = url('/admin/laporan/excel') . '?' . $exportQuery;

$rentangAktif = (string) ($filters['rentang'] ?? 'bulan_ini');
$rentangLabels = LaporanService::rentangLabels();
$tanggalKustom = $rentangAktif === 'pilih_tanggal';
$chartCount = is_array($charts) ? count($charts) : 0;
$singleChart = $chartCount === 1;
$statusTotal = 0;
if (isset($charts['status']['values']) && is_array($charts['status']['values'])) {
    $statusTotal = array_sum(array_map('intval', $charts['status']['values']));
}

/**
 * Render satu sel tabel sesuai tipenya.
 */
$cell = static function (mixed $value, string $type): string {
    if ($type === 'status') {
        return status_badge_html((string) $value, false);
    }
    if ($type === 'rating') {
        if ($value === '' || $value === null) {
            return '<span class="cell-muted">—</span>';
        }

        return '<span class="rating-inline">' . icon('star') . ' ' . e((string) $value) . '</span>';
    }

    return e(LaporanService::cellText($value, $type));
};

/**
 * Apakah sebuah data grafik punya nilai untuk dirender.
 */
$hasChart = static function (?array $chart): bool {
    if ($chart === null || empty($chart['values'])) {
        return false;
    }

    foreach ($chart['values'] as $value) {
        if ((float) $value > 0) {
            return true;
        }
    }

    return false;
};
?>
<div class="report-page">
<?= component('page-header', [
    'title' => 'Laporan & Analitik',
    'subtitle' => 'Ringkasan data operasional WiFiCare CDN.',
]) ?>

<?php if (!empty($filterErrors)): ?>
    <div class="alert-inline alert-inline--danger" role="alert">
        <?= icon('alert') ?>
        <span><?= e(implode(' ', $filterErrors)) ?></span>
    </div>
<?php endif; ?>

<section class="card report-filter-card" aria-labelledby="report-filter-title">
    <div class="card-body">
        <div class="report-filter-head">
            <div class="report-filter-heading">
                <h3 class="card-title" id="report-filter-title">Filter Laporan</h3>
                <p class="report-filter-note">Pilih jenis laporan dan periode data yang ingin ditampilkan.</p>
            </div>
            <div class="report-export-actions" aria-label="Ekspor laporan">
                <a class="btn btn-outline-light btn-sm" href="<?= e($pdfUrl) ?>"><?= icon('file') ?><span>Export PDF</span></a>
                <a class="btn btn-outline-light btn-sm" href="<?= e($excelUrl) ?>"><?= icon('download') ?><span>Export Excel</span></a>
            </div>
        </div>

        <form class="report-filter report-filter--compact<?= $tanggalKustom ? ' report-filter--custom' : '' ?>" method="get" action="<?= e($baseUrl) ?>" data-rentang-form>
            <div class="report-filter-main">
                <div class="report-filter-fields">
                    <div class="form-field">
                        <label class="form-label" for="f-jenis">Jenis Laporan</label>
                        <select class="form-select" id="f-jenis" name="jenis">
                            <?php foreach ($jenisList as $key => $meta): ?>
                                <option value="<?= e($key) ?>"<?= $key === $jenis ? ' selected' : '' ?>><?= e($meta['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-field">
                        <label class="form-label" for="f-rentang">Periode</label>
                        <select class="form-select" id="f-rentang" name="rentang" data-rentang-select>
                            <?php foreach ($rentangLabels as $key => $label): ?>
                                <option value="<?= e($key) ?>"<?= $rentangAktif === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-field<?= $tanggalKustom ? '' : ' is-hidden' ?>" data-rentang-custom>
                        <label class="form-label" for="f-date-from">Tanggal Mulai</label>
                        <input class="form-control" type="date" id="f-date-from" name="date_from" value="<?= e((string) ($filters['date_from'] ?? '')) ?>">
                    </div>
                    <div class="form-field<?= $tanggalKustom ? '' : ' is-hidden' ?>" data-rentang-custom>
                        <label class="form-label" for="f-date-to">Tanggal Akhir</label>
                        <input class="form-control" type="date" id="f-date-to" name="date_to" value="<?= e((string) ($filters['date_to'] ?? '')) ?>">
                    </div>
                </div>

                <div class="report-filter-actions">
                    <button class="btn btn-primary btn-sm" type="submit"><?= icon('filter') ?><span>Terapkan</span></button>
                    <a class="btn btn-outline-light btn-sm" href="<?= e($baseUrl . '?jenis=' . $jenis) ?>"><?= icon('refresh') ?><span>Reset</span></a>
                </div>
            </div>

            <?php if (!empty($filterLabels)): ?>
                <div class="report-filter-summary">
                    <span class="report-filter-summary-label"><?= icon('calendar') ?> Rentang aktif</span>
                    <div class="filter-chips">
                        <?php foreach ($filterLabels as $item): ?>
                            <span class="chip"><span class="chip-label"><?= e((string) $item['label']) ?>:</span> <?= e((string) $item['value']) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </form>
    </div>
</section>

<div class="stat-grid stat-grid--4 report-stat-grid">
    <?php foreach ($summary as $card): ?>
        <?= component('stat-card', [
            'label' => (string) $card['label'],
            'value' => (string) $card['value'],
            'icon' => (string) ($card['icon'] ?? 'report'),
            'tone' => (string) ($card['tone'] ?? 'primary'),
            'hint' => (string) ($card['hint'] ?? ''),
            'empty' => (bool) ($card['empty'] ?? false),
        ]) ?>
    <?php endforeach; ?>
</div>

<?php if (!empty($charts)): ?>
    <div class="report-charts<?= $singleChart ? ' report-charts--single' : '' ?>">
        <?php if (isset($charts['status'])): ?>
            <section class="card report-chart-card report-chart-card--status">
                <div class="card-head">
                    <h3 class="card-title">Distribusi Status</h3>
                    <?php if ($hasChart($charts['status'])): ?>
                        <span class="card-head-note"><?= e((string) $statusTotal) ?> tiket</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if ($hasChart($charts['status'])): ?>
                        <div class="chart-wrap chart-wrap--doughnut"><canvas id="chartStatus" role="img" aria-label="Distribusi status pengaduan"></canvas></div>
                    <?php else: ?>
                        <?= component_html('empty-state', ['icon' => 'report', 'title' => 'Belum ada data', 'message' => 'Grafik distribusi status muncul setelah ada pengaduan pada filter ini.']) ?>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if (isset($charts['trend'])): ?>
            <section class="card report-chart-card">
                <div class="card-head"><h3 class="card-title">Tren Pengaduan</h3></div>
                <div class="card-body">
                    <?php if ($hasChart($charts['trend'])): ?>
                        <div class="chart-wrap"><canvas id="chartTrend" role="img" aria-label="Tren jumlah pengaduan"></canvas></div>
                    <?php else: ?>
                        <?= component_html('empty-state', ['icon' => 'activity', 'title' => 'Belum ada data', 'message' => 'Tren pengaduan muncul setelah ada data pada periode ini.']) ?>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if (isset($charts['kategori'])): ?>
            <section class="card report-chart-card">
                <div class="card-head"><h3 class="card-title">Kategori Terbanyak</h3></div>
                <div class="card-body">
                    <?php if ($hasChart($charts['kategori'])): ?>
                        <div class="chart-wrap"><canvas id="chartKategori" role="img" aria-label="Distribusi kategori gangguan"></canvas></div>
                    <?php else: ?>
                        <?= component_html('empty-state', ['icon' => 'tag', 'title' => 'Belum ada data', 'message' => 'Distribusi kategori muncul setelah ada pengaduan.']) ?>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if (isset($charts['teknisi'])): ?>
            <section class="card report-chart-card">
                <div class="card-head"><h3 class="card-title">Penyelesaian per Teknisi</h3></div>
                <div class="card-body">
                    <?php if ($hasChart($charts['teknisi'])): ?>
                        <div class="chart-wrap"><canvas id="chartTeknisi" role="img" aria-label="Jumlah tugas per teknisi"></canvas></div>
                    <?php else: ?>
                        <?= component_html('empty-state', ['icon' => 'tool', 'title' => 'Belum ada data', 'message' => 'Data teknisi muncul setelah ada penugasan.']) ?>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if (isset($charts['ratingTren'])): ?>
            <section class="card report-chart-card">
                <div class="card-head"><h3 class="card-title">Tren Rating Layanan</h3></div>
                <div class="card-body">
                    <?php if ($hasChart($charts['ratingTren'])): ?>
                        <div class="chart-wrap"><canvas id="chartRatingTren" role="img" aria-label="Tren rating layanan per bulan"></canvas></div>
                    <?php else: ?>
                        <?= component_html('empty-state', ['icon' => 'star', 'title' => 'Belum ada data', 'message' => 'Tren rating muncul setelah ada penilaian pelanggan.']) ?>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if (isset($charts['ratingDist'])): ?>
            <section class="card report-chart-card">
                <div class="card-head"><h3 class="card-title">Distribusi Bintang</h3></div>
                <div class="card-body">
                    <?php if ($hasChart($charts['ratingDist'])): ?>
                        <div class="chart-wrap"><canvas id="chartRatingDist" role="img" aria-label="Distribusi jumlah bintang rating"></canvas></div>
                    <?php else: ?>
                        <?= component_html('empty-state', ['icon' => 'star', 'title' => 'Belum ada data', 'message' => 'Distribusi bintang muncul setelah ada rating.']) ?>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php foreach ($notes as $note): ?>
    <div class="alert-inline alert-inline--info" role="status"><?= icon('info') ?><span><?= e((string) $note) ?></span></div>
<?php endforeach; ?>

<section class="table-card report-table-card">
    <div class="table-toolbar">
        <h3 class="card-title"><?= e((string) ($dataset['title'] ?? 'Laporan')) ?></h3>
        <div class="table-toolbar-actions ms-auto">
            <span class="card-head-note">
                <?= e((string) ($dataset['row_count'] ?? count($rows))) ?> baris
                <?php if (($dataset['jenis'] ?? '') === 'semua'): ?>
                    dari <?= e((string) ($dataset['total'] ?? 0)) ?> tiket
                <?php endif; ?>
            </span>
            <a class="btn btn-outline-light btn-sm" href="<?= e($pdfUrl) ?>"><?= icon('file') ?><span>Export PDF</span></a>
            <a class="btn btn-outline-light btn-sm" href="<?= e($excelUrl) ?>"><?= icon('download') ?><span>Export Excel</span></a>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table app-table">
            <thead>
                <tr>
                    <?php foreach ($columns as $column): ?>
                        <th scope="col" class="text-<?= e((string) ($column['align'] ?? 'left')) ?>"><?= e((string) $column['label']) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr class="table-empty-row">
                        <td colspan="<?= count($columns) > 0 ? count($columns) : 1 ?>">
                            <?= component_html('empty-state', ['icon' => 'report', 'title' => 'Belum ada data', 'message' => 'Tidak ada data yang cocok dengan filter ini.']) ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <?php foreach ($columns as $index => $column): ?>
                                <td class="text-<?= e((string) ($column['align'] ?? 'left')) ?>"><?= $cell($row[$index] ?? '', (string) $column['type']) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if (($dataset['jenis'] ?? '') === 'semua' && (int) ($dataset['pages'] ?? 1) > 1): ?>
        <?= component_html('pagination', [
            'page' => (int) ($dataset['page'] ?? 1),
            'last' => (int) ($dataset['pages'] ?? 1),
            'total' => (int) ($dataset['total'] ?? 0),
            'baseUrl' => $baseUrl,
            'query' => $query,
        ]) ?>
    <?php endif; ?>
</section>
</div>

<?= chart_data_script($charts) ?>
