<?php
/**
 * Admin — Detail Kinerja Teknisi (Tahap 8).
 * Menampilkan ringkasan kinerja dan riwayat pekerjaan seorang teknisi.
 */

declare(strict_types=1);

$content = '';

$teknisi = $teknisi ?? [];
$history = $history ?? [];
$ratingRata = $rating_rata ?? null;
$ratingJumlah = (int) ($rating_jumlah ?? 0);

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
foreach ($history as $item) {
    $isCancelled = (int) $item['is_cancelled'] === 1;
    $isArchived = $item['archived_at'] !== null;

    $statusCell = status_badge_html((string) $item['status'], $isCancelled);
    if ($isArchived) {
        $statusCell .= ' <span class="chip chip--muted">Arsip</span>';
    }
    if ((int) $item['penugasan_aktif'] === 1 && status_is_active((string) $item['status']) && !$isCancelled) {
        $statusCell .= ' <span class="chip chip--info">Tugas aktif</span>';
    }

    $row = [
        '<a class="link-inline" href="' . e(url('/admin/pengaduan/' . (int) $item['id'])) . '">'
            . e((string) $item['nomor_tiket']) . '</a>',
        e((string) $item['nama_pelanggan_snapshot']),
        e((string) ($item['kategori'] ?? '—')),
        $statusCell,
        e(format_waktu((string) $item['created_at'])),
        e($item['completed_at'] !== null ? format_waktu((string) $item['completed_at']) : '—'),
    ];

    if (rating_layanan_enabled()) {
        $row[] = $item['rating'] !== null
            ? '<span class="rating-inline">' . icon('star') . ' ' . e((string) $item['rating']) . '</span>'
            : '<span class="cell-muted">—</span>';
    }

    $rows[] = $row;
}

$columns = [
    ['label' => 'Nomor Tiket'],
    ['label' => 'Pelanggan'],
    ['label' => 'Kategori'],
    ['label' => 'Status'],
    ['label' => 'Dibuat'],
    ['label' => 'Selesai'],
];
if (rating_layanan_enabled()) {
    $columns[] = ['label' => 'Rating'];
}
?>
<?= component('page-header', [
    'title' => 'Kinerja Teknisi ' . e((string) ($teknisi['nama'] ?? '-')),
    'subtitle' => '@' . e((string) ($teknisi['username'] ?? '')) . ' — ringkasan kinerja dan riwayat pekerjaan.',
    'actions' => [
        ['label' => 'Kembali', 'url' => url('/admin/teknisi'), 'icon' => 'arrow-left', 'variant' => 'outline-light'],
    ],
]) ?>

<div class="stat-grid stat-grid--4">
    <?= component('stat-card', [
        'label' => 'Total Tugas',
        'value' => (string) (int) ($teknisi['total_tugas'] ?? 0),
        'icon' => 'ticket',
        'tone' => 'primary',
        'hint' => 'Seluruh tiket yang pernah ditugaskan',
    ]) ?>
    <?= component('stat-card', [
        'label' => 'Tugas Aktif',
        'value' => (string) (int) ($teknisi['aktif'] ?? 0),
        'icon' => 'activity',
        'tone' => 'warning',
        'hint' => 'Sedang menunggu / dikerjakan',
    ]) ?>
    <?= component('stat-card', [
        'label' => 'Tugas Selesai',
        'value' => (string) (int) ($teknisi['selesai'] ?? 0),
        'icon' => 'check',
        'tone' => 'success',
        'hint' => 'Tiket yang telah diselesaikan',
    ]) ?>
    <?= component('stat-card', [
        'label' => 'Rata-rata Penanganan',
        'value' => $formatDuration($teknisi['avg_menit'] ?? null),
        'icon' => 'clock',
        'tone' => 'info',
        'hint' => 'Dari mulai ditangani hingga laporan selesai',
    ]) ?>
</div>

<?php if (rating_layanan_enabled()): ?>
    <section class="card">
        <div class="card-head">
            <h3 class="card-title">Rating Pelanggan</h3>
        </div>
        <div class="card-body">
            <?php if ($ratingRata !== null): ?>
                <div class="rating-display">
                    <span class="rating-score"><?= e(number_format((float) $ratingRata, 1)) ?>/5</span>
                    <span class="cell-muted">dari <?= (int) $ratingJumlah ?> penilaian</span>
                </div>
            <?php else: ?>
                <p class="page-subtitle">Belum ada pelanggan yang menilai pekerjaan teknisi ini.</p>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<?= component('table', [
    'id' => 'teknisi-riwayat',
    'search' => false,
    'columns' => $columns,
    'rows' => $rows,
    'empty' => [
        'icon' => 'ticket',
        'title' => 'Belum ada riwayat',
        'message' => 'Teknisi ini belum pernah menerima penugasan.',
    ],
]) ?>
