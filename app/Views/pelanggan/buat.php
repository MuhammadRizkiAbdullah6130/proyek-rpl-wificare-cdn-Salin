<?php
/**
 * Pelanggan — Buat Pengaduan (Tahap 5).
 *
 * Nama & Username PPPoE diambil dari profil (read-only). Nomor WhatsApp pada
 * formulir hanya menjadi kontak untuk tiket ini dan tidak mengubah nomor utama
 * profil. Kategori berasal dari tabel `kategori_gangguan`. Foto wajib.
 */

declare(strict_types=1);
$content = '';

$profil = $profil ?? ['nama' => '', 'username_pppoe' => '', 'nomor_whatsapp' => ''];
$kategori = $kategori ?? [];
$aktif = $aktif ?? [];
$old = $old ?? [];
$flashError = $flash_error ?? null;

$waValue = (string) ($old['nomor_whatsapp'] ?? ($profil['nomor_whatsapp'] ?? ''));
$kategoriValue = (int) ($old['kategori_id'] ?? 0);
$deskripsiValue = (string) ($old['deskripsi'] ?? '');
?>
<?= component('page-header', [
    'title' => 'Buat Pengaduan',
    'subtitle' => 'Isi data gangguan dengan lengkap agar dapat ditangani lebih cepat.',
]) ?>

<?php if ($flashError !== null): ?>
    <div class="callout callout--danger">
        <?= icon('alert') ?>
        <p><?= e($flashError) ?></p>
    </div>
<?php endif; ?>

<?php if (!empty($aktif)): ?>
    <div class="callout callout--warning">
        <?= icon('alert') ?>
        <p>
            Anda masih memiliki <strong><?= e((string) count($aktif)) ?></strong> tiket aktif.
            Periksa dahulu apakah gangguannya sama sebelum membuat tiket baru.
        </p>
    </div>

    <ul class="status-list">
        <?php foreach ($aktif as $tiket): ?>
            <li class="status-list-item">
                <span class="status-list-label">
                    <strong><?= e((string) $tiket['nomor_tiket']) ?></strong>
                    <?= component_html('badge-status', ['status' => (string) $tiket['status']]) ?>
                </span>
                <a class="btn btn-outline-light btn-sm" href="<?= e(url('/pelanggan/pengaduan/' . (int) $tiket['id'])) ?>">
                    <?= icon('eye') ?><span>Lihat Tiket</span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<div class="grid-2 grid-2--detail">
    <section class="card">
        <div class="card-head">
            <h3 class="card-title">Formulir Pengaduan</h3>
        </div>
        <div class="card-body">
            <form method="post" action="<?= e(url('/pelanggan/pengaduan')) ?>" enctype="multipart/form-data" novalidate
                  data-confirm
                  data-confirm-title="Kirim pengaduan ini?"
                  data-confirm-message="Pastikan data dan foto bukti sudah benar. Nomor tiket dibuat otomatis setelah pengaduan berhasil terkirim."
                  data-confirm-label="Ya, kirim pengaduan"
                  data-confirm-variant="primary"
                  data-confirm-icon="upload"
                  data-confirm-loading="Mengirim pengaduan...">
                <?= csrf_field() ?>

                <div class="form-group">
                    <label class="form-label" for="aduan-nama">Nama pelanggan</label>
                    <input class="form-control" type="text" id="aduan-nama" value="<?= e((string) $profil['nama']) ?>" readonly>
                    <p class="form-hint">Diambil otomatis dari profil akun Anda.</p>
                </div>

                <div class="form-group">
                    <label class="form-label" for="aduan-pppoe">Username PPPoE / Nama WAN</label>
                    <input class="form-control" type="text" id="aduan-pppoe" value="<?= e((string) $profil['username_pppoe']) ?>" readonly>
                    <p class="form-hint">Password jaringan PPPoE tidak pernah diminta atau disimpan.</p>
                </div>

                <div class="form-group">
                    <label class="form-label" for="aduan-wa">Nomor WhatsApp kontak tiket</label>
                    <input class="form-control" type="tel" id="aduan-wa" name="nomor_whatsapp"
                           inputmode="tel" autocomplete="tel"
                           value="<?= e($waValue) ?>" placeholder="08xxxxxxxxxx">
                    <p class="form-hint">Nomor ini hanya dipakai sebagai kontak untuk tiket ini dan tidak mengubah nomor WhatsApp utama di Profil. Kosongkan untuk memakai nomor utama.</p>
                </div>

                <div class="form-group">
                    <label class="form-label" for="aduan-kategori">Kategori gangguan <span class="form-required">*</span></label>
                    <select class="form-select" id="aduan-kategori" name="kategori_id" required>
                        <option value="">— Pilih kategori —</option>
                        <?php foreach ($kategori as $item): ?>
                            <option value="<?= e((string) $item['id']) ?>"
                                <?= $kategoriValue === (int) $item['id'] ? 'selected' : '' ?>>
                                <?= e((string) $item['nama']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="aduan-deskripsi">Deskripsi gangguan <span class="form-required">*</span></label>
                    <textarea class="form-control" id="aduan-deskripsi" name="deskripsi" rows="5"
                              maxlength="2000" required
                              placeholder="Jelaskan gangguan yang Anda alami, sejak kapan, dan gejalanya…"><?= e($deskripsiValue) ?></textarea>
                    <p class="form-hint">Minimal 10 karakter, maksimal 2000 karakter.</p>
                </div>

                <?= component('upload-photo', [
                    'id' => 'aduan-foto',
                    'name' => 'foto[]',
                    'label' => 'Foto bukti gangguan',
                    'hint' => 'Format JPG, PNG, atau WebP. Maksimal 5 MB per berkas. Wajib diunggah.',
                    'multiple' => true,
                    'required' => true,
                ]) ?>

                <div class="form-actions">
                    <button class="btn btn-primary" type="submit">
                        <?= icon('check') ?><span>Kirim Pengaduan</span>
                    </button>
                    <a class="btn btn-outline-light" href="<?= e(url('/pelanggan/pengaduan')) ?>">
                        <?= icon('x') ?><span>Batal</span>
                    </a>
                </div>
            </form>
        </div>
    </section>

    <aside class="stack">
        <section class="card">
            <div class="card-head">
                <h3 class="card-title">Panduan Pengaduan</h3>
            </div>
            <div class="card-body">
                <ul class="checklist">
                    <li>Pastikan nama dan Username PPPoE sudah sesuai.</li>
                    <li>Pilih kategori gangguan yang paling mendekati.</li>
                    <li>Jelaskan gejala dengan detail (sejak kapan, frekuensi, perangkat).</li>
                    <li>Unggah foto bukti yang jelas (lampu indikator, kabel, pesan error).</li>
                    <li>Nomor tiket akan dibuat otomatis setelah pengaduan terkirim.</li>
                </ul>
                <p class="form-hint">Satu pengaduan sebaiknya untuk satu gangguan. Anda tetap dapat membuat tiket baru jika diperlukan.</p>
            </div>
        </section>
    </aside>
</div>
