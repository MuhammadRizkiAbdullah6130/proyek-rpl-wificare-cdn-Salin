<?php
/**
 * Admin — Dashboard.
 *
 * Seluruh statistik dan grafik berasal dari database (DashboardService).
 */

declare(strict_types=1);
$content = '';

$distribution = $distribution ?? [];
$trend = $trend ?? [];
$latest = $latest ?? [];
$teknisiPerformance = $teknisi_performance ?? [];
$activity = $activity ?? [];

$total = (int) ($total ?? 0);
$activeCount = (int) ($active_count ?? 0);
$selesai = (int) ($selesai ?? 0);
$ditolak = (int) ($ditolak ?? 0);
$hasData = $total > 0;

// Baris tabel pengaduan terbaru.
$latestRows = [];
foreach ($latest as $item) {
    $latestRows[] = [
        e((string) $item['nomor_tiket']),
        e((string) $item['nama_pelanggan_snapshot']),
        e((string) ($item['kategori'] ?? '—')),
        component_html('badge-status', ['status' => (string) $item['status']]),
        e(format_waktu((string) $item['created_at'])),
    ];
}

// Baris tabel kinerja teknisi.
$perfRows = [];
foreach ($teknisiPerformance as $row) {
    $perfRows[] = [
        e((string) $row['nama']),
        (string) (int) $row['total_tugas'],
        (string) (int) $row['aktif'],
        (string) (int) $row['selesai'],
    ];
}

// Baris tabel aktivitas sistem.
$activityRows = [];
foreach ($activity as $row) {
    $actor = $row['actor'] !== null
        ? '<span class="activity-actor">' . e((string) $row['actor']) . '</span>'
            . '<span class="activity-role">' . e(role_label((string) ($row['actor_role'] ?? ''))) . '</span>'
        : '<span class="activity-role">Sistem</span>';

    $activityRows[] = [
        e(format_waktu((string) $row['created_at'])),
        $actor,
        e((string) $row['aksi']),
    ];
}
?>
<?= component('page-header', [
    'title' => 'Dashboard Admin',
    'subtitle' => 'Ringkasan operasional pengaduan WiFiCare CDN.',
    'actions' => [
        ['label' => 'Lihat Laporan', 'url' => url('/admin/laporan'), 'icon' => 'report', 'variant' => 'outline-light'],
    ],
]) ?>

<div class="stat-grid">
    <?= component_html('stat-card', ['label' => 'Total Pengaduan', 'value' => (string) $total, 'icon' => 'ticket', 'hint' => 'Seluruh tiket (tanpa yang dibatalkan)', 'tone' => 'primary']) ?>
    <?= component_html('stat-card', ['label' => 'Tiket Aktif', 'value' => (string) $activeCount, 'icon' => 'activity', 'hint' => 'Verifikasi, CS, menunggu & penanganan', 'tone' => 'info']) ?>
    <?= component_html('stat-card', ['label' => 'Tiket Selesai', 'value' => (string) $selesai, 'icon' => 'check', 'hint' => 'Sudah diselesaikan', 'tone' => 'success']) ?>
    <?= component_html('stat-card', ['label' => 'Tiket Ditolak', 'value' => (string) $ditolak, 'icon' => 'x', 'hint' => 'Tidak dapat diproses', 'tone' => 'danger']) ?>
</div>

<div class="grid-2">
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
                <ul class="status-list mt-3">
                    <?php foreach (status_map() as $key => $meta): ?>
                        <li class="status-list-item">
                            <?= component_html('badge-status', ['status' => $key]) ?>
                            <span class="status-list-value"><strong><?= (int) ($distribution[$key] ?? 0) ?></strong></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <?= component_html('empty-state', [
                    'icon' => 'report',
                    'title' => 'Belum ada pengaduan',
                    'message' => 'Grafik distribusi status akan tampil setelah ada pengaduan masuk.',
                ]) ?>
            <?php endif; ?>
        </div>
    </section>

    <section class="card">
        <div class="card-head">
            <h3 class="card-title">Tren Pengaduan (14 Hari)</h3>
            <span class="card-head-note">Harian</span>
        </div>
        <div class="card-body">
            <?php if ($hasData): ?>
                <div class="chart-wrap">
                    <canvas id="chartTrend" role="img" aria-label="Grafik tren pengaduan harian"></canvas>
                </div>
            <?php else: ?>
                <?= component_html('empty-state', [
                    'icon' => 'activity',
                    'title' => 'Belum ada tren',
                    'message' => 'Tren pengaduan harian akan muncul setelah data tersedia.',
                ]) ?>
            <?php endif; ?>
        </div>
    </section>
</div>

<?= component('table', [
    'id' => 'admin-pengaduan-terbaru',
    'search' => false,
    'columns' => [
        ['label' => 'Nomor Tiket'],
        ['label' => 'Pelanggan'],
        ['label' => 'Kategori'],
        ['label' => 'Status'],
        ['label' => 'Diajukan'],
    ],
    'rows' => $latestRows,
    'empty' => [
        'icon' => 'inbox',
        'title' => 'Belum ada pengaduan',
        'message' => 'Pengaduan terbaru akan muncul di sini setelah pelanggan mengirim tiket.',
    ],
]) ?>

<div class="grid-2">
    <section>
        <?= component('table', [
            'id' => 'admin-kinerja-teknisi',
            'search' => false,
            'columns' => [
                ['label' => 'Teknisi'],
                ['label' => 'Total Tugas', 'align' => 'center'],
                ['label' => 'Aktif', 'align' => 'center'],
                ['label' => 'Selesai', 'align' => 'center'],
            ],
            'rows' => $perfRows,
            'empty' => [
                'icon' => 'tool',
                'title' => 'Belum ada data teknisi',
                'message' => 'Ringkasan kinerja teknisi akan tampil setelah ada penugasan.',
            ],
        ]) ?>
    </section>

    <section>
        <?= component('table', [
            'id' => 'admin-aktivitas-sistem',
            'search' => false,
            'columns' => [
                ['label' => 'Waktu'],
                ['label' => 'Aktor'],
                ['label' => 'Aksi'],
            ],
            'rows' => $activityRows,
            'empty' => [
                'icon' => 'shield',
                'title' => 'Belum ada aktivitas',
                'message' => 'Aktivitas penting Sistem akan tercatat di sini.',
            ],
        ]) ?>
    </section>
</div>

<?= chart_data_script([
    'status' => status_chart_data($distribution),
    'trend' => trend_chart_data($trend),
]) ?>
