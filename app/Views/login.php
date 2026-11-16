<?php
/**
 * Halaman login WiFiCare CDN (layout autentikasi full-screen).
 *
 * Form nyata: mengirim POST ke /login dengan token CSRF.
 * Pelanggan menggunakan Username PPPoE + password akun WiFiCare
 * (bukan password jaringan PPPoE). Tidak ada registrasi publik.
 *
 * Variabel:
 * - $error   : pesan error login (flash).
 * - $success : pesan sukses (flash).
 * - $old     : input lama (username) untuk dipertahankan.
 */

declare(strict_types=1);

$error = $error ?? null;
$success = $success ?? null;
$old = $old ?? [];
$oldUsername = is_array($old) ? (string) ($old['username'] ?? '') : '';
?>
<main class="auth-shell" id="konten-utama">
    <div class="auth-panel">
        <div class="auth-brand">
            <?php component('brand', ['variant' => 'signal']); ?>
            <span class="auth-brand-name">WiFiCare CDN</span>
            <span class="auth-brand-sub">Sistem Manajemen Pengaduan Pelanggan WiFi</span>
        </div>

        <div class="auth-card">
            <header class="auth-head">
                <h1 class="auth-title">Selamat Datang</h1>
                <p class="auth-subtitle">Masuk ke akun WiFiCare CDN Anda</p>
            </header>

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

            <form method="post" action="<?= e(url('/login')) ?>" novalidate data-loading>
                <?= csrf_field() ?>

                <div class="form-group">
                    <label class="form-label" for="login-username">Username / PPPoE</label>
                    <div class="auth-input">
                        <span class="auth-input-icon" aria-hidden="true"><?= icon('user') ?></span>
                        <input class="form-control" type="text" id="login-username" name="username"
                               value="<?= e($oldUsername) ?>" autocomplete="username"
                               placeholder="Petugas: username · Pelanggan: username PPPoE"
                               maxlength="64" required autofocus>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="login-password">Password WiFiCare</label>
                    <div class="auth-input">
                        <span class="auth-input-icon" aria-hidden="true"><?= icon('lock') ?></span>
                        <input class="form-control" type="password" id="login-password" name="password"
                               autocomplete="current-password" maxlength="200" required>
                        <button class="auth-input-toggle" type="button" data-password-toggle="login-password"
                                aria-label="Tampilkan kata sandi" aria-pressed="false" aria-controls="login-password">
                            <?= icon('eye') ?>
                        </button>
                    </div>
                    <p class="form-hint">Gunakan password WiFiCare — bukan password jaringan PPPoE.</p>
                </div>

                <button class="btn btn-primary w-100 auth-submit" type="submit">
                    <span class="auth-submit-label"><?= icon('logout') ?><span>Masuk</span></span>
                    <span class="auth-submit-loading">
                        <span class="auth-spinner" aria-hidden="true"></span><span>Memproses…</span>
                    </span>
                </button>
            </form>

            <p class="auth-inline-link">
                <a href="<?= e(url('/lupa-password')) ?>">Lupa Password?</a>
            </p>

            <div class="auth-note">
                <?= icon('info') ?>
                <p>Akun WiFiCare dibuat oleh Administrator. Belum punya akun? Hubungi Administrator
                    untuk pengajuan akun — pendaftaran mandiri tidak tersedia.</p>
            </div>
        </div>

        <p class="auth-foot">&copy; <?= e(date('Y')) ?> WiFiCare CDN</p>
    </div>
</main>
