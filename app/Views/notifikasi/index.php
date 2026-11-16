<?php
/**
 * Notifikasi in-app (Tahap 8).
 *
 * Daftar notifikasi milik pengguna yang sedang masuk. Menandai dibaca sekaligus
 * mengarahkan ke detail tiket terkait (sesuai peran) melalui endpoint POST.
 */

declare(strict_types=1);

$content = '';

$items = $items ?? [];
$total = (int) ($total ?? 0);
$pages = (int) ($pages ?? 1);
$page = (int) ($page ?? 1);
$unread = (int) ($unread ?? 0);
$flashSukses = $flash_sukses ?? null;
$flashError = $flash_error ?? null;

$baseUrl = url('/notifikasi');
$query = [];
?>
<?= component('page-header', [
    'title' => 'Notifikasi',
    'subtitle' => ($unread > 0 ? $unread . ' notifikasi belum dibaca.' : 'Semua notifikasi sudah dibaca.'),
    'actions' => $unread > 0 ? [
        ['label' => 'Tandai semua dibaca', 'icon' => 'check', 'variant' => 'outline-light', 'modal' => 'notif-read-all'],
    ] : [],
]) ?>

<?php if ($flashSukses !== null): ?>
    <div class="callout callout--success"><?= icon('check') ?><p><?= e($flashSukses) ?></p></div>
<?php endif; ?>
<?php if ($flashError !== null): ?>
    <div class="callout callout--danger"><?= icon('alert') ?><p><?= e($flashError) ?></p></div>
<?php endif; ?>

<?php if (empty($items)): ?>
    <?= component('empty-state', [
        'icon' => 'bell',
        'title' => 'Belum ada notifikasi',
        'message' => 'Notifikasi akan muncul ketika tiket dibuat atau statusnya berubah.',
    ]) ?>
<?php else: ?>
    <ul class="notif-page-list">
        <?php foreach ($items as $item): ?>
            <?php $isUnread = ($item['read_at'] ?? null) === null; ?>
            <li class="notif-page-item<?= $isUnread ? ' is-unread' : '' ?>">
                <form method="post" action="<?= e(url('/notifikasi/' . (int) $item['id'] . '/baca')) ?>" class="notif-page-form">
                    <?= csrf_field() ?>
                    <button type="submit" class="notif-page-button">
                        <span class="notif-page-head">
                            <span class="notif-page-title">
                                <?php if ($isUnread): ?><span class="app-notif-dot" aria-hidden="true"></span><?php endif; ?>
                                <?= e((string) $item['judul']) ?>
                            </span>
                            <span class="notif-page-time"><?= e(format_waktu((string) $item['created_at'])) ?></span>
                        </span>
                        <span class="notif-page-message"><?= e((string) $item['pesan']) ?></span>
                    </button>
                </form>
            </li>
        <?php endforeach; ?>
    </ul>

    <?= component('pagination', [
        'page' => $page,
        'last' => $pages,
        'total' => $total,
        'baseUrl' => $baseUrl,
        'query' => $query,
    ]) ?>
<?php endif; ?>

<?php if ($unread > 0): ?>
    <?= component_html('modal-confirm', [
        'id' => 'notif-read-all',
        'title' => 'Tandai semua dibaca',
        'message' => 'Tandai seluruh notifikasi sebagai sudah dibaca?',
        'variant' => 'primary',
        'icon' => 'bell',
        'footer' => '<button class="btn btn-outline-light btn-sm" type="button" data-modal-close>Batal</button>'
            . '<button class="btn btn-primary btn-sm" type="button" data-modal-confirm data-modal-submit="notif-read-all-form">'
            . '<span data-modal-confirm-label>Ya, tandai semua</span></button>',
    ]) ?>
    <form id="notif-read-all-form" method="post" action="<?= e(url('/notifikasi/baca-semua')) ?>" hidden>
        <?= csrf_field() ?>
    </form>
<?php endif; ?>
