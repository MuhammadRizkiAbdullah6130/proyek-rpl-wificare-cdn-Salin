<?php
/**
 * Customer Service — Dashboard.
 *
 * Membaca pengaduan operasional. Membuka dashboard tidak mengubah status tiket.
 */

declare(strict_types=1);
$content = '';

$distribution = $distribution ?? [];
$antrian = $antrian ?? [];
$latest = $latest ?? [];
$teknisiPerformance = $teknisi_performance ?? [];

$menungguVerifikasi = (int) ($menunggu_verifikasi ?? 0);
$diprosesCs = (int) ($diproses_cs ?? 0);
$menungguTeknisi = (int) ($menunggu_teknisi ?? 0);
$selesai = (int) ($selesai ?? 0);
$total = (int) ($total ?? 0);
$hasData = $total > 0;

$antrianRows = [];
foreach ($antrian as $item) {
    $link = url('/cs/pengaduan/' . (int) $item['id']);
    $antrianRows[] = [
        '<a class="link-inline" href="' . e($link) . '">' . e((string) $item['nomor_tiket']) . '</a>',
        e((string) $item['nama_pelanggan_snapshot']),
        e((string) ($item['kategori'] ?? '—')),
        e(format_waktu((string) $item['created_at'])),
        '<div class="table-actions"><a class="btn btn-primary btn-sm" href="' . e($link) . '">' . icon('check') . '<span>Verifikasi</span></a></div>',
    ];
}

$latestRows = [];
foreach ($latest as $item) {
    $link = url('/cs/pengaduan/' . (int) $item['id']);
    $latestRows[] = [
        '<a class="link-inline" href="' . e($link) . '">' . e((string) $item['nomor_tiket']) . '</a>',
        e((string) $item['nama_pelanggan_snapshot']),
        e((string) ($item['kategori'] ?? '—')),
        status_badge_html((string) $item['status'], (int) ($item['is_cancelled'] ?? 0) === 1),
        e(format_waktu((string) $item['created_at'])),
        '<div class="table-actions"><a class="btn btn-outline-light btn-sm" href="' . e($link) . '">' . icon('eye') . '<span>Detail</span></a></div>',
    ];
}

$perfRows = [];
foreach ($teknisiPerformance as $row) {
    $perfRows[] = [
        e((string) $row['nama']),
        (string) (int) $row['total_tugas'],
        (string) (int) $row['aktif'],
        (string) (int) $row['selesai'],
    ];
}
?>
<?= component('page-header', [
    'title' => 'Dashboard Customer Service',
    'subtitle' => 'Pantau pengaduan baru, verifikasi, penugasan, dan penyelesaian.',
    'actions' => [
        ['label' => 'Pengaduan Masuk', 'url' => url('/cs/pengaduan'), 'icon' => 'inbox', 'variant' => 'primary'],
    ],
]) ?>

<div class="stat-grid">
    <?= component_html('stat-card', ['label' => 'Menunggu Verifikasi', 'value' => (string) $menungguVerifikasi, 'icon' => 'inbox', 'hint' => 'Perlu diverifikasi CS', 'tone' => 'warning']) ?>
    <?= component_html('stat-card', ['label' => 'Diproses CS', 'value' => (string) $diprosesCs, 'icon' => 'activity', 'hint' => 'Sedang ditangani CS', 'tone' => 'info']) ?>
    <?= component_html('stat-card', ['label' => 'Menunggu Teknisi', 'value' => (string) $menungguTeknisi, 'icon' => 'clock', 'hint' => 'Sudah diverifikasi, belum ditangani', 'tone' => 'primary']) ?>
    <?= component_html('stat-card', ['label' => 'Selesai', 'value' => (string) $selesai, 'icon' => 'check', 'hint' => 'Pengaduan selesai', 'tone' => 'success']) ?>
</div>

<div class="grid-2">
    <section>
        <?= component('table', [
            'id' => 'cs-antrian-verifikasi',
            'search' => false,
            'columns' => [
                ['label' => 'Nomor Tiket'],
                ['label' => 'Pelanggan'],
                ['label' => 'Kategori'],
                ['label' => 'Diajukan'],
                ['label' => 'Aksi', 'align' => 'right'],
            ],
            'rows' => $antrianRows,
            'empty' => [
                'icon' => 'check',
                'title' => 'Belum ada antrian',
                'message' => 'Pengaduan dengan status Menunggu Verifikasi akan tampil di sini.',
                'action' => ['label' => 'Lihat Pengaduan Masuk', 'url' => url('/cs/pengaduan'), 'icon' => 'inbox'],
            ],
        ]) ?>
    </section>

    <section class="card">
        <div class="card-head">
            <h3 class="card-title">Aksi Cepat</h3>
        </div>
        <div class="card-body">
            <div class="quick-actions">
                <a class="quick-action" href="<?= e(url('/cs/verifikasi')) ?>"><?= icon('check') ?><span>Verifikasi Tiket</span></a>
                <a class="quick-action" href="<?= e(url('/cs/monitoring')) ?>"><?= icon('activity') ?><span>Monitoring Pengaduan</span></a>
                <a class="quick-action" href="<?= e(url('/cs/penugasan')) ?>"><?= icon('tool') ?><span>Tugaskan Teknisi</span></a>
                <a class="quick-action" href="<?= e(url('/cs/pelanggan')) ?>"><?= icon('search') ?><span>Cari Pelanggan</span></a>
            </div>
        </div>
    </section>
</div>

<?= component('table', [
    'id' => 'cs-terbaru',
    'search' => false,
    'columns' => [
        ['label' => 'Nomor Tiket'],
        ['label' => 'Pelanggan'],
        ['label' => 'Kategori'],
        ['label' => 'Status'],
        ['label' => 'Diajukan'],
        ['label' => 'Aksi', 'align' => 'right'],
    ],
    'rows' => $latestRows,
    'empty' => ['icon' => 'ticket', 'title' => 'Belum ada pengaduan', 'message' => 'Tiket terbaru akan tampil setelah pelanggan mengirim pengaduan.'],
]) ?>

<div class="grid-2">
    <section>
        <?= component('table', [
            'id' => 'cs-kinerja-teknisi',
            'search' => false,
            'columns' => [
                ['label' => 'Teknisi'],
                ['label' => 'Total', 'align' => 'center'],
                ['label' => 'Aktif', 'align' => 'center'],
                ['label' => 'Selesai', 'align' => 'center'],
            ],
            'rows' => $perfRows,
            'empty' => [
                'icon' => 'tool',
                'title' => 'Belum ada data teknisi',
                'message' => 'Ringkasan penanganan teknisi akan tampil setelah ada penugasan.',
            ],
        ]) ?>
    </section>

    <section class="card">
        <div class="card-head">
            <h3 class="card-title">Distribusi Status</h3>
            <span class="card-head-note"><?= e((string) $total) ?> tiket</span>
        </div>
        <div class="card-body">
            <?php if ($hasData): ?>
                <div class="chart-wrap">
                    <canvas id="chartStatus" role="img" aria-label="Grafik distribusi status pengaduan"></canvas>
                </div>
            <?php else: ?>
                <?= component_html('empty-state', [
                    'icon' => 'report',
                    'title' => 'Belum ada pengaduan',
                    'message' => 'Grafik akan tampil setelah ada pengaduan masuk.',
                ]) ?>
            <?php endif; ?>
        </div>
    </section>
</div>

<?= chart_data_script([
    'status' => status_chart_data($distribution),
]) ?>
