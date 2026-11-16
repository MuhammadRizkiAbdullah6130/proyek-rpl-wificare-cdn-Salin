<?php
/**
 * Admin — Detail Pengaduan (Tahap 8).
 *
 * Read-only monitoring: informasi tiket, foto, timeline, penugasan, hasil
 * penanganan, rating pelanggan, catatan internal, pembatalan, dan pengarsipan.
 * Admin dapat membatalkan (via alur CS) dan mengarsipkan tiket.
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
$rating = $rating ?? null;
$flashSukses = $flash_sukses ?? null;
$flashError = $flash_error ?? null;

$id = (int) ($pengaduan['id'] ?? 0);
$status = (string) ($pengaduan['status'] ?? 'menunggu_verifikasi');
$isCancelled = (int) ($pengaduan['is_cancelled'] ?? 0) === 1;
$isArchived = ($pengaduan['archived_at'] ?? null) !== null;
$nomorTiket = (string) ($pengaduan['nomor_tiket'] ?? '-');
$canCancel = !$isCancelled && status_is_active($status) && $status !== 'ditolak';
?>
<?= component('page-header', [
    'title' => 'Detail Pengaduan ' . e($nomorTiket),
    'subtitle' => 'Pantau hasil penanganan, riwayat, pembatalan, dan kelola arsip tiket.',
    'actions' => [
        ['label' => 'Kembali', 'url' => url('/admin/pengaduan'), 'icon' => 'arrow-left', 'variant' => 'outline-light'],
    ],
]) ?>

<?php if ($flashSukses !== null): ?>
    <div class="callout callout--success"><?= icon('check') ?><p><?= e($flashSukses) ?></p></div>
<?php endif; ?>
<?php if ($flashError !== null): ?>
    <div class="callout callout--danger"><?= icon('alert') ?><p><?= e($flashError) ?></p></div>
<?php endif; ?>

<?php if ($isArchived): ?>
    <div class="callout callout--info">
        <?= icon('file') ?><p>Tiket ini telah <strong>diarsipkan</strong><?php if (!empty($pengaduan['arsip_oleh'])): ?> oleh <?= e((string) $pengaduan['arsip_oleh']) ?><?php endif; ?> pada <?= e(format_waktu((string) $pengaduan['archived_at'])) ?>. Riwayat tetap tersimpan.</p>
    </div>
<?php endif; ?>

<?php if ($isCancelled): ?>
    <div class="callout callout--danger">
        <?= icon('x') ?>
        <p>
            Tiket ini <strong>dibatalkan</strong><?php if (!empty($pengaduan['cancelled_at'])): ?> pada <?= e(format_waktu((string) $pengaduan['cancelled_at'])) ?><?php endif; ?>.
            <?php if (!empty($pengaduan['cancellation_reason'])): ?>Alasan: <?= e((string) $pengaduan['cancellation_reason']) ?><?php endif; ?>
        </p>
    </div>
<?php endif; ?>

<div class="grid-2 grid-2--detail">
    <section class="card">
        <div class="card-head">
            <h3 class="card-title">Informasi Tiket</h3>
            <span class="status-list-label"><?= status_badge_html($status, $isCancelled) ?></span>
        </div>
        <div class="card-body">
            <dl class="detail-list">
                <div><dt>Nomor tiket</dt><dd><?= e($nomorTiket) ?></dd></div>
                <div><dt>Nama pelanggan</dt><dd><?= e((string) ($pengaduan['nama_pelanggan_snapshot'] ?? '-')) ?></dd></div>
                <div><dt>Username PPPoE</dt><dd><?= e((string) ($pengaduan['username_pppoe_snapshot'] ?? '-')) ?></dd></div>
                <div><dt>Nomor WhatsApp</dt><dd><?= e((string) ($pengaduan['nomor_whatsapp_snapshot'] ?? '-')) ?></dd></div>
                <div><dt>Kategori gangguan</dt><dd><?= e((string) ($pengaduan['kategori'] ?? 'Tanpa kategori')) ?></dd></div>
                <div><dt>Teknisi</dt><dd><?= e((string) ($pengaduan['teknisi_nama'] ?? '—')) ?></dd></div>
                <div><dt>Tanggal pengaduan</dt><dd><?= e(format_waktu((string) ($pengaduan['created_at'] ?? ''))) ?></dd></div>
                <div><dt>Pelanggan</dt><dd>
                    <a class="link-inline" href="<?= e(url('/admin/pelanggan/' . (int) $pengaduan['pelanggan_id'])) ?>">Lihat data pelanggan</a>
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
                                <a class="foto-item" href="<?= e(url('/pengaduan/foto/' . (int) $foto['id'])) ?>" target="_blank" rel="noopener">
                                    <img src="<?= e(url('/pengaduan/foto/' . (int) $foto['id'])) ?>" alt="Foto bukti gangguan" loading="lazy">
                                </a>
                            </figure>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <?= component_html('empty-state', ['icon' => 'image', 'title' => 'Tidak ada foto', 'message' => 'Tidak ada foto bukti untuk tiket ini.', 'compact' => true]) ?>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <div class="stack">
        <?php if (rating_layanan_enabled()): ?>
            <section class="card">
                <div class="card-head"><h3 class="card-title">Rating Pelanggan</h3></div>
                <div class="card-body">
                    <?php if (is_array($rating)): ?>
                        <div class="rating-display" aria-label="Rating <?= e((string) $rating['rating']) ?> dari 5">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <span class="rating-star<?= $i <= (int) $rating['rating'] ? ' is-on' : '' ?>"><?= icon('star') ?></span>
                            <?php endfor; ?>
                            <span class="rating-score"><?= e((string) $rating['rating']) ?>/5</span>
                        </div>
                        <?php if (!empty($rating['ulasan'])): ?>
                            <p class="detail-text"><?= nl2br(e((string) $rating['ulasan'])) ?></p>
                        <?php endif; ?>
                        <p class="timeline-meta"><?= e(format_waktu((string) ($rating['created_at'] ?? ''))) ?></p>
                    <?php else: ?>
                        <p class="page-subtitle">Pelanggan belum memberikan rating untuk tiket ini.</p>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <section class="card">
            <div class="card-head"><h3 class="card-title">Hasil Penanganan</h3></div>
            <div class="card-body">
                <?php if (is_array($laporan)): ?>
                    <dl class="detail-list">
                        <div><dt>Teknisi</dt><dd><?= e((string) ($laporan['teknisi_nama'] ?? '-')) ?></dd></div>
                        <div><dt>Selesai</dt><dd><?= e(format_waktu((string) ($laporan['completed_at'] ?? ''))) ?></dd></div>
                    </dl>
                    <div class="form-group"><p class="form-label">Penyebab gangguan</p>
                        <p class="detail-text"><?= nl2br(e((string) ($laporan['penyebab_gangguan'] ?? '-'))) ?></p></div>
                    <div class="form-group"><p class="form-label">Tindakan perbaikan</p>
                        <p class="detail-text"><?= nl2br(e((string) ($laporan['tindakan_perbaikan'] ?? '-'))) ?></p></div>
                    <?php if (!empty($laporan['catatan'])): ?>
                        <div class="form-group"><p class="form-label">Catatan tambahan</p>
                            <p class="detail-text"><?= nl2br(e((string) $laporan['catatan'])) ?></p></div>
                    <?php endif; ?>
                    <?php if (!empty($laporanFotos)): ?>
                        <div class="form-group"><p class="form-label">Foto hasil perbaikan</p>
                            <div class="foto-grid">
                                <?php foreach ($laporanFotos as $foto): ?>
                                    <a class="foto-item" href="<?= e(url('/laporan/foto/' . (int) $foto['id'])) ?>" target="_blank" rel="noopener">
                                        <img src="<?= e(url('/laporan/foto/' . (int) $foto['id'])) ?>" alt="Foto hasil perbaikan" loading="lazy">
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php elseif (is_array($penyelesaian)): ?>
                    <dl class="detail-list">
                        <div><dt>Diselesaikan oleh</dt><dd>CS <?= e((string) ($penyelesaian['cs_nama'] ?? '-')) ?></dd></div>
                        <div><dt>Selesai</dt><dd><?= e(format_waktu((string) ($penyelesaian['completed_at'] ?? ''))) ?></dd></div>
                    </dl>
                    <div class="form-group"><p class="form-label">Solusi / tindakan</p>
                        <p class="detail-text"><?= nl2br(e((string) ($penyelesaian['solusi'] ?? '-'))) ?></p></div>
                <?php else: ?>
                    <?= component_html('empty-state', ['icon' => 'file', 'title' => 'Belum ada hasil penanganan', 'message' => 'Hasil penanganan akan tampil setelah tiket selesai.', 'compact' => true]) ?>
                <?php endif; ?>
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h3 class="card-title">Riwayat Status</h3></div>
            <div class="card-body">
                <?php if (!empty($riwayat)): ?>
                    <ul class="timeline">
                        <?php $lastIndex = count($riwayat) - 1; foreach ($riwayat as $index => $log): ?>
                            <li class="timeline-item<?= $index === $lastIndex ? ' is-current' : '' ?>">
                                <span class="timeline-dot" aria-hidden="true"></span>
                                <div class="timeline-content">
                                    <?= component_html('badge-status', ['status' => (string) $log['status_baru'], 'dot' => false]) ?>
                                    <p class="timeline-meta"><?= e(format_waktu((string) $log['created_at'])) ?>
                                        <?php if (!empty($log['actor_nama'])): ?> &middot; <?= e((string) $log['actor_nama']) ?><?php endif; ?></p>
                                    <?php if (!empty($log['catatan'])): ?><p class="timeline-text"><?= e((string) $log['catatan']) ?></p><?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="page-subtitle">Belum ada riwayat status.</p>
                <?php endif; ?>
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h3 class="card-title">Penugasan Teknisi</h3></div>
            <div class="card-body">
                <?php if (is_array($penugasanAktif)): ?>
                    <dl class="detail-list">
                        <div><dt>Teknisi aktif</dt><dd><?= e((string) ($penugasanAktif['teknisi_nama'] ?? '-')) ?></dd></div>
                        <div><dt>Ditugaskan</dt><dd><?= e(format_waktu((string) ($penugasanAktif['assigned_at'] ?? ''))) ?></dd></div>
                        <?php if (!empty($penugasanAktif['started_at'])): ?>
                            <div><dt>Mulai ditangani</dt><dd><?= e(format_waktu((string) $penugasanAktif['started_at'])) ?></dd></div>
                        <?php endif; ?>
                    </dl>
                <?php else: ?>
                    <p class="page-subtitle">Belum ada teknisi aktif pada tiket ini.</p>
                <?php endif; ?>
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h3 class="card-title">Catatan Internal</h3></div>
            <div class="card-body">
                <?php if (!empty($catatan)): ?>
                    <div class="note-list">
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
                    <p class="page-subtitle">Belum ada catatan internal.</p>
                <?php endif; ?>
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h3 class="card-title">Pembatalan &amp; Pengarsipan</h3></div>
            <div class="card-body detail-actions">
                <?php if ($isCancelled): ?>
                    <dl class="detail-list">
                        <div><dt>Status</dt><dd><span class="badge-cancel"><?= icon('x') ?>Dibatalkan</span></dd></div>
                        <div><dt>Waktu</dt><dd><?= e(format_waktu((string) ($pengaduan['cancelled_at'] ?? ''))) ?></dd></div>
                        <div><dt>Alasan</dt><dd><?= e((string) ($pengaduan['cancellation_reason'] ?? '-')) ?></dd></div>
                    </dl>
                <?php elseif ($canCancel): ?>
                    <form class="form-stack" method="post" action="<?= e(url('/cs/pengaduan/' . $id . '/batalkan')) ?>"
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
                            <textarea class="form-control" id="alasan-batal" name="alasan" rows="3" required maxlength="2000"></textarea>
                        </div>
                        <button class="btn btn-danger" type="submit"><?= icon('x') ?><span>Batalkan Pengaduan</span></button>
                    </form>
                <?php else: ?>
                    <p class="page-subtitle">Tiket yang sudah selesai atau ditolak tidak dapat dibatalkan.</p>
                <?php endif; ?>

                <hr class="rule">

                <?php if ($isArchived): ?>
                    <p class="page-subtitle">Tiket ini sudah diarsipkan.</p>
                <?php elseif (status_is_active($status) && !$isCancelled): ?>
                    <p class="page-subtitle">Tiket yang masih aktif belum dapat diarsipkan.</p>
                <?php else: ?>
                    <button class="btn btn-outline-light" type="button" data-modal-open="arsip-<?= $id ?>">
                        <?= icon('file') ?><span>Arsipkan Tiket</span>
                    </button>
                    <div class="app-modal-backdrop" data-modal="arsip-<?= $id ?>" hidden>
                        <div class="app-modal" role="dialog" aria-modal="true">
                            <div class="app-modal-head">
                                <h3 class="app-modal-title">Arsipkan Tiket</h3>
                                <button class="app-icon-button" type="button" data-modal-close aria-label="Tutup"><?= icon('x') ?></button>
                            </div>
                            <div class="app-modal-body">
                                <p class="app-modal-message">Arsipkan tiket <strong><?= e($nomorTiket) ?></strong>? Riwayat tiket tetap tersimpan dan dapat dilihat kembali melalui filter arsip.</p>
                            </div>
                            <div class="app-modal-foot">
                                <button class="btn btn-outline-light btn-sm" type="button" data-modal-close>Batal</button>
                                <button class="btn btn-primary btn-sm" type="button" data-modal-submit="arsip-form-<?= $id ?>">Ya, arsipkan</button>
                            </div>
                        </div>
                    </div>
                    <form id="arsip-form-<?= $id ?>" method="post" action="<?= e(url('/admin/pengaduan/' . $id . '/arsipkan')) ?>" hidden>
                        <?= csrf_field() ?>
                    </form>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>
