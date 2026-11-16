<?php
/**
 * Admin — Riwayat Pengaduan Pelanggan (detail) (Tahap 8/10).
 *
 * Menampilkan identitas pelanggan (termasuk status akun) dan seluruh riwayat
 * tiketnya. Tidak ada data rahasia (password akun maupun jaringan PPPoE).
 */

declare(strict_types=1);
$content = '';

$pelanggan = $pelanggan ?? [];
$tickets = $tickets ?? [];
$flashSukses = $flash_sukses ?? null;
$flashError = $flash_error ?? null;

$totalTiket = count($tickets);
$aktif = 0;
$selesai = 0;
$dibatalkan = 0;
foreach ($tickets as $ticket) {
    $status = (string) $ticket['status'];
    if ((int) $ticket['is_cancelled'] === 1) {
        $dibatalkan++;
    } elseif ($status === 'selesai') {
        $selesai++;
    } elseif (status_is_active($status)) {
        $aktif++;
    }
}

$isActive = (int) ($pelanggan['is_active'] ?? 0) === 1;

$rows = [];
foreach ($tickets as $ticket) {
    $id = (int) $ticket['id'];
    $link = url('/admin/pengaduan/' . $id);

    $hasil = '—';
    if (!empty($ticket['hasil_teknisi'])) {
        $hasil = (string) $ticket['hasil_teknisi'];
    } elseif (!empty($ticket['hasil_cs'])) {
        $hasil = (string) $ticket['hasil_cs'];
    }

    $rows[] = [
        '<a class="link-inline" href="' . e($link) . '">' . e((string) $ticket['nomor_tiket']) . '</a>',
        e((string) ($ticket['kategori'] ?? '—')),
        status_badge_html((string) $ticket['status'], (int) $ticket['is_cancelled'] === 1),
        e(format_waktu((string) $ticket['created_at'])),
        e(!empty($ticket['completed_at']) ? format_waktu((string) $ticket['completed_at']) : '—'),
        '<span class="cell-muted">' . e(mb_strimwidth($hasil, 0, 90, '…')) . '</span>',
        '<div class="table-actions"><a class="btn btn-outline-light btn-sm" href="' . e($link) . '">'
            . icon('eye') . '<span>Detail</span></a></div>',
    ];
}
?>
<?= component('page-header', [
    'title' => 'Riwayat Pelanggan',
    'subtitle' => 'Identitas pelanggan dan seluruh riwayat pengaduannya.',
    'actions' => [
        ['label' => 'Kembali', 'url' => url('/admin/pelanggan'), 'icon' => 'arrow-left', 'variant' => 'outline-light'],
    ],
]) ?>

<?php if ($flashSukses !== null): ?>
    <div class="callout callout--success"><?= icon('check') ?><p><?= e($flashSukses) ?></p></div>
<?php endif; ?>
<?php if ($flashError !== null): ?>
    <div class="callout callout--danger"><?= icon('alert') ?><p><?= e($flashError) ?></p></div>
<?php endif; ?>

<div class="grid-2 grid-2--detail">
    <section class="card">
        <div class="card-head">
            <h3 class="card-title">Identitas Pelanggan</h3>
        </div>
        <div class="card-body">
            <dl class="detail-list">
                <div><dt>Nama</dt><dd><?= e((string) ($pelanggan['nama'] ?? '-')) ?></dd></div>
                <div><dt>Username PPPoE</dt><dd><?= e((string) ($pelanggan['username_pppoe'] ?? '-')) ?></dd></div>
                <div><dt>Nomor WhatsApp</dt><dd><?= e((string) ($pelanggan['nomor_whatsapp'] ?? '-')) ?></dd></div>
                <div><dt>Alamat</dt><dd><?= e((string) ($pelanggan['alamat'] ?? '—')) ?></dd></div>
                <div><dt>Status Akun</dt><dd>
                    <?php if ($isActive): ?>
                        <span class="chip chip--success">Aktif</span>
                    <?php else: ?>
                        <span class="chip chip--muted">Nonaktif</span>
                    <?php endif; ?>
                </dd></div>
            </dl>
            <div class="callout callout--info">
                <?= icon('info') ?>
                <p>Password akun dan password jaringan PPPoE tidak pernah disimpan atau ditampilkan di sistem ini.</p>
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card-head">
            <h3 class="card-title">Ringkasan</h3>
        </div>
        <div class="card-body">
            <dl class="detail-list">
                <div><dt>Total pengaduan</dt><dd><?= e((string) $totalTiket) ?></dd></div>
                <div><dt>Tiket aktif</dt><dd><?= e((string) $aktif) ?></dd></div>
                <div><dt>Selesai</dt><dd><?= e((string) $selesai) ?></dd></div>
                <div><dt>Dibatalkan</dt><dd><?= e((string) $dibatalkan) ?></dd></div>
            </dl>
        </div>
    </section>
</div>

<?= component('table', [
    'id' => 'admin-pelanggan-riwayat',
    'search' => false,
    'columns' => [
        ['label' => 'Nomor Tiket'],
        ['label' => 'Kategori'],
        ['label' => 'Status'],
        ['label' => 'Diajukan'],
        ['label' => 'Selesai'],
        ['label' => 'Penyelesaian'],
        ['label' => 'Aksi', 'align' => 'right'],
    ],
    'rows' => $rows,
    'empty' => [
        'icon' => 'ticket',
        'title' => 'Belum ada pengaduan',
        'message' => 'Pelanggan ini belum pernah mengajukan pengaduan.',
    ],
]) ?>
