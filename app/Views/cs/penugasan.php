<?php
/**
 * Customer Service — Penugasan Teknisi (Tahap 6).
 *
 * Antrian tiket berstatus Diproses CS yang siap ditugaskan ke teknisi.
 * Penugasan dilakukan dari halaman detail tiket.
 */

declare(strict_types=1);
$content = '';

$items = $items ?? [];
$total = (int) ($total ?? 0);
$pages = (int) ($pages ?? 1);
$page = (int) ($page ?? 1);
$filters = $filters ?? [];
$flashSukses = $flash_sukses ?? null;
$flashError = $flash_error ?? null;

$baseUrl = url('/cs/penugasan');
$query = array_filter([
    'q' => (string) ($filters['q'] ?? ''),
    'date_from' => (string) ($filters['date_from'] ?? ''),
    'date_to' => (string) ($filters['date_to'] ?? ''),
], static fn ($value): bool => $value !== '');

$rows = [];
foreach ($items as $item) {
    $id = (int) $item['id'];
    $link = url('/cs/pengaduan/' . $id);

    $rows[] = [
        '<a class="link-inline" href="' . e($link) . '">' . e((string) $item['nomor_tiket']) . '</a>',
        '<div class="cell-stack"><span class="cell-strong">' . e((string) $item['nama_pelanggan_snapshot'])
            . '</span><span class="cell-muted">' . e((string) $item['username_pppoe_snapshot']) . '</span></div>',
        e((string) ($item['kategori'] ?? '—')),
        e(format_waktu((string) $item['created_at'])),
        status_badge_html((string) $item['status'], (int) $item['is_cancelled'] === 1),
        '<div class="table-actions"><a class="btn btn-primary btn-sm" href="' . e($link) . '">'
            . icon('tool') . '<span>Tugaskan</span></a></div>',
    ];
}
?>
<?= component('page-header', [
    'title' => 'Penugasan Teknisi',
    'subtitle' => 'Pilih tiket terverifikasi lalu tugaskan teknisi aktif.',
]) ?>

<?php if ($flashSukses !== null): ?>
    <div class="callout callout--success"><?= icon('check') ?><p><?= e($flashSukses) ?></p></div>
<?php endif; ?>
<?php if ($flashError !== null): ?>
    <div class="callout callout--danger"><?= icon('alert') ?><p><?= e($flashError) ?></p></div>
<?php endif; ?>

<?php if ($total === 0): ?>
    <?= component('empty-state', [
        'icon' => 'tool',
        'title' => 'Tidak ada tiket siap ditugaskan',
        'message' => 'Tiket berstatus Diproses CS akan tampil di sini untuk ditugaskan ke teknisi.',
    ]) ?>
<?php else: ?>
    <form class="filter-bar" method="get" action="<?= e($baseUrl) ?>" role="search">
        <div class="form-group form-group--grow">
            <label class="form-label" for="cs-q">Cari nomor tiket / pelanggan</label>
            <input class="form-control" type="search" id="cs-q" name="q"
                   value="<?= e((string) ($filters['q'] ?? '')) ?>" placeholder="mis. WFC-20261016-000001">
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
        <div class="filter-bar-actions">
            <button class="btn btn-primary" type="submit"><?= icon('search') ?><span>Terapkan</span></button>
            <a class="btn btn-outline-light" href="<?= e($baseUrl) ?>"><?= icon('refresh') ?><span>Reset</span></a>
        </div>
    </form>

    <?= component('table', [
        'id' => 'cs-penugasan',
        'search' => false,
        'columns' => [
            ['label' => 'Nomor Tiket'],
            ['label' => 'Pelanggan'],
            ['label' => 'Kategori'],
            ['label' => 'Diajukan'],
            ['label' => 'Status'],
            ['label' => 'Aksi', 'align' => 'right'],
        ],
        'rows' => $rows,
        'empty' => [
            'icon' => 'tool',
            'title' => 'Tidak ada data',
            'message' => 'Tidak ada tiket yang cocok dengan filter.',
        ],
        'pagination' => $total > 0 ? [
            'page' => $page,
            'last' => $pages,
            'total' => $total,
            'baseUrl' => $baseUrl,
            'query' => $query,
        ] : null,
    ]) ?>
<?php endif; ?>
