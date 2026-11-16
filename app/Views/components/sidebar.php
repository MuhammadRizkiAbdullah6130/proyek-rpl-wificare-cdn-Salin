<?php
/**
 * Komponen: Sidebar kiri.
 *
 * Variabel:
 * - $items     : array menu (lihat role_menu()).
 * - $active    : kunci menu aktif.
 * - $role      : kunci peran untuk label footer.
 * - $variant   : 'app' atau 'public'.
 */

declare(strict_types=1);

$items = $items ?? [];
$active = $active ?? '';
$role = $role ?? 'publik';
$variant = $variant ?? 'app';
?>
<aside class="app-sidebar" data-sidebar data-variant="<?= e($variant) ?>">
    <div class="app-sidebar-head">
        <a class="app-brand" href="<?= e(url('/')) ?>">
            <?php component('brand', ['variant' => 'signal']); ?>
            <span class="app-brand-text">WiFiCare CDN</span>
        </a>
        <button class="app-sidebar-close" type="button" data-menu-close aria-label="Tutup menu">
            <?= icon('x') ?>
        </button>
    </div>

    <nav class="app-sidebar-nav" aria-label="Navigasi utama">
        <?php foreach ($items as $item): ?>
            <?php if (!empty($item['group'])): ?>
                <p class="app-sidebar-group"><?= e($item['group']) ?></p>
                <?php continue; ?>
            <?php endif; ?>
            <?php
            $children = is_array($item['children'] ?? null) ? $item['children'] : [];
            $parentActive = false;
            foreach ($children as $child) {
                if ((string) ($child['key'] ?? '') === $active) {
                    $parentActive = true;
                    break;
                }
            }
            $submenuId = 'sidebar-submenu-' . preg_replace('/[^a-z0-9_-]+/i', '-', (string) ($item['key'] ?? 'menu'));
            ?>
            <?php if ($children !== []): ?>
                <div class="app-sidebar-item<?= $parentActive ? ' is-active-parent' : '' ?>" data-sidebar-submenu>
                    <button class="app-sidebar-link app-sidebar-toggle<?= $parentActive ? ' is-active-parent' : '' ?>"
                            type="button"
                            data-submenu-toggle
                            aria-expanded="<?= $parentActive ? 'true' : 'false' ?>"
                            aria-controls="<?= e($submenuId) ?>">
                        <?= icon((string) ($item['icon'] ?? 'info')) ?>
                        <span><?= e((string) ($item['label'] ?? '')) ?></span>
                        <span class="app-sidebar-chevron"><?= icon('chevron-down') ?></span>
                    </button>
                    <div class="app-sidebar-submenu" id="<?= e($submenuId) ?>" data-submenu-panel<?= $parentActive ? '' : ' hidden' ?>>
                        <?php foreach ($children as $child): ?>
                            <a class="app-sidebar-sublink<?= is_active((string) ($child['key'] ?? ''), $active) ?>"
                               href="<?= e((string) ($child['url'] ?? '#')) ?>"
                               <?= (string) ($child['key'] ?? '') === $active ? 'aria-current="page"' : '' ?>>
                                <span class="app-sidebar-subdot" aria-hidden="true"></span>
                                <span><?= e((string) ($child['label'] ?? '')) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <a class="app-sidebar-link<?= is_active((string) ($item['key'] ?? ''), $active) ?>"
                   href="<?= e((string) ($item['url'] ?? '#')) ?>">
                    <?= icon((string) ($item['icon'] ?? 'info')) ?>
                    <span><?= e((string) ($item['label'] ?? '')) ?></span>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>

    <div class="app-sidebar-footer">
        <span class="app-sidebar-role"><?= e(role_label($role)) ?></span>
        <span class="app-sidebar-muted">WiFiCare CDN v1.0</span>
    </div>
</aside>
