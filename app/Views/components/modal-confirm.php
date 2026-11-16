<?php
/**
 * Komponen: Modal konfirmasi reusable.
 *
 * Modal generik global disediakan layout (`#appConfirmModal`) dan dipicu
 * melalui atribut data-confirm pada form/tautan/tombol, atau secara programatik
 * lewat WifiCare.confirm(). Komponen ini juga dapat dipakai untuk modal khusus
 * halaman dengan isi/footer kustom.
 *
 * Variabel:
 * - $id           : ID modal.
 * - $title        : Judul modal.
 * - $message      : Isi pesan (boleh kosong bila memakai $body).
 * - $confirmLabel : Teks tombol konfirmasi.
 * - $cancelLabel  : Teks tombol batal.
 * - $variant      : primary|danger|success|warning|info (warna tombol/ikon).
 * - $icon         : Nama ikon (check|x|alert|info|upload|tool|lock|trash|star|bell|settings).
 * - $body         : HTML isi tambahan (opsional) yang sudah aman.
 * - $footer       : HTML footer kustom (opsional) yang sudah aman.
 */

declare(strict_types=1);

$id = $id ?? 'modal-confirm';
$title = $title ?? 'Konfirmasi tindakan';
$message = $message ?? 'Apakah Anda yakin ingin melanjutkan?';
$confirmLabel = $confirmLabel ?? 'Ya, lanjutkan';
$cancelLabel = $cancelLabel ?? 'Batal';
$variant = $variant ?? 'primary';
$icon = $icon ?? 'alert';
$body = $body ?? '';
$footer = $footer ?? '';

$allowedVariants = ['primary', 'danger', 'success', 'warning', 'info'];
if (!in_array($variant, $allowedVariants, true)) {
    $variant = 'primary';
}

$titleId = $id . '-title';
$descriptionId = $id . '-desc';
?>
<div class="app-modal-backdrop" data-modal="<?= e($id) ?>" hidden>
    <div class="app-modal app-modal--confirm app-modal--<?= e($variant) ?>"
         role="dialog" aria-modal="true" aria-labelledby="<?= e($titleId) ?>"
         <?= $message !== '' ? 'aria-describedby="' . e($descriptionId) . '"' : '' ?>>
        <button class="app-modal-close" type="button" data-modal-close aria-label="Tutup dialog">
            <?= icon('x') ?>
        </button>

        <div class="app-modal-body">
            <div class="app-modal-icon app-modal-icon--<?= e($variant) ?>" data-modal-icon>
                <?= icon($icon) ?>
            </div>
            <h3 class="app-modal-title" id="<?= e($titleId) ?>"><?= e($title) ?></h3>
            <?php if ($message !== ''): ?>
                <p class="app-modal-message" id="<?= e($descriptionId) ?>"><?= e($message) ?></p>
            <?php endif; ?>
            <?= $body ?>
        </div>

        <div class="app-modal-foot">
            <?php if ($footer !== ''): ?>
                <?= $footer ?>
            <?php else: ?>
                <button class="btn btn-outline-light" type="button" data-modal-close>
                    <span data-modal-cancel-label><?= e($cancelLabel) ?></span>
                </button>
                <button class="btn btn-<?= e($variant) ?>" type="button" data-modal-confirm>
                    <span data-modal-confirm-label><?= e($confirmLabel) ?></span>
                </button>
            <?php endif; ?>
        </div>
    </div>
</div>
