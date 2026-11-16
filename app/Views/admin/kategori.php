<?php
/**
 * Admin — Kategori Gangguan (Tahap 8).
 * Kategori dinonaktifkan (soft-disable), tidak dihapus, agar tiket lama tetap valid.
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

$baseUrl = url('/admin/kategori');
$query = array_filter([
    'q' => (string) ($filters['q'] ?? ''),
    'status' => (string) ($filters['status'] ?? ''),
], static fn ($value): bool => $value !== '');

$modal = static function (string $id, string $title, string $body, string $foot): string {
    return '<div class="app-modal-backdrop" data-modal="' . e($id) . '" hidden>'
        . '<div class="app-modal" role="dialog" aria-modal="true">'
        . '<div class="app-modal-head"><h3 class="app-modal-title">' . e($title) . '</h3>'
        . '<button class="app-icon-button" type="button" data-modal-close aria-label="Tutup">' . icon('x') . '</button></div>'
        . '<div class="app-modal-body">' . $body . '</div>'
        . '<div class="app-modal-foot">' . $foot . '</div>'
        . '</div></div>';
};

$rows = [];
$modals = [];
foreach ($items as $item) {
    $id = (int) $item['id'];
    $isActive = (int) $item['is_active'];

    $actions = '<div class="table-actions">'
        . '<button class="btn btn-outline-light btn-sm" type="button" data-modal-open="kat-edit-' . $id . '">' . icon('edit') . '<span>Edit</span></button>'
        . '<button class="btn btn-outline-light btn-sm" type="button" data-modal-open="kat-status-' . $id . '">'
        . ($isActive === 1 ? icon('x') . '<span>Nonaktifkan</span>' : icon('check') . '<span>Aktifkan</span>')
        . '</button></div>';

    $rows[] = [
        '<span class="cell-strong">' . e((string) $item['nama']) . '</span>',
        '<span class="cell-muted">' . e((string) ($item['deskripsi'] ?? '—')) . '</span>',
        e((string) $item['jumlah_tiket']),
        $isActive === 1 ? '<span class="chip chip--success">Aktif</span>' : '<span class="chip chip--muted">Nonaktif</span>',
        $actions,
    ];

    $editBody = '<form id="kat-edit-form-' . $id . '" method="post" action="' . e(url('/admin/kategori/update')) . '" class="form-grid">'
        . csrf_field()
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<div class="form-group"><label class="form-label" for="ke-name-' . $id . '">Nama kategori</label>'
        . '<input class="form-control" id="ke-name-' . $id . '" name="nama" value="' . e((string) $item['nama']) . '" required maxlength="120"></div>'
        . '<div class="form-group"><label class="form-label" for="ke-desc-' . $id . '">Deskripsi (opsional)</label>'
        . '<input class="form-control" id="ke-desc-' . $id . '" name="deskripsi" value="' . e((string) ($item['deskripsi'] ?? '')) . '" maxlength="255"></div>'
        . '</form>';

    $modals[] = $modal(
        'kat-edit-' . $id,
        'Edit Kategori',
        $editBody,
        '<button class="btn btn-outline-light btn-sm" type="button" data-modal-close>Batal</button>'
        . '<button class="btn btn-primary btn-sm" type="submit" form="kat-edit-form-' . $id . '">Simpan</button>'
    );

    $newState = $isActive === 1 ? 0 : 1;
    $statusBody = '<form id="kat-status-form-' . $id . '" method="post" action="' . e(url('/admin/kategori/aktif')) . '">'
        . csrf_field()
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<input type="hidden" name="is_active" value="' . $newState . '">'
        . '<p class="app-modal-message">'
        . ($isActive === 1
            ? 'Nonaktifkan kategori <strong>' . e((string) $item['nama']) . '</strong>? Kategori tidak akan muncul pada formulir pengaduan baru.'
            : 'Aktifkan kembali kategori <strong>' . e((string) $item['nama']) . '</strong>?')
        . '</p></form>';

    $modals[] = $modal(
        'kat-status-' . $id,
        $isActive === 1 ? 'Nonaktifkan Kategori' : 'Aktifkan Kategori',
        $statusBody,
        '<button class="btn btn-outline-light btn-sm" type="button" data-modal-close>Batal</button>'
        . '<button class="btn btn-' . ($isActive === 1 ? 'danger' : 'primary') . ' btn-sm" type="submit" form="kat-status-form-' . $id . '">'
        . ($isActive === 1 ? 'Ya, nonaktifkan' : 'Ya, aktifkan') . '</button>'
    );
}

$createBody = '<form id="kat-create-form" method="post" action="' . e(url('/admin/kategori')) . '" class="form-grid">'
    . csrf_field()
    . '<div class="form-group"><label class="form-label" for="kc-name">Nama kategori</label>'
    . '<input class="form-control" id="kc-name" name="nama" required maxlength="120"></div>'
    . '<div class="form-group"><label class="form-label" for="kc-desc">Deskripsi (opsional)</label>'
    . '<input class="form-control" id="kc-desc" name="deskripsi" maxlength="255"></div>'
    . '</form>';
?>
<?= component('page-header', [
    'title' => 'Kategori Gangguan',
    'subtitle' => 'Kelola master kategori yang dipakai pada formulir pengaduan pelanggan.',
    'actions' => [
        ['label' => 'Tambah Kategori', 'icon' => 'plus', 'variant' => 'primary', 'modal' => 'kat-create'],
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
        <label class="form-label" for="ka-q">Cari kategori</label>
        <input class="form-control" type="search" id="ka-q" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>">
    </div>
    <div class="form-group">
        <label class="form-label" for="ka-status">Status</label>
        <select class="form-select" id="ka-status" name="status">
            <option value="">Semua status</option>
            <option value="aktif" <?= (string) ($filters['status'] ?? '') === 'aktif' ? 'selected' : '' ?>>Aktif</option>
            <option value="nonaktif" <?= (string) ($filters['status'] ?? '') === 'nonaktif' ? 'selected' : '' ?>>Nonaktif</option>
        </select>
    </div>
    <div class="filter-bar-actions">
        <button class="btn btn-primary" type="submit"><?= icon('search') ?><span>Terapkan</span></button>
        <a class="btn btn-outline-light" href="<?= e($baseUrl) ?>"><?= icon('refresh') ?><span>Reset</span></a>
    </div>
</form>

<?= component('table', [
    'id' => 'admin-kategori',
    'search' => false,
    'columns' => [
        ['label' => 'Nama'],
        ['label' => 'Deskripsi'],
        ['label' => 'Tiket'],
        ['label' => 'Status'],
        ['label' => 'Aksi', 'align' => 'right'],
    ],
    'rows' => $rows,
    'empty' => [
        'icon' => 'tag',
        'title' => 'Belum ada kategori',
        'message' => 'Tambahkan kategori agar pelanggan dapat memilih jenis gangguan.',
    ],
    'pagination' => $total > 0 ? [
        'page' => $page,
        'last' => $pages,
        'total' => $total,
        'baseUrl' => $baseUrl,
        'query' => $query,
    ] : null,
]) ?>

<?= $modal(
    'kat-create',
    'Tambah Kategori',
    $createBody,
    '<button class="btn btn-outline-light btn-sm" type="button" data-modal-close>Batal</button>'
    . '<button class="btn btn-primary btn-sm" type="submit" form="kat-create-form">Simpan</button>'
) ?>

<?php foreach ($modals as $m): ?><?= $m ?><?php endforeach; ?>
