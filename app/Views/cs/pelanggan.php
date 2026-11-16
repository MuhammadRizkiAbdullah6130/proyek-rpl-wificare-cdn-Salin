<?php
/**
 * Customer Service — Riwayat Pelanggan (daftar) (Tahap 6).
 *
 * Daftar pelanggan beserta jumlah tiket dan status tiket terakhir.
 * Tidak menampilkan password atau data rahasia apa pun.
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

$baseUrl = url('/cs/pelanggan');
$query = array_filter([
    'q' => (string) ($filters['q'] ?? ''),
], static fn ($value): bool => $value !== '');

$rows = [];
foreach ($items as $item) {
    $id = (int) $item['id'];
    $link = url('/cs/pelanggan/' . $id);
    $statusTerakhir = (string) ($item['status_terakhir'] ?? '');

    $rows[] = [
        '<a class="link-inline" href="' . e($link) . '">' . e((string) $item['nama']) . '</a>',
        e((string) $item['username_pppoe']),
        e((string) $item['nomor_whatsapp']),
        e((string) (int) $item['total_tiket']),
        e((string) (int) $item['tiket_aktif']),
        $statusTerakhir !== '' ? component_html('badge-status', ['status' => $statusTerakhir]) : e('—'),
        e(!empty($item['terakhir']) ? format_waktu((string) $item['terakhir']) : '—'),
        '<div class="table-actions"><a class="btn btn-outline-light btn-sm" href="' . e($link) . '">'
            . icon('eye') . '<span>Riwayat</span></a></div>',
    ];
}
?>
<?= component('page-header', [
    'title' => 'Riwayat Pelanggan',
    'subtitle' => 'Cari pelanggan berdasarkan nama atau Username PPPoE.',
]) ?>

<?php if ($flashSukses !== null): ?>
    <div class="callout callout--success"><?= icon('check') ?><p><?= e($flashSukses) ?></p></div>
<?php endif; ?>
<?php if ($flashError !== null): ?>
    <div class="callout callout--danger"><?= icon('alert') ?><p><?= e($flashError) ?></p></div>
<?php endif; ?>

<form class="filter-bar" method="get" action="<?= e($baseUrl) ?>" role="search">
    <div class="form-group form-group--grow">
        <label class="form-label" for="cs-q">Cari nama / Username PPPoE</label>
        <input class="form-control" type="search" id="cs-q" name="q"
               value="<?= e((string) ($filters['q'] ?? '')) ?>" placeholder="mis. Dewi atau pppoe.rina">
    </div>
    <div class="filter-bar-actions">
        <button class="btn btn-primary" type="submit"><?= icon('search') ?><span>Terapkan</span></button>
        <a class="btn btn-outline-light" href="<?= e($baseUrl) ?>"><?= icon('refresh') ?><span>Reset</span></a>
    </div>
</form>

<?= component('table', [
    'id' => 'cs-pelanggan',
    'search' => false,
    'columns' => [
        ['label' => 'Nama'],
        ['label' => 'Username PPPoE'],
        ['label' => 'WhatsApp'],
        ['label' => 'Total Tiket', 'align' => 'center'],
        ['label' => 'Tiket Aktif', 'align' => 'center'],
        ['label' => 'Status Terakhir'],
        ['label' => 'Terakhir'],
        ['label' => 'Aksi', 'align' => 'right'],
    ],
    'rows' => $rows,
    'empty' => [
        'icon' => 'user',
        'title' => 'Pelanggan tidak ditemukan',
        'message' => 'Tidak ada pelanggan yang cocok dengan pencarian.',
    ],
    'pagination' => $total > 0 ? [
        'page' => $page,
        'last' => $pages,
        'total' => $total,
        'baseUrl' => $baseUrl,
        'query' => $query,
    ] : null,
]) ?>
