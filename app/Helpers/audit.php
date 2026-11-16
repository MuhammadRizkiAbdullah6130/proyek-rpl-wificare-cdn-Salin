<?php
/**
 * Helper penyajian audit yang aman dan ramah dibaca.
 *
 * Helper ini HANYA mengubah presentasi. Data asli pada tabel audit tetap utuh.
 * Nilai rahasia/teknis tidak pernah dirender ke HTML.
 */

declare(strict_types=1);

if (!function_exists('audit_action_label')) {
    /**
     * Ubah kode aksi audit menjadi kalimat Indonesia yang mudah dipahami.
     */
    function audit_action_label(string $action): string
    {
        $key = strtolower(trim($action));
        if ($key === '') {
            return 'Aktivitas sistem';
        }

        $labels = [
            'login_berhasil' => 'Login berhasil',
            'logout' => 'Logout',
            'login_diblokir' => 'Login diblokir',
            'login_nonaktif' => 'Login akun nonaktif ditolak',
            'login_peran_invalid' => 'Login dengan peran tidak valid ditolak',
            'password_diubah' => 'Password diubah',
            'password_direset' => 'Password direset',
            'password_reset_berhasil' => 'Reset password berhasil',
            'profil_diperbarui' => 'Profil diperbarui',
            'pengaduan_dibuat' => 'Pengaduan dibuat',
            'pengaduan_diterima' => 'Pengaduan diterima CS',
            'pengaduan_ditolak' => 'Pengaduan ditolak',
            'pengaduan_diselesaikan_cs' => 'Pengaduan diselesaikan CS',
            'pengaduan_ditugaskan' => 'Pengaduan ditugaskan ke teknisi',
            'pengaduan_dibatalkan' => 'Pengaduan dibatalkan',
            'pengaduan_diarsipkan' => 'Pengaduan diarsipkan',
            'penanganan_dimulai' => 'Penanganan dimulai',
            'laporan_perbaikan_dikirim' => 'Laporan perbaikan dikirim',
            'catatan_cs_ditambah' => 'Catatan internal ditambahkan',
            'rating_diberikan' => 'Rating layanan diberikan',
            'kategori_dibuat' => 'Kategori dibuat',
            'kategori_diperbarui' => 'Kategori diperbarui',
            'kategori_dihapus' => 'Kategori dihapus',
            'pelanggan_dibuat' => 'Pelanggan ditambahkan',
            'pelanggan_diperbarui' => 'Pelanggan diperbarui',
            'pengguna_dibuat' => 'Pengguna ditambahkan',
            'pengguna_diperbarui' => 'Pengguna diperbarui',
            'pengaturan_diubah' => 'Pengaturan aplikasi diubah',
            'api_whatsapp_tes_koneksi' => 'Tes koneksi WhatsApp',
            'api_kredensial_dibuat' => 'Kredensial WhatsApp ditambahkan',
            'api_kredensial_diperbarui' => 'Kredensial WhatsApp diperbarui',
            'api_koneksi_diaktifkan' => 'Koneksi WhatsApp diubah',
            'api_kredensial_dicabut' => 'Kredensial WhatsApp dicabut',
            'api_pengaturan_gateway_disimpan' => 'Pengaturan gateway WhatsApp disimpan',
            'api_pengaturan_otp_disimpan' => 'Konfigurasi OTP WhatsApp disimpan',
            'api_pengaturan_notifikasi_disimpan' => 'Konfigurasi notifikasi WhatsApp disimpan',
            'api_pengaturan_laporan_disimpan' => 'Konfigurasi laporan WhatsApp disimpan',
            'whatsapp_laporan_dikirim' => 'Laporan WhatsApp dikirim',
            'kredensial_dibuat' => 'Kredensial WhatsApp ditambahkan',
            'kredensial_diperbarui' => 'Kredensial WhatsApp diperbarui',
            'koneksi_diaktifkan' => 'Koneksi WhatsApp diubah',
            'kredensial_dicabut' => 'Kredensial WhatsApp dicabut',
            'pengaturan_gateway_disimpan' => 'Pengaturan gateway WhatsApp disimpan',
            'pengaturan_otp_disimpan' => 'Konfigurasi OTP WhatsApp disimpan',
            'pengaturan_notifikasi_disimpan' => 'Konfigurasi notifikasi WhatsApp disimpan',
            'pengaturan_laporan_disimpan' => 'Konfigurasi laporan WhatsApp disimpan',
            'antrean_diproses' => 'Antrean notifikasi WhatsApp diproses',
        ];

        if (isset($labels[$key])) {
            return $labels[$key];
        }

        // Fallback aman: kode internal diubah menjadi kalimat, bukan ditampilkan mentah.
        $human = str_replace('_', ' ', $key);
        $human = (string) preg_replace('/\bapi\b/i', 'API', $human);
        $human = (string) preg_replace('/\botp\b/i', 'OTP', $human);
        $human = (string) preg_replace('/\bcs\b/i', 'CS', $human);
        $human = (string) preg_replace('/\bwhatsapp\b/i', 'WhatsApp', $human);

        return ucfirst(trim($human));
    }
}

if (!function_exists('audit_ip_label')) {
    /**
     * Tampilkan loopback sebagai "Lokal" tanpa mengubah data audit asli.
     */
    function audit_ip_label(?string $ip): string
    {
        $ip = trim((string) $ip);
        if ($ip === '') {
            return '—';
        }

        if (in_array(strtolower($ip), ['::1', '127.0.0.1', '::ffff:127.0.0.1'], true)) {
            return 'Lokal';
        }

        return mb_strimwidth($ip, 0, 64, '…');
    }
}

if (!function_exists('audit_object_label')) {
    /**
     * Label objek audit yang aman dan tidak menampilkan nama tabel mentah.
     *
     * @param array<string, mixed> $metadata
     */
    function audit_object_label(?string $object, ?int $objectId, array $metadata = []): string
    {
        $object = trim((string) $object);
        $id = (int) ($objectId ?? 0);
        $idLabel = $id > 0 ? ' #' . $id : '';
        $ticket = trim((string) ($metadata['nomor_tiket'] ?? ''));

        $labels = [
            'pengaduan' => 'Pengaduan',
            'users' => 'Akun pengguna',
            'user' => 'Akun pengguna',
            'pelanggan' => 'Pelanggan',
            'kategori_gangguan' => 'Kategori gangguan',
            'kategori' => 'Kategori gangguan',
            'penugasan' => 'Penugasan teknisi',
            'laporan_teknisi' => 'Laporan perbaikan',
            'catatan_cs' => 'Catatan internal',
            'penyelesaian_cs' => 'Penyelesaian CS',
            'pengaturan' => 'Pengaturan aplikasi',
            'api_credentials' => 'Kredensial WhatsApp',
            'api_integration_settings' => 'Pengaturan integrasi',
            'whatsapp_report' => 'Laporan WhatsApp',
            'rating_layanan' => 'Rating layanan',
        ];

        if ($object === '') {
            return '—';
        }

        if ($object === 'pengaduan') {
            return $ticket !== '' ? 'Pengaduan ' . $ticket : 'Pengaduan' . $idLabel;
        }

        if ($object === 'pengaturan') {
            return 'Pengaturan aplikasi';
        }

        if (isset($labels[$object])) {
            return $labels[$object] . $idLabel;
        }

        $human = ucfirst(trim((string) preg_replace('/[^a-zA-Z0-9]+/', ' ', $object)));

        return ($human !== '' ? $human : 'Objek sistem') . $idLabel;
    }
}

if (!function_exists('audit_metadata_pairs')) {
    /**
     * Ubah metadata JSON menjadi pasangan label/nilai yang aman.
     *
     * Nilai rahasia, field internal, JSON mentah, SQL, dan stack trace tidak
     * pernah dikembalikan untuk dirender.
     *
     * @return array<int, array{label: string, value: string}>
     */
    function audit_metadata_pairs(?string $metadata): array
    {
        $raw = trim((string) $metadata);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $labels = [
            'ip' => 'Alamat IP',
            'nomor_tiket' => 'Nomor tiket',
            'status_lama' => 'Status sebelumnya',
            'status_baru' => 'Status terbaru',
            'teknisi_nama' => 'Teknisi',
            'teknisi_id' => 'ID teknisi',
            'instruksi' => 'Instruksi',
            'laporan_id' => 'ID laporan',
            'jumlah_foto' => 'Jumlah foto',
            'provider' => 'Provider',
            'hasil' => 'Hasil',
            'kode' => 'Kode',
            'feature' => 'Modul',
            'alasan' => 'Alasan',
            'solusi' => 'Solusi',
            'oleh' => 'Oleh',
            'catatan' => 'Catatan',
            'nama' => 'Nama',
            'role' => 'Peran',
            'email' => 'Email',
            'nomor_whatsapp' => 'Nomor WhatsApp',
            'jenis_laporan' => 'Jenis laporan',
            'penerima' => 'Penerima',
            'status_kirim' => 'Status pengiriman',
            'judul' => 'Judul',
        ];

        $pairs = [];
        foreach ($decoded as $key => $value) {
            $key = (string) $key;

            // Daftar field teknis tidak ditampilkan pada UI standar.
            if ($key === 'fields') {
                continue;
            }

            if (preg_match('/password|passwd|token|secret|otp|api[_-]?key|authorization|cookie|session|hash|credential|kredensial|private/i', $key) === 1) {
                continue;
            }

            $label = $labels[$key] ?? ucfirst(trim((string) preg_replace('/[^a-zA-Z0-9]+/', ' ', $key)));
            if ($label === '') {
                $label = 'Keterangan tambahan';
            }

            $display = audit_metadata_value($key, $value);
            if ($display === null) {
                continue;
            }

            $pairs[] = ['label' => $label, 'value' => $display];
        }

        return $pairs;
    }
}

if (!function_exists('audit_metadata_value')) {
    /**
     * Nilai metadata yang sudah disanitasi untuk tampilan.
     */
    function audit_metadata_value(string $key, mixed $value): ?string
    {
        if ($key === 'ip') {
            return audit_ip_label(is_scalar($value) ? (string) $value : null);
        }

        if (in_array($key, ['status_lama', 'status_baru'], true) && is_scalar($value)) {
            $status = (string) $value;
            if ($status === '') {
                return '—';
            }

            return status_map()[$status]['label'] ?? ucfirst(str_replace('_', ' ', $status));
        }

        if ($key === 'feature' && is_scalar($value)) {
            $feature = (string) $value;
            if ($feature === '') {
                return '—';
            }

            return class_exists('ApiFeatureRegistry')
                ? ApiFeatureRegistry::label($feature)
                : ucfirst(str_replace('_', ' ', $feature));
        }

        if (is_array($value) || is_object($value)) {
            return 'Informasi tambahan tercatat secara aman';
        }

        if (is_bool($value)) {
            return $value ? 'Ya' : 'Tidak';
        }

        if ($value === null) {
            return '—';
        }

        $text = trim((string) $value);
        if ($text === '') {
            return '—';
        }

        // Jangan render data teknis mentah ke UI biasa.
        if (preg_match('/<\?php|Stack trace|#0\s|SELECT\s|INSERT\s|UPDATE\s|DELETE\s|PDOException|-----BEGIN|eyJ[A-Za-z0-9_-]{20,}/i', $text) === 1) {
            return 'Tidak ditampilkan (data teknis/sensitif)';
        }

        if ((str_starts_with($text, '{') && str_ends_with($text, '}'))
            || (str_starts_with($text, '[') && str_ends_with($text, ']'))
        ) {
            return 'Data terstruktur tersimpan secara aman';
        }

        return mb_strimwidth($text, 0, 240, '…');
    }
}

if (!function_exists('api_config_action_summary')) {
    /**
     * Ringkasan perubahan konfigurasi API yang aman untuk Admin.
     */
    function api_config_action_summary(string $action): string
    {
        $summaries = [
            'kredensial_dibuat' => 'Kredensial koneksi ditambahkan tanpa menampilkan token.',
            'kredensial_diperbarui' => 'Data koneksi diperbarui; nilai rahasia tidak ditampilkan.',
            'koneksi_diaktifkan' => 'Koneksi WhatsApp default diubah.',
            'kredensial_dicabut' => 'Kredensial dicabut dan secret dihapus.',
            'pengaturan_gateway_disimpan' => 'Pengaturan gateway disimpan.',
            'pengaturan_otp_disimpan' => 'Konfigurasi OTP disimpan.',
            'pengaturan_notifikasi_disimpan' => 'Konfigurasi notifikasi disimpan.',
            'pengaturan_laporan_disimpan' => 'Konfigurasi laporan disimpan.',
            'antrean_diproses' => 'Antrean notifikasi WhatsApp diproses.',
        ];

        return $summaries[strtolower(trim($action))] ?? 'Perubahan konfigurasi berhasil dicatat.';
    }
}
