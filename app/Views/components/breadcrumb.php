<?php
/**
 * Komponen: Breadcrumb.
 *
 * Variabel:
 * - $items : array item: ['label' => string, 'url' => string|null].
 *            Item terakhir dianggap halaman aktif.
 */

declare(strict_types=1);

$items = $items ?? [];
if ($items === []) {
    return;
}
?>
<nav class="app-breadcrumb" aria-label="Breadcrumb">
    <ol>
        <?php foreach ($items as $index => $item): ?>
            <?php $isLast = $index === array_key_last($items); ?>
            <li<?= $isLast ? ' class="is-current" aria-current="page"' : '' ?>>
                <?php if (!$isLast && !empty($item['url'])): ?>
                    <a href="<?= e((string) $item['url']) ?>"><?= e((string) ($item['label'] ?? '')) ?></a>
                <?php else: ?>
                    <span><?= e((string) ($item['label'] ?? '')) ?></span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
</nav>
