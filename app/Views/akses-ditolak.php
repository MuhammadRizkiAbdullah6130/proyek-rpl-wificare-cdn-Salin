<?php
/**
 * Halaman akses ditolak (403).
 */

declare(strict_types=1);
$content = '';

$loggedIn = function_exists('auth_check') && auth_check();
$homeUrl = $loggedIn ? Auth::dashboardUrl((string) auth_role()) : url('/login');
$homeLabel = $loggedIn ? 'Kembali ke Dashboard' : 'Ke Halaman Masuk';
$homeIcon = $loggedIn ? 'dashboard' : 'logout';
?>
<section class="section">
    <div class="container py-5 state-wrap">
        <div class="card state-card">
            <div class="card-body">
                <span class="state-code">403</span>
                <span class="state-icon"><?= icon('lock') ?></span>
                <h1 class="h4 card-title">Akses Ditolak</h1>
                <p class="page-subtitle">
                    Halaman ini tidak dapat dibuka oleh peran Anda. Bila Anda merasa ini sebuah kesalahan,
                    hubungi Administrator untuk memeriksa hak akses akun.
                </p>
                <div class="state-actions">
                    <a class="btn btn-primary" href="<?= e($homeUrl) ?>"><?= icon($homeIcon) ?><span><?= e($homeLabel) ?></span></a>
                    <button class="btn btn-outline-light" type="button" onclick="history.back()">
                        <?= icon('arrow-left') ?><span>Halaman Sebelumnya</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</section>
