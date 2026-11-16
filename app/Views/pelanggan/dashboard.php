<?php
/**
 * Pelanggan — Dashboard.
 *
 * Hanya menampilkan pengaduan milik pelanggan yang sedang masuk
 * (ID pelanggan diambil dari session, bukan dari query string).
 */

declare(strict_types=1);
$content = '';

$distribution = $distribution ?? [];
$latest = $latest ?? [];
$latestTicket = $latest_ticket ?? null;

$total = (int) ($total ?? 0);
$activeCount = (int) ($active_count ?? 0);
$selesai = (int) ($selesai ?? 0);
$ditolak = (int) ($ditolak ?? 0);

$rows = [];
foreach ($latest as $item) {
    $ticketUrl = url('/pelanggan/pengaduan/' . (int) $item['id']);
    $rows[] = [
        '<a class="link-inline" href="' . e($ticketUrl) . '">' . e((string) $item['nomor_tiket']) . '</a>',
        e((string) ($item['kategori'] ?? '—')),
        component_html('badge-status', ['status' => (string) $item['status']]),
        e(format_waktu((string) $item['created_at'])),
    ];
}
?>
<?= component('page-header', [
    'title' => 'Halo, ' . e((string) ($user['nama'] ?? 'Pelanggan')),
    'subtitle' => 'Pantau status pengaduan Anda dan kirim pengaduan baru.',
    'actions' => [
        ['label' => 'Buat Pengaduan', 'url' => url('/pelanggan/pengaduan/baru'), 'icon' => 'plus', 'variant' => 'primary'],
        ['label' => 'Lihat Riwayat', 'url' => url('/pelanggan/pengaduan'), 'icon' => 'ticket', 'variant' => 'outline-light'],
    ],
]) ?>

<div class="stat-grid">
    <?= component_html('stat-card', ['label' => 'Total Pengaduan', 'value' => (string) $total, 'icon' => 'ticket', 'hint' => 'Seluruh tiket Anda', 'tone' => 'primary']) ?>
    <?= component_html('stat-card', ['label' => 'Tiket Aktif', 'value' => (string) $activeCount, 'icon' => 'activity', 'hint' => 'Sedang diproses', 'tone' => 'info']) ?>
    <?= component_html('stat-card', ['label' => 'Tiket Selesai', 'value' => (string) $selesai, 'icon' => 'check', 'hint' => 'Sudah diselesaikan', 'tone' => 'success']) ?>
    <?= component_html('stat-card', ['label' => 'Tiket Ditolak', 'value' => (string) $ditolak, 'icon' => 'x', 'hint' => 'Tidak dapat diproses', 'tone' => 'danger']) ?>
</div>

<div class="grid-2">
    <section class="card">
        <div class="card-head">
            <h3 class="card-title">Status Pengaduan Terbaru</h3>
        </div>
        <div class="card-body">
            <?php if (is_array($latestTicket)): ?>
                <p class="page-subtitle">
                    Tiket <strong><?= e((string) $latestTicket['nomor_tiket']) ?></strong>
                    — <?= e((string) ($latestTicket['kategori'] ?? 'Tanpa kategori')) ?>
                </p>
                <p><?= component_html('badge-status', ['status' => (string) $latestTicket['status']]) ?></p>
                <p class="card-head-note">Diajukan <?= e(format_waktu((string) $latestTicket['created_at'])) ?></p>
                <a class="btn btn-outline-light btn-sm" href="<?= e(url('/pelanggan/pengaduan/' . (int) $latestTicket['id'])) ?>">
                    <?= icon('eye') ?><span>Lihat Detail Tiket</span>
                </a>
            <?php else: ?>
                <?= component_html('empty-state', [
                    'icon' => 'ticket',
                    'title' => 'Belum ada pengaduan',
                    'message' => 'Anda belum pernah mengirim pengaduan. Mulai dengan membuat pengaduan baru.',
                    'action' => ['label' => 'Buat Pengaduan Baru', 'url' => url('/pelanggan/pengaduan/baru'), 'icon' => 'plus'],
                ]) ?>
            <?php endif; ?>
        </div>
    </section>

    <section class="card">
        <div class="card-head">
            <h3 class="card-title">Rincian Status</h3>
            <span class="card-head-note"><?= e((string) $total) ?> tiket</span>
        </div>
        <div class="card-body">
            <ul class="status-list">
                <?php foreach (status_map() as $key => $meta): ?>
                    <li class="status-list-item">
                        <?= component_html('badge-status', ['status' => $key]) ?>
                        <span class="status-list-value"><strong><?= (int) ($distribution[$key] ?? 0) ?></strong></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </section>
</div>

<?= component('table', [
    'id' => 'pelanggan-pengaduan-terbaru',
    'search' => false,
    'columns' => [
        ['label' => 'Nomor Tiket'],
        ['label' => 'Kategori'],
        ['label' => 'Status'],
        ['label' => 'Diajukan'],
    ],
    'rows' => $rows,
    'empty' => [
        'icon' => 'inbox',
        'title' => 'Belum ada pengaduan',
        'message' => 'Pengaduan yang Anda kirim akan tampil di sini.',
        'action' => ['label' => 'Buat Pengaduan Baru', 'url' => url('/pelanggan/pengaduan/baru'), 'icon' => 'plus'],
    ],
]) ?>
