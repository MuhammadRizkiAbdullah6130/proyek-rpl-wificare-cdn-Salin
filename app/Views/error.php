<?php
/**
 * Halaman error (404 dan error server lain).
 *
 * Variabel:
 * - $title  : Judul halaman.
 * - $message: Pesan error.
 * - $status : Kode HTTP (0 bila tidak relevan).
 */

declare(strict_types=1);

$content = '';
$status = $status ?? 0;
$message = $message ?? 'Terjadi kesalahan pada server.';
$code = $status > 0 ? (string) $status : '—';

// Tujuan tombol kembali menyesuaikan status login (tanpa loop redirect).
$loggedIn = function_exists('auth_check') && auth_check();
$homeUrl = $loggedIn ? Auth::dashboardUrl((string) auth_role()) : url('/login');
$homeLabel = $loggedIn ? 'Kembali ke Dashboard' : 'Ke Halaman Masuk';
?>
<section class="section">
    <div class="container py-5 state-wrap">
        <div class="card state-card">
            <div class="card-body">
                <span class="state-code"><?= e($code) ?></span>
                <span class="state-icon"><?= icon('alert') ?></span>
                <h1 class="h4 card-title"><?= e($title ?? 'Terjadi Kesalahan') ?></h1>
                <p class="page-subtitle" role="alert"><?= e($message) ?></p>
                <div class="state-actions">
                    <a class="btn btn-primary" href="<?= e($homeUrl) ?>"><?= icon($loggedIn ? 'dashboard' : 'lock') ?><span><?= e($homeLabel) ?></span></a>
                    <button class="btn btn-outline-light" type="button" onclick="history.back()">
                        <?= icon('arrow-left') ?><span>Halaman Sebelumnya</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</section>
