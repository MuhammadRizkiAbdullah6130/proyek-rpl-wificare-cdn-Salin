<?php
/**
 * Teknisi — Riwayat Pekerjaan (Tahap 7).
 *
 * Riwayat laporan perbaikan milik teknisi yang sedang masuk, dengan pencarian,
 * filter kategori & tanggal, dan pagination server-side.
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

$baseUrl = url('/teknisi/riwayat');
$query = array_filter([
    'q' => (string) ($filters['q'] ?? ''),
    'kategori_id' => (int) ($filters['kategori_id'] ?? 0) > 0 ? (string) (int) $filters['kategori_id'] : '',
    'date_from' => (string) ($filters['date_from'] ?? ''),
    'date_to' => (string) ($filters['date_to'] ?? ''),
], static fn ($value): bool => $value !== '');

$ringkas = static function (?string $text, int $limit = 70): string {
    $text = trim((string) preg_replace('/\s+/', ' ', (string) $text));
    if ($text === '') {
        return '—';
    }

    return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) . '…' : $text;
};

$rows = [];
foreach ($items as $item) {
    $pengaduanId = (int) $item['pengaduan_id'];
    $link = url('/teknisi/tugas/' . $pengaduanId);

    $rows[] = [
        '<a class="link-inline" href="' . e($link) . '">' . e((string) $item['nomor_tiket']) . '</a>',
        e((string) $item['nama_pelanggan_snapshot']),
        e((string) ($item['kategori'] ?? '—')),
        e(format_waktu((string) ($item['assigned_at'] ?? ''))),
        e(format_waktu((string) ($item['started_at'] ?? ''))),
        e(format_waktu((string) ($item['completed_at'] ?? ''))),
        e($ringkas((string) $item['penyebab_gangguan'])),
        e($ringkas((string) $item['tindakan_perbaikan'])),
        status_badge_html((string) $item['status']),
        '<div class="table-actions"><a class="btn btn-outline-light btn-sm" href="' . e($link) . '">'
            . icon('eye') . '<span>Detail</span></a></div>',
    ];
}
?>
<?= component('page-header', [
    'title' => 'Riwayat Pekerjaan',
    'subtitle' => 'Riwayat tugas yang telah Anda selesaikan beserta laporannya.',
    'actions' => [
        ['label' => 'Tugas Saya', 'url' => url('/teknisi/tugas'), 'icon' => 'ticket', 'variant' => 'outline-light'],
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
        <label class="form-label" for="riw-q">Cari nomor tiket / pelanggan</label>
        <input class="form-control" type="search" id="riw-q" name="q"
               value="<?= e((string) ($filters['q'] ?? '')) ?>" placeholder="mis. WFC-20261016-000001 atau nama">
    </div>

    <div class="form-group">
        <label class="form-label" for="riw-kategori">Kategori</label>
        <select class="form-select" id="riw-kategori" name="kategori_id">
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
        <label class="form-label" for="riw-dari">Selesai dari</label>
        <input class="form-control" type="date" id="riw-dari" name="date_from"
               value="<?= e((string) ($filters['date_from'] ?? '')) ?>">
    </div>

    <div class="form-group">
        <label class="form-label" for="riw-sampai">Selesai sampai</label>
        <input class="form-control" type="date" id="riw-sampai" name="date_to"
               value="<?= e((string) ($filters['date_to'] ?? '')) ?>">
    </div>

    <div class="filter-bar-actions">
        <button class="btn btn-primary" type="submit"><?= icon('search') ?><span>Terapkan</span></button>
        <a class="btn btn-outline-light" href="<?= e($baseUrl) ?>"><?= icon('refresh') ?><span>Reset</span></a>
    </div>
</form>

<?= component('table', [
    'id' => 'teknisi-riwayat',
    'search' => false,
    'columns' => [
        ['label' => 'Nomor Tiket'],
        ['label' => 'Pelanggan'],
        ['label' => 'Kategori'],
        ['label' => 'Ditugaskan'],
        ['label' => 'Mulai'],
        ['label' => 'Selesai'],
        ['label' => 'Penyebab'],
        ['label' => 'Tindakan'],
        ['label' => 'Status'],
        ['label' => 'Aksi', 'align' => 'right'],
    ],
    'rows' => $rows,
    'empty' => [
        'icon' => 'report',
        'title' => 'Belum ada riwayat',
        'message' => 'Riwayat pekerjaan akan tampil setelah Anda menyelesaikan tugas dan mengirim laporan.',
    ],
    'pagination' => $total > 0 ? [
        'page' => $page,
        'last' => $pages,
        'total' => $total,
        'baseUrl' => $baseUrl,
        'query' => $query,
    ] : null,
]) ?>
