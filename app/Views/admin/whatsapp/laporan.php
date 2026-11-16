<?php
/**
 * Admin - submenu WhatsApp: Laporan WhatsApp (pengiriman manual, teks).
 *
 * Tidak ada scheduler. Preview dan pengiriman hanya terjadi dari aksi Admin.
 */

declare(strict_types=1);

require __DIR__ . '/_init.php';
require __DIR__ . '/_header.php';

$selectedType = (string) ($reportFilters['report_type'] ?? '');
if (!isset($reportTypes[$selectedType])) {
    $selectedType = (string) (array_key_first($reportTypes) ?: 'ringkasan_pengaduan');
}
$selectedConnectionId = (int) ($reportFilters['connection_id'] ?? 0);
$selectedRecipientIndex = (int) ($reportFilters['recipient_index'] ?? 0);
$selectedRentang = (string) ($reportFilters['rentang'] ?? 'bulan_ini');
$dateFrom = (string) ($reportFilters['date_from'] ?? '');
$dateTo = (string) ($reportFilters['date_to'] ?? '');
$customPeriod = $selectedRentang === 'pilih_tanggal';
$usageReached = !empty($reportUsage['reached']);
$hasConnection = $reportConnections !== [];
$hasRecipient = $reportRecipients !== [];
$previewCanSend = is_array($reportPreview) && !empty($reportPreview['can_send']);

$recipientLines = [];
foreach ((array) ($rs['recipients'] ?? []) as $recipient) {
    if (is_array($recipient)) {
        $recipientLines[] = (string) ($recipient['name'] ?? 'Penerima')
            . '|' . (string) ($recipient['number'] ?? '');
    }
}
?>

<section class="card wa-settings wa-report-page" id="laporan">
    <div class="card-head">
        <h3 class="card-title">Kirim Laporan WhatsApp</h3>
        <?php if ($reportGatewayReady && $hasConnection && $hasRecipient): ?>
            <span class="chip chip--success">Siap</span>
        <?php else: ?>
            <span class="chip chip--warning">Perlu Konfigurasi</span>
        <?php endif; ?>
    </div>
    <div class="card-body wa-settings__body">
        <div class="alert-inline alert-inline--info">
            <?= icon('chat') ?>
            <span><strong>Pengiriman manual.</strong> Laporan hanya dikirim setelah Admin menekan <em>Kirim ke WhatsApp</em>. Tidak ada pengiriman otomatis saat halaman dibuka, pilihan diubah, atau pengaturan disimpan.</span>
        </div>

        <?php if (!$reportGatewayReady): ?>
            <div class="alert-inline alert-inline--warning" role="alert">
                <?= icon('alert') ?>
                <span>WhatsApp Gateway belum aktif. Aktifkan gateway dan koneksi terlebih dahulu sebelum mengirim laporan.</span>
            </div>
        <?php elseif (!$hasConnection): ?>
            <div class="alert-inline alert-inline--warning" role="alert">
                <?= icon('alert') ?>
                <span>Belum ada koneksi WhatsApp aktif. Tambahkan dan aktifkan koneksi pada halaman Gateway.</span>
            </div>
        <?php elseif (!$hasRecipient): ?>
            <div class="alert-inline alert-inline--warning" role="alert">
                <?= icon('alert') ?>
                <span>Belum ada penerima berwenang. Isi daftar penerima pada pengaturan di bawah sebelum membuat pratinjau.</span>
            </div>
        <?php endif; ?>

        <?php if ($usageReached): ?>
            <div class="alert-inline alert-inline--warning" role="alert">
                <?= icon('alert') ?>
                <span>Batas pengiriman laporan harian sudah tercapai. Pratinjau tetap dapat dilihat, tetapi pengiriman baru ditolak sampai batas harian diperbarui atau hari berganti.</span>
            </div>
        <?php endif; ?>

        <form class="wa-report-form" method="get" action="<?= e(url('/admin/whatsapp/laporan')) ?>" data-rentang-form>
            <input type="hidden" name="preview" value="1">

            <div class="wa-report-form-grid">
                <div class="form-group">
                    <label class="form-label" for="rp-connection">Koneksi WhatsApp aktif</label>
                    <select class="form-select" id="rp-connection" name="connection_id" required>
                        <?php if (!$hasConnection): ?>
                            <option value="">Tidak ada koneksi aktif</option>
                        <?php else: ?>
                            <?php foreach ($reportConnections as $connection): ?>
                                <option value="<?= (int) $connection['id'] ?>"<?= $selected($selectedConnectionId, $connection['id']) ?>>
                                    <?= e((string) $connection['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <p class="form-hint">Hanya koneksi aktif yang ditampilkan. Request divalidasi ulang di backend.</p>
                </div>

                <div class="form-group">
                    <label class="form-label" for="rp-report-type">Jenis laporan</label>
                    <select class="form-select" id="rp-report-type" name="report_type" required>
                        <?php foreach ($reportTypes as $type => $label): ?>
                            <option value="<?= e((string) $type) ?>"<?= $selected($selectedType, $type) ?>><?= e((string) $label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="rp-rentang">Periode laporan</label>
                    <select class="form-select" id="rp-rentang" name="rentang" data-rentang-select required>
                        <?php foreach ($reportRentangLabels as $value => $label): ?>
                            <option value="<?= e((string) $value) ?>"<?= $selected($selectedRentang, $value) ?>><?= e((string) $label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="rp-recipient">Penerima berwenang</label>
                    <select class="form-select" id="rp-recipient" name="recipient_index" required>
                        <?php if (!$hasRecipient): ?>
                            <option value="">Belum ada penerima</option>
                        <?php else: ?>
                            <?php foreach ($reportRecipients as $recipient): ?>
                                <option value="<?= (int) $recipient['index'] ?>"<?= $selected($selectedRecipientIndex, $recipient['index']) ?>>
                                    <?= e((string) $recipient['name']) ?> (<?= e((string) $recipient['masked']) ?>)
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <p class="form-hint">Nomor tujuan tidak dapat diketik bebas; backend hanya menerima indeks dari daftar ini.</p>
                </div>

                <div class="form-group wa-report-custom<?= $customPeriod ? '' : ' is-hidden' ?>" data-rentang-custom>
                    <label class="form-label" for="rp-date-from">Tanggal awal</label>
                    <input class="form-control" id="rp-date-from" name="date_from" type="date" value="<?= e($dateFrom) ?>">
                </div>

                <div class="form-group wa-report-custom<?= $customPeriod ? '' : ' is-hidden' ?>" data-rentang-custom>
                    <label class="form-label" for="rp-date-to">Tanggal akhir</label>
                    <input class="form-control" id="rp-date-to" name="date_to" type="date" value="<?= e($dateTo) ?>">
                </div>

                <div class="form-group">
                    <span class="form-label">Format pesan</span>
                    <div class="wa-report-format" aria-label="Format pesan tetap Teks WhatsApp">
                        <?= icon('chat') ?><span>Teks WhatsApp</span>
                    </div>
                    <p class="form-hint">Laporan dikirim sebagai isi pesan; tidak ada lampiran berkas atau media.</p>
                </div>
            </div>

            <div class="form-actions wa-settings-actions">
                <button class="btn btn-primary" type="submit"<?= (!$hasConnection || !$hasRecipient || !$reportGatewayReady) ? ' disabled' : '' ?>>
                    <?= icon('search') ?><span>Preview Laporan</span>
                </button>
                <a class="btn btn-ghost" href="<?= e(url('/admin/whatsapp/laporan')) ?>">Bersihkan</a>
            </div>
        </form>

        <?php if ($reportPreviewError !== ''): ?>
            <div class="callout callout--danger" role="alert">
                <?= icon('alert') ?><p><?= e($reportPreviewError) ?></p>
            </div>
        <?php endif; ?>

        <?php if (is_array($reportPreview)): ?>
            <?php
            $previewType = (string) ($reportPreview['report_type_label'] ?? 'Laporan');
            $previewRecipient = (string) ($reportPreview['recipient_name'] ?? 'Penerima');
            $previewMasked = (string) ($reportPreview['recipient_masked'] ?? '');
            $previewPeriod = (string) ($reportPreview['period_label'] ?? '-');
            ?>
            <section class="wa-report-preview-card" id="preview" aria-labelledby="wa-preview-title">
                <div class="wa-report-preview-head">
                    <div>
                        <h4 id="wa-preview-title">Pratinjau Laporan</h4>
                        <p class="form-hint">Periksa isi di bawah. Pesan yang dikirim dibangun ulang dari data aktual dan harus cocok dengan pratinjau ini.</p>
                    </div>
                    <span class="chip chip--info">Teks WhatsApp</span>
                </div>

                <div class="wa-report-preview-meta">
                    <span class="chip chip--muted"><?= e($previewType) ?></span>
                    <span class="chip chip--muted"><?= e($previewPeriod) ?></span>
                    <span class="chip chip--muted"><?= e($previewRecipient) ?><?= $previewMasked !== '' ? ' (' . e($previewMasked) . ')' : '' ?></span>
                </div>

                <pre class="wa-report-preview"><?= e((string) ($reportPreview['message'] ?? '')) ?></pre>

                <div class="wa-report-preview-foot">
                    <span class="wa-report-char<?= !empty($reportPreview['too_long']) ? ' is-over' : '' ?>">
                        <?= e(number_format((int) ($reportPreview['length'] ?? 0), 0, ',', '.')) ?>
                        / <?= e(number_format((int) ($reportPreview['max_length'] ?? 4000), 0, ',', '.')) ?> karakter
                    </span>
                    <span class="form-hint">Batas ini menjaga kompatibilitas teks WhatsApp. Daftar panjang diringkas dengan keterangan jumlah yang tidak ditampilkan.</span>
                </div>

                <?php if (!empty($reportPreview['too_long'])): ?>
                    <div class="alert-inline alert-inline--danger" role="alert">
                        <?= icon('alert') ?>
                        <span>Pesan melebihi batas provider. Persempit periode atau pilih laporan yang lebih ringkas, lalu buat pratinjau baru. Pesan tidak akan dipotong diam-diam.</span>
                    </div>
                <?php elseif (!$previewCanSend): ?>
                    <div class="alert-inline alert-inline--warning" role="alert">
                        <?= icon('alert') ?>
                        <span>Pratinjau tersedia, tetapi pengiriman belum dapat dilakukan. Periksa batas harian atau konfigurasi koneksi.</span>
                    </div>
                <?php endif; ?>

                <?php if ($previewCanSend): ?>
                    <form method="post" action="<?= e(url('/admin/whatsapp/laporan/kirim')) ?>"
                          class="wa-report-send"
                          data-confirm="Kirim laporan ini ke <?= e($previewRecipient) ?> (<?= e($previewMasked) ?>)? Status setelah pengiriman adalah diterima provider, bukan bukti sudah dibaca penerima."
                          data-confirm-title="Kirim Laporan WhatsApp"
                          data-confirm-label="Ya, kirim"
                          data-confirm-variant="primary"
                          data-confirm-icon="chat">
                        <?= csrf_field() ?>
                        <input type="hidden" name="preview_token" value="<?= e((string) ($reportPreview['token'] ?? '')) ?>">
                        <button class="btn btn-primary" type="submit">
                            <?= icon('send') ?><span>Kirim ke WhatsApp</span>
                        </button>
                        <span class="form-hint">Satu pratinjau hanya dapat dikirim satu kali. Klik ganda dan request ulang ditolak.</span>
                    </form>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <details class="wa-report-settings" id="pengaturan"<?= empty($reportRecipients) ? ' open' : '' ?>>
            <summary>Penerima Berwenang &amp; Batas Harian</summary>
            <form class="wa-settings-form" method="post" action="<?= e(url('/admin/whatsapp/laporan')) ?>">
                <?= csrf_field() ?>
                <div class="wa-report-settings-grid">
                    <div class="form-group">
                        <label class="form-label" for="rp-recipients">Daftar penerima</label>
                        <textarea class="form-control" id="rp-recipients" name="recipients" rows="5" placeholder="Nama|6281234567890" required><?= e(implode("\n", $recipientLines)) ?></textarea>
                        <p class="form-hint">Satu penerima per baris dengan format <code>Nama|Nomor</code>. Nomor divalidasi dan dinormalisasi saat disimpan.</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="rp-limit">Batas kirim per hari</label>
                        <input class="form-control" id="rp-limit" name="daily_limit" type="number" min="0" max="100" value="<?= e((string) ($rs['daily_limit'] ?? 0)) ?>">
                        <p class="form-hint">Isi <strong>0</strong> untuk tanpa batas khusus laporan. Batas gateway tetap berlaku. Menyimpan pengaturan tidak mengirim pesan.</p>
                    </div>
                </div>
                <div class="wa-settings-actions">
                    <button class="btn btn-primary" type="submit"><?= icon('check') ?><span>Simpan Penerima &amp; Batas</span></button>
                </div>
            </form>
        </details>

        <section class="wa-report-status" aria-labelledby="wa-report-status-title">
            <div class="wa-settings-section__head">
                <h4 id="wa-report-status-title">Status Pengiriman Terakhir</h4>
                <p class="form-hint">Status delivery hanya dipercaya dari webhook provider. Diterima provider tidak sama dengan sudah dibaca penerima.</p>
            </div>

            <div class="stat-grid stat-grid--3 wa-stat-grid">
                <div class="stat-card">
                    <div class="stat-card-body">
                        <div class="stat-card-label">Terkirim hari ini</div>
                        <div class="stat-card-value"><?= e((string) ((int) ($reportUsage['used'] ?? 0))) ?></div>
                        <div class="stat-card-hint">
                            <?= ($reportUsage['limit'] ?? 0) > 0
                                ? 'dari batas ' . e((string) ((int) $reportUsage['limit']))
                                : 'tanpa batas laporan' ?>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-body">
                        <div class="stat-card-label">Sisa kuota</div>
                        <div class="stat-card-value"><?= $reportUsage['remaining'] === null ? '∞' : e((string) ((int) $reportUsage['remaining'])) ?></div>
                        <div class="stat-card-hint">Kuota laporan manual</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-body">
                        <div class="stat-card-label">Status terakhir</div>
                        <div class="stat-card-value wa-report-last-status">
                            <?php if ($reportLast === null): ?>
                                <span class="cell-muted">Belum ada</span>
                            <?php else: ?>
                                <span class="chip <?= e((string) ($reportLast['status_class'] ?? 'chip--muted')) ?>"><?= e((string) ($reportLast['status_label'] ?? '-')) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="stat-card-hint">
                            <?= $reportLast === null ? 'Belum ada pengiriman laporan' : e((string) ($reportLast['status_note'] ?? '')) ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="wa-report-history">
                <div class="api-section-head">
                    <h4>Riwayat Pengiriman</h4>
                    <span class="form-hint">Maksimal 10 pengiriman terbaru</span>
                </div>
                <?php if ($reportHistory === []): ?>
                    <div class="empty-state empty-state--compact">
                        <div class="empty-state-icon"><?= icon('chat') ?></div>
                        <p class="empty-state-title">Belum ada riwayat</p>
                        <p class="empty-state-message">Riwayat muncul setelah Admin mengirim laporan melalui formulir di atas.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="app-table">
                            <thead>
                            <tr>
                                <th>Waktu</th>
                                <th>Jenis</th>
                                <th>Penerima</th>
                                <th>Status</th>
                                <th>Keterangan</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($reportHistory as $history): ?>
                                <tr>
                                    <td><?= e(format_waktu((string) ($history['created_at'] ?? ''))) ?></td>
                                    <td>
                                        <strong><?= e((string) ($history['type_label'] ?? 'Laporan')) ?></strong>
                                        <?php if (!empty($history['period_label'])): ?>
                                            <div class="table-note"><?= e((string) $history['period_label']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e((string) ($history['recipient_masked'] ?? '-')) ?></td>
                                    <td>
                                        <span class="chip <?= e((string) ($history['status_class'] ?? 'chip--muted')) ?>"><?= e((string) ($history['status_label'] ?? '-')) ?></span>
                                    </td>
                                    <td class="table-note"><?= e((string) ($history['status_note'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
