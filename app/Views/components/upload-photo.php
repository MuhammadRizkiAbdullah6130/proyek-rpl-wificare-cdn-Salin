<?php
/**
 * Komponen: Form upload foto (drag & drop + pratinjau).
 *
 * Variabel:
 * - $id       : ID input berkas.
 * - $name     : Nama field (boleh `foto[]`).
 * - $label    : Label field.
 * - $hint     : Keterangan batas berkas.
 * - $multiple : Izinkan banyak berkas.
 * - $accept   : Daftar MIME yang diterima.
 * - $required : Tandai wajib.
 */

declare(strict_types=1);

$id = $id ?? 'upload-foto';
$name = $name ?? 'foto';
$label = $label ?? 'Foto bukti';
$hint = $hint ?? 'Format JPG, PNG, atau WebP. Maksimal 5 MB per berkas.';
$multiple = $multiple ?? false;
$accept = $accept ?? 'image/jpeg,image/png,image/webp';
$required = $required ?? false;
?>
<div class="form-group">
    <label class="form-label" for="<?= e($id) ?>">
        <?= e($label) ?><?= $required ? ' <span class="form-required">*</span>' : '' ?>
    </label>

    <div class="upload-zone" data-upload>
        <input class="upload-input" type="file" id="<?= e($id) ?>" name="<?= e($name) ?>" accept="<?= e($accept) ?>"
               <?= $multiple ? 'multiple' : '' ?><?= $required ? ' required' : '' ?> data-upload-input>
        <label class="upload-zone-label" for="<?= e($id) ?>">
            <span class="upload-zone-icon"><?= icon('image') ?></span>
            <span class="upload-zone-title">Pilih foto atau seret berkas ke sini</span>
            <span class="upload-zone-hint"><?= e($hint) ?></span>
        </label>
    </div>

    <p class="form-hint" data-upload-error hidden></p>
    <ul class="upload-preview" data-upload-preview></ul>
</div>
