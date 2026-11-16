<?php
/**
 * Pelanggan — Rating Layanan (Tahap 8).
 *
 * Menampilkan seluruh tiket selesai milik pelanggan beserta status ratingnya.
 * Rating bersifat sekali per tiket (tidak dapat diubah setelah dikirim).
 */

declare(strict_types=1);

$content = '';

$tickets = $tickets ?? [];
$flashSukses = $flash_sukses ?? null;
$flashError = $flash_error ?? null;

$stars = static function (int $value): string {
    $html = '<span class="rating-display" aria-label="Rating ' . $value . ' dari 5">';
    for ($i = 1; $i <= 5; $i++) {
        $html .= '<span class="rating-star' . ($i <= $value ? ' is-on' : '') . '">' . icon('star') . '</span>';
    }
    $html .= '<span class="rating-score">' . $value . '/5</span></span>';

    return $html;
};
?>
<?= component('page-header', [
    'title' => 'Rating Layanan',
    'subtitle' => 'Beri penilaian untuk tiket yang telah selesai ditangani. Rating hanya dapat diberikan sekali per tiket.',
]) ?>

<?php if ($flashSukses !== null): ?>
    <div class="callout callout--success"><?= icon('check') ?><p><?= e($flashSukses) ?></p></div>
<?php endif; ?>
<?php if ($flashError !== null): ?>
    <div class="callout callout--danger"><?= icon('alert') ?><p><?= e($flashError) ?></p></div>
<?php endif; ?>

<?php if (empty($tickets)): ?>
    <?= component('empty-state', [
        'icon' => 'star',
        'title' => 'Belum ada tiket selesai',
        'message' => 'Rating dapat diberikan setelah pengaduan Anda selesai ditangani.',
        'action' => ['label' => 'Buat Pengaduan', 'url' => url('/pelanggan/pengaduan/baru'), 'icon' => 'plus'],
    ]) ?>
<?php else: ?>
    <div class="rating-card-list">
        <?php foreach ($tickets as $ticket): ?>
            <?php
            $id = (int) $ticket['id'];
            $hasRating = $ticket['rating'] !== null;
            ?>
            <section class="card rating-card">
                <div class="card-head">
                    <h3 class="card-title">
                        <a class="link-inline" href="<?= e(url('/pelanggan/pengaduan/' . $id)) ?>"><?= e((string) $ticket['nomor_tiket']) ?></a>
                    </h3>
                    <span class="card-head-note">Selesai <?= e(format_waktu((string) ($ticket['completed_at'] ?? $ticket['created_at']))) ?></span>
                </div>
                <div class="card-body">
                    <?php if ($hasRating): ?>
                        <?= $stars((int) $ticket['rating']) ?>
                        <?php if (!empty($ticket['ulasan'])): ?>
                            <p class="detail-text"><?= nl2br(e((string) $ticket['ulasan'])) ?></p>
                        <?php endif; ?>
                        <p class="timeline-meta">Dinilai <?= e(format_waktu((string) ($ticket['rating_at'] ?? ''))) ?></p>
                    <?php else: ?>
                        <form class="form-stack" method="post" action="<?= e(url('/pelanggan/pengaduan/' . $id . '/rating')) ?>"
                              data-confirm
                              data-confirm-title="Kirim rating ini?"
                              data-confirm-message="Rating hanya dapat diberikan sekali per tiket dan tidak dapat diubah setelah dikirim."
                              data-confirm-label="Ya, kirim rating"
                              data-confirm-variant="primary"
                              data-confirm-icon="star"
                              data-confirm-loading="Mengirim rating...">
                            <?= csrf_field() ?>
                            <div class="form-group">
                                <p class="form-label">Penilaian Anda</p>
                                <div class="rating-stars" data-rating data-value="0" role="radiogroup" aria-label="Pilih rating 1 sampai 5">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <button type="button" class="rating-star" data-rating-value="<?= $i ?>" aria-checked="false" aria-label="<?= $i ?> bintang">
                                            <?= icon('star') ?>
                                        </button>
                                    <?php endfor; ?>
                                    <input type="hidden" name="rating" value="0">
                                </div>
                                <p class="form-hint" data-rating-text>Pilih jumlah bintang (1–5).</p>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="ulasan-<?= $id ?>">Ulasan (opsional)</label>
                                <textarea class="form-control" id="ulasan-<?= $id ?>" name="ulasan" rows="3" maxlength="1000" placeholder="Ceritakan pengalaman Anda"></textarea>
                            </div>
                            <div class="form-actions">
                                <button class="btn btn-primary" type="submit"><?= icon('star') ?><span>Kirim Rating</span></button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
