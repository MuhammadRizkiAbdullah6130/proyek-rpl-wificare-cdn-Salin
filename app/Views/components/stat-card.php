<?php
/**
 * Komponen: Kartu statistik.
 *
 * Variabel:
 * - $label : Judul metrik.
 * - $value : Nilai metrik (string). Gunakan '—' bila data belum tersedia.
 * - $icon  : Nama ikon.
 * - $hint  : Keterangan kecil di bawah nilai.
 * - $tone  : primary|success|warning|danger|info.
 * - $demo  : Tampilkan label DEMO pada nilai contoh.
 * - $empty : True bila metrik belum memiliki data.
 */

declare(strict_types=1);

$label = $label ?? '';
$value = $value ?? '—';
$icon = $icon ?? 'report';
$hint = $hint ?? '';
$tone = $tone ?? 'primary';
$demo = $demo ?? false;
$empty = $empty ?? false;
?>
<article class="stat-card stat-card--<?= e($tone) ?>">
    <div class="stat-card-icon"><?= icon($icon) ?></div>
    <div class="stat-card-body">
        <p class="stat-card-label">
            <?= e($label) ?>
            <?php if ($demo): ?><span class="demo-badge">DEMO</span><?php endif; ?>
        </p>
        <p class="stat-card-value<?= $empty ? ' is-empty' : '' ?>"><?= e((string) $value) ?></p>
        <?php if ($hint !== ''): ?>
            <p class="stat-card-hint"><?= e($hint) ?></p>
        <?php endif; ?>
    </div>
</article>
