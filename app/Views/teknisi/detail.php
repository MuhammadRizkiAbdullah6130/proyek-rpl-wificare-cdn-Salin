<?php
/**
 * Teknisi — Detail Tugas (Tahap 7).
 *
 * Menampilkan identitas pelanggan, gangguan, foto bukti (via endpoint
 * berotorisasi), instruksi penugasan, timeline status yang boleh dilihat
 * teknisi, dan hasil laporan perbaikan bila sudah ada.
 *
 * Catatan internal CS TIDAK dimuat ke halaman ini.
 */

declare(strict_types=1);
$content = '';

$pengaduan = $pengaduan ?? [];
$penugasanAktif = $penugasanAktif ?? null;
$fotos = $fotos ?? [];
$riwayat = $riwayat ?? [];
$laporan = $laporan ?? null;
$laporanFotos = $laporanFotos ?? [];
$allowedTargets = $teknisiAllowedTargets ?? [];
$flashSukses = $flash_sukses ?? null;
$flashError = $flash_error ?? null;

$id = (int) ($pengaduan['id'] ?? 0);
$status = (string) ($pengaduan['status'] ?? 'menunggu_teknisi');
$nomorTiket = (string) ($pengaduan['nomor_tiket'] ?? '-');
$nama = (string) ($pengaduan['nama_pelanggan_snapshot'] ?? '-');
$wa = (string) ($pengaduan['nomor_whatsapp_snapshot'] ?? '');

$can = static fn (string $target): bool => in_array($target, $allowedTargets, true);

$waLink = whatsapp_link(
    $wa,
    'Halo ' . $nama . ', saya teknisi WiFiCare CDN yang menangani gangguan Anda pada tiket ' . $nomorTiket
        . '. Mohon informasi waktu yang tepat untuk pengecekan.'
);
?>
<?= component('page-header', [
    'title' => 'Detail Tugas ' . e($nomorTiket),
    'subtitle' => 'Informasi pelanggan, gangguan, dan bukti foto.',
    'actions' => [
        ['label' => 'Kembali', 'url' => url('/teknisi/tugas'), 'icon' => 'arrow-left', 'variant' => 'outline-light'],
    ],
]) ?>

<?php if ($flashSukses !== null): ?>
    <div class="callout callout--success"><?= icon('check') ?><p><?= e($flashSukses) ?></p></div>
<?php endif; ?>
<?php if ($flashError !== null): ?>
    <div class="callout callout--danger"><?= icon('alert') ?><p><?= e($flashError) ?></p></div>
<?php endif; ?>

<div class="grid-2 grid-2--detail">
    <section class="card">
        <div class="card-head">
            <h3 class="card-title">Informasi Tugas</h3>
            <span class="status-list-label"><?= status_badge_html($status) ?></span>
        </div>
        <div class="card-body">
            <dl class="detail-list">
                <div><dt>Nomor tiket</dt><dd><?= e($nomorTiket) ?></dd></div>
                <div><dt>Nama pelanggan</dt><dd><?= e($nama) ?></dd></div>
                <div><dt>Username PPPoE</dt><dd><?= e((string) ($pengaduan['username_pppoe_snapshot'] ?? '-')) ?></dd></div>
                <div><dt>Nomor WhatsApp</dt><dd><?= e($wa !== '' ? $wa : '—') ?></dd></div>
                <div><dt>Alamat</dt><dd><?= e((string) ($pengaduan['alamat'] ?? '') !== '' ? (string) $pengaduan['alamat'] : '—') ?></dd></div>
                <div><dt>Kategori gangguan</dt><dd><?= e((string) ($pengaduan['kategori'] ?? 'Tanpa kategori')) ?></dd></div>
                <div><dt>Tanggal pengaduan</dt><dd><?= e(format_waktu((string) ($pengaduan['created_at'] ?? ''))) ?></dd></div>
            </dl>

            <div class="form-group">
                <?php if ($waLink !== null): ?>
                    <a class="btn btn-success" href="<?= e($waLink) ?>" target="_blank" rel="noopener">
                        <?= icon('phone') ?><span>Hubungi Pelanggan (WhatsApp)</span>
                    </a>
                <?php else: ?>
                    <div class="callout callout--warning" style="margin:0;">
                        <?= icon('alert') ?>
                        <p>Nomor WhatsApp pelanggan tidak valid atau tidak tersedia, sehingga tautan tidak dapat dibuat.</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <p class="form-label">Deskripsi gangguan</p>
                <p class="detail-text"><?= nl2br(e((string) ($pengaduan['deskripsi'] ?? '-'))) ?></p>
            </div>

            <div class="form-group">
                <p class="form-label">Foto bukti dari pelanggan</p>
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
                <h3 class="card-title">Tindakan Teknisi</h3>
            </div>
            <div class="card-body detail-actions">
                <?php if ($status === 'menunggu_teknisi' && $can('dalam_penanganan')): ?>
                    <form class="form-stack" method="post" action="<?= e(url('/teknisi/tugas/' . $id . '/mulai')) ?>"
                          data-confirm
                          data-confirm-title="Mulai penanganan tiket ini?"
                          data-confirm-message="Status tiket akan berubah menjadi Dalam Penanganan setelah Anda mengonfirmasi."
                          data-confirm-label="Ya, mulai penanganan"
                          data-confirm-variant="primary"
                          data-confirm-icon="tool"
                          data-confirm-loading="Memulai penanganan...">
                        <?= csrf_field() ?>
                        <button class="btn btn-primary" type="submit"><?= icon('tool') ?><span>Mulai Penanganan</span></button>
                    </form>
                <?php elseif ($status === 'dalam_penanganan'): ?>
                    <a class="btn btn-primary" href="<?= e(url('/teknisi/tugas/' . $id . '/laporan')) ?>">
                        <?= icon('upload') ?><span>Isi Laporan Perbaikan</span>
                    </a>
                <?php elseif ($status === 'selesai'): ?>
                    <div class="callout callout--success" style="margin:0;">
                        <?= icon('check') ?><p>Tugas telah selesai. Laporan perbaikan ada di bawah.</p>
                    </div>
                <?php else: ?>
                    <div class="callout callout--info" style="margin:0;">
                        <?= icon('info') ?><p>Tidak ada tindakan yang tersedia untuk status tiket saat ini.</p>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="card">
            <div class="card-head">
                <h3 class="card-title">Instruksi Penugasan</h3>
            </div>
            <div class="card-body">
                <?php if (is_array($penugasanAktif)): ?>
                    <dl class="detail-list">
                        <div><dt>Ditugaskan</dt><dd><?= e(format_waktu((string) ($penugasanAktif['assigned_at'] ?? ''))) ?></dd></div>
                        <div><dt>Mulai penanganan</dt><dd><?= e(format_waktu((string) ($penugasanAktif['started_at'] ?? ''))) ?></dd></div>
                    </dl>
                    <?php if (!empty($penugasanAktif['catatan'])): ?>
                        <div class="form-group">
                            <p class="form-label">Instruksi dari CS</p>
                            <p class="detail-text"><?= nl2br(e((string) $penugasanAktif['catatan'])) ?></p>
                        </div>
                    <?php else: ?>
                        <p class="page-subtitle">Tidak ada instruksi khusus dari CS.</p>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="page-subtitle">Data penugasan tidak tersedia.</p>
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
                <h3 class="card-title">Hasil Laporan Perbaikan</h3>
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

                    <div class="form-group">
                        <p class="form-label">Foto hasil perbaikan</p>
                        <?php if (!empty($laporanFotos)): ?>
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
                        <?php else: ?>
                            <p class="page-subtitle">Tidak ada foto hasil perbaikan tersimpan.</p>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <?= component_html('empty-state', [
                        'icon' => 'file',
                        'title' => 'Belum ada laporan',
                        'message' => 'Laporan hasil perbaikan akan tampil setelah tugas diselesaikan.',
                        'compact' => true,
                    ]) ?>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>
