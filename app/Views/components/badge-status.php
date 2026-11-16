<?php
/**
 * Komponen: Badge status pengaduan.
 *
 * Hanya menggunakan enam status resmi. Teks selalu ditampilkan,
 * sehingga informasi tidak bergantung pada warna saja.
 *
 * Variabel:
 * - $status : kunci status (mis. menunggu_verifikasi).
 * - $dot    : Tampilkan titik indikator.
 */

declare(strict_types=1);

$status = (string) ($status ?? '');
$dot = $dot ?? true;
?>
<span class="status-badge <?= e(status_class($status)) ?>">
    <?php if ($dot): ?><span class="status-dot" aria-hidden="true"></span><?php endif; ?>
    <?= e(status_label($status)) ?>
</span>
