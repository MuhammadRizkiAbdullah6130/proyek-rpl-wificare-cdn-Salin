<?php
/**
 * Komponen: Tampilan kosong (empty state) dan status error/loading.
 *
 * Variabel:
 * - $icon    : Nama ikon.
 * - $title   : Judul pesan.
 * - $message : Penjelasan.
 * - $action  : array ['label', 'url', 'icon'] (opsional).
 * - $compact : Versi ringkas untuk dropdown/panel kecil.
 */

declare(strict_types=1);

$icon = $icon ?? 'inbox';
$title = $title ?? 'Belum ada data';
$message = $message ?? '';
$action = $action ?? null;
$compact = $compact ?? false;
?>
<div class="empty-state<?= $compact ? ' empty-state--compact' : '' ?>" role="status">
    <span class="empty-state-icon"><?= icon($icon) ?></span>
    <p class="empty-state-title"><?= e($title) ?></p>
    <?php if ($message !== ''): ?>
        <p class="empty-state-message"><?= e($message) ?></p>
    <?php endif; ?>
    <?php if (!empty($action)): ?>
        <a class="btn btn-primary btn-sm" href="<?= e((string) ($action['url'] ?? '#')) ?>">
            <?php if (!empty($action['icon'])): ?><?= icon((string) $action['icon']) ?><?php endif; ?>
            <span><?= e((string) ($action['label'] ?? 'Lanjut')) ?></span>
        </a>
    <?php endif; ?>
</div>
