<?php
/**
 * Komponen: Skeleton / loading state.
 *
 * Variabel:
 * - $lines : Jumlah baris skeleton.
 */

declare(strict_types=1);

$lines = (int) ($lines ?? 3);
?>
<div class="skeleton" aria-hidden="true" aria-busy="true">
    <?php for ($i = 0; $i < $lines; $i++): ?>
        <span class="skeleton-line" style="width: <?= e((string) (90 - ($i * 12))) ?>%"></span>
    <?php endfor; ?>
</div>
