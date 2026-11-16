<?php
/**
 * Komponen: Tabel responsif dengan pencarian, filter, dan pagination.
 *
 * Variabel:
 * - $id         : ID tabel (dipakai pencarian klien).
 * - $columns    : array kolom: ['label' => string, 'align' => left|center|right].
 * - $rows       : array baris; setiap sel berupa string HTML yang SUDAH di-escape
 *                 pemanggil (gunakan e() / component_html()).
 * - $empty      : array untuk komponen empty-state bila $rows kosong.
 * - $search     : placeholder pencarian; false untuk menyembunyikan.
 * - $filters    : array filter select: ['label' => string, 'options' => array].
 * - $pagination : array untuk komponen pagination.
 * - $demo       : Tampilkan label DEMO pada toolbar.
 */

declare(strict_types=1);

$id = $id ?? 'tabel';
$columns = $columns ?? [];
$rows = $rows ?? [];
$empty = $empty ?? ['title' => 'Belum ada data'];
$search = $search ?? 'Cari data…';
$filters = $filters ?? [];
$pagination = $pagination ?? null;
$demo = $demo ?? false;
?>
<div class="table-card">
    <?php if ($search !== false || !empty($filters) || $demo): ?>
        <div class="table-toolbar">
            <?php if ($search !== false): ?>
                <div class="table-search">
                    <span class="table-search-icon"><?= icon('search') ?></span>
                    <label class="visually-hidden" for="<?= e($id) ?>-search">Pencarian tabel</label>
                    <input class="form-control" type="search" id="<?= e($id) ?>-search"
                           data-table-search="<?= e($id) ?>" placeholder="<?= e((string) $search) ?>">
                </div>
            <?php endif; ?>

            <?php foreach ($filters as $filter): ?>
                <div class="table-filter">
                    <label class="visually-hidden" for="<?= e($id) ?>-filter-<?= e((string) ($filter['label'] ?? 'f')) ?>">
                        <?= e((string) ($filter['label'] ?? 'Filter')) ?>
                    </label>
                    <select class="form-select" data-table-filter="<?= e($id) ?>">
                        <option value=""><?= e((string) ($filter['label'] ?? 'Filter')) ?></option>
                        <?php foreach (($filter['options'] ?? []) as $optionValue => $optionLabel): ?>
                            <option value="<?= e((string) $optionValue) ?>"><?= e((string) $optionLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endforeach; ?>

            <?php if ($demo): ?>
                <span class="demo-badge ms-auto">DEMO</span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="table app-table" id="<?= e($id) ?>">
            <thead>
                <tr>
                    <?php foreach ($columns as $column): ?>
                        <th scope="col" class="text-<?= e((string) ($column['align'] ?? 'left')) ?>">
                            <?= e((string) ($column['label'] ?? '')) ?>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr class="table-empty-row">
                        <td colspan="<?= count($columns) > 0 ? count($columns) : 1 ?>">
                            <?= component_html('empty-state', $empty) ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <?php foreach ($row as $cell): ?>
                                <td><?= $cell ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <p class="table-no-result" data-table-empty hidden>Data tidak ditemukan pada pencarian ini.</p>

    <?php if (!empty($pagination)): ?>
        <?= component_html('pagination', $pagination) ?>
    <?php endif; ?>
</div>
