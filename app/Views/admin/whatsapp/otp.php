<?php
/**
 * Admin — submenu WhatsApp: OTP WhatsApp.
 *
 * Halaman mandiri; hanya memuat konfigurasi yang relevan.
 */

declare(strict_types=1);

require __DIR__ . '/_init.php';
require __DIR__ . '/_header.php';
?>

<section class="card wa-settings" id="otp">
    <div class="card-head">
        <h3 class="card-title">Konfigurasi OTP WhatsApp</h3>
        <span class="chip <?= !empty($otp['is_enabled']) ? 'chip--success' : 'chip--muted' ?>">
            <?= !empty($otp['is_enabled']) ? 'Aktif' : 'Nonaktif' ?>
        </span>
    </div>
    <div class="card-body wa-settings__body">
        <section class="wa-settings-section" aria-labelledby="otp-stats-title">
            <div class="wa-settings-section__head">
                <h4 id="otp-stats-title">Ringkasan Penggunaan OTP</h4>
                <p class="form-hint">Angka berasal dari monitoring OTP terbaru.</p>
            </div>
            <div class="stat-grid stat-grid--3 wa-stat-grid">
                <div class="stat-card"><div class="stat-card-body"><div class="stat-card-label">OTP diminta</div><div class="stat-card-value"><?= $formatNumber($otpStats['requested'] ?? 0) ?></div></div></div>
                <div class="stat-card stat-card--success"><div class="stat-card-body"><div class="stat-card-label">Berhasil dikirim</div><div class="stat-card-value"><?= $formatNumber($otpStats['sent'] ?? 0) ?></div></div></div>
                <div class="stat-card stat-card--danger"><div class="stat-card-body"><div class="stat-card-label">Gagal dikirim</div><div class="stat-card-value"><?= $formatNumber($otpStats['failed'] ?? 0) ?></div></div></div>
                <div class="stat-card"><div class="stat-card-body"><div class="stat-card-label">Terverifikasi</div><div class="stat-card-value"><?= $formatNumber($otpStats['verified'] ?? 0) ?></div></div></div>
                <div class="stat-card stat-card--warning"><div class="stat-card-body"><div class="stat-card-label">Kedaluwarsa</div><div class="stat-card-value"><?= $formatNumber($otpStats['expired'] ?? 0) ?></div></div></div>
                <div class="stat-card stat-card--warning"><div class="stat-card-body"><div class="stat-card-label">Ditolak rate limit</div><div class="stat-card-value"><?= $formatNumber($otpStats['rate_limited'] ?? 0) ?></div></div></div>
            </div>
        </section>

        <div class="wa-notice-stack">
            <div class="alert-inline alert-inline--info">
                <?= icon('lock') ?>
                <span>Kode OTP tidak pernah disimpan plaintext dan tidak ditampilkan di monitoring. OTP terikat pada akun, jenis penggunaan, dan nomor tujuan, serta hanya dapat dipakai satu kali.</span>
            </div>

            <div class="alert-inline alert-inline--info">
                <?= icon('chat') ?>
                <span>Provider OTP saat ini: <strong><?= e($otpProviderLabel) ?></strong>.
                    <?php if ($otpProviderSupportsTemplates): ?>
                        Mode <strong>Template Meta</strong> memerlukan nama template Authentication yang sudah disetujui. Semua OTP tidak akan jatuh ke teks biasa.
                    <?php else: ?>
                        Provider ini mengirim <strong>pesan teks</strong> dan memakai template pesan sesuai tujuan di bawah. Nama template Meta tidak wajib dan akan diabaikan saat pengiriman OTP.
                    <?php endif; ?>
                </span>
            </div>
        </div>

        <form class="wa-settings-form" method="post" action="<?= e(url('/admin/whatsapp/otp')) ?>">
            <?= csrf_field() ?>

            <section class="wa-settings-panel" aria-labelledby="otp-connection-title">
                <div class="wa-settings-panel__head">
                    <h4 id="otp-connection-title">Status &amp; Koneksi OTP</h4>
                    <p class="form-hint">Tentukan apakah OTP aktif dan koneksi WhatsApp yang dipakai.</p>
                </div>
                <div class="wa-settings-panel__body">
                    <label class="form-check wa-check-row">
                        <input type="checkbox" name="is_enabled" value="1"<?= $checked($otp['is_enabled'] ?? 0) ?>>
                        <span>Aktifkan OTP WhatsApp</span>
                    </label>
                    <div class="form-group">
                        <label class="form-label" for="otp-connection">Koneksi WhatsApp</label>
                        <select class="form-select" id="otp-connection" name="connection_id">
                            <?php foreach ($options as $oid => $olabel): ?>
                                <option value="<?= e((string) $oid) ?>"<?= $selected((int) ($otp['connection_id'] ?? 0), $oid) ?>><?= e((string) $olabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </section>

            <section class="wa-settings-panel" aria-labelledby="otp-delivery-title">
                <div class="wa-settings-panel__head">
                    <h4 id="otp-delivery-title">Mode Pengiriman</h4>
                    <p class="form-hint">Fonnte mengirim teks; Meta memakai template Authentication yang disetujui.</p>
                </div>
                <div class="wa-settings-panel__body wa-field-grid wa-field-grid--2">
                    <div class="form-group">
                        <label class="form-label" for="otp-mode">Mode pengiriman</label>
                        <select class="form-select" id="otp-mode" name="send_mode">
                            <option value="text"<?= $selected($os['send_mode'] ?? 'text', 'text') ?>>Teks biasa</option>
                            <option value="template"<?= $selected($os['send_mode'] ?? 'text', 'template') ?>>Template Meta</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="otp-template-name">Nama template</label>
                        <input class="form-control" id="otp-template-name" name="template_name" maxlength="120"
                               value="<?= e((string) ($os['template_name'] ?? '')) ?>">
                        <p class="form-hint"><?= $otpProviderSupportsTemplates ? 'Wajib untuk mode template Meta.' : 'Tidak wajib untuk Fonnte (mode teks otomatis).' ?></p>
                    </div>
                    <div class="form-group wa-field-grid__full">
                        <label class="form-label" for="otp-language">Kode bahasa template</label>
                        <input class="form-control" id="otp-language" name="language_code" maxlength="20"
                               value="<?= e((string) ($os['language_code'] ?? 'id')) ?>">
                        <?php if ($otpProviderSupportsTemplates): ?>
                            <p class="form-hint">Di luar jendela 24 jam, Meta mewajibkan template yang disetujui. Semua OTP pelanggan hanya dikirim melalui template Authentication Meta; mode teks biasa akan ditolak dan tidak menjadi fallback.</p>
                        <?php else: ?>
                            <p class="form-hint">Fonnte mengirim pesan teks. Placeholder <code>{kode}</code>, <code>{masa_berlaku}</code>, dan <code>{nama_aplikasi}</code> diisi oleh backend WiFiCare.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <section class="wa-settings-panel" aria-labelledby="otp-security-title">
                <div class="wa-settings-panel__head">
                    <h4 id="otp-security-title">Keamanan OTP</h4>
                    <p class="form-hint">Atur masa berlaku, batas percobaan, cooldown, dan kuota harian.</p>
                </div>
                <div class="wa-settings-panel__body wa-field-grid wa-field-grid--2">
                    <div class="form-group">
                        <label class="form-label" for="otp-ttl">Masa berlaku (detik)</label>
                        <input class="form-control" id="otp-ttl" name="ttl_seconds" type="number" min="60" max="1800" value="<?= e((string) ($os['ttl_seconds'] ?? 300)) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="otp-attempts">Maks. percobaan</label>
                        <input class="form-control" id="otp-attempts" name="max_attempts" type="number" min="1" max="10" value="<?= e((string) ($os['max_attempts'] ?? 5)) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="otp-cooldown">Cooldown kirim ulang (detik)</label>
                        <input class="form-control" id="otp-cooldown" name="resend_cooldown" type="number" min="0" max="3600" value="<?= e((string) ($os['resend_cooldown'] ?? 60)) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="otp-user-limit">Batas per pengguna/hari</label>
                        <input class="form-control" id="otp-user-limit" name="daily_user_limit" type="number" min="1" max="100" value="<?= e((string) ($os['daily_user_limit'] ?? 5)) ?>">
                    </div>
                    <div class="form-group wa-field-grid__full">
                        <label class="form-label" for="otp-phone-limit">Batas per nomor/hari</label>
                        <input class="form-control" id="otp-phone-limit" name="daily_phone_limit" type="number" min="1" max="100" value="<?= e((string) ($os['daily_phone_limit'] ?? 5)) ?>">
                    </div>
                </div>
            </section>

            <section class="wa-settings-panel" aria-labelledby="otp-purposes-title">
                <div class="wa-settings-panel__head">
                    <h4 id="otp-purposes-title">Jenis Penggunaan OTP</h4>
                    <p class="form-hint">Pilih minimal satu alur yang diizinkan memakai OTP WhatsApp.</p>
                </div>
                <fieldset class="wa-settings-panel__body wa-check-grid">
                    <legend class="visually-hidden">Jenis penggunaan OTP</legend>
                    <?php foreach (ApiFeatureRegistry::adminPurposes() as $purposeKey => $purposeLabel): ?>
                        <label class="form-check">
                            <input type="checkbox" name="purposes[<?= e($purposeKey) ?>]" value="1"<?= $checked($os['purposes'][$purposeKey] ?? false) ?>>
                            <span><?= e($purposeLabel) ?></span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
            </section>

            <section class="wa-settings-panel" aria-labelledby="otp-templates-title">
                <div class="wa-settings-panel__head">
                    <h4 id="otp-templates-title">Template Pesan OTP per Tujuan <span aria-hidden="true">*</span></h4>
                    <p class="form-hint">
                        Backend memilih template berdasarkan purpose dari alur server, bukan parameter bebas dari browser.
                        Setiap template wajib memuat <code>{kode}</code>. Placeholder lain: <code>{masa_berlaku}</code>, <code>{nama_aplikasi}</code>.
                        Untuk provider Meta, isi template ini tidak dikirim sebagai teks bebas; pengiriman tetap memakai Authentication template yang disetujui.
                    </p>
                </div>
                <div class="wa-settings-panel__body wa-template-stack">
                    <?php foreach (ApiFeatureRegistry::otpTemplates() as $otpPurpose => $otpTemplate): ?>
                        <div class="wa-template-card">
                            <div class="form-group">
                                <label class="form-label" for="otp-<?= e((string) $otpPurpose) ?>-template">
                                    <?= e((string) $otpTemplate['label']) ?> <span aria-hidden="true">*</span>
                                </label>
                                <textarea class="form-control" id="otp-<?= e((string) $otpPurpose) ?>-template"
                                          name="<?= e((string) $otpTemplate['setting']) ?>" rows="4" required maxlength="1000"><?= e((string) ($os[$otpTemplate['setting']] ?? $otpTemplate['default'])) ?></textarea>
                                <p class="form-hint"><?= e((string) $otpTemplate['description']) ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <div class="form-actions wa-settings-actions">
                <button class="btn btn-primary" type="submit"><?= icon('check') ?><span>Simpan Konfigurasi OTP</span></button>
            </div>
        </form>
    </div>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
