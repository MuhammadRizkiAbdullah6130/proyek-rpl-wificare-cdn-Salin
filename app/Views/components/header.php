<?php
/**
 * Komponen: Header / navbar atas.
 *
 * Variabel:
 * - $title             : Judul halaman.
 * - $role              : Kunci peran (admin/cs/teknisi/pelanggan/publik).
 * - $user              : (opsional) array nama pengguna; null pada halaman publik.
 * - $showSearch        : Tampilkan kolom pencarian global.
 * - $showNotifications : Tampilkan ikon lonceng notifikasi.
 * - $actions           : (opsional) HTML tombol tambahan di sisi kanan.
 */

declare(strict_types=1);

$title = $title ?? 'WiFiCare CDN';
$role = $role ?? 'publik';
$user = $user ?? null;
$showSearch = $showSearch ?? false;
$showNotifications = $showNotifications ?? ($user !== null);
$actions = $actions ?? '';
$notifications = $notifications ?? [];
$unreadCount = (int) ($unreadCount ?? 0);

// Peran efektif untuk tautan dashboard: ikuti akun yang masuk bila ada.
$navRole = is_array($user) && isset($user['role']) ? (string) $user['role'] : $role;

// Tautan detail tiket sesuai peran untuk setiap notifikasi.
$notifBase = match ($navRole) {
    'admin' => '/admin/pengaduan/',
    'cs' => '/cs/pengaduan/',
    'teknisi' => '/teknisi/tugas/',
    'pelanggan' => '/pelanggan/pengaduan/',
    default => '',
};
?>
<header class="app-header">
    <button class="app-menu-toggle" type="button" aria-label="Buka menu" aria-expanded="false" data-menu-toggle>
        <?= icon('menu') ?>
    </button>

    <div class="app-header-title">
        <span class="app-header-eyebrow"><?= e(role_label($role)) ?></span>
        <h1 class="app-header-heading"><?= e($title) ?></h1>
    </div>

    <?php if ($showSearch): ?>
        <form class="app-header-search" role="search" onsubmit="return false;">
            <label class="visually-hidden" for="global-search">Pencarian global</label>
            <span class="app-header-search-icon"><?= icon('search') ?></span>
            <input class="form-control" type="search" id="global-search" placeholder="Cari tiket, pelanggan, atau PPPoE">
            <span class="demo-badge" title="Fitur pencarian baru tersedia setelah backend siap">DEMO</span>
        </form>
    <?php endif; ?>

    <div class="app-header-actions">
        <?= $actions ?>

        <?= component_html('theme-toggle') ?>

        <?php if ($showNotifications): ?>
            <div class="dropdown">
                <button class="app-icon-button" type="button" data-dropdown-toggle aria-label="Notifikasi"
                        data-dropdown-target="notification-panel">
                    <?= icon('bell') ?>
                    <?php if ($unreadCount > 0): ?>
                        <span class="app-icon-badge"><?= e($unreadCount > 99 ? '99+' : (string) $unreadCount) ?></span>
                    <?php endif; ?>
                </button>
                <div class="app-dropdown-panel app-dropdown-panel--wide" id="notification-panel" hidden>
                    <div class="app-dropdown-head">
                        <strong>Notifikasi</strong>
                        <?php if ($unreadCount > 0): ?>
                            <span class="app-notif-count"><?= e((string) $unreadCount) ?> baru</span>
                        <?php endif; ?>
                    </div>
                    <div class="app-dropdown-body app-notif-list">
                        <?php if (empty($notifications)): ?>
                            <?= component_html('empty-state', [
                                'icon' => 'bell',
                                'title' => 'Belum ada notifikasi',
                                'message' => 'Notifikasi akan muncul setelah Anda menerima tiket atau perubahan status.',
                                'compact' => true,
                            ]) ?>
                        <?php else: ?>
                            <?php foreach ($notifications as $notif): ?>
                                <?php
                                $notifId = (int) ($notif['id'] ?? 0);
                                $isUnread = ($notif['read_at'] ?? null) === null;
                                ?>
                                <form method="post" action="<?= e(url('/notifikasi/' . $notifId . '/baca')) ?>" class="app-notif-form">
                                    <?= csrf_field() ?>
                                    <button type="submit"
                                            class="app-notif-item<?= $isUnread ? ' is-unread' : '' ?>">
                                        <span class="app-notif-title">
                                            <?php if ($isUnread): ?><span class="app-notif-dot" aria-hidden="true"></span><?php endif; ?>
                                            <?= e((string) ($notif['judul'] ?? '')) ?>
                                        </span>
                                        <span class="app-notif-message"><?= e((string) ($notif['pesan'] ?? '')) ?></span>
                                        <span class="app-notif-meta"><?= e(format_waktu((string) ($notif['created_at'] ?? ''))) ?></span>
                                    </button>
                                </form>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <div class="app-dropdown-foot">
                        <a class="app-dropdown-foot-link" href="<?= e(url('/notifikasi')) ?>">Lihat semua notifikasi</a>
                        <?php if ($unreadCount > 0): ?>
                            <form method="post" action="<?= e(url('/notifikasi/baca-semua')) ?>" class="app-notif-form">
                                <?= csrf_field() ?>
                                <button type="submit" class="app-link-button">Tandai semua dibaca</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($user !== null): ?>
            <div class="dropdown">
                <button class="app-user-chip" type="button" data-dropdown-toggle data-dropdown-target="user-panel">
                    <span class="app-user-avatar" aria-hidden="true"><?= e(mb_substr((string) ($user['nama'] ?? 'P'), 0, 1)) ?></span>
                    <span class="app-user-meta">
                        <span class="app-user-name"><?= e((string) ($user['nama'] ?? 'Pengguna')) ?></span>
                        <span class="app-user-role"><?= e(role_label((string) ($user['role'] ?? $role))) ?></span>
                    </span>
                </button>
                <div class="app-dropdown-panel" id="user-panel" hidden>
                    <a class="app-dropdown-item" href="<?= e(url('/' . $navRole . '/dashboard')) ?>"><?= icon('dashboard') ?> Dashboard</a>
                    <?php if ($navRole === 'pelanggan'): ?>
                        <a class="app-dropdown-item" href="<?= e(url('/pelanggan/profil')) ?>"><?= icon('user') ?> Profil</a>
                    <?php endif; ?>
                    <form class="app-dropdown-form" method="post" action="<?= e(url('/logout')) ?>">
                        <?= csrf_field() ?>
                        <button class="app-dropdown-item app-dropdown-item--button" type="submit"><?= icon('logout') ?> Keluar</button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <a class="btn btn-primary btn-sm" href="<?= e(url('/login')) ?>">Masuk</a>
        <?php endif; ?>
    </div>
</header>
