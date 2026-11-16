<?php
/**
 * Admin — Monitoring Teknisi (Tahap 8).
 *
 * Ringkasan kinerja seluruh teknisi: jumlah tugas, tugas aktif, tugas selesai,
 * rata-rata waktu penanganan, dan rating. Semua angka berasal dari data MySQL
 * nyata (tanpa data dummy).
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
$ratingEnabled = rating_layanan_enabled();

$baseUrl = url('/admin/teknisi');
$query = array_filter([
    'q' => (string) ($filters['q'] ?? ''),
    'status' => (string) ($filters['status'] ?? ''),
], static fn ($value): bool => $value !== '');

$formatDuration = static function ($minutes): string {
    if ($minutes === null || $minutes === '') {
        return '—';
    }
    $minutes = (int) round((float) $minutes);
    if ($minutes < 60) {
        return $minutes . ' menit';
    }
    $hours = intdiv($minutes, 60);
    $rest = $minutes % 60;

    return $rest === 0 ? $hours . ' jam' : $hours . ' jam ' . $rest . ' menit';
};

$rows = [];
foreach ($items as $item) {
    $id = (int) $item['id'];
    $row = [
        '<div class="cell-stack"><span class="cell-strong">' . e((string) $item['nama']) . '</span>'
            . '<span class="cell-muted">@' . e((string) $item['username']) . '</span></div>',
        e((string) $item['total_tugas']),
        e((string) $item['aktif']),
        e((string) $item['selesai']),
        e($formatDuration($item['avg_menit'])),
    ];

    if ($ratingEnabled) {
        $ratingRata = $item['rating_rata'] !== null ? number_format((float) $item['rating_rata'], 1) : '—';
        $ratingJumlah = (int) ($item['rating_jumlah'] ?? 0);
        $row[] = $ratingRata === '—'
            ? '<span class="cell-muted">Belum ada rating</span>'
            : '<span class="rating-inline">' . icon('star') . ' ' . e($ratingRata)
                . ' <span class="cell-muted">(' . $ratingJumlah . ')</span></span>';
    }

    $row[] = (int) $item['is_active'] === 1
        ? '<span class="chip chip--success">Aktif</span>'
        : '<span class="chip chip--muted">Nonaktif</span>';
    $row[] = '<div class="table-actions"><a class="btn btn-outline-light btn-sm" href="' . e(url('/admin/teknisi/' . $id)) . '">'
        . icon('eye') . '<span>Detail</span></a></div>';

    $rows[] = $row;
}

$columns = [
    ['label' => 'Teknisi'],
    ['label' => 'Total Tugas'],
    ['label' => 'Aktif'],
    ['label' => 'Selesai'],
    ['label' => 'Rata-rata Penanganan'],
];
if ($ratingEnabled) {
    $columns[] = ['label' => 'Rating'];
}
$columns[] = ['label' => 'Status'];
$columns[] = ['label' => 'Aksi', 'align' => 'right'];
?>
<?= component('page-header', [
    'title' => 'Monitoring Teknisi',
    'subtitle' => 'Pantau beban kerja, penyelesaian tugas, dan rata-rata waktu penanganan setiap teknisi.',
]) ?>

<?php if ($flashSukses !== null): ?>
    <div class="callout callout--success"><?= icon('check') ?><p><?= e($flashSukses) ?></p></div>
<?php endif; ?>
<?php if ($flashError !== null): ?>
    <div class="callout callout--danger"><?= icon('alert') ?><p><?= e($flashError) ?></p></div>
<?php endif; ?>

<form class="filter-bar" method="get" action="<?= e($baseUrl) ?>" role="search">
    <div class="form-group form-group--grow">
        <label class="form-label" for="tk-q">Cari nama teknisi</label>
        <input class="form-control" type="search" id="tk-q" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>">
    </div>
    <div class="form-group">
        <label class="form-label" for="tk-status">Status akun</label>
        <select class="form-select" id="tk-status" name="status">
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
    'id' => 'admin-teknisi',
    'search' => false,
    'columns' => $columns,
    'rows' => $rows,
    'empty' => [
        'icon' => 'activity',
        'title' => 'Belum ada teknisi',
        'message' => 'Belum ada akun teknisi yang cocok dengan filter.',
    ],
    'pagination' => $total > 0 ? [
        'page' => $page,
        'last' => $pages,
        'total' => $total,
        'baseUrl' => $baseUrl,
        'query' => $query,
    ] : null,
]) ?>
