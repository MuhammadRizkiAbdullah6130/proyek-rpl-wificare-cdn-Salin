<?php
/**
 * Layout aplikasi WiFiCare CDN (area per peran).
 *
 * Variabel yang diharapkan (dikirim oleh controller melalui render_view):
 * - $title        : Judul dokumen HTML.
 * - $role         : Kunci peran (admin|cs|teknisi|pelanggan).
 * - $active       : Kunci menu aktif.
 * - $pageTitle    : Judul di header.
 * - $breadcrumb   : array item breadcrumb (opsional).
 * - $user         : Data pengguna yang sedang masuk (dari autentikasi).
 * - $showSearch   : Tampilkan pencarian global.
 * - $content      : Isi halaman.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../Helpers/functions.php';

$active = $active ?? 'dashboard';
$pageTitle = $pageTitle ?? ($title ?? 'WiFiCare CDN');
$breadcrumb = $breadcrumb ?? [];
$showSearch = $showSearch ?? false;
$includeCharts = $includeCharts ?? false;
$includeAi = $includeAi ?? false;

// Identitas & peran diambil dari SATU sumber autentikasi (database), bukan dari
// variabel `$role` yang bisa saja tersisa dari view atau berasal dari URL.
// Tidak ada fallback ke peran Pelanggan: pengguna tanpa sesi diarahkan ke login,
// dan peran yang tidak dikenali ditolak dengan aman (HTTP 403).
$account = auth_user();
if ($account === null) {
    redirect('/login');
}

$role = Auth::isValidRole((string) ($account['role'] ?? '')) ? (string) $account['role'] : null;
if ($role === null) {
    render_forbidden('Peran akun tidak dikenali. Hubungi Administrator.');
}

// Bentuk identitas minimal (tanpa kolom sensitif) untuk header/sidebar.
$user = [
    'nama' => (string) ($account['nama'] ?? ''),
    'role' => $role,
    'username' => (string) ($account['username'] ?? ''),
];

// Notifikasi in-app: hanya notifikasi milik pengguna yang masuk.
$currentUserId = (int) (auth_id() ?? 0);
$notifications = [];
$unreadCount = 0;
if ($currentUserId > 0) {
    $unreadCount = Notifikasi::unreadCountFor($currentUserId);
    $notifications = Notifikasi::latestFor($currentUserId, 8);
}
?>
<!DOCTYPE html>
<html lang="id" class="no-js" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($title ?? 'WiFiCare CDN') ?><?= isset($title) ? ' — WiFiCare CDN' : '' ?></title>
    <script>
        /* Anti-flash: pasang tema tersimpan sebelum stylesheet dimuat. */
        (function () {
            try {
                var saved = window.localStorage.getItem('wificare-theme');
                var theme = saved === 'dark' ? 'dark' : 'light';
                document.documentElement.setAttribute('data-theme', theme);
                document.documentElement.setAttribute('data-bs-theme', theme);
            } catch (error) {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        })();
    </script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body>
    <a class="skip-link" href="#konten-utama">Lompat ke konten utama</a>

    <div class="app-shell">
        <?= component_html('sidebar', [
            'items' => role_menu($role),
            'active' => $active,
            'role' => $role,
            'variant' => 'app',
        ]) ?>

        <div class="app-body">
            <div class="app-main">
                <?= component_html('header', [
                    'title' => $pageTitle,
                    'role' => $role,
                    'user' => $user,
                    'showSearch' => $showSearch,
                    'showNotifications' => true,
                    'notifications' => $notifications,
                    'unreadCount' => $unreadCount,
                ]) ?>

                <main class="app-content" id="konten-utama">
                    <?php if (!empty($breadcrumb)): ?>
                        <?= component_html('breadcrumb', ['items' => $breadcrumb]) ?>
                    <?php endif; ?>

                    <?= $content ?>
                </main>

                <footer class="app-footer">
                    <span>&copy; <?= e(date('Y')) ?> WiFiCare CDN</span>
                    <span class="app-footer-muted">Sistem Manajemen Pengaduan Pelanggan WiFi</span>
                </footer>
            </div>
        </div>
    </div>

    <button class="app-sidebar-scrim" type="button" data-menu-close aria-label="Tutup menu" tabindex="-1" hidden></button>

    <?= component_html('modal-confirm', [
        'id' => 'appConfirmModal',
        'title' => 'Konfirmasi tindakan',
        'message' => 'Apakah Anda yakin ingin melanjutkan tindakan ini?',
        'confirmLabel' => 'Ya, lanjutkan',
        'cancelLabel' => 'Batal',
        'variant' => 'primary',
        'icon' => 'alert',
    ]) ?>

    <?= component_html('toast') ?>

    <?php
    $wcFlashMessages = [];
    if (!empty($flash_sukses)) {
        $wcFlashMessages[] = ['type' => 'success', 'message' => (string) $flash_sukses];
    }
    if (!empty($flash_error)) {
        $wcFlashMessages[] = ['type' => 'danger', 'message' => (string) $flash_error];
    }
    ?>
    <?php if ($wcFlashMessages !== []): ?>
        <script type="application/json" id="wificare-flash"><?=
            json_encode(
                $wcFlashMessages,
                JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            )
        ?></script>
    <?php endif; ?>

    <?php if ($user !== null): ?>
        <?= component_html('ai-widget', [
            'configured' => AiAssistantService::isAvailable(),
            'aiState' => AiSettingsService::state(),
            'quickQuestions' => AiKnowledge::quickQuestions((string) $role),
            'chatUrl' => url('/ai/chat'),
            'token' => csrf_token(),
        ]) ?>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <?php if ($includeCharts): ?>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js" crossorigin="anonymous"></script>
        <script src="<?= e(asset('js/dashboard.js')) ?>"></script>
    <?php endif; ?>
    <?php if ($user !== null): ?>
        <script src="<?= e(asset('js/ai-render.js')) ?>"></script>
    <?php endif; ?>
    <?php if (!empty($includeAi)): ?>
        <script src="<?= e(asset('js/ai.js')) ?>"></script>
    <?php endif; ?>
    <?php if ($user !== null): ?>
        <script src="<?= e(asset('js/ai-widget.js')) ?>"></script>
    <?php endif; ?>
    <script src="<?= e(asset('js/main.js')) ?>"></script>
</body>
</html>
