<?php
/**
 * Admin — Data Pelanggan (Tahap 8).
 *
 * Pelanggan login memakai Username PPPoE. Menambah pelanggan sekaligus membuat
 * akun login-nya. Password jaringan PPPoE tidak pernah disimpan.
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

$baseUrl = url('/admin/pelanggan');
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
    $detailUrl = url('/admin/pelanggan/' . $id);

    $actions = '<div class="table-actions">'
        . '<a class="btn btn-outline-light btn-sm" href="' . e($detailUrl) . '">' . icon('eye') . '<span>Detail</span></a>'
        . '<button class="btn btn-outline-light btn-sm" type="button" data-modal-open="pel-edit-' . $id . '">' . icon('edit') . '<span>Edit</span></button>'
        . '<button class="btn btn-outline-light btn-sm" type="button" data-modal-open="pel-status-' . $id . '">'
        . ($isActive === 1 ? icon('x') . '<span>Nonaktifkan</span>' : icon('check') . '<span>Aktifkan</span>')
        . '</button></div>';

    $rows[] = [
        '<div class="cell-stack"><span class="cell-strong">' . e((string) $item['nama']) . '</span>'
            . '<span class="cell-muted">' . e((string) ($item['alamat'] ?? '—')) . '</span></div>',
        '<span class="cell-strong">' . e((string) $item['username_pppoe']) . '</span>',
        e((string) $item['nomor_whatsapp']),
        e((string) $item['total_tiket']),
        $isActive === 1 ? '<span class="chip chip--success">Aktif</span>' : '<span class="chip chip--muted">Nonaktif</span>',
        $actions,
    ];

    // Modal edit
    $editBody = '<form id="pel-edit-form-' . $id . '" method="post" action="' . e(url('/admin/pelanggan/update')) . '" class="form-grid">'
        . csrf_field()
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<div class="form-group"><label class="form-label" for="pe-name-' . $id . '">Nama lengkap</label>'
        . '<input class="form-control" id="pe-name-' . $id . '" name="nama" value="' . e((string) $item['nama']) . '" required maxlength="120"></div>'
        . '<div class="form-group"><label class="form-label" for="pe-pppoe-' . $id . '">Username PPPoE</label>'
        . '<input class="form-control" id="pe-pppoe-' . $id . '" name="username_pppoe" value="' . e((string) $item['username_pppoe']) . '" required></div>'
        . '<div class="form-group"><label class="form-label" for="pe-wa-' . $id . '">Nomor WhatsApp</label>'
        . '<input class="form-control" id="pe-wa-' . $id . '" name="nomor_whatsapp" value="' . e((string) $item['nomor_whatsapp']) . '" required></div>'
        . '<div class="form-group"><label class="form-label" for="pe-alamat-' . $id . '">Alamat</label>'
        . '<input class="form-control" id="pe-alamat-' . $id . '" name="alamat" value="' . e((string) ($item['alamat'] ?? '')) . '" maxlength="255"></div>'
        . '</form>';

    $modals[] = $modal(
        'pel-edit-' . $id,
        'Edit Pelanggan',
        $editBody,
        '<button class="btn btn-outline-light btn-sm" type="button" data-modal-close>Batal</button>'
        . '<button class="btn btn-primary btn-sm" type="submit" form="pel-edit-form-' . $id . '">Simpan</button>'
    );

    // Modal aktif/nonaktif
    $newState = $isActive === 1 ? 0 : 1;
    $statusBody = '<form id="pel-status-form-' . $id . '" method="post" action="' . e(url('/admin/pelanggan/aktif')) . '">'
        . csrf_field()
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<input type="hidden" name="is_active" value="' . $newState . '">'
        . '<p class="app-modal-message">'
        . ($isActive === 1
            ? 'Nonaktifkan akun pelanggan <strong>' . e((string) $item['nama']) . '</strong>? Pelanggan tidak akan dapat login.'
            : 'Aktifkan kembali akun <strong>' . e((string) $item['nama']) . '</strong>?')
        . '</p></form>';

    $modals[] = $modal(
        'pel-status-' . $id,
        $isActive === 1 ? 'Nonaktifkan Pelanggan' : 'Aktifkan Pelanggan',
        $statusBody,
        '<button class="btn btn-outline-light btn-sm" type="button" data-modal-close>Batal</button>'
        . '<button class="btn btn-' . ($isActive === 1 ? 'danger' : 'primary') . ' btn-sm" type="submit" form="pel-status-form-' . $id . '">'
        . ($isActive === 1 ? 'Ya, nonaktifkan' : 'Ya, aktifkan') . '</button>'
    );
}

$createBody = '<form id="pel-create-form" method="post" action="' . e(url('/admin/pelanggan')) . '" class="form-grid">'
    . csrf_field()
    . '<div class="form-group"><label class="form-label" for="cp-name">Nama lengkap</label>'
    . '<input class="form-control" id="cp-name" name="nama" required maxlength="120"></div>'
    . '<div class="form-group"><label class="form-label" for="cp-pppoe">Username PPPoE</label>'
    . '<input class="form-control" id="cp-pppoe" name="username_pppoe" required></div>'
    . '<div class="form-group"><label class="form-label" for="cp-wa">Nomor WhatsApp</label>'
    . '<input class="form-control" id="cp-wa" name="nomor_whatsapp" required placeholder="mis. 081234567890"></div>'
    . '<div class="form-group"><label class="form-label" for="cp-alamat">Alamat (opsional)</label>'
    . '<input class="form-control" id="cp-alamat" name="alamat" maxlength="255"></div>'
    . '<div class="form-group"><label class="form-label" for="cp-pass">Password WiFiCare</label>'
    . '<input class="form-control" id="cp-pass" type="password" name="password" minlength="8" required autocomplete="new-password"></div>'
    . '<div class="form-group"><label class="form-label" for="cp-conf">Konfirmasi password</label>'
    . '<input class="form-control" id="cp-conf" type="password" name="password_confirm" minlength="8" required autocomplete="new-password"></div>'
    . '<label class="form-check"><input type="checkbox" name="is_active" value="1" checked><span>Akun aktif</span></label>'
    . '<p class="form-hint">Username PPPoE digunakan pelanggan untuk login. Password WiFiCare berbeda dari password jaringan PPPoE.</p>'
    . '</form>';
?>
<?= component('page-header', [
    'title' => 'Data Pelanggan',
    'subtitle' => 'Kelola data pelanggan, Username PPPoE, nomor WhatsApp, dan akun login.',
    'actions' => [
        ['label' => 'Tambah Pelanggan', 'icon' => 'plus', 'variant' => 'primary', 'modal' => 'pel-create'],
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
        <label class="form-label" for="pl-q">Cari nama / PPPoE / WhatsApp</label>
        <input class="form-control" type="search" id="pl-q" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>">
    </div>
    <div class="form-group">
        <label class="form-label" for="pl-status">Status akun</label>
        <select class="form-select" id="pl-status" name="status">
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
    'id' => 'admin-pelanggan',
    'search' => false,
    'columns' => [
        ['label' => 'Pelanggan'],
        ['label' => 'Username PPPoE'],
        ['label' => 'WhatsApp'],
        ['label' => 'Total Tiket'],
        ['label' => 'Status'],
        ['label' => 'Aksi', 'align' => 'right'],
    ],
    'rows' => $rows,
    'empty' => [
        'icon' => 'user',
        'title' => 'Belum ada pelanggan',
        'message' => 'Tidak ada pelanggan yang cocok dengan filter. Tambahkan pelanggan baru untuk memulai.',
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
    'pel-create',
    'Tambah Pelanggan',
    $createBody,
    '<button class="btn btn-outline-light btn-sm" type="button" data-modal-close>Batal</button>'
    . '<button class="btn btn-primary btn-sm" type="submit" form="pel-create-form">Simpan</button>'
) ?>

<?php foreach ($modals as $m): ?><?= $m ?><?php endforeach; ?>
