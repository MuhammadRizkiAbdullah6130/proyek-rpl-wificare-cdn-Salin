<?php
/**
 * Admin — Data Karyawan (Tahap 8 / Revisi 4).
 *
 * Daftar akun STAF (admin/cs/teknisi) dengan pencarian, filter peran & status,
 * pembuatan akun, edit, aktivasi, dan reset password. Akun pelanggan dikelola
 * terpisah melalui menu Data Pelanggan.
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

$baseUrl = url('/admin/pengguna');
$query = array_filter([
    'q' => (string) ($filters['q'] ?? ''),
    'role' => (string) ($filters['role'] ?? ''),
    'status' => (string) ($filters['status'] ?? ''),
], static fn ($value): bool => $value !== '');

$roleLabels = ['admin' => 'Admin', 'cs' => 'Customer Service', 'teknisi' => 'Teknisi'];
$roleChips = ['admin' => 'chip--danger', 'cs' => 'chip--info', 'teknisi' => 'chip--primary'];

$roleBadge = static function (string $role) use ($roleLabels, $roleChips): string {
    return '<span class="chip ' . ($roleChips[$role] ?? 'chip--muted') . '">'
        . e($roleLabels[$role] ?? $role) . '</span>';
};
$statusBadge = static function (int $active): string {
    return $active === 1
        ? '<span class="chip chip--success">Aktif</span>'
        : '<span class="chip chip--muted">Nonaktif</span>';
};
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
    $itemRole = (string) $item['role'];
    $isActive = (int) $item['is_active'];

    $actions = '<div class="table-actions">';
    $actions .= '<button class="btn btn-outline-light btn-sm" type="button" data-modal-open="user-edit-' . $id . '">'
        . icon('edit') . '<span>Edit</span></button>';
    $actions .= '<button class="btn btn-outline-light btn-sm" type="button" data-modal-open="user-reset-' . $id . '">'
        . icon('lock') . '<span>Reset</span></button>';
    $actions .= '<button class="btn btn-outline-light btn-sm" type="button" data-modal-open="user-status-' . $id . '">'
        . ($isActive === 1 ? icon('x') . '<span>Nonaktifkan</span>' : icon('check') . '<span>Aktifkan</span>')
        . '</button>';
    $actions .= '</div>';

    $rows[] = [
        '<div class="cell-stack"><span class="cell-strong">' . e((string) $item['nama']) . '</span>'
            . '<span class="cell-muted">@' . e((string) $item['username']) . '</span></div>',
        $roleBadge($itemRole),
        $statusBadge($isActive),
        '<span class="cell-muted">' . e($item['last_login_at'] !== null ? format_waktu((string) $item['last_login_at']) : 'Belum pernah') . '</span>',
        e(format_tanggal((string) $item['created_at'])),
        $actions,
    ];

    // Modal edit
    $editBody = '<form id="user-edit-form-' . $id . '" method="post" action="' . e(url('/admin/pengguna/update')) . '" class="form-grid">'
        . csrf_field()
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<div class="form-group"><label class="form-label" for="eu-name-' . $id . '">Nama lengkap</label>'
        . '<input class="form-control" id="eu-name-' . $id . '" name="nama" value="' . e((string) $item['nama']) . '" required maxlength="120"></div>'
        . '<div class="form-group"><label class="form-label" for="eu-user-' . $id . '">Username</label>'
        . '<input class="form-control" id="eu-user-' . $id . '" name="username" value="' . e((string) $item['username']) . '" required></div>'
        . '<div class="form-group"><label class="form-label" for="eu-role-' . $id . '">Peran</label>'
        . '<select class="form-select" id="eu-role-' . $id . '" name="role">';
    foreach (['admin', 'cs', 'teknisi'] as $r) {
        $editBody .= '<option value="' . e($r) . '"' . ($itemRole === $r ? ' selected' : '') . '>' . e($roleLabels[$r]) . '</option>';
    }
    $editBody .= '</select></div></form>';

    $modals[] = $modal(
        'user-edit-' . $id,
        'Edit Karyawan',
        $editBody,
        '<button class="btn btn-outline-light btn-sm" type="button" data-modal-close>Batal</button>'
        . '<button class="btn btn-primary btn-sm" type="submit" form="user-edit-form-' . $id . '">Simpan</button>'
    );

    // Modal reset password
    $resetBody = '<form id="user-reset-form-' . $id . '" method="post" action="' . e(url('/admin/pengguna/reset')) . '" class="form-grid">'
        . csrf_field()
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<p class="form-hint">Atur ulang password untuk <strong>' . e((string) $item['nama']) . '</strong>. Password lama tidak dapat ditampilkan.</p>'
        . '<div class="form-group"><label class="form-label" for="rp-pass-' . $id . '">Password baru</label>'
        . '<input class="form-control" id="rp-pass-' . $id . '" type="password" name="password" minlength="8" required autocomplete="new-password"></div>'
        . '<div class="form-group"><label class="form-label" for="rp-conf-' . $id . '">Konfirmasi password</label>'
        . '<input class="form-control" id="rp-conf-' . $id . '" type="password" name="password_confirm" minlength="8" required autocomplete="new-password"></div>'
        . '</form>';

    $modals[] = $modal(
        'user-reset-' . $id,
        'Reset Password',
        $resetBody,
        '<button class="btn btn-outline-light btn-sm" type="button" data-modal-close>Batal</button>'
        . '<button class="btn btn-primary btn-sm" type="submit" form="user-reset-form-' . $id . '">Reset</button>'
    );

    // Modal aktif/nonaktif
    $newState = $isActive === 1 ? 0 : 1;
    $statusBody = '<form id="user-status-form-' . $id . '" method="post" action="' . e(url('/admin/pengguna/aktif')) . '">'
        . csrf_field()
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<input type="hidden" name="is_active" value="' . $newState . '">'
        . '<p class="app-modal-message">'
        . ($isActive === 1
            ? 'Nonaktifkan akun <strong>' . e((string) $item['nama']) . '</strong>? Akun tidak akan dapat login.'
            : 'Aktifkan kembali akun <strong>' . e((string) $item['nama']) . '</strong>?')
        . '</p></form>';

    $modals[] = $modal(
        'user-status-' . $id,
        $isActive === 1 ? 'Nonaktifkan Akun' : 'Aktifkan Akun',
        $statusBody,
        '<button class="btn btn-outline-light btn-sm" type="button" data-modal-close>Batal</button>'
        . '<button class="btn btn-' . ($isActive === 1 ? 'danger' : 'primary') . ' btn-sm" type="submit" form="user-status-form-' . $id . '">'
        . ($isActive === 1 ? 'Ya, nonaktifkan' : 'Ya, aktifkan') . '</button>'
    );
}

// Modal tambah pengguna
$createBody = '<form id="user-create-form" method="post" action="' . e(url('/admin/pengguna')) . '" class="form-grid">'
    . csrf_field()
    . '<div class="form-group"><label class="form-label" for="cu-name">Nama lengkap</label>'
    . '<input class="form-control" id="cu-name" name="nama" required maxlength="120"></div>'
    . '<div class="form-group"><label class="form-label" for="cu-user">Username</label>'
    . '<input class="form-control" id="cu-user" name="username" required pattern="[A-Za-z0-9._-]{3,64}"></div>'
    . '<div class="form-group"><label class="form-label" for="cu-role">Peran</label>'
    . '<select class="form-select" id="cu-role" name="role" required>'
    . '<option value="cs">Customer Service</option><option value="teknisi">Teknisi</option><option value="admin">Admin</option>'
    . '</select></div>'
    . '<div class="form-group"><label class="form-label" for="cu-pass">Password</label>'
    . '<input class="form-control" id="cu-pass" type="password" name="password" minlength="8" required autocomplete="new-password"></div>'
    . '<div class="form-group"><label class="form-label" for="cu-conf">Konfirmasi password</label>'
    . '<input class="form-control" id="cu-conf" type="password" name="password_confirm" minlength="8" required autocomplete="new-password"></div>'
    . '<label class="form-check"><input type="checkbox" name="is_active" value="1" checked><span>Akun aktif</span></label>'
    . '<p class="form-hint">Akun pelanggan dibuat melalui menu <strong>Data Pelanggan</strong>.</p>'
    . '</form>';
?>
<?= component('page-header', [
    'title' => 'Data Karyawan',
    'subtitle' => 'Kelola akun admin, customer service, dan teknisi. Pelanggan dikelola di menu Data Pelanggan.',
    'actions' => [
        ['label' => 'Tambah Karyawan', 'icon' => 'plus', 'variant' => 'primary', 'modal' => 'user-create'],
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
        <label class="form-label" for="pu-q">Cari nama / username</label>
        <input class="form-control" type="search" id="pu-q" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>">
    </div>
    <div class="form-group">
        <label class="form-label" for="pu-role">Peran</label>
        <select class="form-select" id="pu-role" name="role">
            <option value="">Semua peran</option>
            <?php foreach ($roleLabels as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= (string) ($filters['role'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label class="form-label" for="pu-status">Status</label>
        <select class="form-select" id="pu-status" name="status">
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
    'id' => 'admin-pengguna',
    'search' => false,
    'columns' => [
        ['label' => 'Nama'],
        ['label' => 'Peran'],
        ['label' => 'Status'],
        ['label' => 'Login Terakhir'],
        ['label' => 'Dibuat'],
        ['label' => 'Aksi', 'align' => 'right'],
    ],
    'rows' => $rows,
    'empty' => [
        'icon' => 'users',
        'title' => 'Belum ada karyawan',
        'message' => 'Tidak ada akun karyawan yang cocok dengan filter. Tambahkan akun baru untuk memulai.',
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
    'user-create',
    'Tambah Karyawan',
    $createBody,
    '<button class="btn btn-outline-light btn-sm" type="button" data-modal-close>Batal</button>'
    . '<button class="btn btn-primary btn-sm" type="submit" form="user-create-form">Simpan</button>'
) ?>

<?php foreach ($modals as $m): ?><?= $m ?><?php endforeach; ?>
