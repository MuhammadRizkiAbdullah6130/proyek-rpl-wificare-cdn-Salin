<?php
/**
 * Admin — Pengaturan Aplikasi (Tahap 8).
 *
 * Hanya menyimpan konfigurasi NONRAHASIA. Kredensial database/API key tidak
 * pernah disimpan maupun ditampilkan di halaman ini.
 */

declare(strict_types=1);

$content = '';

$settings = $settings ?? [];
$flashSukses = $flash_sukses ?? null;
$flashError = $flash_error ?? null;

$value = static fn (string $key, string $default = ''): string => (string) ($settings[$key] ?? $default);
?>
<?= component('page-header', [
    'title' => 'Pengaturan Aplikasi',
    'subtitle' => 'Konfigurasi identitas dan kontak aplikasi yang tampil di antarmuka.',
]) ?>

<?php if ($flashSukses !== null): ?>
    <div class="callout callout--success"><?= icon('check') ?><p><?= e($flashSukses) ?></p></div>
<?php endif; ?>
<?php if ($flashError !== null): ?>
    <div class="callout callout--danger"><?= icon('alert') ?><p><?= e($flashError) ?></p></div>
<?php endif; ?>

<div class="grid-2">
    <section class="card">
        <div class="card-head">
            <h3 class="card-title">Identitas &amp; Kontak</h3>
        </div>
        <div class="card-body">
            <form class="form-stack" method="post" action="<?= e(url('/admin/pengaturan')) ?>">
                <?= csrf_field() ?>

                <div class="form-group">
                    <label class="form-label" for="st-app">Nama aplikasi <span aria-hidden="true">*</span></label>
                    <input class="form-control" id="st-app" name="app_name" value="<?= e($value('app_name', 'WiFiCare CDN')) ?>" required maxlength="120">
                </div>

                <div class="form-group">
                    <label class="form-label" for="st-isp">Nama ISP</label>
                    <input class="form-control" id="st-isp" name="isp_name" value="<?= e($value('isp_name')) ?>" maxlength="120">
                </div>

                <div class="form-group">
                    <label class="form-label" for="st-email">Email kontak</label>
                    <input class="form-control" id="st-email" type="email" name="contact_email" value="<?= e($value('contact_email')) ?>" maxlength="120">
                </div>

                <div class="form-group">
                    <label class="form-label" for="st-phone">Telepon kontak</label>
                    <input class="form-control" id="st-phone" name="contact_phone" value="<?= e($value('contact_phone')) ?>" maxlength="30">
                </div>

                <div class="form-group">
                    <label class="form-label" for="st-wa">Nomor WhatsApp kontak</label>
                    <input class="form-control" id="st-wa" name="contact_whatsapp" value="<?= e($value('contact_whatsapp')) ?>" maxlength="20">
                </div>

                <div class="form-group">
                    <label class="form-label" for="st-upload">Batas ukuran upload (MB)</label>
                    <input class="form-control" id="st-upload" type="number" name="upload_max_mb" min="1" max="50" value="<?= e($value('upload_max_mb', '5')) ?>" required>
                    <p class="form-hint">Nilai 1–50 MB. Berlaku untuk unggahan foto pengaduan dan laporan teknisi.</p>
                </div>

                <div class="form-actions">
                    <button class="btn btn-primary" type="submit"><?= icon('check') ?><span>Simpan Pengaturan</span></button>
                </div>
            </form>
        </div>
    </section>

    <section class="card">
        <div class="card-head">
            <h3 class="card-title">Informasi Sistem</h3>
        </div>
        <div class="card-body">
            <dl class="detail-list">
                <div><dt>Prefix nomor tiket</dt><dd><?= e($value('ticket_prefix', 'WFC')) ?></dd></div>
                <div><dt>Modul AI Assistant</dt><dd><?= AiSettingsService::isEnabled() ? 'Aktif' : 'Nonaktif' ?></dd></div>
                <div><dt>Mode pemeliharaan</dt><dd><?= (int) $value('maintenance_mode', '0') === 1 ? 'Aktif' : 'Nonaktif' ?></dd></div>
            </dl>
            <div class="callout callout--info">
                <?= icon('shield') ?>
                <p>Kredensial database, kata sandi, dan API key <strong>tidak pernah</strong> disimpan atau ditampilkan melalui halaman ini.</p>
            </div>
        </div>
    </section>
</div>
