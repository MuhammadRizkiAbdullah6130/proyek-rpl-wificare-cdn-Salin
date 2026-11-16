<?php
/**
 * Customer Service — Monitoring Pengaduan (Tahap 6).
 *
 * Ringkasan jumlah tiket, filter status/teknisi/tanggal, pencarian, dan
 * kolom tanggal selesai. Tiket yang dibatalkan ditandai terpisah.
 */

declare(strict_types=1);
$content = '';

$items = $items ?? [];
$total = (int) ($total ?? 0);
$pages = (int) ($pages ?? 1);
$page = (int) ($page ?? 1);
$filters = $filters ?? [];
$kategoriOptions = $kategoriOptions ?? [];
$teknisiOptions = $teknisiOptions ?? [];
$distribution = $distribution ?? [];
$cancelledCount = (int) ($cancelledCount ?? 0);
$flashSukses = $flash_sukses ?? null;
$flashError = $flash_error ?? null;

$baseUrl = url('/cs/monitoring');
$query = array_filter([
    'q' => (string) ($filters['q'] ?? ''),
    'status' => (string) ($filters['status'] ?? ''),
    'teknisi_id' => (int) ($filters['teknisi_id'] ?? 0) > 0 ? (string) (int) $filters['teknisi_id'] : '',
    'date_from' => (string) ($filters['date_from'] ?? ''),
    'date_to' => (string) ($filters['date_to'] ?? ''),
    'cancel' => !empty($filters['only_cancelled']) ? 'batal' : '',
], static fn ($value): bool => $value !== '');

$summaryTotal = 0;
foreach (status_map() as $key => $meta) {
    $summaryTotal += (int) ($distribution[$key] ?? 0);
}

$rows = [];
foreach ($items as $item) {
    $id = (int) $item['id'];
    $link = url('/cs/pengaduan/' . $id);

    $rows[] = [
        '<a class="link-inline" href="' . e($link) . '">' . e((string) $item['nomor_tiket']) . '</a>',
        '<div class="cell-stack"><span class="cell-strong">' . e((string) $item['nama_pelanggan_snapshot'])
            . '</span><span class="cell-muted">' . e((string) $item['username_pppoe_snapshot']) . '</span></div>',
        e((string) ($item['teknisi_nama'] ?? '—')),
        status_badge_html((string) $item['status'], (int) $item['is_cancelled'] === 1),
        e(format_waktu((string) $item['created_at'])),
        e(!empty($item['completed_at']) ? format_waktu((string) $item['completed_at']) : '—'),
        '<div class="table-actions"><a class="btn btn-outline-light btn-sm" href="' . e($link) . '">'
            . icon('eye') . '<span>Pantau</span></a></div>',
    ];
}
?>
<?= component('page-header', [
    'title' => 'Monitoring Pengaduan',
    'subtitle' => 'Pantau progres setiap tiket dan kinerja penugasan teknisi.',
]) ?>

<?php if ($flashSukses !== null): ?>
    <div class="callout callout--success"><?= icon('check') ?><p><?= e($flashSukses) ?></p></div>
<?php endif; ?>
<?php if ($flashError !== null): ?>
    <div class="callout callout--danger"><?= icon('alert') ?><p><?= e($flashError) ?></p></div>
<?php endif; ?>

<div class="summary-chips">
    <span class="summary-chip"><?= icon('ticket') ?>Total aktif <strong><?= e((string) $summaryTotal) ?></strong></span>
    <?php foreach (status_map() as $key => $meta): ?>
        <span class="summary-chip"><?= e($meta['label']) ?> <strong><?= e((string) (int) ($distribution[$key] ?? 0)) ?></strong></span>
    <?php endforeach; ?>
    <span class="summary-chip"><?= icon('x') ?>Dibatalkan <strong><?= e((string) $cancelledCount) ?></strong></span>
</div>

<form class="filter-bar" method="get" action="<?= e($baseUrl) ?>" role="search">
    <div class="form-group form-group--grow">
        <label class="form-label" for="cs-q">Cari nomor tiket / pelanggan</label>
        <input class="form-control" type="search" id="cs-q" name="q"
               value="<?= e((string) ($filters['q'] ?? '')) ?>" placeholder="mis. WFC-20261016-000001">
    </div>

    <div class="form-group">
        <label class="form-label" for="cs-status">Status</label>
        <select class="form-select" id="cs-status" name="status">
            <option value="">Semua status</option>
            <?php foreach (status_map() as $key => $meta): ?>
                <option value="<?= e($key) ?>" <?= (string) ($filters['status'] ?? '') === $key ? 'selected' : '' ?>>
                    <?= e($meta['label']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="form-group">
        <label class="form-label" for="cs-teknisi">Teknisi</label>
        <select class="form-select" id="cs-teknisi" name="teknisi_id">
            <option value="">Semua teknisi</option>
            <?php foreach ($teknisiOptions as $teknisi): ?>
                <option value="<?= e((string) $teknisi['id']) ?>"
                    <?= (int) ($filters['teknisi_id'] ?? 0) === (int) $teknisi['id'] ? 'selected' : '' ?>>
                    <?= e((string) $teknisi['nama']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="form-group">
        <label class="form-label" for="cs-dari">Dari tanggal</label>
        <input class="form-control" type="date" id="cs-dari" name="date_from"
               value="<?= e((string) ($filters['date_from'] ?? '')) ?>">
    </div>

    <div class="form-group">
        <label class="form-label" for="cs-sampai">Sampai tanggal</label>
        <input class="form-control" type="date" id="cs-sampai" name="date_to"
               value="<?= e((string) ($filters['date_to'] ?? '')) ?>">
    </div>

    <div class="form-group">
        <label class="form-label" for="cs-cancel">Pembatalan</label>
        <select class="form-select" id="cs-cancel" name="cancel">
            <option value="">Termasuk dibatalkan</option>
            <option value="batal" <?= !empty($filters['only_cancelled']) ? 'selected' : '' ?>>Hanya dibatalkan</option>
        </select>
    </div>

    <div class="filter-bar-actions">
        <button class="btn btn-primary" type="submit"><?= icon('search') ?><span>Terapkan</span></button>
        <a class="btn btn-outline-light" href="<?= e($baseUrl) ?>"><?= icon('refresh') ?><span>Reset</span></a>
    </div>
</form>

<?= component('table', [
    'id' => 'cs-monitoring',
    'search' => false,
    'columns' => [
        ['label' => 'Nomor Tiket'],
        ['label' => 'Pelanggan'],
        ['label' => 'Teknisi'],
        ['label' => 'Status'],
        ['label' => 'Dibuat'],
        ['label' => 'Selesai'],
        ['label' => 'Aksi', 'align' => 'right'],
    ],
    'rows' => $rows,
    'empty' => [
        'icon' => 'activity',
        'title' => 'Belum ada data',
        'message' => 'Tidak ada tiket yang cocok dengan filter monitoring.',
    ],
    'pagination' => $total > 0 ? [
        'page' => $page,
        'last' => $pages,
        'total' => $total,
        'baseUrl' => $baseUrl,
        'query' => $query,
    ] : null,
]) ?>
