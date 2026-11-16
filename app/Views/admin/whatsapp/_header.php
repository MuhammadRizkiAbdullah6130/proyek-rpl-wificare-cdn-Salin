<?= component('page-header', [
    'title' => $pageTitle,
    'subtitle' => $pageSubtitle,
]) ?>

<?php if ($flashSukses !== null): ?>
    <div class="callout callout--success"><?= icon('check') ?><p><?= e((string) $flashSukses) ?></p></div>
<?php endif; ?>
<?php if ($flashError !== null): ?>
    <div class="callout callout--danger"><?= icon('alert') ?><p><?= e((string) $flashError) ?></p></div>
<?php endif; ?>

<?php if (empty($encryptionReady)): ?>
    <div class="alert-inline alert-inline--danger" role="alert">
        <?= icon('alert') ?>
        <span>Master encryption key belum siap. Kredensial <strong>tidak akan disimpan sebagai plaintext</strong>. Atur <code>AI_SETTINGS_KEY</code> atau <code>API_CREDENTIALS_KEY</code> pada environment server.</span>
    </div>
<?php endif; ?>
