<?php
/**
 * Admin — Semua Pengaduan (Tahap 8).
 *
 * Monitoring seluruh tiket dengan pencarian, filter status/kategori/teknisi/
 * tanggal/pembatalan/arsip, dan pagination server-side.
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
$flashSukses = $flash_sukses ?? null;
$flashError = $flash_error ?? null;
$ratingEnabled = rating_layanan_enabled();

$baseUrl = url('/admin/pengaduan');
$query = array_filter([
    'q' => (string) ($filters['q'] ?? ''),
    'status' => (string) ($filters['status'] ?? ''),
    'periode' => (string) ($filters['periode'] ?? ''),
], static fn ($value): bool => $value !== '' && $value !== 'semua');

$rows = [];
foreach ($items as $item) {
    $id = (int) $item['id'];
    $link = url('/admin/pengaduan/' . $id);
    $isCancelled = (int) $item['is_cancelled'] === 1;
    $isArchived = $item['archived_at'] !== null;

    $statusCell = status_badge_html((string) $item['status'], $isCancelled);
    if ($isArchived) {
        $statusCell .= ' <span class="chip chip--muted" title="Diarsipkan">Arsip</span>';
    }

    $row = [
        '<a class="link-inline" href="' . e($link) . '">' . e((string) $item['nomor_tiket']) . '</a>',
        '<div class="cell-stack"><span class="cell-strong">' . e((string) $item['nama_pelanggan_snapshot'])
            . '</span><span class="cell-muted">' . e((string) ($item['username_pppoe_snapshot'] ?? '—')) . '</span></div>',
        e((string) ($item['kategori'] ?? '—')),
        $statusCell,
        e((string) ($item['teknisi_nama'] ?? '—')),
        e(format_waktu((string) $item['created_at'])),
        e($item['completed_at'] !== null ? format_waktu((string) $item['completed_at']) : '—'),
    ];

    if ($ratingEnabled) {
        $row[] = $item['rating'] !== null
            ? '<span class="rating-inline">' . icon('star') . ' ' . e((string) $item['rating']) . '</span>'
            : '<span class="cell-muted">—</span>';
    }

    $row[] = '<div class="table-actions"><a class="btn btn-outline-light btn-sm" href="' . e($link) . '">'
        . icon('eye') . '<span>Detail</span></a></div>';

    $rows[] = $row;
}

$columns = [
    ['label' => 'Nomor Tiket'],
    ['label' => 'Pelanggan'],
    ['label' => 'Kategori'],
    ['label' => 'Status'],
    ['label' => 'Teknisi'],
    ['label' => 'Dibuat'],
    ['label' => 'Selesai'],
];
if ($ratingEnabled) {
    $columns[] = ['label' => 'Rating'];
}
$columns[] = ['label' => 'Aksi', 'align' => 'right'];
?>
<?= component('page-header', [
    'title' => 'Semua Pengaduan',
    'subtitle' => 'Monitor seluruh tiket beserta status efektif, teknisi, pembatalan, dan arsip.',
]) ?>

<?php if ($flashSukses !== null): ?>
    <div class="callout callout--success"><?= icon('check') ?><p><?= e($flashSukses) ?></p></div>
<?php endif; ?>
<?php if ($flashError !== null): ?>
    <div class="callout callout--danger"><?= icon('alert') ?><p><?= e($flashError) ?></p></div>
<?php endif; ?>

<form class="filter-bar filter-bar--compact" method="get" action="<?= e($baseUrl) ?>" role="search">
    <div class="form-group form-group--grow">
        <label class="form-label" for="ad-q">Cari</label>
        <input class="form-control" type="search" id="ad-q" name="q"
               placeholder="Cari nomor tiket, pelanggan, atau PPPoE..." value="<?= e((string) ($filters['q'] ?? '')) ?>">
    </div>
    <div class="form-group">
        <label class="form-label" for="ad-status">Status</label>
        <select class="form-select" id="ad-status" name="status">
            <option value="">Semua</option>
            <?php foreach (status_map() as $key => $meta): ?>
                <option value="<?= e($key) ?>" <?= (string) ($filters['status'] ?? '') === $key ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label class="form-label" for="ad-periode">Periode</label>
        <select class="form-select" id="ad-periode" name="periode">
            <?php
            $periodeOptions = [
                'semua' => 'Semua Waktu',
                'hari_ini' => 'Hari Ini',
                '7_hari' => '7 Hari Terakhir',
                'bulan_ini' => 'Bulan Ini',
            ];
            $periodeAktif = (string) ($filters['periode'] ?? 'semua');
            ?>
            <?php foreach ($periodeOptions as $pKey => $pLabel): ?>
                <option value="<?= e($pKey) ?>" <?= $periodeAktif === $pKey ? 'selected' : '' ?>><?= e($pLabel) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-bar-actions">
        <button class="btn btn-primary" type="submit"><?= icon('search') ?><span>Cari</span></button>
        <a class="btn btn-outline-light" href="<?= e($baseUrl) ?>"><?= icon('refresh') ?><span>Reset</span></a>
    </div>
</form>

<?= component('table', [
    'id' => 'admin-pengaduan',
    'search' => false,
    'columns' => $columns,
    'rows' => $rows,
    'empty' => [
        'icon' => 'inbox',
        'title' => 'Belum ada pengaduan',
        'message' => 'Tidak ada tiket yang cocok dengan filter yang dipilih.',
    ],
    'pagination' => $total > 0 ? [
        'page' => $page,
        'last' => $pages,
        'total' => $total,
        'baseUrl' => $baseUrl,
        'query' => $query,
    ] : null,
]) ?>
