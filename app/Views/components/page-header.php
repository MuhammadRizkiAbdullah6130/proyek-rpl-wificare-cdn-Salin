<?php
/**
 * Komponen: Kepala halaman (judul, subjudul, tombol aksi).
 *
 * Variabel:
 * - $title    : Judul halaman.
 * - $subtitle : Deskripsi singkat.
 * - $demo     : Tampilkan label DEMO.
 * - $actions  : array tombol: label, url, icon, variant, modal, attrs.
 */

declare(strict_types=1);

$title = $title ?? '';
$subtitle = $subtitle ?? '';
$demo = $demo ?? false;
$actions = $actions ?? [];
?>
<div class="page-header">
    <div class="page-header-text">
        <h2 class="page-title">
            <?= e($title) ?>
            <?php if ($demo): ?>
                <span class="demo-badge" title="Tampilan pratinjau — belum terhubung ke database">DEMO</span>
            <?php endif; ?>
        </h2>
        <?php if ($subtitle !== ''): ?>
            <p class="page-subtitle"><?= e($subtitle) ?></p>
        <?php endif; ?>
    </div>

    <?php if (!empty($actions)): ?>
        <div class="page-header-actions">
            <?php foreach ($actions as $action): ?>
                <?php
                $attrs = '';
                if (!empty($action['modal'])) {
                    $attrs .= ' data-modal-open="' . e((string) $action['modal']) . '"';
                }
                if (!empty($action['attrs'])) {
                    $attrs .= ' ' . $action['attrs'];
                }
                ?>
                <a class="btn btn-<?= e((string) ($action['variant'] ?? 'primary')) ?> btn-sm"
                   href="<?= e((string) ($action['url'] ?? '#')) ?>"<?= $attrs ?>>
                    <?php if (!empty($action['icon'])): ?><?= icon((string) $action['icon']) ?><?php endif; ?>
                    <span><?= e((string) ($action['label'] ?? 'Aksi')) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
