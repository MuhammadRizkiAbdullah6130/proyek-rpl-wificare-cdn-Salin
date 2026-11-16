<?php
/**
 * Pelanggan — Detail Tiket (Tahap 5).
 *
 * Seluruh data berasal dari database: informasi tiket, foto bukti, riwayat
 * status (timeline), penugasan teknisi, dan laporan perbaikan.
 * Catatan internal CS tidak pernah dimuat ke halaman ini.
 */

declare(strict_types=1);
$content = '';

$pengaduan = $pengaduan ?? [];
$fotos = $fotos ?? [];
$riwayat = $riwayat ?? [];
$teknisi = $teknisi ?? null;
$laporan = $laporan ?? null;
$laporanFotos = $laporanFotos ?? [];
$penyelesaian = $penyelesaian ?? null;
$flashSukses = $flash_sukses ?? null;

$status = (string) ($pengaduan['status'] ?? 'menunggu_verifikasi');
$isCancelled = (int) ($pengaduan['is_cancelled'] ?? 0) === 1;
$nomorTiket = (string) ($pengaduan['nomor_tiket'] ?? '-');
?>
<?= component('page-header', [
    'title' => 'Detail Tiket ' . e($nomorTiket),
    'subtitle' => 'Status terkini, riwayat penanganan, dan hasil perbaikan.',
    'actions' => [
        ['label' => 'Kembali', 'url' => url('/pelanggan/pengaduan'), 'icon' => 'arrow-left', 'variant' => 'outline-light'],
    ],
]) ?>

<?php if ($flashSukses !== null): ?>
    <div class="callout callout--success"><?= icon('check') ?><p><?= e($flashSukses) ?></p></div>
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
        </p>
    </div>
<?php endif; ?>

<div class="grid-2 grid-2--detail">
    <section class="card">
        <div class="card-head">
            <h3 class="card-title">Informasi Tiket</h3>
            <span>
                <?= component_html('badge-status', ['status' => $status]) ?>
                <?php if ($isCancelled): ?>
                    <span class="badge-cancel"><?= icon('x') ?>Dibatalkan</span>
                <?php endif; ?>
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
                                    <p class="timeline-meta"><?= e(format_waktu((string) $log['created_at'])) ?></p>
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
                <h3 class="card-title">Teknisi</h3>
            </div>
            <div class="card-body">
                <?php if (is_array($teknisi)): ?>
                    <dl class="detail-list">
                        <div><dt>Nama teknisi</dt><dd><?= e((string) ($teknisi['nama'] ?? '-')) ?></dd></div>
                        <div><dt>Ditugaskan</dt><dd><?= e(format_waktu((string) ($teknisi['assigned_at'] ?? ''))) ?></dd></div>
                    </dl>
                <?php else: ?>
                    <p class="page-subtitle">Belum ada teknisi yang ditugaskan pada tiket ini.</p>
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
                        <div><dt>Diselesaikan oleh</dt><dd>Customer Service</dd></div>
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
    </div>
</div>

<?php if ($status === 'selesai' && !$isCancelled && rating_layanan_enabled()): ?>
    <section class="card">
        <div class="card-head">
            <h3 class="card-title">Rating Layanan</h3>
        </div>
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
                <p class="timeline-meta">Dinilai <?= e(format_waktu((string) ($rating['created_at'] ?? ''))) ?></p>
            <?php else: ?>
                <p class="page-subtitle">Bagaimana penanganan tiket ini? Beri penilaian Anda (sekali per tiket).</p>
                <form class="form-stack" method="post" action="<?= e(url('/pelanggan/pengaduan/' . (int) ($pengaduan['id'] ?? 0) . '/rating')) ?>">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <p class="form-label">Penilaian Anda</p>
                        <div class="rating-stars" data-rating data-value="0" role="radiogroup" aria-label="Pilih rating 1 sampai 5">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <button type="button" class="rating-star" data-rating-value="<?= $i ?>" aria-checked="false" aria-label="<?= $i ?> bintang">
                                    <?= icon('star') ?>
                                </button>
                            <?php endfor; ?>
                            <input type="hidden" name="rating" value="0">
                        </div>
                        <p class="form-hint" data-rating-text>Pilih jumlah bintang (1–5).</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="ulasan-detail">Ulasan (opsional)</label>
                        <textarea class="form-control" id="ulasan-detail" name="ulasan" rows="3" maxlength="1000" placeholder="Ceritakan pengalaman Anda"></textarea>
                    </div>
                    <div class="form-actions">
                        <button class="btn btn-primary" type="submit"><?= icon('star') ?><span>Kirim Rating</span></button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>
