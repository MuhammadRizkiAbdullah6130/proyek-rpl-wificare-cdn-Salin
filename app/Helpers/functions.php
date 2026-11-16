<?php
/**
 * Helper aplikasi WiFiCare CDN.
 *
 * Menyediakan rendering view, komponen reusable, ikon SVG, URL,
 * escaping, dan pemetaan status pengaduan.
 */

declare(strict_types=1);

require_once __DIR__ . '/menu.php';
require_once __DIR__ . '/audit.php';

if (!function_exists('rating_layanan_enabled')) {
    /**
     * Feature flag sementara untuk Rating Layanan.
     *
     * Default NONAKTIF sesuai keputusan produk. Data rating lama tetap utuh;
     * flag ini hanya menyembunyikan UI dan menutup endpoint. Dapat diaktifkan
     * kembali tanpa migrasi database melalui env RATING_LAYANAN_ENABLED=1.
     */
    function rating_layanan_enabled(): bool
    {
        $value = getenv('RATING_LAYANAN_ENABLED');
        if ($value === false) {
            return false;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }
}

if (!function_exists('app_base_path')) {
    /**
     * Base path aplikasi (mis. saat dipasang di subfolder Apache).
     *
     * Atur melalui environment APP_BASE_PATH. Kosong berarti document root
     * sudah mengarah ke folder `public/`.
     */
    function app_base_path(): string
    {
        $base = (string) (getenv('APP_BASE_PATH') ?: '');

        return $base === '/' ? '' : rtrim($base, '/');
    }
}

if (!function_exists('url')) {
    /**
     * Bangun URL aplikasi.
     */
    function url(string $path = ''): string
    {
        $path = '/' . ltrim($path, '/');
        if ($path === '/') {
            return app_base_path() . '/';
        }

        return app_base_path() . $path;
    }
}

if (!function_exists('asset')) {
    /**
     * Bangun URL aset statis (CSS/JS/gambar).
     */
    function asset(string $path): string
    {
        $path = ltrim($path, '/');
        $url = app_base_path() . '/assets/' . $path;
        $file = dirname(__DIR__, 2) . '/public/assets/' . $path;

        // Cache-busting untuk CSS/JS agar perubahan UI langsung terbaca setelah
        // deploy tanpa pengguna harus melakukan hard refresh.
        if (is_file($file) && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['css', 'js'], true)) {
            $url .= '?v=' . filemtime($file);
        }

        return $url;
    }
}

if (!function_exists('e')) {
    /**
     * Escape output HTML.
     */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('component')) {
    /**
     * Cetak sebuah komponen reusable.
     *
     * @param array<string, mixed> $data Data komponen.
     */
    function component(string $__component, array $__data = []): void
    {
        echo component_html($__component, $__data);
    }
}

if (!function_exists('component_html')) {
    /**
     * Ambil output sebuah komponen sebagai string.
     *
     * Nama parameter memakai awalan ganda-garis-bawah (`$__component`,
     * `$__data`) agar TIDAK bertabrakan dengan variabel data komponen yang
     * di-`extract()`. Sebelumnya parameter bernama `$name` membuat data
     * komponen `name` (mis. `foto[]` pada komponen upload) diabaikan oleh
     * `EXTR_SKIP` — menyebabkan input berkas bernama salah sehingga foto tidak
     * pernah terkirim ke server.
     *
     * @param array<string, mixed> $__data Data komponen.
     */
    function component_html(string $__component, array $__data = []): string
    {
        $__file = __DIR__ . '/../Views/components/' . $__component . '.php';
        if (!is_file($__file)) {
            return '<!-- komponen tidak ditemukan: ' . e($__component) . ' -->';
        }

        extract($__data, EXTR_SKIP);

        ob_start();
        include $__file;

        return (string) ob_get_clean();
    }
}

if (!function_exists('render_view')) {
    /**
     * Render sebuah view di dalam layout utama.
     *
     * @param array<string, mixed> $data Data yang dibagikan ke view.
     * @param string $layout Nama layout (tanpa ekstensi) pada Views/layouts.
     */
    function render_view(string $view, array $data = [], string $layout = 'layouts/base'): void
    {
        $viewsDir = __DIR__ . '/../Views/';
        $viewFile = $viewsDir . $view . '.php';
        $layoutFile = $viewsDir . $layout . '.php';

        // Render isi view dalam LINGKUP TERISOLASI. Variabel yang dibuat di
        // dalam view (mis. `$role` pada loop daftar pengguna) TIDAK boleh bocor
        // ke layout — dahulu hal ini membuat layout memakai `$role` sisa loop
        // sehingga sidebar/header tampil dengan peran yang salah.
        $renderContent = static function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            include $__file;

            return (string) ob_get_clean();
        };

        $content = $renderContent($viewFile, $data);

        // Layout menerima DATA ASLI dari controller (bukan sisa variabel view).
        extract($data, EXTR_SKIP);
        $content = $content;
        include $layoutFile;
    }
}

if (!function_exists('periode_range')) {
    /**
     * Rentang tanggal (Y-m-d) untuk preset periode filter.
     *
     * Preset yang didukung: semua, hari_ini, 7_hari, bulan_ini, bulan_lalu,
     * tahun_ini. `semua` mengembalikan dua string kosong (tanpa batas tanggal).
     *
     * @return array{0: string, 1: string} [date_from, date_to]
     */
    function periode_range(string $periode): array
    {
        $today = date('Y-m-d');

        switch ($periode) {
            case 'hari_ini':
                return [$today, $today];
            case '7_hari':
                return [date('Y-m-d', strtotime('-6 days')), $today];
            case 'bulan_ini':
                return [date('Y-m-01'), $today];
            case 'bulan_lalu':
                return [
                    date('Y-m-01', strtotime('first day of last month')),
                    date('Y-m-t', strtotime('last day of last month')),
                ];
            case 'tahun_ini':
                return [date('Y-01-01'), $today];
            case 'semua':
            default:
                return ['', ''];
        }
    }
}

if (!function_exists('render_error')) {
    /**
     * Render halaman error dengan layout error sederhana (tanpa sidebar publik).
     */
    function render_error(string $title, string $message, int $status = 0): void
    {
        if ($status >= 400) {
            http_response_code($status);
        }

        render_view('error', [
            'title' => $title,
            'message' => $message,
            'status' => $status,
        ], 'layouts/error');
    }
}

if (!function_exists('status_map')) {
    /**
     * Pemetaan enam status resmi pengaduan.
     *
     * @return array<string, array{label: string, class: string}>
     */
    function status_map(): array
    {
        return [
            'menunggu_verifikasi' => ['label' => 'Menunggu Verifikasi', 'class' => 'status-menunggu-verifikasi'],
            'diproses_cs' => ['label' => 'Diproses CS', 'class' => 'status-diproses-cs'],
            'menunggu_teknisi' => ['label' => 'Menunggu Teknisi', 'class' => 'status-menunggu-teknisi'],
            'dalam_penanganan' => ['label' => 'Dalam Penanganan', 'class' => 'status-dalam-penanganan'],
            'selesai' => ['label' => 'Selesai', 'class' => 'status-selesai'],
            'ditolak' => ['label' => 'Ditolak', 'class' => 'status-ditolak'],
        ];
    }
}

if (!function_exists('status_label')) {
    /**
     * Label teks untuk kunci status.
     */
    function status_label(string $status): string
    {
        return status_map()[$status]['label'] ?? ucfirst(str_replace('_', ' ', $status));
    }
}

if (!function_exists('status_class')) {
    /**
     * Kelas CSS untuk kunci status.
     */
    function status_class(string $status): string
    {
        return status_map()[$status]['class'] ?? 'status-netral';
    }
}

if (!function_exists('status_keys')) {
    /**
     * Daftar enam kunci status resmi.
     *
     * @return array<int, string>
     */
    function status_keys(): array
    {
        return array_keys(status_map());
    }
}

if (!function_exists('status_badge_html')) {
    /**
     * HTML badge status efektif pengaduan.
     *
     * Pembatalan bukan status ketujuh. Bila tiket dibatalkan, badge "Dibatalkan"
     * menjadi status efektif tunggal agar tidak bertentangan dengan status lama.
     * Status sebelum pembatalan tetap tersedia pada riwayat/detail tiket.
     */
    function status_badge_html(string $status, bool $cancelled = false): string
    {
        if ($cancelled) {
            return '<span class="status-badge status-dibatalkan">'
                . '<span class="status-dot" aria-hidden="true"></span>Dibatalkan</span>';
        }

        return component_html('badge-status', ['status' => $status]);
    }
}

if (!function_exists('active_statuses')) {
    /**
     * Status yang dianggap TIKET AKTIF (di luar selesai/ditolak).
     *
     * Sumber tunggal agar perhitungan status tidak diduplikasi.
     *
     * @return array<int, string>
     */
    function active_statuses(): array
    {
        return ['menunggu_verifikasi', 'diproses_cs', 'menunggu_teknisi', 'dalam_penanganan'];
    }
}

if (!function_exists('status_is_active')) {
    /**
     * Apakah status termasuk kategori aktif.
     */
    function status_is_active(string $status): bool
    {
        return in_array($status, active_statuses(), true);
    }
}

if (!function_exists('status_sum_active')) {
    /**
     * Jumlahkan tiket aktif dari distribusi status.
     *
     * @param array<string, int> $distribution
     */
    function status_sum_active(array $distribution): int
    {
        $sum = 0;
        foreach (active_statuses() as $key) {
            $sum += (int) ($distribution[$key] ?? 0);
        }

        return $sum;
    }
}

if (!function_exists('status_color')) {
    /**
     * Warna grafik untuk kunci status (selaras palet Dark Mode).
     */
    function status_color(string $status): string
    {
        $colors = [
            'menunggu_verifikasi' => '#f59e0b',
            'diproses_cs' => '#3b82f6',
            'menunggu_teknisi' => '#8b5cf6',
            'dalam_penanganan' => '#06b6d4',
            'selesai' => '#22c55e',
            'ditolak' => '#ef4444',
        ];

        return $colors[$status] ?? '#94a3b8';
    }
}

if (!function_exists('bulan_singkat')) {
    /**
     * Nama bulan singkat Bahasa Indonesia (1–12).
     *
     * @return array<int, string>
     */
    function bulan_singkat(): array
    {
        return [
            1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
            7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
        ];
    }
}

if (!function_exists('format_tanggal')) {
    /**
     * Format tanggal ringkas Bahasa Indonesia (mis. 16 Okt 2026).
     */
    function format_tanggal(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return (string) $value;
        }

        $bulan = bulan_singkat();

        return date('d', $timestamp) . ' ' . ($bulan[(int) date('n', $timestamp)] ?? '') . ' ' . date('Y', $timestamp);
    }
}

if (!function_exists('format_waktu')) {
    /**
     * Format tanggal + jam ringkas Bahasa Indonesia (mis. 16 Okt 2026 14:05).
     */
    function format_waktu(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return (string) $value;
        }

        return format_tanggal($value) . ' ' . date('H:i', $timestamp);
    }
}

if (!function_exists('bulan_panjang')) {
    /**
     * Nama bulan lengkap Bahasa Indonesia (1–12).
     *
     * @return array<int, string>
     */
    function bulan_panjang(): array
    {
        return [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
            7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];
    }
}

if (!function_exists('format_waktu_panjang')) {
    /**
     * Format tanggal + jam lengkap Bahasa Indonesia (mis. 16 Oktober 2026, 20:55).
     *
     * Dipakai kartu data tiket pada chatbot (Revisi 2).
     */
    function format_waktu_panjang(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return (string) $value;
        }

        $bulan = bulan_panjang();

        return date('d', $timestamp) . ' ' . ($bulan[(int) date('n', $timestamp)] ?? '')
            . ' ' . date('Y', $timestamp) . ', ' . date('H:i', $timestamp);
    }
}

if (!function_exists('whatsapp_number')) {
    /**
     * Normalisasi nomor WhatsApp ke format E.164 tanpa tanda plus
     * (mis. 6281234567890 atau 15551687957).
     *
     * Menerima format internasional dengan +, spasi, tanda kurung, titik,
     * atau tanda hubung. Nomor lokal Indonesia (08.../8...) tetap
     * dinormalisasi ke 62. Karakter selain digit dan pemisah nomor yang
     * diizinkan (termasuk huruf) ditolak.
     *
     * Mengembalikan null bila nomor tidak valid, sehingga pemanggil dapat
     * menampilkan pesan yang sesuai alih-alih membuat tautan rusak.
     */
    function whatsapp_number(?string $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        // Tolak huruf/karakter aneh. Tanda + hanya boleh di awal. Format
        // pemisah yang lazim (spasi, kurung, titik, tanda hubung) diterima.
        if (preg_match('/^\+?[0-9\s().-]+$/u', $raw) !== 1) {
            return null;
        }

        $explicitInternational = str_starts_with($raw, '+');
        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === null || $digits === '') {
            return null;
        }

        // Buang awalan internasional 00; nomor setelahnya tidak boleh
        // diperlakukan sebagai nomor lokal Indonesia.
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
            $explicitInternational = true;
        }
        if ($digits === '') {
            return null;
        }

        if (!$explicitInternational) {
            if (str_starts_with($digits, '0')) {
                $digits = '62' . substr($digits, 1);
            } elseif (str_starts_with($digits, '8')) {
                // Kompatibilitas input lokal Indonesia tanpa awalan 0.
                $digits = '62' . $digits;
            }
        }

        // Bentuk +62 08... (angka 0 setelah kode negara) juga dinormalisasi.
        if (str_starts_with($digits, '62') && strlen($digits) > 2 && $digits[2] === '0') {
            $digits = '62' . substr($digits, 3);
        }

        // E.164: 8-15 digit, diawali digit non-nol. Nomor Indonesia tetap
        // memakai batas lama (10-15 digit termasuk kode 62).
        if (preg_match('/^[1-9][0-9]{7,14}$/', $digits) !== 1) {
            return null;
        }
        if (str_starts_with($digits, '62') && strlen($digits) < 10) {
            return null;
        }

        return $digits;
    }
}

if (!function_exists('whatsapp_link')) {
    /**
     * Bangun tautan wa.me tervalidasi. Mengembalikan null bila nomor tidak valid.
     */
    function whatsapp_link(?string $value, ?string $message = null): ?string
    {
        $number = whatsapp_number($value);
        if ($number === null) {
            return null;
        }

        $url = 'https://wa.me/' . $number;
        if ($message !== null && $message !== '') {
            $url .= '?text=' . rawurlencode($message);
        }

        return $url;
    }
}

if (!function_exists('status_chart_data')) {
    /**
     * Susun data grafik distribusi status (label, nilai, warna).
     *
     * @param array<string, int> $distribution
     * @return array{labels: array<int, string>, values: array<int, int>, colors: array<int, string>}
     */
    function status_chart_data(array $distribution): array
    {
        $labels = [];
        $values = [];
        $colors = [];

        foreach (status_map() as $key => $meta) {
            $labels[] = $meta['label'];
            $values[] = (int) ($distribution[$key] ?? 0);
            $colors[] = status_color($key);
        }

        return ['labels' => $labels, 'values' => $values, 'colors' => $colors];
    }
}

if (!function_exists('trend_chart_data')) {
    /**
     * Susun data grafik tren harian (label tanggal, nilai).
     *
     * @param array<string, int> $trend Tanggal (Y-m-d) => jumlah.
     * @return array{labels: array<int, string>, values: array<int, int>}
     */
    function trend_chart_data(array $trend): array
    {
        $labels = [];
        $values = [];

        foreach ($trend as $date => $count) {
            $timestamp = strtotime((string) $date);
            if ($timestamp !== false) {
                $bulan = bulan_singkat();
                $labels[] = date('d', $timestamp) . ' ' . ($bulan[(int) date('n', $timestamp)] ?? '');
            } else {
                $labels[] = (string) $date;
            }
            $values[] = (int) $count;
        }

        return ['labels' => $labels, 'values' => $values];
    }
}

if (!function_exists('chart_data_script')) {
    /**
     * Cetak tag skrip JSON berisi data grafik (dibaca dashboard.js).
     *
     * @param array<string, mixed> $payload
     */
    function chart_data_script(array $payload): string
    {
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        return '<script type="application/json" id="wificare-chart-data">' . $json . '</script>';
    }
}

if (!function_exists('is_active')) {
    /**
     * Tandai menu aktif sebagai kelas CSS.
     */
    function is_active(string $key, string $active): string
    {
        return $key === $active ? ' is-active' : '';
    }
}

if (!function_exists('icon')) {
    /**
     * Ikon SVG inline dengan gaya konsisten (stroke currentColor).
     *
     * @param string $name Nama ikon.
     * @param string $class Kelas tambahan opsional.
     */
    function icon(string $name, string $class = ''): string
    {
        $paths = [
            'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
            'users' => '<circle cx="9" cy="8" r="3.2"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 5.2a3 3 0 0 1 0 5.6"/><path d="M17.5 14.2A5.5 5.5 0 0 1 21.5 20"/>',
            'user' => '<circle cx="12" cy="8" r="3.6"/><path d="M4.5 20.5a7.5 7.5 0 0 1 15 0"/>',
            'ticket' => '<path d="M4 6.5A1.5 1.5 0 0 1 5.5 5h13A1.5 1.5 0 0 1 20 6.5v2.2a2.3 2.3 0 0 0 0 4.6v4.2A1.5 1.5 0 0 1 18.5 19h-13A1.5 1.5 0 0 1 4 17.5v-4.2a2.3 2.3 0 0 0 0-4.6Z"/><path d="M12 8v8"/>',
            'tool' => '<path d="M14.7 6.3a4 4 0 0 1 5.3 5.3L11 20.6a2.8 2.8 0 0 1-4-4Z"/><path d="M14.7 6.3 9.5 11.5"/><path d="M17.5 4.5a4 4 0 0 0 2 3"/>',
            'report' => '<path d="M4 20h16"/><rect x="6" y="11" width="3" height="6" rx="1"/><rect x="11" y="7" width="3" height="10" rx="1"/><rect x="16" y="13" width="3" height="4" rx="1"/>',
            'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 13a7.6 7.6 0 0 0 .1-2l1.7-1.3-1.8-3.1-2 .8a7.7 7.7 0 0 0-1.7-1L15.4 4h-3.6l-.3 2.4a7.7 7.7 0 0 0-1.7 1l-2-.8L6 9.7 7.7 11a7.6 7.6 0 0 0 0 2L6 14.3l1.8 3.1 2-.8a7.7 7.7 0 0 0 1.7 1l.3 2.4h3.6l.3-2.4a7.7 7.7 0 0 0 1.7-1l2 .8 1.8-3.1Z"/>',
            'shield' => '<path d="M12 3l7 3v5.5c0 4.2-2.9 7.9-7 9.5-4.1-1.6-7-5.3-7-9.5V6Z"/><path d="M9.5 12.5 11.5 15l3.5-4"/>',
            'inbox' => '<path d="M4 13.5V6.5A1.5 1.5 0 0 1 5.5 5h13A1.5 1.5 0 0 1 20 6.5v7"/><path d="M4 13.5h4l1.2 2.5h5.6L16 13.5h4v4A1.5 1.5 0 0 1 18.5 19h-13A1.5 1.5 0 0 1 4 17.5Z"/>',
            'check' => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
            'x' => '<path d="M6 6l12 12M18 6 6 18"/>',
            'clock' => '<circle cx="12" cy="12" r="8"/><path d="M12 7.5V12l3 2"/>',
            'alert' => '<path d="M12 4.5 20 19H4Z"/><path d="M12 10v4"/><path d="M12 16.8h.01"/>',
            'bell' => '<path d="M6 9a6 6 0 1 1 12 0c0 4 1.5 5.5 1.5 5.5H4.5S6 13 6 9Z"/><path d="M10 18a2 2 0 0 0 4 0"/>',
            'search' => '<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4 4"/>',
            'filter' => '<path d="M4 6h16l-6 7v6l-4-2v-4Z"/>',
            'plus' => '<path d="M12 5v14M5 12h14"/>',
            'edit' => '<path d="M4 20h4l10-10-4-4L4 16Z"/><path d="m14 6 4 4"/>',
            'trash' => '<path d="M5 7h14"/><path d="M9 7V5h6v2"/><path d="M7 7l1 12h8l1-12"/>',
            'eye' => '<path d="M2.5 12S6 6 12 6s9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/>',
            'upload' => '<path d="M12 16V5"/><path d="m7.5 9.5 4.5-4.5 4.5 4.5"/><path d="M5 19h14"/>',
            'download' => '<path d="M12 5v11"/><path d="m7.5 11.5 4.5 4.5 4.5-4.5"/><path d="M5 19h14"/>',
            'star' => '<path d="m12 4 2.4 5 5.6.6-4.2 3.8 1.2 5.6L12 16.4 7 19l1.2-5.6L4 9.6l5.6-.6Z"/>',
            'chat' => '<path d="M5 5h14a1.5 1.5 0 0 1 1.5 1.5v8A1.5 1.5 0 0 1 19 16h-7l-5 4v-4H5a1.5 1.5 0 0 1-1.5-1.5v-8A1.5 1.5 0 0 1 5 5Z"/>',
            'logout' => '<path d="M14 5H6.5A1.5 1.5 0 0 0 5 6.5v11A1.5 1.5 0 0 0 6.5 19H14"/><path d="M12 12h8"/><path d="m17 9 3 3-3 3"/>',
            'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
            'home' => '<path d="M4 11 12 4l8 7"/><path d="M6 10v9h12v-9"/>',
            'info' => '<circle cx="12" cy="12" r="8"/><path d="M12 11v5"/><path d="M12 8.2h.01"/>',
            'note' => '<path d="M6 4h9l4 4v12H6Z"/><path d="M9 9h6M9 13h6M9 17h4"/>',
            'tag' => '<path d="M4 12.5V5h7.5L20 13.5 13.5 20Z"/><circle cx="8.5" cy="8.5" r="1.3"/>',
            'activity' => '<path d="M3 12h4l2.5-6 5 12L17 12h4"/>',
            'phone' => '<path d="M6.5 4h3l1.5 4-2 1.5a11 11 0 0 0 5.5 5.5L16 13l4 1.5v3A1.5 1.5 0 0 1 18.5 19C11 19 5 13 5 5.5A1.5 1.5 0 0 1 6.5 4Z"/>',
            'calendar' => '<rect x="4" y="5.5" width="16" height="15" rx="1.5"/><path d="M4 10h16"/><path d="M9 3.5v4M15 3.5v4"/>',
            'refresh' => '<path d="M20 12a8 8 0 1 1-2.3-5.6"/><path d="M20 4v4h-4"/>',
            'lock' => '<rect x="5" y="10" width="14" height="10" rx="1.5"/><path d="M8.5 10V7.5a3.5 3.5 0 0 1 7 0V10"/>',
            'arrow-left' => '<path d="M19 12H5"/><path d="m10 7-5 5 5 5"/>',
            'file' => '<path d="M7 3h7l4 4v14H7Z"/><path d="M14 3v4h4"/>',
            'image' => '<rect x="4" y="5" width="16" height="14" rx="1.5"/><circle cx="9" cy="10" r="1.8"/><path d="m5 18 5-5 4 4 3-2 3 3"/>',
            'cpu' => '<rect x="7" y="7" width="10" height="10" rx="2"/><path d="M10 3v4M14 3v4M10 17v4M14 17v4M3 10h4M3 14h4M17 10h4M17 14h4"/><rect x="10.5" y="10.5" width="3" height="3" rx="0.5"/>',
            'database' => '<ellipse cx="12" cy="6" rx="7" ry="3"/><path d="M5 6v6c0 1.7 3.1 3 7 3s7-1.3 7-3V6"/><path d="M5 12v6c0 1.7 3.1 3 7 3s7-1.3 7-3v-6"/>',
            'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2.2M12 19.3v2.2M4.2 4.2l1.6 1.6M18.2 18.2l1.6 1.6M2.5 12h2.2M19.3 12h2.2M4.2 19.8l1.6-1.6M18.2 5.8l1.6-1.6"/>',
            'moon' => '<path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z"/>',
            'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
        ];

        $path = $paths[$name] ?? $paths['info'];
        $classAttr = $class === '' ? 'ui-icon' : 'ui-icon ' . $class;

        return '<svg class="' . e($classAttr) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
            . 'stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . $path . '</svg>';
    }
}
