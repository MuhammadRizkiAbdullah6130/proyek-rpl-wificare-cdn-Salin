<?php
/**
 * Komponen: Bilah pencarian dan filter mandiri.
 *
 * Dipakai pada halaman yang tidak memakai komponen tabel penuh.
 *
 * Variabel:
 * - $target     : ID elemen yang difilter (opsional).
 * - $search     : placeholder pencarian (string) atau false.
 * - $filters    : array filter: ['label' => string, 'options' => array].
 * - $action     : array tombol aksi: ['label', 'url', 'icon', 'variant'].
 * - $demo       : Tandai pratinjau.
 */

declare(strict_types=1);

$target = $target ?? '';
$search = $search ?? 'Cari…';
$filters = $filters ?? [];
$action = $action ?? null;
$demo = $demo ?? false;
?>
<form class="search-filter" role="search" onsubmit="return false;">
    <?php if ($search !== false): ?>
        <div class="search-filter-field">
            <span class="table-search-icon"><?= icon('search') ?></span>
            <label class="visually-hidden" for="filter-search">Pencarian</label>
            <input class="form-control" type="search" id="filter-search" placeholder="<?= e((string) $search) ?>"
                   <?= $target !== '' ? 'data-table-search="' . e($target) . '"' : '' ?>>
        </div>
    <?php endif; ?>

    <?php foreach ($filters as $index => $filter): ?>
        <div class="search-filter-field">
            <label class="visually-hidden" for="filter-<?= e((string) $index) ?>"><?= e((string) ($filter['label'] ?? 'Filter')) ?></label>
            <select class="form-select" id="filter-<?= e((string) $index) ?>"
                    <?= $target !== '' ? 'data-table-filter="' . e($target) . '"' : '' ?>>
                <option value=""><?= e((string) ($filter['label'] ?? 'Filter')) ?></option>
                <?php foreach (($filter['options'] ?? []) as $value => $label): ?>
                    <option value="<?= e((string) $value) ?>"><?= e((string) $label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endforeach; ?>

    <?php if ($demo): ?>
        <span class="demo-badge">DEMO</span>
    <?php endif; ?>

    <?php if (!empty($action)): ?>
        <a class="btn btn-<?= e((string) ($action['variant'] ?? 'primary')) ?> btn-sm search-filter-action"
           href="<?= e((string) ($action['url'] ?? '#')) ?>">
            <?php if (!empty($action['icon'])): ?><?= icon((string) $action['icon']) ?><?php endif; ?>
            <span><?= e((string) ($action['label'] ?? 'Aksi')) ?></span>
        </a>
    <?php endif; ?>
</form>
