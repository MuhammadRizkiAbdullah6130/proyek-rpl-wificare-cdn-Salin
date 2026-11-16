<?php
/**
 * Teknisi — Dashboard.
 *
 * Hanya menampilkan tugas milik teknisi yang sedang masuk (difilter dari session).
 */

declare(strict_types=1);
$content = '';

$distribution = $distribution ?? [];
$latest = $latest ?? [];
$kategori = $kategori ?? [];
$reports = $reports ?? [];

$total = (int) ($total ?? 0);
$menungguTeknisi = (int) ($menunggu_teknisi ?? 0);
$dalamPenanganan = (int) ($dalam_penanganan ?? 0);
$selesai = (int) ($selesai ?? 0);
$selesaiHariIni = (int) ($completed_today ?? 0);
$hasData = $total > 0;

$taskRows = [];
foreach ($latest as $item) {
    $detailUrl = url('/teknisi/tugas/' . (int) $item['id']);
    $taskRows[] = [
        '<a class="link-inline" href="' . e($detailUrl) . '">' . e((string) $item['nomor_tiket']) . '</a>',
        e((string) $item['nama_pelanggan_snapshot']),
        e((string) ($item['kategori'] ?? '—')),
        component_html('badge-status', ['status' => (string) $item['status']]),
        '<a class="btn btn-sm btn-outline-light" href="' . e($detailUrl) . '">' . icon('eye') . '<span>Detail</span></a>',
    ];
}

$kategoriRows = [];
foreach ($kategori as $row) {
    $kategoriRows[] = [
        e((string) $row['nama']),
        (string) (int) $row['jumlah'],
    ];
}

$reportRows = [];
foreach ($reports as $row) {
    $reportRows[] = [
        e((string) $row['nomor_tiket']),
        e((string) $row['nama_pelanggan_snapshot']),
        e(format_waktu((string) $row['completed_at'])),
    ];
}
?>
<?= component('page-header', [
    'title' => 'Dashboard Teknisi',
    'subtitle' => 'Ringkasan tugas yang ditugaskan kepada Anda.',
    'actions' => [
        ['label' => 'Lihat Tugas', 'url' => url('/teknisi/tugas'), 'icon' => 'ticket', 'variant' => 'primary'],
        ['label' => 'Riwayat Pekerjaan', 'url' => url('/teknisi/riwayat'), 'icon' => 'report', 'variant' => 'outline-light'],
    ],
]) ?>

<div class="stat-grid">
    <?= component_html('stat-card', ['label' => 'Total Tugas', 'value' => (string) $total, 'icon' => 'ticket', 'hint' => 'Ditugaskan kepada Anda', 'tone' => 'primary']) ?>
    <?= component_html('stat-card', ['label' => 'Menunggu Teknisi', 'value' => (string) $menungguTeknisi, 'icon' => 'clock', 'hint' => 'Belum mulai dikerjakan', 'tone' => 'warning']) ?>
    <?= component_html('stat-card', ['label' => 'Dalam Penanganan', 'value' => (string) $dalamPenanganan, 'icon' => 'activity', 'hint' => 'Sedang dikerjakan', 'tone' => 'info']) ?>
    <?= component_html('stat-card', ['label' => 'Selesai', 'value' => (string) $selesai, 'icon' => 'check', 'hint' => $selesaiHariIni . ' selesai hari ini', 'tone' => 'success']) ?>
</div>

<div class="grid-2">
    <section>
        <?= component('table', [
            'id' => 'teknisi-tugas-terbaru',
            'search' => false,
            'columns' => [
                ['label' => 'Nomor Tiket'],
                ['label' => 'Pelanggan'],
                ['label' => 'Kategori'],
                ['label' => 'Status'],
                ['label' => 'Aksi', 'align' => 'right'],
            ],
            'rows' => $taskRows,
            'empty' => [
                'icon' => 'ticket',
                'title' => 'Belum ada tugas',
                'message' => 'Tugas akan tampil setelah Customer Service menugaskan tiket kepada Anda.',
                'action' => ['label' => 'Buka Tugas Saya', 'url' => url('/teknisi/tugas'), 'icon' => 'ticket'],
            ],
        ]) ?>
    </section>

    <section class="card">
        <div class="card-head">
            <h3 class="card-title">Status Tugas Anda</h3>
            <span class="card-head-note"><?= e((string) $total) ?> tugas</span>
        </div>
        <div class="card-body">
            <?php if ($hasData): ?>
                <div class="chart-wrap">
                    <canvas id="chartTaskStatus" role="img" aria-label="Grafik distribusi status tugas"></canvas>
                </div>
            <?php else: ?>
                <?= component_html('empty-state', [
                    'icon' => 'report',
                    'title' => 'Belum ada tugas',
                    'message' => 'Grafik status tugas akan tampil setelah ada penugasan.',
                ]) ?>
            <?php endif; ?>
        </div>
    </section>
</div>

<div class="grid-2">
    <section>
        <?= component('table', [
            'id' => 'teknisi-kategori',
            'search' => false,
            'columns' => [
                ['label' => 'Kategori Gangguan'],
                ['label' => 'Jumlah Tugas', 'align' => 'center'],
            ],
            'rows' => $kategoriRows,
            'empty' => [
                'icon' => 'tag',
                'title' => 'Belum ada kategori',
                'message' => 'Informasi kategori gangguan akan tampil setelah Anda memiliki tugas.',
            ],
        ]) ?>
    </section>

    <section>
        <?= component('table', [
            'id' => 'teknisi-riwayat-singkat',
            'search' => false,
            'columns' => [
                ['label' => 'Nomor Tiket'],
                ['label' => 'Pelanggan'],
                ['label' => 'Selesai'],
            ],
            'rows' => $reportRows,
            'empty' => [
                'icon' => 'upload',
                'title' => 'Belum ada laporan',
                'message' => 'Ringkasan pekerjaan terbaru akan tampil setelah Anda mengirim laporan perbaikan.',
            ],
        ]) ?>
    </section>
</div>

<?= chart_data_script([
    'task' => status_chart_data($distribution),
]) ?>
