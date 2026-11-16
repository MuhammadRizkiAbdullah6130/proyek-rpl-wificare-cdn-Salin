<?php
/**
 * Komponen: Tombol pergantian tema (Light / Dark).
 *
 * Light Mode adalah default; Dark Mode bersifat opsional. Preferensi disimpan
 * di localStorage oleh main.js, dan atribut `data-theme` dipasang sedini
 * mungkin lewat skrip anti-flash pada layout agar tidak ada kedip tema.
 *
 * Variabel:
 * - $floating : bool, menempel di sudut layar (dipakai halaman tanpa header,
 *               mis. login dan halaman error).
 */

declare(strict_types=1);

$floating = (bool) ($floating ?? false);
$classes = 'app-icon-button theme-toggle' . ($floating ? ' theme-toggle--floating' : '');
?>
<button class="<?= e($classes) ?>" type="button" data-theme-toggle
        aria-pressed="false" aria-label="Aktifkan mode gelap" title="Mode gelap">
    <span class="theme-icon-moon" aria-hidden="true"><?= icon('moon') ?></span>
    <span class="theme-icon-sun" aria-hidden="true"><?= icon('sun') ?></span>
</button>
