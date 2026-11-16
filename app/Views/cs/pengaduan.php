<?php
/**
 * Customer Service — Pengaduan Masuk (Tahap 6).
 *
 * Daftar pengaduan dengan pencarian (nomor tiket / pelanggan / PPPoE),
 * filter status, kategori, dan rentang tanggal, serta pagination server-side.
 */

declare(strict_types=1);
$content = '';

$items = $items ?? [];
$total = (int) ($total ?? 0);
$pages = (int) ($pages ?? 1);
$page = (int) ($page ?? 1);
$filters = $filters ?? [];
$kategoriOptions = $kategoriOptions ?? [];
$flashSukses = $flash_sukses ?? null;
$flashError = $flash_error ?? null;

$baseUrl = url('/cs/pengaduan');
$query = array_filter([
    'q' => (string) ($filters['q'] ?? ''),
    'status' => (string) ($filters['status'] ?? ''),
    'kategori_id' => (int) ($filters['kategori_id'] ?? 0) > 0 ? (string) (int) $filters['kategori_id'] : '',
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
        e((string) $item['nomor_whatsapp_snapshot']),
        e((string) ($item['kategori'] ?? '—')),
        e(format_waktu((string) $item['created_at'])),
        status_badge_html((string) $item['status'], (int) $item['is_cancelled'] === 1),
        e((string) ($item['teknisi_nama'] ?? '—')),
        '<div class="table-actions"><a class="btn btn-outline-light btn-sm" href="' . e($link) . '">'
            . icon('eye') . '<span>Detail</span></a></div>',
    ];
}
?>
<?= component('page-header', [
    'title' => 'Pengaduan Masuk',
    'subtitle' => 'Semua pengaduan beserta status, teknisi, dan tindakannya.',
]) ?>

<?php if ($flashSukses !== null): ?>
    <div class="callout callout--success"><?= icon('check') ?><p><?= e($flashSukses) ?></p></div>
<?php endif; ?>
<?php if ($flashError !== null): ?>
    <div class="callout callout--danger"><?= icon('alert') ?><p><?= e($flashError) ?></p></div>
<?php endif; ?>

<form class="filter-bar" method="get" action="<?= e($baseUrl) ?>" role="search">
    <div class="form-group form-group--grow">
        <label class="form-label" for="cs-q">Cari nomor tiket / pelanggan</label>
        <input class="form-control" type="search" id="cs-q" name="q"
               value="<?= e((string) ($filters['q'] ?? '')) ?>" placeholder="mis. WFC-20261016-000001 atau nama / PPPoE">
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
        <label class="form-label" for="cs-kategori">Kategori</label>
        <select class="form-select" id="cs-kategori" name="kategori_id">
            <option value="">Semua kategori</option>
            <?php foreach ($kategoriOptions as $kategori): ?>
                <option value="<?= e((string) $kategori['id']) ?>"
                    <?= (int) ($filters['kategori_id'] ?? 0) === (int) $kategori['id'] ? 'selected' : '' ?>>
                    <?= e((string) $kategori['nama']) ?>
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

    <div class="filter-bar-actions">
        <button class="btn btn-primary" type="submit"><?= icon('search') ?><span>Terapkan</span></button>
        <a class="btn btn-outline-light" href="<?= e($baseUrl) ?>"><?= icon('refresh') ?><span>Reset</span></a>
    </div>
</form>

<?= component('table', [
    'id' => 'cs-pengaduan',
    'search' => false,
    'columns' => [
        ['label' => 'Nomor Tiket'],
        ['label' => 'Pelanggan'],
        ['label' => 'WhatsApp'],
        ['label' => 'Kategori'],
        ['label' => 'Diajukan'],
        ['label' => 'Status'],
        ['label' => 'Teknisi'],
        ['label' => 'Aksi', 'align' => 'right'],
    ],
    'rows' => $rows,
    'empty' => [
        'icon' => 'inbox',
        'title' => 'Belum ada pengaduan',
        'message' => 'Tidak ada tiket yang cocok dengan filter. Pengaduan baru akan tampil di sini.',
    ],
    'pagination' => $total > 0 ? [
        'page' => $page,
        'last' => $pages,
        'total' => $total,
        'baseUrl' => $baseUrl,
        'query' => $query,
    ] : null,
]) ?>
