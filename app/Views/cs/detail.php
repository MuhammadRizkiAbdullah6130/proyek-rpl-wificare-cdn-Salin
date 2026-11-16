<?php
/**
 * Customer Service — Detail Pengaduan / pusat tindakan (Tahap 6).
 *
 * Menampilkan informasi tiket, foto bukti (via endpoint berotorisasi),
 * timeline status, penugasan teknisi, hasil penanganan, catatan internal,
 * dan informasi pembatalan — beserta tindakan yang SAH menurut status.
 *
 * Tombol tindakan hanya ditampilkan bila transisi sah; backend tetap
 * memvalidasi ulang setiap aksi.
 */

declare(strict_types=1);
$content = '';

$pengaduan = $pengaduan ?? [];
$fotos = $fotos ?? [];
$riwayat = $riwayat ?? [];
$penugasanAktif = $penugasanAktif ?? null;
$penugasanRiwayat = $penugasanRiwayat ?? [];
$laporan = $laporan ?? null;
$laporanFotos = $laporanFotos ?? [];
$penyelesaian = $penyelesaian ?? null;
$catatan = $catatan ?? [];
$teknisiOptions = $teknisiOptions ?? [];
$allowedTargets = $allowedTargets ?? [];
$flashSukses = $flash_sukses ?? null;
$flashError = $flash_error ?? null;

$id = (int) ($pengaduan['id'] ?? 0);
$status = (string) ($pengaduan['status'] ?? 'menunggu_verifikasi');
$isCancelled = (int) ($pengaduan['is_cancelled'] ?? 0) === 1;
$nomorTiket = (string) ($pengaduan['nomor_tiket'] ?? '-');

$can = static fn (string $target): bool => in_array($target, $allowedTargets, true);
$actionUrl = static fn (string $action): string => url('/cs/pengaduan/' . $id . '/' . $action);
?>
<?= component('page-header', [
    'title' => 'Detail Pengaduan ' . e($nomorTiket),
    'subtitle' => 'Verifikasi, tangani, tugaskan, atau batalkan tiket sesuai status.',
    'actions' => [
        ['label' => 'Kembali', 'url' => url('/cs/pengaduan'), 'icon' => 'arrow-left', 'variant' => 'outline-light'],
    ],
]) ?>

<?php if ($flashSukses !== null): ?>
    <div class="callout callout--success"><?= icon('check') ?><p><?= e($flashSukses) ?></p></div>
<?php endif; ?>
<?php if ($flashError !== null): ?>
    <div class="callout callout--danger"><?= icon('alert') ?><p><?= e($flashError) ?></p></div>
<?php endif; ?>

<?php if ($isCancelled): ?>
    <div class="callout callout--danger">
        <?= icon('x') ?>
        <p>
            Tiket ini <strong>dibatalkan</strong>
            <?php if (!empty($pengaduan['cancelled_at'])): ?>
                pada <?= e(format_waktu((string) $pengaduan['cancelled_at'])) ?>
            <?php endif; ?>.
            <?php if (!empty($pengaduan['cancellation_reason'])): ?>
                Alasan: <?= e((string) $pengaduan['cancellation_reason']) ?>
            <?php endif; ?>
            Tiket dibatalkan tidak dapat diproses melalui alur normal.
        </p>
    </div>
<?php endif; ?>

<?php if ($status === 'ditolak' && !$isCancelled): ?>
    <div class="callout callout--warning">
        <?= icon('alert') ?>
        <p>
            Tiket ini <strong>ditolak</strong>.
            <?php if (!empty($pengaduan['alasan_penolakan'])): ?>
                Alasan: <?= e((string) $pengaduan['alasan_penolakan']) ?>
            <?php endif; ?>
            Tiket ditolak tidak dapat diproses lagi melalui alur normal.
        </p>
    </div>
<?php endif; ?>

<div class="grid-2 grid-2--detail">
    <section class="card">
        <div class="card-head">
            <h3 class="card-title">Informasi Tiket</h3>
            <span class="status-list-label">
                <?= status_badge_html($status, $isCancelled) ?>
            </span>
        </div>
        <div class="card-body">
            <dl class="detail-list">
                <div><dt>Nomor tiket</dt><dd><?= e($nomorTiket) ?></dd></div>
                <div><dt>Nama pelanggan</dt><dd><?= e((string) ($pengaduan['nama_pelanggan_snapshot'] ?? '-')) ?></dd></div>
                <div><dt>Username PPPoE</dt><dd><?= e((string) ($pengaduan['username_pppoe_snapshot'] ?? '-')) ?></dd></div>
                <div><dt>Nomor WhatsApp</dt><dd><?= e((string) ($pengaduan['nomor_whatsapp_snapshot'] ?? '-')) ?></dd></div>
                <div><dt>Kategori gangguan</dt><dd><?= e((string) ($pengaduan['kategori'] ?? 'Tanpa kategori')) ?></dd></div>
                <div><dt>Tanggal pengaduan</dt><dd><?= e(format_waktu((string) ($pengaduan['created_at'] ?? ''))) ?></dd></div>
                <div><dt>Pelanggan</dt><dd>
                    <a class="link-inline" href="<?= e(url('/cs/pelanggan/' . (int) $pengaduan['pelanggan_id'])) ?>">
                        Lihat riwayat pelanggan
                    </a>
                </dd></div>
            </dl>

            <div class="form-group">
                <p class="form-label">Deskripsi gangguan</p>
                <p class="detail-text"><?= nl2br(e((string) ($pengaduan['deskripsi'] ?? '-'))) ?></p>
            </div>

            <div class="form-group">
                <p class="form-label">Foto bukti</p>
                <?php if (!empty($fotos)): ?>
                    <div class="foto-grid">
                        <?php foreach ($fotos as $foto): ?>
                            <figure style="margin:0;">
                                <a class="foto-item" href="<?= e(url('/pengaduan/foto/' . (int) $foto['id'])) ?>"
                                   target="_blank" rel="noopener" title="Buka foto ukuran penuh">
                                    <img src="<?= e(url('/pengaduan/foto/' . (int) $foto['id'])) ?>"
                                         alt="Foto bukti gangguan" loading="lazy">
                                </a>
                                <figcaption class="foto-meta"><?= e((string) ($foto['original_name'] ?? 'foto')) ?></figcaption>
                            </figure>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <?= component_html('empty-state', [
                        'icon' => 'image',
                        'title' => 'Tidak ada foto',
                        'message' => 'Tidak ada foto bukti yang tersimpan untuk tiket ini.',
                        'compact' => true,
                    ]) ?>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <div class="stack">
        <section class="card">
            <div class="card-head">
                <h3 class="card-title">Tindakan CS</h3>
            </div>
            <div class="card-body detail-actions">
                <?php if ($isCancelled): ?>
                    <div class="callout callout--danger" style="margin:0;">
                        <?= icon('x') ?><p>Tiket dibatalkan. Tidak ada tindakan yang tersedia.</p>
                    </div>
                <?php elseif ($status === 'ditolak'): ?>
                    <div class="callout callout--warning" style="margin:0;">
                        <?= icon('alert') ?><p>Tiket sudah ditolak. Tidak ada tindakan lanjutan.</p>
                    </div>
                <?php elseif ($status === 'selesai'): ?>
                    <div class="callout callout--success" style="margin:0;">
                        <?= icon('check') ?><p>Tiket sudah selesai. Lihat hasil penanganan di bawah.</p>
                    </div>
                <?php endif; ?>

                <?php if (!$isCancelled && $can('diproses_cs')): ?>
                    <form class="form-stack" method="post" action="<?= e($actionUrl('terima')) ?>"
                          data-confirm
                          data-confirm-title="Terima pengaduan ini?"
                          data-confirm-message="Status tiket akan berubah menjadi Diproses CS setelah pengaduan diverifikasi."
                          data-confirm-label="Ya, terima pengaduan"
                          data-confirm-variant="primary"
                          data-confirm-icon="check"
                          data-confirm-loading="Memproses verifikasi...">
                        <?= csrf_field() ?>
                        <button class="btn btn-primary" type="submit"><?= icon('check') ?><span>Terima Pengaduan</span></button>
                    </form>

                    <form class="form-stack" method="post" action="<?= e($actionUrl('tolak')) ?>"
                          data-confirm
                          data-confirm-title="Tolak pengaduan ini?"
                          data-confirm-message="Pengaduan akan ditolak dan pelanggan akan melihat alasan penolakan yang Anda isi. Pastikan alasan sudah benar."
                          data-confirm-label="Ya, tolak pengaduan"
                          data-confirm-variant="danger"
                          data-confirm-icon="x"
                          data-confirm-loading="Menolak pengaduan...">
                        <?= csrf_field() ?>
                        <div class="form-group">
                            <label class="form-label" for="alasan-tolak">Alasan penolakan <span aria-hidden="true">*</span></label>
                            <textarea class="form-control" id="alasan-tolak" name="alasan" rows="3" required
                                      maxlength="2000" placeholder="Jelaskan alasan penolakan pengaduan."></textarea>
                        </div>
                        <button class="btn btn-danger" type="submit"><?= icon('x') ?><span>Tolak Pengaduan</span></button>
                    </form>
                <?php endif; ?>

                <?php if (!$isCancelled && $can('selesai')): ?>
                    <form class="form-stack" method="post" action="<?= e($actionUrl('selesaikan')) ?>"
                          data-confirm
                          data-confirm-title="Selesaikan tiket ini?"
                          data-confirm-message="Tiket akan ditandai selesai langsung oleh CS. Pastikan solusi atau tindakan penyelesaian sudah diisi dengan benar."
                          data-confirm-label="Ya, selesaikan tiket"
                          data-confirm-variant="success"
                          data-confirm-icon="check"
                          data-confirm-loading="Menyelesaikan tiket...">
                        <?= csrf_field() ?>
                        <div class="form-group">
                            <label class="form-label" for="solusi">Solusi / tindakan yang dilakukan <span aria-hidden="true">*</span></label>
                            <textarea class="form-control" id="solusi" name="solusi" rows="4" required
                                      maxlength="2000" placeholder="Tindakan penyelesaian yang dilakukan CS."></textarea>
                        </div>
                        <button class="btn btn-success" type="submit"><?= icon('check') ?><span>Selesaikan Langsung</span></button>
                    </form>
                <?php endif; ?>

                <?php if (!$isCancelled && $can('menunggu_teknisi')): ?>
                    <?php if (empty($teknisiOptions)): ?>
                        <div class="callout callout--warning" style="margin:0;">
                            <?= icon('alert') ?><p>Belum ada akun teknisi aktif. Tambahkan teknisi terlebih dahulu.</p>
                        </div>
                    <?php else: ?>
                        <form class="form-stack" method="post" action="<?= e($actionUrl('tugaskan')) ?>"
                              data-confirm
                              data-confirm-title="Tugaskan tiket ini?"
                              data-confirm-message="Tiket akan diberikan kepada teknisi yang dipilih dan status berubah menjadi Menunggu Teknisi."
                              data-confirm-label="Ya, tugaskan teknisi"
                              data-confirm-variant="primary"
                              data-confirm-icon="tool"
                              data-confirm-loading="Menugaskan teknisi...">
                            <?= csrf_field() ?>
                            <div class="form-group">
                                <label class="form-label" for="teknisi_id">Pilih teknisi <span aria-hidden="true">*</span></label>
                                <select class="form-select" id="teknisi_id" name="teknisi_id" required>
                                    <option value="">— Pilih teknisi aktif —</option>
                                    <?php foreach ($teknisiOptions as $teknisi): ?>
                                        <option value="<?= e((string) $teknisi['id']) ?>">
                                            <?= e((string) $teknisi['nama']) ?> (<?= e((string) $teknisi['username']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="instruksi">Instruksi penugasan (opsional)</label>
                                <textarea class="form-control" id="instruksi" name="catatan" rows="3" maxlength="2000"
                                          placeholder="Catatan/instruksi untuk teknisi."></textarea>
                            </div>
                            <button class="btn btn-primary" type="submit"><?= icon('tool') ?><span>Tugaskan Teknisi</span></button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if (!$isCancelled && $status === 'menunggu_teknisi'): ?>
                    <div class="callout callout--info" style="margin:0;">
                        <?= icon('clock') ?><p>Menunggu teknisi mengerjakan tiket. Pantau melalui monitoring.</p>
                    </div>
                <?php endif; ?>

                <?php if (!$isCancelled && $status === 'dalam_penanganan'): ?>
                    <div class="callout callout--info" style="margin:0;">
                        <?= icon('activity') ?><p>Tiket sedang dalam penanganan teknisi. Pantau melalui monitoring.</p>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="card">
            <div class="card-head">
                <h3 class="card-title">Riwayat Status</h3>
                <span class="card-head-note"><?= e((string) count($riwayat)) ?> catatan</span>
            </div>
            <div class="card-body">
                <?php if (!empty($riwayat)): ?>
                    <ul class="timeline">
                        <?php
                        $lastIndex = count($riwayat) - 1;
                        foreach ($riwayat as $index => $log):
                        ?>
                            <li class="timeline-item<?= $index === $lastIndex ? ' is-current' : '' ?>">
                                <span class="timeline-dot" aria-hidden="true"></span>
                                <div class="timeline-content">
                                    <?= component_html('badge-status', ['status' => (string) $log['status_baru'], 'dot' => false]) ?>
                                    <p class="timeline-meta">
                                        <?= e(format_waktu((string) $log['created_at'])) ?>
                                        <?php if (!empty($log['actor_nama'])): ?>
                                            &middot; <?= e((string) $log['actor_nama']) ?>
                                        <?php endif; ?>
                                    </p>
                                    <?php if (!empty($log['catatan'])): ?>
                                        <p class="timeline-text"><?= e((string) $log['catatan']) ?></p>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <?= component_html('empty-state', [
                        'icon' => 'clock',
                        'title' => 'Belum ada riwayat',
                        'message' => 'Riwayat perubahan status akan tampil di sini.',
                        'compact' => true,
                    ]) ?>
                <?php endif; ?>
            </div>
        </section>

        <section class="card">
            <div class="card-head">
                <h3 class="card-title">Penugasan Teknisi</h3>
            </div>
            <div class="card-body">
                <?php if (is_array($penugasanAktif)): ?>
                    <dl class="detail-list">
                        <div><dt>Teknisi aktif</dt><dd><?= e((string) ($penugasanAktif['teknisi_nama'] ?? '-')) ?></dd></div>
                        <div><dt>Ditugaskan</dt><dd><?= e(format_waktu((string) ($penugasanAktif['assigned_at'] ?? ''))) ?></dd></div>
                        <?php if (!empty($penugasanAktif['catatan'])): ?>
                            <div><dt>Instruksi</dt><dd><?= e((string) $penugasanAktif['catatan']) ?></dd></div>
                        <?php endif; ?>
                    </dl>
                <?php else: ?>
                    <p class="page-subtitle">Belum ada teknisi yang ditugaskan pada tiket ini.</p>
                <?php endif; ?>

                <?php if (count($penugasanRiwayat) > 1): ?>
                    <div class="form-group">
                        <p class="form-label">Riwayat penugasan</p>
                        <ul class="timeline">
                            <?php foreach ($penugasanRiwayat as $riwayatTugas): ?>
                                <li class="timeline-item">
                                    <span class="timeline-dot" aria-hidden="true"></span>
                                    <div class="timeline-content">
                                        <p class="timeline-text">
                                            <strong><?= e((string) $riwayatTugas['teknisi_nama']) ?></strong>
                                            <?= (int) $riwayatTugas['is_active'] === 1 ? '(aktif)' : '(selesai/diganti)' ?>
                                        </p>
                                        <p class="timeline-meta"><?= e(format_waktu((string) $riwayatTugas['assigned_at'])) ?></p>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="card">
            <div class="card-head">
                <h3 class="card-title">Hasil Penanganan</h3>
            </div>
            <div class="card-body">
                <?php if (is_array($laporan)): ?>
                    <dl class="detail-list">
                        <div><dt>Teknisi</dt><dd><?= e((string) ($laporan['teknisi_nama'] ?? '-')) ?></dd></div>
                        <div><dt>Selesai</dt><dd><?= e(format_waktu((string) ($laporan['completed_at'] ?? ''))) ?></dd></div>
                    </dl>
                    <div class="form-group">
                        <p class="form-label">Penyebab gangguan</p>
                        <p class="detail-text"><?= nl2br(e((string) ($laporan['penyebab_gangguan'] ?? '-'))) ?></p>
                    </div>
                    <div class="form-group">
                        <p class="form-label">Tindakan perbaikan</p>
                        <p class="detail-text"><?= nl2br(e((string) ($laporan['tindakan_perbaikan'] ?? '-'))) ?></p>
                    </div>
                    <?php if (!empty($laporan['catatan'])): ?>
                        <div class="form-group">
                            <p class="form-label">Catatan tambahan</p>
                            <p class="detail-text"><?= nl2br(e((string) $laporan['catatan'])) ?></p>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($laporanFotos)): ?>
                        <div class="form-group">
                            <p class="form-label">Foto hasil perbaikan</p>
                            <div class="foto-grid">
                                <?php foreach ($laporanFotos as $foto): ?>
                                    <figure style="margin:0;">
                                        <a class="foto-item" href="<?= e(url('/laporan/foto/' . (int) $foto['id'])) ?>"
                                           target="_blank" rel="noopener" title="Buka foto ukuran penuh">
                                            <img src="<?= e(url('/laporan/foto/' . (int) $foto['id'])) ?>"
                                                 alt="Foto hasil perbaikan" loading="lazy">
                                        </a>
                                        <figcaption class="foto-meta"><?= e((string) ($foto['original_name'] ?? 'foto')) ?></figcaption>
                                    </figure>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php elseif (is_array($penyelesaian)): ?>
                    <dl class="detail-list">
                        <div><dt>Diselesaikan oleh</dt><dd>CS <?= e((string) ($penyelesaian['cs_nama'] ?? '-')) ?></dd></div>
                        <div><dt>Selesai</dt><dd><?= e(format_waktu((string) ($penyelesaian['completed_at'] ?? ''))) ?></dd></div>
                    </dl>
                    <div class="form-group">
                        <p class="form-label">Solusi / tindakan</p>
                        <p class="detail-text"><?= nl2br(e((string) ($penyelesaian['solusi'] ?? '-'))) ?></p>
                    </div>
                <?php else: ?>
                    <?= component_html('empty-state', [
                        'icon' => 'file',
                        'title' => 'Belum ada hasil penanganan',
                        'message' => 'Hasil penanganan akan tampil setelah tiket selesai.',
                        'compact' => true,
                    ]) ?>
                <?php endif; ?>
            </div>
        </section>

        <section class="card">
            <div class="card-head">
                <h3 class="card-title">Catatan Internal</h3>
                <span class="card-head-note">Tidak terlihat pelanggan</span>
            </div>
            <div class="card-body">
                <?php if (!empty($catatan)): ?>
                    <div class="note-list" style="margin-bottom:1rem;">
                        <?php foreach ($catatan as $note): ?>
                            <article class="note-item">
                                <div class="note-item-head">
                                    <span class="note-item-actor"><?= e((string) ($note['cs_nama'] ?? 'Petugas')) ?></span>
                                    <span class="note-item-time"><?= e(format_waktu((string) $note['created_at'])) ?></span>
                                </div>
                                <p class="note-item-text"><?= nl2br(e((string) $note['catatan'])) ?></p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="page-subtitle">Belum ada catatan internal pada tiket ini.</p>
                <?php endif; ?>

                <form class="form-stack" method="post" action="<?= e($actionUrl('catatan')) ?>">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label class="form-label" for="catatan">Tambah catatan internal</label>
                        <textarea class="form-control" id="catatan" name="catatan" rows="3" required
                                  maxlength="2000" placeholder="Catatan untuk petugas lain (tidak dilihat pelanggan)."></textarea>
                    </div>
                    <button class="btn btn-outline-light" type="submit"><?= icon('note') ?><span>Simpan Catatan</span></button>
                </form>
            </div>
        </section>

        <section class="card">
            <div class="card-head">
                <h3 class="card-title">Pembatalan</h3>
            </div>
            <div class="card-body">
                <?php if ($isCancelled): ?>
                    <dl class="detail-list">
                        <div><dt>Status</dt><dd><span class="badge-cancel"><?= icon('x') ?>Dibatalkan</span></dd></div>
                        <div><dt>Waktu</dt><dd><?= e(format_waktu((string) ($pengaduan['cancelled_at'] ?? ''))) ?></dd></div>
                        <div><dt>Alasan</dt><dd><?= e((string) ($pengaduan['cancellation_reason'] ?? '-')) ?></dd></div>
                    </dl>
                <?php elseif (status_is_active($status)): ?>
                    <form class="form-stack" method="post" action="<?= e($actionUrl('batalkan')) ?>"
                          data-confirm
                          data-confirm-title="Batalkan tiket ini?"
                          data-confirm-message="Tiket akan dibatalkan dan tindakan ini tercatat pada audit log. Pastikan alasan pembatalan sudah benar."
                          data-confirm-label="Ya, batalkan tiket"
                          data-confirm-variant="danger"
                          data-confirm-icon="alert"
                          data-confirm-loading="Membatalkan tiket...">
                        <?= csrf_field() ?>
                        <div class="form-group">
                            <label class="form-label" for="alasan-batal">Alasan pembatalan <span aria-hidden="true">*</span></label>
                            <textarea class="form-control" id="alasan-batal" name="alasan" rows="3" required
                                      maxlength="2000" placeholder="Jelaskan alasan pembatalan tiket."></textarea>
                        </div>
                        <button class="btn btn-danger" type="submit"><?= icon('x') ?><span>Batalkan Pengaduan</span></button>
                    </form>
                <?php else: ?>
                    <p class="page-subtitle">Tiket yang sudah selesai atau ditolak tidak dapat dibatalkan.</p>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>
