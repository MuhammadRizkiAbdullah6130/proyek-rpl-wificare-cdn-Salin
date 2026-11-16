<?php
/**
 * Admin — Audit Log (Tahap 8).
 *
 * Menampilkan catatan aktivitas penting (append-only). Tidak ada aksi ubah/hapus
 * melalui antarmuka. Audit log tidak pernah menampilkan password/token.
 */

declare(strict_types=1);

$content = '';

$items = $items ?? [];
$total = (int) ($total ?? 0);
$pages = (int) ($pages ?? 1);
$page = (int) ($page ?? 1);
$filters = $filters ?? [];
$actions = $actions ?? [];
$actors = $actors ?? [];
$flashSukses = $flash_sukses ?? null;
$flashError = $flash_error ?? null;

$baseUrl = url('/admin/audit');
$query = array_filter([
    'q' => (string) ($filters['q'] ?? ''),
    'aksi' => (string) ($filters['aksi'] ?? ''),
    'actor_id' => (int) ($filters['actor_id'] ?? 0) > 0 ? (string) (int) $filters['actor_id'] : '',
    'date_from' => (string) ($filters['date_from'] ?? ''),
    'date_to' => (string) ($filters['date_to'] ?? ''),
], static fn ($value): bool => $value !== '');

$rows = [];
foreach ($items as $item) {
    $actor = $item['actor'] !== null
        ? '<div class="cell-stack"><span class="cell-strong">' . e((string) $item['actor']) . '</span>'
            . '<span class="cell-muted">' . e(role_label((string) ($item['actor_role'] ?? ''))) . '</span></div>'
        : '<span class="cell-muted">Sistem</span>';

    $metadataRaw = isset($item['metadata']) ? (string) $item['metadata'] : null;
    $metadata = $metadataRaw !== null && $metadataRaw !== ''
        ? json_decode($metadataRaw, true)
        : [];
    if (!is_array($metadata)) {
        $metadata = [];
    }

    $objek = audit_object_label(
        isset($item['objek']) ? (string) $item['objek'] : null,
        isset($item['objek_id']) ? (int) $item['objek_id'] : null,
        $metadata
    );

    $pairs = audit_metadata_pairs($metadataRaw);
    if ($pairs !== []) {
        $detail = '<details class="audit-detail"><summary>Lihat keterangan</summary><dl class="audit-meta">';
        foreach ($pairs as $pair) {
            $detail .= '<div><dt>' . e($pair['label']) . '</dt><dd>' . e($pair['value']) . '</dd></div>';
        }
        $detail .= '</dl></details>';
    } else {
        $detail = '<span class="cell-muted">—</span>';
    }

    $rows[] = [
        e(format_waktu((string) $item['created_at'])),
        $actor,
        '<span class="audit-action">' . e(audit_action_label((string) $item['aksi'])) . '</span>',
        e($objek),
        e(audit_ip_label(isset($item['ip_address']) ? (string) $item['ip_address'] : null)),
        $detail,
    ];
}
?>
<?= component('page-header', [
    'title' => 'Audit Log',
    'subtitle' => 'Catatan aktivitas penting. Hanya dapat dilihat — tidak dapat diubah atau dihapus.',
]) ?>

<?php if ($flashSukses !== null): ?>
    <div class="callout callout--success"><?= icon('check') ?><p><?= e($flashSukses) ?></p></div>
<?php endif; ?>
<?php if ($flashError !== null): ?>
    <div class="callout callout--danger"><?= icon('alert') ?><p><?= e($flashError) ?></p></div>
<?php endif; ?>

<form class="filter-bar" method="get" action="<?= e($baseUrl) ?>" role="search">
    <div class="form-group form-group--grow">
        <label class="form-label" for="au-q">Cari pengguna, aktivitas, atau objek</label>
        <input class="form-control" type="search" id="au-q" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>">
    </div>
    <div class="form-group">
        <label class="form-label" for="au-aksi">Aktivitas</label>
        <select class="form-select" id="au-aksi" name="aksi">
            <option value="">Semua aktivitas</option>
            <?php foreach ($actions as $aksi): ?>
                <option value="<?= e($aksi) ?>" <?= (string) ($filters['aksi'] ?? '') === $aksi ? 'selected' : '' ?>><?= e(audit_action_label($aksi)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label class="form-label" for="au-aktor">Pengguna</label>
        <select class="form-select" id="au-aktor" name="actor_id">
            <option value="">Semua pengguna</option>
            <?php foreach ($actors as $actor): ?>
                <option value="<?= e((string) $actor['id']) ?>" <?= (int) ($filters['actor_id'] ?? 0) === (int) $actor['id'] ? 'selected' : '' ?>>
                    <?= e((string) $actor['nama']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label class="form-label" for="au-dari">Dari tanggal</label>
        <input class="form-control" type="date" id="au-dari" name="date_from" value="<?= e((string) ($filters['date_from'] ?? '')) ?>">
    </div>
    <div class="form-group">
        <label class="form-label" for="au-sampai">Sampai tanggal</label>
        <input class="form-control" type="date" id="au-sampai" name="date_to" value="<?= e((string) ($filters['date_to'] ?? '')) ?>">
    </div>
    <div class="filter-bar-actions">
        <button class="btn btn-primary" type="submit"><?= icon('search') ?><span>Terapkan</span></button>
        <a class="btn btn-outline-light" href="<?= e($baseUrl) ?>"><?= icon('refresh') ?><span>Reset</span></a>
    </div>
</form>

<?= component('table', [
    'id' => 'admin-audit',
    'search' => false,
    'columns' => [
        ['label' => 'Waktu'],
        ['label' => 'Pengguna'],
        ['label' => 'Aktivitas'],
        ['label' => 'Objek'],
        ['label' => 'Alamat IP'],
        ['label' => 'Keterangan'],
    ],
    'rows' => $rows,
    'empty' => [
        'icon' => 'shield',
        'title' => 'Belum ada aktivitas',
        'message' => 'Tidak ada catatan audit yang cocok dengan filter.',
    ],
    'pagination' => $total > 0 ? [
        'page' => $page,
        'last' => $pages,
        'total' => $total,
        'baseUrl' => $baseUrl,
        'query' => $query,
    ] : null,
]) ?>
