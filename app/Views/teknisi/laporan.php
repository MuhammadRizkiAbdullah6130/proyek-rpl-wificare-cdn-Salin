<?php
/**
 * Teknisi — Form Laporan Perbaikan (Tahap 7).
 *
 * Penyebab & tindakan perbaikan wajib diisi; minimal satu foto hasil perbaikan
 * wajib diunggah. Penyimpanan laporan dan perubahan status menjadi Selesai
 * dilakukan atomik di backend, bukan di halaman ini.
 */

declare(strict_types=1);
$content = '';

$pengaduan = $pengaduan ?? [];
$penugasanAktif = $penugasanAktif ?? null;
$old = $old ?? [];
$flashError = $flash_error ?? null;

$id = (int) ($pengaduan['id'] ?? 0);
$nomorTiket = (string) ($pengaduan['nomor_tiket'] ?? '-');
$nama = (string) ($pengaduan['nama_pelanggan_snapshot'] ?? '-');

$penyebabValue = (string) ($old['penyebab_gangguan'] ?? '');
$tindakanValue = (string) ($old['tindakan_perbaikan'] ?? '');
$catatanValue = (string) ($old['catatan'] ?? '');
?>
<?= component('page-header', [
    'title' => 'Form Laporan Perbaikan',
    'subtitle' => 'Laporan penyebab dan tindakan perbaikan wajib disertai foto hasil.',
    'actions' => [
        ['label' => 'Kembali ke Tugas', 'url' => url('/teknisi/tugas/' . $id), 'icon' => 'arrow-left', 'variant' => 'outline-light'],
    ],
]) ?>

<?php if ($flashError !== null): ?>
    <div class="callout callout--danger"><?= icon('alert') ?><p><?= e($flashError) ?></p></div>
<?php endif; ?>

<div class="grid-2 grid-2--detail">
    <section class="card">
        <div class="card-head">
            <h3 class="card-title">Laporan untuk <?= e($nomorTiket) ?></h3>
            <?= component_html('badge-status', ['status' => 'dalam_penanganan']) ?>
        </div>
        <div class="card-body">
            <form method="post" action="<?= e(url('/teknisi/tugas/' . $id . '/laporan')) ?>"
                  enctype="multipart/form-data" novalidate
                  data-confirm
                  data-confirm-title="Kirim Laporan Perbaikan?"
                  data-confirm-message="Pastikan seluruh informasi dan foto hasil perbaikan sudah benar. Setelah laporan berhasil disimpan, status tiket otomatis berubah menjadi Selesai."
                  data-confirm-label="Ya, Kirim Laporan"
                  data-confirm-variant="primary"
                  data-confirm-icon="upload"
                  data-confirm-loading="Mengirim laporan...">
                <?= csrf_field() ?>

                <div class="form-group">
                    <label class="form-label" for="laporan-penyebab">Penyebab gangguan <span class="form-required">*</span></label>
                    <textarea class="form-control" id="laporan-penyebab" name="penyebab_gangguan" rows="3"
                              maxlength="2000" required
                              placeholder="Jelaskan penyebab gangguan yang ditemukan di lapangan…"><?= e($penyebabValue) ?></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label" for="laporan-tindakan">Tindakan perbaikan <span class="form-required">*</span></label>
                    <textarea class="form-control" id="laporan-tindakan" name="tindakan_perbaikan" rows="4"
                              maxlength="2000" required
                              placeholder="Jelaskan tindakan perbaikan yang dilakukan…"><?= e($tindakanValue) ?></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label" for="laporan-catatan">Catatan tambahan (opsional)</label>
                    <textarea class="form-control" id="laporan-catatan" name="catatan" rows="3"
                              maxlength="2000"
                              placeholder="Informasi tambahan, mis. kebutuhan penggantian perangkat."><?= e($catatanValue) ?></textarea>
                </div>

                <?= component('upload-photo', [
                    'id' => 'laporan-foto',
                    'name' => 'foto[]',
                    'label' => 'Foto bukti setelah perbaikan',
                    'hint' => 'Format JPG, PNG, atau WebP. Maksimal 5 MB per berkas. Wajib diunggah.',
                    'multiple' => true,
                    'required' => true,
                ]) ?>

                <div class="form-actions">
                    <button class="btn btn-success" type="submit">
                        <?= icon('check') ?><span>Kirim Laporan &amp; Selesaikan</span>
                    </button>
                    <a class="btn btn-outline-light" href="<?= e(url('/teknisi/tugas/' . $id)) ?>">
                        <?= icon('x') ?><span>Batal</span>
                    </a>
                </div>
            </form>
        </div>
    </section>

    <aside class="stack">
        <section class="card">
            <div class="card-head">
                <h3 class="card-title">Ringkasan Tugas</h3>
            </div>
            <div class="card-body">
                <dl class="detail-list">
                    <div><dt>Nomor tiket</dt><dd><?= e($nomorTiket) ?></dd></div>
                    <div><dt>Pelanggan</dt><dd><?= e($nama) ?></dd></div>
                    <div><dt>Kategori</dt><dd><?= e((string) ($pengaduan['kategori'] ?? '—')) ?></dd></div>
                    <?php if (is_array($penugasanAktif) && !empty($penugasanAktif['catatan'])): ?>
                        <div><dt>Instruksi CS</dt><dd><?= e((string) $penugasanAktif['catatan']) ?></dd></div>
                    <?php endif; ?>
                </dl>
            </div>
        </section>

        <section class="card">
            <div class="card-head">
                <h3 class="card-title">Panduan Pengisian</h3>
            </div>
            <div class="card-body">
                <ul class="checklist">
                    <li>Isi penyebab gangguan berdasarkan temuan di lokasi.</li>
                    <li>Uraikan tindakan perbaikan yang benar-benar dilakukan.</li>
                    <li>Unggah minimal satu foto hasil perbaikan yang jelas.</li>
                    <li>Setelah laporan tersimpan, tiket otomatis berstatus <strong>Selesai</strong>.</li>
                    <li>Laporan yang sudah terkirim tidak dapat dikirim ulang.</li>
                </ul>
            </div>
        </section>
    </aside>
</div>
