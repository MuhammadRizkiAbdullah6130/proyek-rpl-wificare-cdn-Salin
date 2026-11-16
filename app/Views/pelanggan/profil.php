<?php
/**
 * Pelanggan — Profil.
 *
 * Data identitas diambil dari akun yang sedang masuk (session -> users ->
 * pelanggan). Tidak ada data contoh/demo. Pelanggan hanya dapat mengubah
 * nama melalui formulir ini; nomor WhatsApp dikunci dan perubahan nomor
 * wajib melalui alur verifikasi OTP.
 */

declare(strict_types=1);
$content = '';

$profil = $profil ?? [
    'nama' => '',
    'username_pppoe' => '',
    'nomor_whatsapp' => '',
    'alamat' => '',
];
$flashSukses = $flash_sukses ?? null;
$flashError = $flash_error ?? null;

$verifikasi = $whatsapp_verification ?? [];
$sudahTerverifikasi = !empty($verifikasi['verified']);
$otpSiap = !empty($verifikasi['ready']);
$otpAktif = !empty($verifikasi['has_active_code']);
$otpAutoOpen = !empty($verifikasi['auto_open']);
$otpRetryAfter = max(0, (int) ($verifikasi['resend_after'] ?? 0));
$otpExpiresIn = max(0, (int) ($verifikasi['code_expires_in'] ?? 0));
$otpTtlMenit = max(1, (int) ($verifikasi['ttl_minutes'] ?? 5));
$otpMaxAttempts = max(1, (int) ($verifikasi['max_attempts'] ?? 5));
$nomorTersamar = (string) ($verifikasi['masked_phone'] ?? '—');
$waktuVerifikasi = $verifikasi['verified_at'] ?? null;
$otpRetryText = $otpRetryAfter > 0
    ? 'Kirim ulang tersedia dalam ' . $otpRetryAfter . ' detik.'
    : 'Anda dapat meminta kode baru.';
$otpExpiryText = $otpAktif
    ? 'Kode aktif. Sisa waktu ' . $otpExpiresIn . ' detik.'
    : 'Belum ada kode aktif.';

$ganti = $whatsapp_change ?? [];
$gantiAktif = !empty($ganti['pending']);
$gantiSiap = !empty($ganti['ready']);
$gantiAutoOpen = !empty($ganti['auto_open']);
$gantiNomorTersamar = (string) ($ganti['new_phone_masked'] ?? '—');
$gantiRetryAfter = max(0, (int) ($ganti['resend_after'] ?? 0));
$gantiExpiresIn = max(0, (int) ($ganti['code_expires_in'] ?? 0));
$gantiTtlMenit = max(1, (int) ($ganti['ttl_minutes'] ?? 5));
$gantiMaxAttempts = max(1, (int) ($ganti['max_attempts'] ?? 5));
$gantiProviderLabel = (string) ($ganti['provider_label'] ?? '');
$gantiOldInput = is_array($ganti_nomor_old ?? null)
    ? (string) ($ganti_nomor_old['nomor_whatsapp_baru'] ?? '')
    : '';
$gantiRetryText = $gantiRetryAfter > 0
    ? 'Kirim ulang tersedia dalam ' . $gantiRetryAfter . ' detik.'
    : 'Anda dapat meminta kode baru.';
$gantiExpiryText = $gantiAktif
    ? 'Kode aktif. Sisa waktu ' . $gantiExpiresIn . ' detik.'
    : 'Belum ada kode aktif.';
?>
<?= component('page-header', [
    'title' => 'Profil Saya',
    'subtitle' => 'Kelola data yang boleh Anda ubah sendiri.',
]) ?>

<?php if ($flashSukses !== null): ?>
    <div class="callout callout--success"><?= icon('check') ?><p><?= e($flashSukses) ?></p></div>
<?php endif; ?>
<?php if ($flashError !== null): ?>
    <div class="callout callout--danger"><?= icon('alert') ?><p><?= e($flashError) ?></p></div>
<?php endif; ?>

<div class="grid-2 grid-2--detail">
    <div class="stack">
        <section class="card">
            <div class="card-head">
                <h3 class="card-title">Data Akun</h3>
            </div>
            <div class="card-body">
                <form class="form-stack" method="post" action="<?= e(url('/pelanggan/profil')) ?>" data-loading novalidate>
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label class="form-label" for="profil-nama">Nama lengkap <span class="form-required">*</span></label>
                        <input class="form-control" type="text" id="profil-nama" name="nama"
                               value="<?= e((string) $profil['nama']) ?>" maxlength="120" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="profil-pppoe">Username PPPoE</label>
                        <input class="form-control" type="text" id="profil-pppoe" value="<?= e((string) $profil['username_pppoe']) ?>" readonly>
                        <p class="form-hint">Username PPPoE hanya dapat diubah oleh Administrator.</p>
                    </div>

                    <div class="profil-whatsapp-field">
                        <div class="form-group">
                            <label class="form-label" for="profil-whatsapp">Nomor WhatsApp</label>
                            <input class="form-control" type="tel" id="profil-whatsapp"
                                   inputmode="tel" autocomplete="tel"
                                   value="<?= e((string) $profil['nomor_whatsapp']) ?>" readonly>
                            <p class="form-hint">Nomor ini dipakai untuk menghubungi Anda terkait pengaduan. Perubahan nomor wajib melalui verifikasi OTP ke nomor baru.</p>
                        </div>
                        <button class="btn btn-outline-light profil-whatsapp-change-btn" type="button"
                                data-modal-open="profilGantiNomorModal">
                            <?= icon('refresh') ?><span>Ganti Nomor WhatsApp</span>
                        </button>
                    </div>

                    <div class="form-actions">
                        <button class="btn btn-primary" type="submit">
                            <?= icon('check') ?><span>Simpan Perubahan</span>
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <section class="card profil-verification-card"
                 data-otp-panel
                 data-otp-auto-open="<?= $otpAutoOpen ? '1' : '0' ?>"
                 data-otp-ready="<?= $otpSiap ? '1' : '0' ?>"
                 data-otp-has-active="<?= $otpAktif ? '1' : '0' ?>"
                 data-otp-retry-after="<?= $otpRetryAfter ?>"
                 data-otp-expires-in="<?= $otpExpiresIn ?>">
            <div class="card-head profil-verification-head">
                <div>
                    <h3 class="card-title">Verifikasi Nomor WhatsApp</h3>
                    <p class="form-hint">Buktikan bahwa nomor utama ini benar-benar milik Anda.</p>
                </div>
                <span class="status-badge <?= $sudahTerverifikasi ? 'status-selesai' : 'status-menunggu-verifikasi' ?>">
                    <span class="status-dot" aria-hidden="true"></span>
                    <?= $sudahTerverifikasi ? 'Terverifikasi' : 'Belum Terverifikasi' ?>
                </span>
            </div>
            <div class="card-body">
                <div class="profil-verification-phone">
                    <span class="profil-verification-icon" aria-hidden="true"><?= icon('phone') ?></span>
                    <div>
                        <span class="profil-verification-label">Nomor WhatsApp utama</span>
                        <strong><?= e($nomorTersamar) ?></strong>
                    </div>
                </div>

                <?php if ($sudahTerverifikasi): ?>
                    <div class="callout callout--success profil-verification-callout">
                        <?= icon('check') ?>
                        <p>Nomor ini sudah terverifikasi<?= $waktuVerifikasi !== null ? ' pada ' . e(format_waktu((string) $waktuVerifikasi)) : '' ?>. Nomor utama tetap tidak berubah.</p>
                    </div>
                <?php else: ?>
                    <div class="profil-verification-meta">
                        <span><?= icon('clock') ?> Kode berlaku <?= $otpTtlMenit ?> menit.</span>
                        <span><?= icon('shield') ?> Maksimal <?= $otpMaxAttempts ?> percobaan.</span>
                        <?php if ((string) ($verifikasi['provider_label'] ?? '') !== ''): ?>
                            <span><?= icon('chat') ?> Dikirim via <?= e((string) $verifikasi['provider_label']) ?>.</span>
                        <?php endif; ?>
                    </div>

                    <p class="profil-otp-expiry" data-otp-expiry aria-live="polite"><?= e($otpExpiryText) ?></p>

                    <?php if (!$otpSiap): ?>
                        <div class="callout callout--warning profil-verification-callout">
                            <?= icon('alert') ?>
                            <p>Verifikasi OTP belum siap. Hubungi Administrator untuk mengaktifkan gateway dan konfigurasi pengiriman OTP.</p>
                        </div>
                    <?php endif; ?>

                    <div class="profil-verification-actions">
                        <form id="profilOtpRequestForm" class="profil-otp-send-form" method="post"
                              action="<?= e(url('/pelanggan/profil/whatsapp/otp')) ?>" data-loading>
                            <?= csrf_field() ?>
                            <button class="btn btn-primary" type="submit" data-otp-send <?= $otpSiap ? '' : 'disabled' ?>>
                                <?= icon($otpAktif ? 'refresh' : 'phone') ?>
                                <span data-otp-send-label><?= $otpAktif ? 'Kirim Ulang Kode OTP' : 'Kirim Kode OTP' ?></span>
                            </button>
                        </form>

                        <?php if ($otpAktif): ?>
                            <button class="btn btn-outline-light" type="button" data-modal-open="profilOtpModal">
                                <?= icon('lock') ?><span>Masukkan Kode OTP</span>
                            </button>
                        <?php endif; ?>
                    </div>

                    <p class="form-hint profil-otp-cooldown" data-otp-cooldown aria-live="polite"><?= e($otpRetryText) ?></p>

                    <button type="button" hidden
                            data-modal-open="profilOtpModal"
                            data-otp-auto-trigger>Buka verifikasi OTP</button>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <div class="stack">
        <section class="card">
            <div class="card-head">
                <h3 class="card-title">Ganti Password WiFiCare</h3>
            </div>
            <div class="card-body">
                <form class="form-stack" method="post" action="<?= e(url('/pelanggan/profil/password')) ?>" data-loading novalidate>
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label class="form-label" for="profil-pass-lama">Password saat ini</label>
                        <input class="form-control" type="password" id="profil-pass-lama" name="password_lama" autocomplete="current-password" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="profil-pass-baru">Password baru</label>
                        <input class="form-control" type="password" id="profil-pass-baru" name="password_baru" autocomplete="new-password" required>
                        <p class="form-hint">Minimal 8 karakter, sertakan huruf dan angka.</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="profil-pass-konfirmasi">Konfirmasi password baru</label>
                        <input class="form-control" type="password" id="profil-pass-konfirmasi" name="password_konfirmasi" autocomplete="new-password" required>
                    </div>
                    <div class="form-actions">
                        <button class="btn btn-outline-light" type="submit">
                            <?= icon('lock') ?><span>Perbarui Password</span>
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <section class="card">
            <div class="card-head">
                <h3 class="card-title">Catatan Keamanan</h3>
            </div>
            <div class="card-body">
                <div class="callout callout--info">
                    <?= icon('shield') ?>
                    <p>Password WiFiCare berbeda dari password jaringan PPPoE. Jangan pernah memberikan password PPPoE kepada siapa pun.</p>
                </div>
            </div>
        </section>
    </div>
</div>

<?php if (!$sudahTerverifikasi): ?>
    <div class="app-modal-backdrop" data-modal="profilOtpModal" hidden>
        <div class="app-modal profil-otp-modal" role="dialog" aria-modal="true"
             aria-labelledby="profilOtpModalTitle" aria-describedby="profilOtpModalDesc">
            <button class="app-modal-close" type="button" data-modal-close aria-label="Tutup dialog">
                <?= icon('x') ?>
            </button>

            <div class="app-modal-body">
                <div class="profil-otp-modal-head">
                    <span class="profil-otp-modal-icon" aria-hidden="true"><?= icon('shield') ?></span>
                    <div>
                        <h3 class="app-modal-title" id="profilOtpModalTitle">Masukkan Kode OTP</h3>
                        <p class="app-modal-message" id="profilOtpModalDesc">
                            Kode 6 digit dikirim ke WhatsApp <strong><?= e($nomorTersamar) ?></strong>.
                        </p>
                    </div>
                </div>

                <p class="profil-otp-expiry" data-otp-expiry aria-live="polite"><?= e($otpExpiryText) ?></p>

                <form class="form-stack" method="post"
                      action="<?= e(url('/pelanggan/profil/whatsapp/verifikasi')) ?>"
                      data-loading data-otp-verify-form>
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label class="form-label" for="profil-otp-kode">Kode OTP</label>
                        <input class="form-control profil-otp-input" type="text"
                               id="profil-otp-kode" name="kode"
                               inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                               autocomplete="one-time-code" placeholder="000000"
                               required data-otp-code data-modal-autofocus
                               <?= $otpAktif ? '' : 'disabled' ?>>
                        <p class="form-hint">Kode hanya dapat digunakan sekali dan tidak boleh dibagikan kepada siapa pun.</p>
                    </div>

                    <button class="btn btn-primary" type="submit" data-otp-verify <?= $otpAktif ? '' : 'disabled' ?>>
                        <?= icon('check') ?><span>Verifikasi</span>
                    </button>
                </form>

                <div class="profil-otp-resend">
                    <div>
                        <strong>Belum menerima kode?</strong>
                        <p class="form-hint" data-otp-cooldown aria-live="polite"><?= e($otpRetryText) ?></p>
                    </div>
                    <button class="btn btn-outline-light" type="submit"
                            form="profilOtpRequestForm" data-otp-resend
                            <?= $otpSiap ? '' : 'disabled' ?>>
                        <?= icon('refresh') ?>
                        <span data-otp-resend-label><?= $otpAktif ? 'Kirim Ulang' : 'Kirim Kode' ?></span>
                    </button>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="app-modal-backdrop" data-modal="profilGantiNomorModal" hidden>
    <div class="app-modal profil-otp-modal profil-change-modal" role="dialog" aria-modal="true"
         aria-labelledby="profilGantiNomorModalTitle" aria-describedby="profilGantiNomorModalDesc">
        <button class="app-modal-close" type="button" data-modal-close aria-label="Tutup dialog">
            <?= icon('x') ?>
        </button>

        <div class="app-modal-body"
             data-change-panel
             data-change-auto-open="<?= $gantiAutoOpen ? '1' : '0' ?>"
             data-change-pending="<?= $gantiAktif ? '1' : '0' ?>"
             data-change-ready="<?= $gantiSiap ? '1' : '0' ?>"
             data-change-has-active="<?= $gantiAktif ? '1' : '0' ?>"
             data-change-retry-after="<?= $gantiRetryAfter ?>"
             data-change-expires-in="<?= $gantiExpiresIn ?>">
            <div class="profil-otp-modal-head">
                <span class="profil-otp-modal-icon" aria-hidden="true"><?= icon('refresh') ?></span>
                <div>
                    <h3 class="app-modal-title" id="profilGantiNomorModalTitle">Ganti Nomor WhatsApp</h3>
                    <p class="app-modal-message" id="profilGantiNomorModalDesc">
                        Verifikasi password dan nomor baru sebelum nomor utama diganti.
                    </p>
                </div>
            </div>

            <div class="profil-verification-phone profil-change-current">
                <span class="profil-verification-icon" aria-hidden="true"><?= icon('phone') ?></span>
                <div>
                    <span class="profil-verification-label">Nomor WhatsApp saat ini</span>
                    <strong><?= e($nomorTersamar) ?></strong>
                </div>
            </div>

            <div class="callout callout--info profil-verification-callout">
                <?= icon('shield') ?>
                <p>Nomor lama tetap aktif dan tidak berubah sampai kode OTP ke nomor baru berhasil diverifikasi.</p>
            </div>

            <?php if ($gantiAktif): ?>
                <div class="profil-verification-phone profil-change-target">
                    <span class="profil-verification-icon" aria-hidden="true"><?= icon('phone') ?></span>
                    <div>
                        <span class="profil-verification-label">Nomor baru menunggu verifikasi</span>
                        <strong><?= e($gantiNomorTersamar) ?></strong>
                    </div>
                </div>

                <div class="profil-verification-meta">
                    <span><?= icon('clock') ?> Kode berlaku <?= $gantiTtlMenit ?> menit.</span>
                    <span><?= icon('shield') ?> Maksimal <?= $gantiMaxAttempts ?> percobaan.</span>
                    <?php if ($gantiProviderLabel !== ''): ?>
                        <span><?= icon('chat') ?> Dikirim via <?= e($gantiProviderLabel) ?>.</span>
                    <?php endif; ?>
                </div>

                <p class="profil-otp-expiry" data-change-expiry aria-live="polite"><?= e($gantiExpiryText) ?></p>

                <form class="form-stack" method="post"
                      action="<?= e(url('/pelanggan/profil/whatsapp/ganti/verifikasi')) ?>"
                      data-loading data-change-verify-form>
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label class="form-label" for="profil-ganti-kode">Kode OTP nomor baru</label>
                        <input class="form-control profil-otp-input" type="text"
                               id="profil-ganti-kode" name="kode"
                               inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                               autocomplete="one-time-code" placeholder="000000"
                               required data-change-code data-modal-autofocus>
                        <p class="form-hint">Kode hanya dapat digunakan sekali. Nomor lama tetap aktif bila kode salah.</p>
                    </div>

                    <button class="btn btn-primary" type="submit" data-change-verify>
                        <?= icon('check') ?><span>Verifikasi dan Simpan</span>
                    </button>
                </form>

                <div class="profil-otp-resend">
                    <div>
                        <strong>Belum menerima kode?</strong>
                        <p class="form-hint" data-change-cooldown aria-live="polite"><?= e($gantiRetryText) ?></p>
                    </div>
                    <form id="profilGantiNomorResendForm" method="post"
                          action="<?= e(url('/pelanggan/profil/whatsapp/ganti/kirim-ulang')) ?>" data-loading>
                        <?= csrf_field() ?>
                    </form>
                    <button class="btn btn-outline-light" type="submit"
                            form="profilGantiNomorResendForm" data-change-resend
                            <?= $gantiSiap ? '' : 'disabled' ?>>
                        <?= icon('refresh') ?><span data-change-resend-label>Kirim Ulang</span>
                    </button>
                </div>

                <form class="profil-change-cancel" method="post"
                      action="<?= e(url('/pelanggan/profil/whatsapp/ganti/batal')) ?>" data-loading>
                    <?= csrf_field() ?>
                    <button class="btn btn-outline-light" type="submit">
                        <?= icon('x') ?><span>Batalkan Permintaan</span>
                    </button>
                </form>
            <?php else: ?>
                <?php if (!$gantiSiap): ?>
                    <div class="callout callout--warning profil-verification-callout">
                        <?= icon('alert') ?>
                        <p>Ganti nomor belum siap. Hubungi Administrator untuk mengaktifkan gateway, OTP, dan jenis penggunaan verifikasi nomor.</p>
                    </div>
                <?php endif; ?>

                <form class="form-stack" method="post"
                      action="<?= e(url('/pelanggan/profil/whatsapp/ganti')) ?>"
                      data-loading novalidate>
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label class="form-label" for="profil-ganti-password">Password WiFiCare saat ini</label>
                        <input class="form-control" type="password" id="profil-ganti-password"
                               name="password" autocomplete="current-password" required>
                        <p class="form-hint">Password ini hanya untuk mengonfirmasi permintaan. Password PPPoE tidak berubah.</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="profil-ganti-nomor-baru">Nomor WhatsApp baru</label>
                        <input class="form-control" type="tel" id="profil-ganti-nomor-baru"
                               name="nomor_whatsapp_baru" inputmode="tel" autocomplete="tel"
                               value="<?= e($gantiOldInput) ?>" placeholder="08xxxxxxxxxx" required>
                        <p class="form-hint">Gunakan nomor yang aktif menerima WhatsApp. Nomor baru baru disimpan setelah OTP diverifikasi.</p>
                    </div>
                    <button class="btn btn-primary" type="submit" data-change-send <?= $gantiSiap ? '' : 'disabled' ?>>
                        <?= icon('phone') ?><span>Kirim OTP ke Nomor Baru</span>
                    </button>
                </form>
            <?php endif; ?>

            <button type="button" hidden
                    data-modal-open="profilGantiNomorModal"
                    data-change-auto-trigger>Buka ganti nomor</button>
        </div>
    </div>
</div>

<script src="<?= e(asset('js/pelanggan-profil-otp.js')) ?>" defer></script>
<script src="<?= e(asset('js/pelanggan-profil-ganti-nomor.js')) ?>" defer></script>
