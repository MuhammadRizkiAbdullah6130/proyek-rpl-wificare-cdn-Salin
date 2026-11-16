<?php
/**
 * Admin — submenu WhatsApp: Notifikasi WhatsApp.
 *
 * Halaman mandiri; hanya memuat konfigurasi yang relevan.
 */

declare(strict_types=1);

require __DIR__ . '/_init.php';
require __DIR__ . '/_header.php';
?>

<section class="card wa-settings" id="notifikasi">
    <div class="card-head">
        <h3 class="card-title">Konfigurasi Notifikasi WhatsApp</h3>
        <span class="chip <?= !empty($notification['is_enabled']) ? 'chip--success' : 'chip--muted' ?>">
            <?= !empty($notification['is_enabled']) ? 'Aktif' : 'Nonaktif' ?>
        </span>
    </div>
    <div class="card-body wa-settings__body">
        <section class="wa-settings-section" aria-labelledby="notif-stats-title">
            <div class="wa-settings-section__head">
                <h4 id="notif-stats-title">Ringkasan Antrean Notifikasi</h4>
                <p class="form-hint">Status berasal dari antrean dan webhook provider yang terverifikasi.</p>
            </div>
            <div class="stat-grid stat-grid--3 wa-stat-grid">
                <div class="stat-card"><div class="stat-card-body"><div class="stat-card-label">Request terkirim</div><div class="stat-card-value"><?= $formatNumber($notifStats['requests'] ?? 0) ?></div></div></div>
                <div class="stat-card stat-card--warning"><div class="stat-card-body"><div class="stat-card-label">Pending</div><div class="stat-card-value"><?= $formatNumber($notifStats['pending'] ?? 0) ?></div><div class="stat-card-hint">Termasuk yang sedang diproses</div></div></div>
                <div class="stat-card stat-card--info"><div class="stat-card-body"><div class="stat-card-label">Diterima provider</div><div class="stat-card-value"><?= $formatNumber($notifStats['accepted'] ?? 0) ?></div><div class="stat-card-hint">Belum tentu delivered</div></div></div>
                <div class="stat-card stat-card--success"><div class="stat-card-body"><div class="stat-card-label">Delivered/read</div><div class="stat-card-value"><?= $formatNumber($notifStats['delivered'] ?? 0) ?></div></div></div>
                <div class="stat-card stat-card--danger"><div class="stat-card-body"><div class="stat-card-label">Gagal</div><div class="stat-card-value"><?= $formatNumber($notifStats['failed'] ?? 0) ?></div></div></div>
            </div>
        </section>

        <div class="wa-notice-stack">
            <div class="alert-inline alert-inline--success">
                <?= icon('check') ?>
                <span><strong>Pengiriman otomatis aktif.</strong> Event tiket diproses otomatis setelah transaksi berhasil di-commit. Admin tidak perlu menekan tombol proses antrean.</span>
            </div>
            <div class="alert-inline alert-inline--warning">
                <?= icon('info') ?>
                <span><strong>Diterima provider ≠ delivered.</strong> Status delivered/read hanya diperbarui oleh webhook provider yang signature-nya valid. Retry otomatis berjalan pada request berikutnya atau melalui worker.</span>
            </div>
        </div>

        <section class="wa-queue-panel" aria-labelledby="notif-queue-title">
            <div class="wa-queue-panel__text">
                <h4 id="notif-queue-title">Pemulihan Antrean (Opsional)</h4>
                <p class="form-hint">Bukan langkah wajib. Gunakan hanya untuk memulihkan pesan pending setelah gangguan provider; pengiriman normal sudah otomatis.</p>
            </div>
            <div class="wa-queue-panel__actions">
                <form method="post" action="<?= e(url('/admin/whatsapp/antrean')) ?>"
                      data-confirm="Proses hingga 20 pesan pending sekarang? Pastikan gateway dan kredensial sudah benar."
                      data-confirm-title="Proses antrean WhatsApp" data-confirm-label="Ya, proses">
                    <?= csrf_field() ?>
                    <button class="btn btn-outline-light btn-sm" type="submit"><?= icon('refresh') ?><span>Proses Antrean Sekarang</span></button>
                </form>
                <span class="form-hint">Untuk produksi, jalankan <code>php bin/whatsapp-worker.php</code> secara berkala.</span>
            </div>
        </section>

        <form class="wa-settings-form" method="post" action="<?= e(url('/admin/whatsapp/notifikasi')) ?>">
            <?= csrf_field() ?>

            <section class="wa-settings-panel" aria-labelledby="notif-general-title">
                <div class="wa-settings-panel__head">
                    <h4 id="notif-general-title">Pengaturan Umum</h4>
                    <p class="form-hint">Aktifkan notifikasi, pilih koneksi, dan atur batas pengiriman.</p>
                </div>
                <div class="wa-settings-panel__body">
                    <label class="form-check wa-check-row">
                        <input type="checkbox" name="is_enabled" value="1"<?= $checked($notification['is_enabled'] ?? 0) ?>>
                        <span>Aktifkan notifikasi WhatsApp</span>
                    </label>
                    <div class="wa-field-grid wa-field-grid--2">
                        <div class="form-group">
                            <label class="form-label" for="nt-connection">Koneksi WhatsApp</label>
                            <select class="form-select" id="nt-connection" name="connection_id">
                                <?php foreach ($options as $oid => $olabel): ?>
                                    <option value="<?= e((string) $oid) ?>"<?= $selected((int) ($notification['connection_id'] ?? 0), $oid) ?>><?= e((string) $olabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="nt-admin">Nomor operasional Admin</label>
                            <input class="form-control" id="nt-admin" name="admin_number" maxlength="32"
                                   value="<?= e((string) ($ns['admin_number'] ?? '')) ?>" placeholder="6281234567890">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="nt-limit">Batas pesan/hari</label>
                            <input class="form-control" id="nt-limit" name="daily_limit" type="number" min="0" max="10000" value="<?= e((string) ($ns['daily_limit'] ?? 0)) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="nt-retry">Retry maksimal</label>
                            <input class="form-control" id="nt-retry" name="retry_max" type="number" min="1" max="10" value="<?= e((string) ($ns['retry_max'] ?? 3)) ?>">
                        </div>
                    </div>
                </div>
            </section>

            <div class="api-placeholder-note wa-placeholder-note">
                <strong>Placeholder:</strong>
                <?php foreach ($placeholders as $ph => $label): ?>
                    <code><?= e((string) $ph) ?></code> <?= e((string) $label) ?><?= $ph !== array_key_last($placeholders) ? ' · ' : '' ?>
                <?php endforeach; ?>
            </div>

            <section class="wa-settings-section" aria-labelledby="notif-events-title">
                <div class="wa-settings-section__head">
                    <h4 id="notif-events-title">Notifikasi per Event</h4>
                    <p class="form-hint">Buka setiap event untuk mengatur penerima, template, dan mode pengiriman.</p>
                </div>
                <div class="wa-event-stack">
                    <?php $first = true; ?>
                    <?php foreach ($eventList as $eventKey => $meta): ?>
                        <?php
                        $cfg = is_array($events[$eventKey] ?? null) ? $events[$eventKey] : [];
                        $enabledEvent = !empty($cfg['enabled']);
                        ?>
                        <details class="api-event wa-event"<?= $first ? ' open' : '' ?>>
                            <summary>
                                <span><?= e((string) $meta['label']) ?></span>
                                <span class="chip <?= $enabledEvent ? 'chip--success' : 'chip--muted' ?>"><?= $enabledEvent ? 'Aktif' : 'Nonaktif' ?></span>
                            </summary>
                            <div class="api-event-body wa-event-body">
                                <p class="form-hint"><?= e((string) $meta['description']) ?></p>
                                <label class="form-check wa-check-row">
                                    <input type="checkbox" name="events[<?= e($eventKey) ?>][enabled]" value="1"<?= $checked($enabledEvent) ?>>
                                    <span>Kirim notifikasi event ini</span>
                                </label>

                                <div class="wa-field-grid wa-field-grid--2">
                                    <div class="form-group">
                                        <label class="form-label" for="ev-<?= e($eventKey) ?>-recipient">Penerima</label>
                                        <select class="form-select" id="ev-<?= e($eventKey) ?>-recipient" name="events[<?= e($eventKey) ?>][recipient]">
                                            <?php foreach ($recipientOptions as $rid => $rlabel): ?>
                                                <option value="<?= e((string) $rid) ?>"<?= $selected($cfg['recipient'] ?? $meta['recipient'], $rid) ?>><?= e((string) $rlabel) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="ev-<?= e($eventKey) ?>-custom">Nomor tertentu</label>
                                        <input class="form-control" id="ev-<?= e($eventKey) ?>-custom" name="events[<?= e($eventKey) ?>][custom_number]" maxlength="32"
                                               value="<?= e((string) ($cfg['custom_number'] ?? '')) ?>" placeholder="6281234567890">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label" for="ev-<?= e($eventKey) ?>-template">Template pesan</label>
                                    <textarea class="form-control" id="ev-<?= e($eventKey) ?>-template" name="events[<?= e($eventKey) ?>][template]" rows="3" maxlength="1000"><?= e((string) ($cfg['template'] ?? $meta['template'])) ?></textarea>
                                </div>

                                <div class="wa-field-grid wa-field-grid--3">
                                    <div class="form-group">
                                        <label class="form-label" for="ev-<?= e($eventKey) ?>-mode">Mode</label>
                                        <select class="form-select" id="ev-<?= e($eventKey) ?>-mode" name="events[<?= e($eventKey) ?>][send_mode]">
                                            <option value="text"<?= $selected($cfg['send_mode'] ?? 'text', 'text') ?>>Teks biasa</option>
                                            <option value="template"<?= $selected($cfg['send_mode'] ?? 'text', 'template') ?>>Template Meta</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="ev-<?= e($eventKey) ?>-template-name">Nama template</label>
                                        <input class="form-control" id="ev-<?= e($eventKey) ?>-template-name" name="events[<?= e($eventKey) ?>][template_name]" maxlength="120"
                                               value="<?= e((string) ($cfg['template_name'] ?? '')) ?>">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="ev-<?= e($eventKey) ?>-lang">Bahasa</label>
                                        <input class="form-control" id="ev-<?= e($eventKey) ?>-lang" name="events[<?= e($eventKey) ?>][language_code]" maxlength="20"
                                               value="<?= e((string) ($cfg['language_code'] ?? 'id')) ?>">
                                    </div>
                                </div>
                            </div>
                        </details>
                        <?php $first = false; ?>
                    <?php endforeach; ?>
                </div>
            </section>

            <div class="form-actions wa-settings-actions">
                <button class="btn btn-primary" type="submit"><?= icon('check') ?><span>Simpan Notifikasi</span></button>
            </div>
        </form>

        <section class="wa-monitoring" aria-labelledby="notif-monitoring-title">
            <div class="api-section-head"><h4 id="notif-monitoring-title">Monitoring per Event</h4></div>
            <div class="table-responsive">
                <table class="app-table">
                    <thead><tr><th>Event</th><th>Pending</th><th>Diterima provider</th><th>Delivered/read</th><th>Gagal</th></tr></thead>
                    <tbody>
                    <?php foreach (($notifStats['per_event'] ?? []) as $row): ?>
                        <tr>
                            <td><?= e((string) ($row['label'] ?? '')) ?></td>
                            <td><?= $formatNumber($row['pending'] ?? 0) ?></td>
                            <td><?= $formatNumber($row['accepted'] ?? 0) ?></td>
                            <td><?= $formatNumber($row['delivered'] ?? 0) ?></td>
                            <td><?= $formatNumber($row['failed'] ?? 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
