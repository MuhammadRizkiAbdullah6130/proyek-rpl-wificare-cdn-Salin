<?php
/**
 * Admin — submenu WhatsApp: Gateway & kredensial WhatsApp.
 *
 * Halaman mandiri; hanya memuat konfigurasi yang relevan.
 */

declare(strict_types=1);

require __DIR__ . '/_init.php';
$waIncludeProviderScript = true;
require __DIR__ . '/_header.php';
?>

<section class="card" id="gateway">
    <div class="card-head">
        <h3 class="card-title">Gateway WhatsApp</h3>
        <span class="chip <?= !empty($gateway['is_enabled']) ? 'chip--success' : 'chip--muted' ?>">
            <?= !empty($gateway['is_enabled']) ? 'Aktif' : 'Nonaktif' ?>
        </span>
    </div>
    <div class="card-body">
        <div class="alert-inline alert-inline--info">
            <?= icon('shield') ?>
            <span>Provider yang didukung: <strong>WhatsApp Business Cloud API (Meta)</strong> dan <strong>Fonnte WhatsApp API</strong>. Token API, verification token, dan webhook secret disimpan terenkripsi (libsodium) dan tidak pernah ditampilkan kembali.</span>
        </div>

        <div class="api-section-head">
            <h4>Kredensial Tersimpan</h4>
            <a class="btn btn-primary btn-sm" href="<?= e(url('/admin/whatsapp/gateway')) ?>">
                <?= icon('plus') ?><span>Tambah / Kelola</span>
            </a>
        </div>

        <?php if ($connections === []): ?>
            <div class="empty-state empty-state--compact">
                <div class="empty-state-icon"><?= icon('chat') ?></div>
                <p class="empty-state-title">Belum ada kredensial WhatsApp</p>
                <p class="empty-state-message">Tambahkan koneksi Meta atau Fonnte di bawah. Token API akan dienkripsi sebelum disimpan.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="app-table">
                    <thead>
                    <tr>
                        <th>Nama</th>
                        <th>Provider</th>
                        <th>ID Provider</th>
                        <th>Nomor</th>
                        <th>Status</th>
                        <th>Tes Terakhir</th>
                        <th>Aksi</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($connections as $connection): ?>
                        <?php
                        $cid = (int) $connection['id'];
                        $revoked = !empty($connection['is_revoked']);
                        $active = !empty($connection['is_active']) && !$revoked;
                        ?>
                        <tr>
                            <td>
                                <strong><?= e((string) $connection['name']) ?></strong>
                                <?php if (($connection['provider'] ?? '') === 'meta_cloud' && !empty($webhookUrls[$cid])): ?>
                                    <details class="api-webhook">
                                        <summary>Webhook</summary>
                                        <code class="api-code"><?= e((string) $webhookUrls[$cid]) ?></code>
                                    </details>
                                <?php endif; ?>
                            </td>
                            <td><?= e((string) ($providers[(string) $connection['provider']] ?? $connection['provider'])) ?></td>
                            <td>
                                <?php if ((string) $connection['phone_number_id'] !== ''): ?>
                                    <code><?= e((string) $connection['phone_number_id']) ?></code>
                                <?php else: ?>
                                    <span class="table-note">Token perangkat</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e((string) ($connection['sender_number'] !== '' ? $connection['sender_number'] : '—')) ?></td>
                            <td>
                                <?php if ($revoked): ?>
                                    <span class="chip chip--danger">Dicabut</span>
                                <?php elseif ($active): ?>
                                    <span class="chip chip--success">Aktif</span>
                                <?php else: ?>
                                    <span class="chip chip--muted">Tidak aktif</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($connection['last_tested_at'])): ?>
                                    <span class="chip <?= ($connection['last_test_status'] ?? '') === 'connected' ? 'chip--success' : 'chip--danger' ?>">
                                        <?= ($connection['last_test_status'] ?? '') === 'connected' ? 'Terhubung' : 'Gagal' ?>
                                    </span>
                                    <div class="table-note"><?= e(format_waktu((string) $connection['last_tested_at'])) ?></div>
                                    <?php if (!empty($connection['last_test_message'])): ?>
                                        <div class="table-note"><?= e(mb_substr((string) $connection['last_test_message'], 0, 160)) ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="chip chip--muted">Belum diuji</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="table-actions">
                                    <?php if (!$revoked): ?>
                                        <a class="table-action" href="<?= e(url('/admin/whatsapp/gateway') . '?edit=' . $cid) ?>" title="Edit"><?= icon('edit') ?></a>
                                        <?php if (!empty($connection['has_secret'])): ?>
                                            <form method="post" action="<?= e(url('/admin/whatsapp/kredensial/tes')) ?>"
                                                  data-confirm="Tes koneksi akan memanggil API provider dan dapat memakai kuota. Lanjutkan?"
                                                  data-confirm-title="Tes koneksi WhatsApp" data-confirm-label="Ya, tes">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= $cid ?>">
                                                <button class="table-action" type="submit" title="Tes koneksi"><?= icon('refresh') ?></button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if (!$active): ?>
                                            <form method="post" action="<?= e(url('/admin/whatsapp/kredensial/aktif')) ?>">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= $cid ?>">
                                                <button class="table-action" type="submit" title="Jadikan aktif"><?= icon('check') ?></button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="post" action="<?= e(url('/admin/whatsapp/kredensial/cabut')) ?>"
                                              data-confirm="Cabut kredensial ini? Secret akan dihapus permanen dan koneksi tidak dapat dipakai lagi."
                                              data-confirm-title="Cabut kredensial" data-confirm-label="Ya, cabut" data-confirm-variant="danger">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= $cid ?>">
                                            <button class="table-action table-action--danger" type="submit" title="Cabut"><?= icon('trash') ?></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <hr class="api-separator">

        <div class="grid-2">
            <div>
                <div class="api-section-head">
                    <h4><?= $isEdit ? 'Edit Kredensial' : 'Tambah Kredensial' ?></h4>
                    <?php if ($isEdit): ?>
                        <a class="btn btn-outline-light btn-sm" href="<?= e(url('/admin/whatsapp/gateway')) ?>">Batal edit</a>
                    <?php endif; ?>
                </div>
                <form class="form-stack" method="post" action="<?= e($credentialAction) ?>">
                    <?= csrf_field() ?>
                    <?php if ($isEdit): ?>
                        <input type="hidden" name="id" value="<?= (int) $editConnection['id'] ?>">
                    <?php endif; ?>

                    <div class="grid-2">
                        <div class="form-group">
                            <label class="form-label" for="wa-name">Nama koneksi <span aria-hidden="true">*</span></label>
                            <input class="form-control" id="wa-name" name="name" required maxlength="100"
                                   value="<?= e((string) ($editConnection['name'] ?? '')) ?>"
                                   data-placeholder-meta="mis. WhatsApp Operasional"
                                   data-placeholder-fonnte="mis. Fonnte CDN"
                                   placeholder="<?= $selectedProvider === 'fonnte' ? 'mis. Fonnte CDN' : 'mis. WhatsApp Operasional' ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="wa-provider">Provider <span aria-hidden="true">*</span></label>
                            <select class="form-select" id="wa-provider" name="provider" required>
                                <?php foreach ($providers as $pid => $plabel): ?>
                                    <option value="<?= e((string) $pid) ?>"<?= $selected($editConnection['provider'] ?? 'meta_cloud', $pid) ?>><?= e((string) $plabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="wa-token"><span data-wa-token-label><?= $selectedProvider === 'fonnte' ? 'API Token Fonnte' : 'Access token' ?></span> <span aria-hidden="true">*</span></label>
                        <input class="form-control" id="wa-token" name="access_token" type="password"
                               autocomplete="new-password" maxlength="4096"
                               <?= $isEdit ? '' : 'required' ?>
                               data-placeholder-meta="<?= $isEdit ? 'Kosongkan untuk mempertahankan token lama' : 'Tempel access token Meta' ?>"
                               data-placeholder-fonnte="<?= $isEdit ? 'Kosongkan untuk mempertahankan token lama' : 'Tempel API token Fonnte' ?>"
                               placeholder="<?= $isEdit ? 'Kosongkan untuk mempertahankan token lama' : ($selectedProvider === 'fonnte' ? 'Tempel API token Fonnte' : 'Tempel access token Meta') ?>">
                        <?php if ($isEdit && !empty($editConnection['has_secret'])): ?>
                            <p class="form-hint" data-wa-token-hint>Token sudah tersimpan terenkripsi. Input kosong tidak menghapus token lama.</p>
                        <?php else: ?>
                            <p class="form-hint" data-wa-token-hint>Token tidak akan ditampilkan kembali setelah disimpan.</p>
                        <?php endif; ?>
                    </div>

                    <div data-wa-provider-fields="fonnte"<?= $selectedProvider === 'fonnte' ? '' : ' hidden' ?>>
                        <div class="alert-inline alert-inline--info">
                            <?= icon('info') ?>
                            <span>Fonnte hanya memerlukan <strong>nama koneksi</strong>, <strong>API Token Fonnte</strong>, dan status aktif. WABA ID, Meta Phone Number ID, serta webhook Meta tidak diperlukan. Pesan OTP dikirim sebagai teks.</span>
                        </div>
                    </div>

                    <div data-wa-provider-fields="meta_cloud"<?= $selectedProvider === 'meta_cloud' ? '' : ' hidden' ?>>
                        <div class="grid-2">
                            <div class="form-group">
                                <label class="form-label" for="wa-waba">Business Account ID</label>
                                <input class="form-control" id="wa-waba" name="business_account_id" maxlength="64"
                                       value="<?= e((string) ($editConnection['business_account_id'] ?? '')) ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="wa-phone-id">Phone Number ID <span aria-hidden="true">*</span></label>
                                <input class="form-control" id="wa-phone-id" name="phone_number_id" maxlength="64"
                                       data-wa-required-meta
                                       value="<?= e((string) ($editConnection['phone_number_id'] ?? '')) ?>">
                            </div>
                        </div>

                        <div class="grid-2">
                            <div class="form-group">
                                <label class="form-label" for="wa-verify">Webhook verification token</label>
                                <input class="form-control" id="wa-verify" name="webhook_verify_token" type="password"
                                       autocomplete="new-password" maxlength="255"
                                       placeholder="<?= $isEdit && !empty($editConnection['has_webhook_verify_token']) ? 'Kosongkan untuk mempertahankan' : 'Minimal 8 karakter' ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="wa-secret">Webhook secret (app secret)</label>
                                <input class="form-control" id="wa-secret" name="webhook_secret" type="password"
                                       autocomplete="new-password" maxlength="255"
                                       placeholder="<?= $isEdit && !empty($editConnection['has_webhook_secret']) ? 'Kosongkan untuk mempertahankan' : 'Minimal 8 karakter' ?>">
                                <p class="form-hint">Dipakai untuk memverifikasi <code>X-Hub-Signature-256</code>.</p>
                            </div>
                        </div>
                    </div>

                    <div class="grid-2">
                        <div class="form-group">
                            <label class="form-label" for="wa-sender">Nomor pengirim <span class="form-hint">(opsional)</span></label>
                            <input class="form-control" id="wa-sender" name="sender_number" maxlength="32"
                                   value="<?= e((string) ($editConnection['sender_number'] ?? '')) ?>"
                                   placeholder="6281234567890 / +15551687957">
                            <p class="form-hint">Format E.164. Tanda +, spasi, tanda kurung, dan tanda hubung dinormalisasi.</p>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="wa-base">Base URL API <span class="form-hint">(opsional)</span></label>
                            <input class="form-control" id="wa-base" name="base_url" maxlength="255"
                                   value="<?= e((string) ($editConnection['base_url'] ?? '')) ?>"
                                   placeholder="<?= $selectedProvider === 'fonnte' ? 'https://api.fonnte.com' : 'https://graph.facebook.com/v20.0' ?>">
                            <p class="form-hint">Kosongkan untuk memakai default provider. HTTP hanya diizinkan untuk localhost pengujian.</p>
                        </div>
                    </div>

                    <label class="form-check">
                        <input type="checkbox" name="is_active" value="1"<?= $checked($editConnection['is_active'] ?? false) ?>>
                        <span>Jadikan koneksi WhatsApp aktif/default</span>
                    </label>

                    <div class="form-actions">
                        <button class="btn btn-primary" type="submit"><?= icon('lock') ?><span><?= $isEdit ? 'Simpan Perubahan' : 'Simpan Kredensial' ?></span></button>
                    </div>
                </form>
            </div>

            <div>
                <div class="api-section-head"><h4>Status Gateway</h4></div>
                <dl class="detail-list">
                    <div><dt>Enkripsi</dt><dd><?= !empty($encryptionReady) ? 'Siap (libsodium)' : 'Belum siap' ?></dd></div>
                    <div><dt>Master key</dt><dd><?= !empty($masterKeyPresent) ? 'Tersedia di environment' : 'Tidak tersedia' ?></dd></div>
                    <div><dt>Koneksi</dt><dd><?= count(array_filter($connections, static fn (array $c): bool => empty($c['is_revoked']))) ?> kredensial</dd></div>
                    <div><dt>Tes koneksi bulan ini</dt><dd><?= $formatNumber($gatewayUsage['total'] ?? 0) ?></dd></div>
                </dl>

                <form class="form-stack" method="post" action="<?= e(url('/admin/whatsapp/gateway')) ?>">
                    <?= csrf_field() ?>
                    <label class="form-check">
                        <input type="checkbox" name="is_enabled" value="1"<?= $checked($gateway['is_enabled'] ?? 0) ?>>
                        <span>Aktifkan WhatsApp Gateway</span>
                    </label>

                    <div class="form-group">
                        <label class="form-label" for="gw-connection">Koneksi default</label>
                        <select class="form-select" id="gw-connection" name="connection_id">
                            <?php foreach ($options as $oid => $olabel): ?>
                                <option value="<?= e((string) $oid) ?>"<?= $selected((int) ($gateway['connection_id'] ?? 0), $oid) ?>><?= e((string) $olabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="grid-2">
                        <div class="form-group">
                            <label class="form-label" for="gw-country">Kode negara</label>
                            <input class="form-control" id="gw-country" name="default_country_code" type="number" min="1" max="9999"
                                   value="<?= e((string) ($gs['default_country_code'] ?? '62')) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="gw-timeout">Timeout (detik)</label>
                            <input class="form-control" id="gw-timeout" name="timeout" type="number" min="5" max="60"
                                   value="<?= e((string) ($gs['timeout'] ?? 20)) ?>">
                        </div>
                    </div>

                    <div class="grid-2">
                        <div class="form-group">
                            <label class="form-label" for="gw-limit">Batas pesan/hari</label>
                            <input class="form-control" id="gw-limit" name="daily_limit" type="number" min="0" max="10000"
                                   value="<?= e((string) ($gs['daily_limit'] ?? 0)) ?>">
                            <p class="form-hint">0 = tanpa batas global. Batas per fitur tetap berlaku.</p>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="gw-retry">Retry maksimal</label>
                            <input class="form-control" id="gw-retry" name="retry_max" type="number" min="1" max="10"
                                   value="<?= e((string) ($gs['retry_max'] ?? 3)) ?>">
                        </div>
                    </div>

                    <div class="form-actions">
                        <button class="btn btn-primary" type="submit"><?= icon('check') ?><span>Simpan Gateway</span></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
