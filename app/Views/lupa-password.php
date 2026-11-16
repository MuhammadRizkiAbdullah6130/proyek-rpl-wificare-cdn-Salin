<?php
/**
 * Halaman Lupa Password Pelanggan (OTP WhatsApp).
 *
 * Variabel dari PasswordResetController:
 * - $stage, $notice, $error, $success, $info, $old
 * - $ttl_minutes, $resend_cooldown, $max_attempts
 * - $resend_after, $authorization_expires_in
 *
 * Halaman ini tidak pernah menampilkan username, nomor WhatsApp, kode OTP,
 * token reset, atau status keberadaan akun secara spesifik.
 */

declare(strict_types=1);

$stage = $stage ?? 'identify';
$notice = $notice ?? null;
$error = $error ?? null;
$success = $success ?? null;
$info = $info ?? null;
$old = is_array($old ?? null) ? $old : [];
$oldUsername = isset($old['username']) ? (string) $old['username'] : '';
$ttlMinutes = max(1, (int) ($ttl_minutes ?? 5));
$resendCooldown = max(0, (int) ($resend_cooldown ?? 60));
$maxAttempts = max(1, (int) ($max_attempts ?? 5));
$resendAfter = max(0, (int) ($resend_after ?? 0));
$authorizationExpiresIn = max(0, (int) ($authorization_expires_in ?? 0));

$titles = [
    'identify' => ['Lupa Password', 'Masukkan Username PPPoE akun WiFiCare Anda.'],
    'verify' => ['Verifikasi Kode', 'Masukkan kode 6 digit yang dikirim ke WhatsApp terdaftar.'],
    'password' => ['Password Baru', 'Buat password WiFiCare baru untuk akun Anda.'],
];
[$pageTitle, $pageSubtitle] = $titles[$stage] ?? $titles['identify'];

$stepState = static function (int $step) use ($stage): string {
    $current = $stage === 'identify' ? 1 : ($stage === 'verify' ? 2 : 3);
    if ($step < $current) {
        return 'is-done';
    }

    return $step === $current ? 'is-active' : '';
};
?>
<main class="auth-shell auth-shell--reset" id="konten-utama">
    <div class="auth-panel">
        <div class="auth-brand">
            <?php component('brand', ['variant' => 'signal']); ?>
            <span class="auth-brand-name">WiFiCare CDN</span>
            <span class="auth-brand-sub">Sistem Manajemen Pengaduan Pelanggan WiFi</span>
        </div>

        <div class="auth-card">
            <header class="auth-head">
                <h1 class="auth-title"><?= e($pageTitle) ?></h1>
                <p class="auth-subtitle"><?= e($pageSubtitle) ?></p>
            </header>

            <ol class="auth-steps" aria-label="Tahapan lupa password">
                <li class="auth-step <?= e($stepState(1)) ?>" <?= $stage === 'identify' ? 'aria-current="step"' : '' ?>>
                    <span class="auth-step-number">1</span>
                    <span class="auth-step-label">Akun</span>
                </li>
                <li class="auth-step <?= e($stepState(2)) ?>" <?= $stage === 'verify' ? 'aria-current="step"' : '' ?>>
                    <span class="auth-step-number">2</span>
                    <span class="auth-step-label">OTP</span>
                </li>
                <li class="auth-step <?= e($stepState(3)) ?>" <?= $stage === 'password' ? 'aria-current="step"' : '' ?>>
                    <span class="auth-step-number">3</span>
                    <span class="auth-step-label">Password</span>
                </li>
            </ol>

            <?php if (!empty($notice)): ?>
                <div class="callout callout--warning" role="status">
                    <?= icon('info') ?>
                    <p><?= e((string) $notice) ?></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="callout callout--danger" role="alert">
                    <?= icon('alert') ?>
                    <p><?= e((string) $error) ?></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="callout callout--success" role="status">
                    <?= icon('check') ?>
                    <p><?= e((string) $success) ?></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($info)): ?>
                <div class="callout callout--info" role="status">
                    <?= icon('info') ?>
                    <p><?= e((string) $info) ?></p>
                </div>
            <?php endif; ?>

            <?php if ($stage === 'identify'): ?>
                <form method="post" action="<?= e(url('/lupa-password')) ?>" novalidate data-loading>
                    <?= csrf_field() ?>

                    <div class="form-group">
                        <label class="form-label" for="reset-username">Username PPPoE</label>
                        <div class="auth-input">
                            <span class="auth-input-icon" aria-hidden="true"><?= icon('user') ?></span>
                            <input class="form-control" type="text" id="reset-username" name="username"
                                   value="<?= e($oldUsername) ?>" autocomplete="username"
                                   placeholder="Contoh: pelanggan01" maxlength="64" required autofocus>
                        </div>
                        <p class="form-hint">Gunakan username PPPoE yang biasa Anda pakai untuk masuk.</p>
                    </div>

                    <button class="btn btn-primary w-100 auth-submit" type="submit">
                        <span class="auth-submit-label"><?= icon('logout') ?><span>Kirim Kode OTP</span></span>
                        <span class="auth-submit-loading">
                            <span class="auth-spinner" aria-hidden="true"></span><span>Memproses…</span>
                        </span>
                    </button>
                </form>

                <div class="auth-note">
                    <?= icon('info') ?>
                    <p>Kode hanya dikirim bila akun aktif dan nomor WhatsApp-nya sudah terverifikasi.
                        Jika nomor belum terverifikasi atau tidak dapat diakses, hubungi Admin/CS untuk
                        bantuan reset.</p>
                </div>
            <?php elseif ($stage === 'verify'): ?>
                <form method="post" action="<?= e(url('/lupa-password/verifikasi')) ?>" novalidate data-loading>
                    <?= csrf_field() ?>

                    <div class="form-group">
                        <label class="form-label" for="reset-otp">Kode OTP 6 digit</label>
                        <div class="auth-input">
                            <span class="auth-input-icon" aria-hidden="true"><?= icon('lock') ?></span>
                            <input class="form-control auth-otp-input" type="text" id="reset-otp" name="otp"
                                   inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                                   autocomplete="one-time-code" placeholder="000000" required autofocus>
                        </div>
                        <p class="form-hint">Kode berlaku sekitar <?= e((string) $ttlMinutes) ?> menit.
                            Maksimal <?= e((string) $maxAttempts) ?> percobaan.</p>
                    </div>

                    <button class="btn btn-primary w-100 auth-submit" type="submit">
                        <span class="auth-submit-label"><?= icon('check') ?><span>Verifikasi</span></span>
                        <span class="auth-submit-loading">
                            <span class="auth-spinner" aria-hidden="true"></span><span>Memverifikasi…</span>
                        </span>
                    </button>
                </form>

                <div class="auth-resend">
                    <form method="post" action="<?= e(url('/lupa-password/kirim-ulang')) ?>">
                        <?= csrf_field() ?>
                        <button class="btn btn-secondary" type="submit"
                                data-resend-button data-resend-after="<?= e((string) $resendAfter) ?>"
                                <?= $resendAfter > 0 ? 'disabled' : '' ?>>
                            <span data-resend-label>Kirim Ulang Kode</span>
                        </button>
                    </form>
                    <p class="form-hint" data-resend-hint>
                        <?php if ($resendAfter > 0): ?>
                            Tunggu <span data-resend-timer><?= e((string) $resendAfter) ?></span> detik sebelum meminta kode baru.
                        <?php else: ?>
                            Belum menerima kode? Anda dapat meminta kode baru<?= $resendCooldown > 0 ? ' setelah cooldown' : '' ?>.
                        <?php endif; ?>
                    </p>
                </div>

                <form method="post" action="<?= e(url('/lupa-password/batal')) ?>" class="auth-cancel-form">
                    <?= csrf_field() ?>
                    <button class="btn btn-ghost" type="submit">Batalkan dan mulai ulang</button>
                </form>

                <div class="auth-note">
                    <?= icon('info') ?>
                    <p>Jangan bagikan kode ini kepada siapa pun, termasuk yang mengaku petugas.
                        WiFiCare tidak pernah meminta kode OTP melalui telepon atau chat.</p>
                </div>
            <?php else: ?>
                <form method="post" action="<?= e(url('/lupa-password/simpan')) ?>" novalidate data-loading>
                    <?= csrf_field() ?>

                    <div class="form-group">
                        <label class="form-label" for="reset-password">Password baru</label>
                        <div class="auth-input">
                            <span class="auth-input-icon" aria-hidden="true"><?= icon('lock') ?></span>
                            <input class="form-control" type="password" id="reset-password" name="password_baru"
                                   autocomplete="new-password" minlength="8" maxlength="72" required autofocus>
                            <button class="auth-input-toggle" type="button" data-password-toggle="reset-password"
                                    aria-label="Tampilkan kata sandi" aria-pressed="false" aria-controls="reset-password">
                                <?= icon('eye') ?>
                            </button>
                        </div>
                        <p class="form-hint">Minimal 8 karakter, memuat huruf dan angka, maksimal 72 karakter.</p>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="reset-password-confirm">Konfirmasi password baru</label>
                        <div class="auth-input">
                            <span class="auth-input-icon" aria-hidden="true"><?= icon('lock') ?></span>
                            <input class="form-control" type="password" id="reset-password-confirm"
                                   name="password_konfirmasi" autocomplete="new-password"
                                   minlength="8" maxlength="72" required>
                            <button class="auth-input-toggle" type="button"
                                    data-password-toggle="reset-password-confirm"
                                    aria-label="Tampilkan konfirmasi kata sandi" aria-pressed="false"
                                    aria-controls="reset-password-confirm">
                                <?= icon('eye') ?>
                            </button>
                        </div>
                    </div>

                    <button class="btn btn-primary w-100 auth-submit" type="submit">
                        <span class="auth-submit-label"><?= icon('check') ?><span>Simpan Password Baru</span></span>
                        <span class="auth-submit-loading">
                            <span class="auth-spinner" aria-hidden="true"></span><span>Menyimpan…</span>
                        </span>
                    </button>
                </form>

                <div class="auth-resend">
                    <p class="form-hint" data-auth-hint>
                        Otorisasi reset berlaku sekitar
                        <span data-auth-timer data-auth-seconds="<?= e((string) $authorizationExpiresIn) ?>"><?= e((string) max(1, (int) ceil($authorizationExpiresIn / 60))) ?></span>
                        menit. Setelah tersimpan, semua sesi login lama akan berakhir.
                    </p>
                </div>

                <form method="post" action="<?= e(url('/lupa-password/batal')) ?>" class="auth-cancel-form">
                    <?= csrf_field() ?>
                    <button class="btn btn-ghost" type="submit">Batalkan proses</button>
                </form>

                <div class="auth-note">
                    <?= icon('info') ?>
                    <p>Password ini hanya untuk masuk ke aplikasi WiFiCare CDN. Password jaringan PPPoE
                        tidak diubah oleh proses ini.</p>
                </div>
            <?php endif; ?>

            <p class="auth-inline-link">
                <a href="<?= e(url('/login')) ?>">Kembali ke halaman masuk</a>
            </p>
        </div>

        <p class="auth-foot">&copy; <?= e(date('Y')) ?> WiFiCare CDN</p>
    </div>
</main>
<script src="<?= e(asset('js/lupa-password.js')) ?>" defer></script>
