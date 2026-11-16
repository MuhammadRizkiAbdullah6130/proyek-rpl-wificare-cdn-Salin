<?php
/**
 * Customer Service — Catatan Internal (Tahap 6).
 *
 * Daftar catatan internal terbaru lintas tiket. Catatan hanya dilihat
 * petugas (CS/Admin) dan TIDAK PERNAH ditampilkan pada halaman pelanggan.
 * Penambahan catatan dilakukan dari halaman detail tiket.
 */

declare(strict_types=1);
$content = '';

$notes = $notes ?? [];
$total = (int) ($total ?? 0);
$flashSukses = $flash_sukses ?? null;
$flashError = $flash_error ?? null;
?>
<?= component('page-header', [
    'title' => 'Catatan Internal',
    'subtitle' => 'Catatan CS yang tidak terlihat oleh pelanggan.',
]) ?>

<?php if ($flashSukses !== null): ?>
    <div class="callout callout--success"><?= icon('check') ?><p><?= e($flashSukses) ?></p></div>
<?php endif; ?>
<?php if ($flashError !== null): ?>
    <div class="callout callout--danger"><?= icon('alert') ?><p><?= e($flashError) ?></p></div>
<?php endif; ?>

<section class="card">
    <div class="card-head">
        <h3 class="card-title">Catatan Terbaru</h3>
        <span class="card-head-note"><?= e((string) $total) ?> catatan</span>
    </div>
    <div class="card-body">
        <?php if (empty($notes)): ?>
            <?= component_html('empty-state', [
                'icon' => 'note',
                'title' => 'Belum ada catatan',
                'message' => 'Catatan internal ditambahkan dari halaman detail tiket.',
            ]) ?>
        <?php else: ?>
            <div class="note-list">
                <?php foreach ($notes as $note): ?>
                    <article class="note-item">
                        <div class="note-item-head">
                            <span class="note-item-actor">
                                <?= icon('user') ?> <?= e((string) ($note['cs_nama'] ?? 'Petugas')) ?>
                            </span>
                            <span class="note-item-time"><?= e(format_waktu((string) $note['created_at'])) ?></span>
                        </div>
                        <p class="note-item-text"><?= nl2br(e((string) $note['catatan'])) ?></p>
                        <p class="note-item-time">
                            Tiket
                            <a class="link-inline" href="<?= e(url('/cs/pengaduan/' . (int) $note['pengaduan_id'])) ?>">
                                <?= e((string) $note['nomor_tiket']) ?>
                            </a>
                            <?= status_badge_html((string) $note['status'], (int) $note['is_cancelled'] === 1) ?>
                        </p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
