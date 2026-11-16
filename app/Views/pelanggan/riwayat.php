<?php
/**
 * Pelanggan — Riwayat Pengaduan (Tahap 5).
 *
 * Server-side: pencarian nomor tiket, filter status, filter tanggal, dan
 * pagination. Hanya menampilkan tiket milik pelanggan yang sedang masuk.
 */

declare(strict_types=1);
$content = '';

$items = $items ?? [];
$total = (int) ($total ?? 0);
$pages = (int) ($pages ?? 1);
$page = (int) ($page ?? 1);
$filters = $filters ?? ['q' => '', 'status' => '', 'date_from' => '', 'date_to' => ''];
$flashSukses = $flash_sukses ?? null;
$flashError = $flash_error ?? null;

$query = array_filter([
    'q' => (string) ($filters['q'] ?? ''),
    'status' => (string) ($filters['status'] ?? ''),
    'date_from' => (string) ($filters['date_from'] ?? ''),
    'date_to' => (string) ($filters['date_to'] ?? ''),
], static fn ($value): bool => $value !== '');

$baseUrl = url('/pelanggan/pengaduan');

$rows = [];
foreach ($items as $item) {
    $badge = component_html('badge-status', ['status' => (string) $item['status']]);
    if ((int) ($item['is_cancelled'] ?? 0) === 1) {
        $badge .= ' <span class="badge-cancel">' . icon('x') . 'Dibatalkan</span>';
    }

    $selesai = !empty($item['completed_at']) ? format_tanggal((string) $item['completed_at']) : '—';

    $detail = '<a class="btn btn-outline-light btn-sm" href="'
        . e(url('/pelanggan/pengaduan/' . (int) $item['id'])) . '">'
        . icon('eye') . '<span>Detail</span></a>';

    $rows[] = [
        '<a class="link-inline" href="' . e(url('/pelanggan/pengaduan/' . (int) $item['id'])) . '">'
            . e((string) $item['nomor_tiket']) . '</a>',
        e(format_tanggal((string) $item['created_at'])),
        e((string) ($item['kategori'] ?? '—')),
        $badge,
        e($selesai),
        $detail,
    ];
}
?>
<?= component('page-header', [
    'title' => 'Riwayat Pengaduan',
    'subtitle' => 'Semua tiket yang pernah Anda ajukan beserta statusnya.',
    'actions' => [
        ['label' => 'Buat Pengaduan', 'url' => url('/pelanggan/pengaduan/baru'), 'icon' => 'plus', 'variant' => 'primary'],
    ],
]) ?>

<?php if ($flashSukses !== null): ?>
    <div class="callout callout--success"><?= icon('check') ?><p><?= e($flashSukses) ?></p></div>
<?php endif; ?>
<?php if ($flashError !== null): ?>
    <div class="callout callout--danger"><?= icon('alert') ?><p><?= e($flashError) ?></p></div>
<?php endif; ?>

<form class="filter-bar" method="get" action="<?= e($baseUrl) ?>" role="search">
    <div class="form-group form-group--grow">
        <label class="form-label" for="filter-q">Cari nomor tiket</label>
        <input class="form-control" type="search" id="filter-q" name="q"
               value="<?= e((string) ($filters['q'] ?? '')) ?>" placeholder="mis. WFC-20261016-000001">
    </div>

    <div class="form-group">
        <label class="form-label" for="filter-status">Status</label>
        <select class="form-select" id="filter-status" name="status">
            <option value="">Semua status</option>
            <?php foreach (status_map() as $key => $meta): ?>
                <option value="<?= e($key) ?>" <?= (string) ($filters['status'] ?? '') === $key ? 'selected' : '' ?>>
                    <?= e($meta['label']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="form-group">
        <label class="form-label" for="filter-dari">Dari tanggal</label>
        <input class="form-control" type="date" id="filter-dari" name="date_from"
               value="<?= e((string) ($filters['date_from'] ?? '')) ?>">
    </div>

    <div class="form-group">
        <label class="form-label" for="filter-sampai">Sampai tanggal</label>
        <input class="form-control" type="date" id="filter-sampai" name="date_to"
               value="<?= e((string) ($filters['date_to'] ?? '')) ?>">
    </div>

    <div class="filter-bar-actions">
        <button class="btn btn-primary" type="submit"><?= icon('search') ?><span>Terapkan</span></button>
        <a class="btn btn-outline-light" href="<?= e($baseUrl) ?>"><?= icon('refresh') ?><span>Reset</span></a>
    </div>
</form>

<?= component('table', [
    'id' => 'pelanggan-riwayat',
    'search' => false,
    'columns' => [
        ['label' => 'Nomor Tiket'],
        ['label' => 'Tanggal'],
        ['label' => 'Kategori'],
        ['label' => 'Status'],
        ['label' => 'Selesai'],
        ['label' => 'Aksi', 'align' => 'right'],
    ],
    'rows' => $rows,
    'empty' => [
        'icon' => 'inbox',
        'title' => 'Belum ada pengaduan',
        'message' => 'Tidak ada tiket yang cocok. Buat pengaduan baru bila Anda mengalami gangguan.',
        'action' => ['label' => 'Buat Pengaduan', 'url' => url('/pelanggan/pengaduan/baru'), 'icon' => 'plus'],
    ],
    'pagination' => $total > 0 ? [
        'page' => $page,
        'last' => $pages,
        'total' => $total,
        'baseUrl' => $baseUrl,
        'query' => $query,
    ] : null,
]) ?>
