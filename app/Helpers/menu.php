<?php
/**
 * Definisi menu navigasi per peran WiFiCare CDN.
 *
 * Menu ini murni data tampilan (label, URL, ikon, kunci aktif).
 * Hak akses sebenarnya akan diterapkan pada tahap RBAC.
 */

declare(strict_types=1);

if (!function_exists('role_menu')) {
    /**
     * Ambil daftar item menu untuk sebuah peran.
     *
     * Setiap item dapat memiliki kunci:
     * - group: (opsional) judul kelompok menu.
     * - key: identitas item untuk menandai menu aktif.
     * - label: teks menu.
     * - icon: nama ikon (lihat icon()).
     * - url: tujuan tautan (lihat url()).
     * - children: (opsional) daftar submenu; item induk menjadi tombol
     *   expandable dan tidak memakai url.
     *
     * @return array<int, array<string, mixed>>
     */
    function role_menu(string $role): array
    {
        $menus = [
            'publik' => [
                // Revisi 6: tidak ada lagi navigasi publik (Beranda/Tentang/Masuk).
            ],
            'admin' => [
                ['group' => 'Utama'],
                ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard', 'url' => url('/admin/dashboard')],
                ['key' => 'pelanggan', 'label' => 'Data Pelanggan', 'icon' => 'user', 'url' => url('/admin/pelanggan')],
                ['key' => 'pengguna', 'label' => 'Data Karyawan', 'icon' => 'users', 'url' => url('/admin/pengguna')],
                ['key' => 'pengaduan', 'label' => 'Semua Pengaduan', 'icon' => 'ticket', 'url' => url('/admin/pengaduan')],
                ['key' => 'teknisi', 'label' => 'Monitoring Teknisi', 'icon' => 'activity', 'url' => url('/admin/teknisi')],
                ['group' => 'Laporan & Sistem'],
                ['key' => 'laporan', 'label' => 'Laporan & Analitik', 'icon' => 'report', 'url' => url('/admin/laporan')],
                ['key' => 'kategori', 'label' => 'Kategori Gangguan', 'icon' => 'tag', 'url' => url('/admin/kategori')],
                ['key' => 'audit', 'label' => 'Audit Log', 'icon' => 'shield', 'url' => url('/admin/audit')],
                ['group' => 'Pengaturan'],
                ['key' => 'pengaturan', 'label' => 'Pengaturan Aplikasi', 'icon' => 'settings', 'url' => url('/admin/pengaturan')],
                ['key' => 'pengaturan_ai', 'label' => 'Pengaturan AI Chatbot', 'icon' => 'cpu', 'url' => url('/admin/pengaturan/ai')],
                [
                    'key' => 'whatsapp',
                    'label' => 'WhatsApp Gateway',
                    'icon' => 'chat',
                    'children' => [
                        ['key' => 'whatsapp_gateway', 'label' => 'Gateway', 'url' => url('/admin/whatsapp/gateway')],
                        ['key' => 'whatsapp_otp', 'label' => 'OTP WhatsApp', 'url' => url('/admin/whatsapp/otp')],
                        ['key' => 'whatsapp_notifikasi', 'label' => 'Notifikasi WhatsApp', 'url' => url('/admin/whatsapp/notifikasi')],
                        ['key' => 'whatsapp_laporan', 'label' => 'Laporan WhatsApp', 'url' => url('/admin/whatsapp/laporan')],
                        ['key' => 'whatsapp_audit', 'label' => 'Audit Konfigurasi', 'url' => url('/admin/whatsapp/audit')],
                    ],
                ],
                ['key' => 'monitoring_api', 'label' => 'Monitoring Penggunaan API', 'icon' => 'activity', 'url' => url('/admin/monitoring-api')],
            ],
            'cs' => [
                ['group' => 'Utama'],
                ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard', 'url' => url('/cs/dashboard')],
                ['key' => 'masuk', 'label' => 'Pengaduan Masuk', 'icon' => 'inbox', 'url' => url('/cs/pengaduan')],
                ['key' => 'verifikasi', 'label' => 'Verifikasi', 'icon' => 'check', 'url' => url('/cs/verifikasi')],
                ['key' => 'penugasan', 'label' => 'Penugasan Teknisi', 'icon' => 'tool', 'url' => url('/cs/penugasan')],
                ['key' => 'monitoring', 'label' => 'Monitoring Pengaduan', 'icon' => 'activity', 'url' => url('/cs/monitoring')],
                ['group' => 'Data'],
                ['key' => 'pelanggan', 'label' => 'Riwayat Pelanggan', 'icon' => 'user', 'url' => url('/cs/pelanggan')],
                ['key' => 'catatan', 'label' => 'Catatan Internal', 'icon' => 'note', 'url' => url('/cs/catatan')],
            ],
            'teknisi' => [
                ['group' => 'Tugas'],
                ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard', 'url' => url('/teknisi/dashboard')],
                ['key' => 'tugas', 'label' => 'Tugas Saya', 'icon' => 'ticket', 'url' => url('/teknisi/tugas')],
                ['group' => 'Riwayat'],
                ['key' => 'riwayat', 'label' => 'Riwayat Pekerjaan', 'icon' => 'report', 'url' => url('/teknisi/riwayat')],
            ],
            'pelanggan' => [
                ['group' => 'Menu'],
                ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard', 'url' => url('/pelanggan/dashboard')],
                ['key' => 'buat', 'label' => 'Buat Pengaduan', 'icon' => 'plus', 'url' => url('/pelanggan/pengaduan/baru')],
                ['key' => 'riwayat', 'label' => 'Riwayat Pengaduan', 'icon' => 'ticket', 'url' => url('/pelanggan/pengaduan')],
                ['group' => 'Akun'],
                ['key' => 'profil', 'label' => 'Profil', 'icon' => 'user', 'url' => url('/pelanggan/profil')],
            ],
        ];

        return $menus[$role] ?? [];
    }
}

if (!function_exists('role_label')) {
    /**
     * Label manusiawi untuk sebuah peran.
     */
    function role_label(string $role): string
    {
        $labels = [
            'admin' => 'Administrator',
            'cs' => 'Customer Service',
            'teknisi' => 'Teknisi',
            'pelanggan' => 'Pelanggan',
            'publik' => 'Pengunjung',
        ];

        return $labels[$role] ?? ucfirst($role);
    }
}
