<?php
/**
 * Controller area Pelanggan.
 *
 * Menangani formulir pengaduan, penyimpanan tiket, riwayat, dan detail tiket.
 * Identitas pelanggan selalu diambil dari session (relasi users -> pelanggan),
 * tidak pernah dari input browser.
 */

declare(strict_types=1);

require_once __DIR__ . '/Controller.php';

final class PelangganController extends Controller
{
    /** Ambil data pelanggan (dari session) yang aman ditampilkan di formulir. */
    private function profilDariSession(): array
    {
        $user = auth_user() ?? [];

        return [
            'nama' => (string) ($user['nama'] ?? ''),
            'username_pppoe' => (string) ($user['username_pppoe'] ?? ($user['username'] ?? '')),
            'nomor_whatsapp' => (string) ($user['nomor_whatsapp'] ?? ''),
        ];
    }

    public function dashboard(): void
    {
        // Identitas pelanggan diambil dari session (relasi users -> pelanggan).
        $user = auth_user();
        $pelangganId = (int) ($user['pelanggan_id'] ?? 0);

        $this->renderRole('pelanggan/dashboard', 'pelanggan', 'dashboard', 'Dashboard Pelanggan', [
            ['label' => 'Pelanggan'],
            ['label' => 'Dashboard'],
        ], DashboardService::pelanggan($pelangganId));
    }

    public function buat(): void
    {
        $pelangganId = (int) (auth_user()['pelanggan_id'] ?? 0);

        $this->renderRole('pelanggan/buat', 'pelanggan', 'buat', 'Buat Pengaduan', [
            ['label' => 'Pelanggan', 'url' => url('/pelanggan/dashboard')],
            ['label' => 'Buat Pengaduan'],
        ], [
            'profil' => $this->profilDariSession(),
            'kategori' => Kategori::allActive(),
            'aktif' => Pengaduan::activeForPelanggan($pelangganId),
            'old' => Session::pullInput(),
            'flash_error' => flash_get('error'),
        ]);
    }

    public function simpan(): void
    {
        $user = auth_user();
        if ($user === null) {
            redirect('/login');
            return;
        }

        // Bila ukuran request melebihi post_max_size, PHP mengosongkan $_POST
        // dan $_FILES. Tanpa deteksi ini pengguna hanya melihat pesan "wajib
        // diunggah" yang menyesatkan, padahal berkasnya terlalu besar.
        if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            flash_set('error', 'Berkas terlalu besar sehingga formulir tidak terkirim. Maksimal 5 MB per foto.');
            redirect('/pelanggan/pengaduan/baru');
            return;
        }

        // 1) Validasi CSRF.
        if (!csrf_verify(isset($_POST['_token']) ? (string) $_POST['_token'] : null)) {
            render_error('Sesi formulir tidak valid', 'Token keamanan (CSRF) tidak cocok. Silakan muat ulang formulir.', 419);
            return;
        }

        // 2) Kumpulkan input (kategori, deskripsi, whatsapp) dan berkas.
        $input = [
            'nomor_whatsapp' => (string) ($_POST['nomor_whatsapp'] ?? ''),
            'kategori_id' => (int) ($_POST['kategori_id'] ?? 0),
            'deskripsi' => (string) ($_POST['deskripsi'] ?? ''),
        ];
        $files = Upload::normalizeFiles(isset($_FILES['foto']) && is_array($_FILES['foto']) ? $_FILES['foto'] : null);

        try {
            $created = PengaduanService::createForPelanggan($user, $input, $files);
        } catch (ValidationException $exception) {
            Session::flashInput($input);
            flash_set('error', $exception->errorsAsString());
            redirect('/pelanggan/pengaduan/baru');
            return;
        } catch (Throwable $exception) {
            app_log('Gagal menyimpan pengaduan: ' . $exception->getMessage());
            flash_set('error', 'Pengaduan gagal disimpan. Silakan coba beberapa saat lagi.');
            redirect('/pelanggan/pengaduan/baru');
            return;
        }

        flash_set('sukses', 'Pengaduan berhasil dikirim. Nomor tiket Anda: ' . $created['nomor_tiket'] . '.');
        redirect('/pelanggan/pengaduan/' . $created['id']);
    }

    public function riwayat(): void
    {
        $pelangganId = (int) (auth_user()['pelanggan_id'] ?? 0);

        $filters = [
            'q' => trim((string) ($_GET['q'] ?? '')),
            'status' => (string) ($_GET['status'] ?? ''),
            'date_from' => (string) ($_GET['date_from'] ?? ''),
            'date_to' => (string) ($_GET['date_to'] ?? ''),
        ];
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $result = Pengaduan::paginateForPelanggan($pelangganId, $filters, $page, PengaduanService::PER_HALAMAN);

        $this->renderRole('pelanggan/riwayat', 'pelanggan', 'riwayat', 'Riwayat Pengaduan', [
            ['label' => 'Pelanggan', 'url' => url('/pelanggan/dashboard')],
            ['label' => 'Riwayat Pengaduan'],
        ], [
            'items' => $result['rows'],
            'total' => $result['total'],
            'pages' => $result['pages'],
            'page' => $result['page'],
            'filters' => $filters,
            'flash_sukses' => flash_get('sukses'),
            'flash_error' => flash_get('error'),
        ]);
    }

    /**
     * Detail satu tiket milik pelanggan yang sedang masuk.
     *
     * @param array<string, string> $params Parameter route (id).
     */
    public function detail(array $params = []): void
    {
        $pelangganId = (int) (auth_user()['pelanggan_id'] ?? 0);
        $id = (int) ($params['id'] ?? 0);

        if ($id <= 0) {
            render_error('Tiket tidak ditemukan', 'Nomor tiket tidak valid.', 404);
            return;
        }

        $pengaduan = Pengaduan::findById($id);
        if ($pengaduan === null) {
            render_error('Tiket tidak ditemukan', 'Tiket yang Anda cari tidak tersedia.', 404);
            return;
        }

        // Isolasi hak akses: hanya pemilik tiket yang boleh membukanya.
        if ((int) $pengaduan['pelanggan_id'] !== $pelangganId) {
            render_forbidden('Anda tidak berhak membuka tiket ini.');
            return;
        }

        $laporan = LaporanTeknisi::findByPengaduan($id);

        $this->renderRole('pelanggan/tiket', 'pelanggan', 'riwayat', 'Detail Tiket ' . $pengaduan['nomor_tiket'], [
            ['label' => 'Pelanggan', 'url' => url('/pelanggan/dashboard')],
            ['label' => 'Riwayat Pengaduan', 'url' => url('/pelanggan/pengaduan')],
            ['label' => $pengaduan['nomor_tiket']],
        ], [
            'pengaduan' => $pengaduan,
            'fotos' => Pengaduan::fotosFor($id),
            'riwayat' => Pengaduan::riwayatFor($id),
            'teknisi' => Pengaduan::assignedTeknisiFor($id),
            'laporan' => $laporan,
            'laporanFotos' => is_array($laporan) ? LaporanTeknisi::fotosFor((int) $laporan['id']) : [],
            'penyelesaian' => PenyelesaianCs::forPengaduan($id),
            'rating' => Rating::forPengaduan($id),
            'flash_sukses' => flash_get('sukses'),
            'flash_error' => flash_get('error'),
            'csrf' => csrf_token(),
        ]);
    }

    /** Rute lama tanpa ID diarahkan ke daftar riwayat. */
    public function tiket(): void
    {
        redirect('/pelanggan/pengaduan');
    }

    public function profil(): void
    {
        $user = auth_user();
        if ($user === null) {
            redirect('/login');
            return;
        }

        // Identitas pelanggan dari relasi terverifikasi (users.id -> pelanggan.user_id),
        // bukan dari input browser dan bukan data contoh/demo.
        $pelanggan = Pelanggan::findByUserId((int) $user['id']);
        if ($pelanggan === null) {
            // Relasi tidak konsisten: JANGAN tampilkan data pelanggan lain.
            app_log('Profil: data pelanggan tidak ditemukan untuk user ' . (int) $user['id'] . '.');
            render_error(
                'Profil tidak tersedia',
                'Data pelanggan untuk akun Anda tidak ditemukan. Silakan hubungi Administrator.',
                409
            );
            return;
        }

        $verification = PelangganWhatsAppVerificationService::panelState($pelanggan);
        $verification['auto_open'] = !$verification['verified']
            && (string) ($_GET['otp'] ?? '') === '1';

        $change = PelangganWhatsAppVerificationService::changeState($pelanggan);
        $change['auto_open'] = (string) ($_GET['ganti_nomor'] ?? '') === '1';

        $this->renderRole('pelanggan/profil', 'pelanggan', 'profil', 'Profil Saya', [
            ['label' => 'Pelanggan', 'url' => url('/pelanggan/dashboard')],
            ['label' => 'Profil'],
        ], [
            'profil' => [
                'nama' => (string) ($user['nama'] ?? ''),
                'username_pppoe' => (string) $pelanggan['username_pppoe'],
                'nomor_whatsapp' => (string) $pelanggan['nomor_whatsapp'],
                'alamat' => (string) ($pelanggan['alamat'] ?? ''),
            ],
            'whatsapp_verification' => $verification,
            'whatsapp_change' => $change,
            'ganti_nomor_old' => Session::pullInput(),
            'flash_sukses' => flash_get('sukses'),
            'flash_error' => flash_get('error'),
        ]);
    }

    /**
     * Simpan perubahan profil pelanggan (nama saja).
     *
     * Nomor WhatsApp TIDAK dapat diubah dari formulir ini. Perubahan nomor
     * wajib melalui alur verifikasi OTP. Formulir lama yang masih mengirim
     * field nomor_whatsapp hanya diterima bila nilainya sama dengan nomor
     * aktif; nilai berbeda ditolak agar tidak ada jalur bypass.
     *
     * Hanya data milik pengguna yang sedang masuk yang diubah. ID pengguna dan
     * ID pelanggan selalu berasal dari session, bukan dari formulir.
     */
    public function profilSimpan(): void
    {
        if (!csrf_verify(isset($_POST['_token']) ? (string) $_POST['_token'] : null)) {
            render_error('Sesi formulir tidak valid', 'Token keamanan (CSRF) tidak cocok. Silakan muat ulang halaman.', 419);
            return;
        }

        $user = auth_user();
        if ($user === null) {
            redirect('/login');
            return;
        }

        $userId = (int) $user['id'];
        $pelanggan = Pelanggan::findByUserId($userId);
        if ($pelanggan === null) {
            app_log('Profil simpan: data pelanggan tidak ditemukan untuk user ' . $userId . '.');
            flash_set('error', 'Data pelanggan untuk akun Anda tidak ditemukan. Hubungi Administrator.');
            redirect('/pelanggan/profil');
            return;
        }

        $nama = trim((string) ($_POST['nama'] ?? ''));
        $postedPhonePresent = array_key_exists('nomor_whatsapp', $_POST);
        $postedPhoneRaw = $postedPhonePresent && is_string($_POST['nomor_whatsapp'])
            ? trim((string) $_POST['nomor_whatsapp'])
            : null;

        $errors = [];
        if ($nama === '') {
            $errors[] = 'Nama lengkap wajib diisi.';
        } elseif (mb_strlen($nama) > 120) {
            $errors[] = 'Nama maksimal 120 karakter.';
        }

        if ($postedPhonePresent) {
            $currentPhone = whatsapp_number((string) ($pelanggan['nomor_whatsapp'] ?? ''));
            $postedPhone = $postedPhoneRaw === null ? null : whatsapp_number($postedPhoneRaw);
            if ($currentPhone === null
                || $postedPhone === null
                || !hash_equals($currentPhone, $postedPhone)
            ) {
                $errors[] = 'Nomor WhatsApp tidak dapat diubah langsung dari halaman Profil. Perubahan nomor harus melalui verifikasi OTP.';
            }
        }

        if (!empty($errors)) {
            flash_set('error', implode(' ', $errors));
            redirect('/pelanggan/profil');
            return;
        }

        try {
            Database::transaction(static function () use ($userId, $pelanggan, $nama): void {
                User::updateName($userId, $nama);
                AuditLog::record($userId, 'profil_diperbarui', 'users', $userId, [
                    'username_pppoe' => (string) $pelanggan['username_pppoe'],
                    'nomor_whatsapp_diubah' => false,
                ]);
            });
            flash_set('sukses', 'Nama profil berhasil diperbarui. Nomor WhatsApp tidak diubah dari halaman ini.');
        } catch (Throwable $exception) {
            app_log('Gagal memperbarui profil user ' . $userId . ': ' . $exception->getMessage());
            flash_set('error', 'Profil gagal diperbarui. Silakan coba beberapa saat lagi.');
        }

        redirect('/pelanggan/profil');
    }

    /**
     * Minta OTP verifikasi nomor WhatsApp utama.
     *
     * Nomor selalu diambil dari baris pelanggan di database berdasarkan
     * session. Endpoint tidak menerima nomor dari browser.
     */
    public function whatsappOtpKirim(): void
    {
        if (!csrf_verify(isset($_POST['_token']) ? (string) $_POST['_token'] : null)) {
            render_error('Sesi formulir tidak valid', 'Token keamanan (CSRF) tidak cocok. Silakan muat ulang halaman.', 419);
            return;
        }

        $user = auth_user();
        if ($user === null) {
            redirect('/login');
            return;
        }

        $pelanggan = Pelanggan::findByUserId((int) $user['id']);
        if ($pelanggan === null) {
            flash_set('error', 'Data pelanggan untuk akun Anda tidak ditemukan. Hubungi Administrator.');
            redirect('/pelanggan/profil');
            return;
        }

        $result = PelangganWhatsAppVerificationService::request($user, $pelanggan);
        if (!empty($result['ok'])) {
            flash_set('sukses', (string) $result['message']);
            redirect('/pelanggan/profil?otp=1');
            return;
        }

        $message = (string) ($result['message'] ?? 'Kode OTP gagal dikirim.');
        $retryAfter = (int) ($result['retry_after'] ?? 0);
        if ($retryAfter > 0) {
            $message .= ' Coba lagi dalam ' . $retryAfter . ' detik.';
        }
        flash_set('error', $message);

        $keepOtpState = in_array((string) ($result['code'] ?? ''), ['cooldown', 'verification_failed'], true);
        redirect($keepOtpState ? '/pelanggan/profil?otp=1' : '/pelanggan/profil');
    }

    /**
     * Verifikasi OTP nomor WhatsApp utama.
     */
    public function whatsappOtpVerifikasi(): void
    {
        if (!csrf_verify(isset($_POST['_token']) ? (string) $_POST['_token'] : null)) {
            render_error('Sesi formulir tidak valid', 'Token keamanan (CSRF) tidak cocok. Silakan muat ulang halaman.', 419);
            return;
        }

        $user = auth_user();
        if ($user === null) {
            redirect('/login');
            return;
        }

        $pelanggan = Pelanggan::findByUserId((int) $user['id']);
        if ($pelanggan === null) {
            flash_set('error', 'Data pelanggan untuk akun Anda tidak ditemukan. Hubungi Administrator.');
            redirect('/pelanggan/profil');
            return;
        }

        $code = is_string($_POST['kode'] ?? null) ? (string) $_POST['kode'] : '';
        $result = PelangganWhatsAppVerificationService::verify($user, $pelanggan, $code);

        if (!empty($result['ok'])) {
            flash_set('sukses', 'Nomor WhatsApp berhasil diverifikasi.');
            redirect('/pelanggan/profil');
            return;
        }

        flash_set('error', (string) ($result['message'] ?? 'Kode OTP tidak valid.'));
        redirect('/pelanggan/profil?otp=1');
    }

    /**
     * Minta OTP ganti nomor ke nomor WhatsApp baru.
     *
     * Nomor baru hanya dipakai sebagai tujuan OTP; nomor utama belum diubah.
     * Password WiFiCare diverifikasi sebelum permintaan diproses.
     */
    public function whatsappGantiNomorKirim(): void
    {
        if (!csrf_verify(isset($_POST['_token']) ? (string) $_POST['_token'] : null)) {
            render_error('Sesi formulir tidak valid', 'Token keamanan (CSRF) tidak cocok. Silakan muat ulang halaman.', 419);
            return;
        }

        $user = auth_user();
        if ($user === null) {
            redirect('/login');
            return;
        }

        $pelanggan = Pelanggan::findByUserId((int) $user['id']);
        if ($pelanggan === null) {
            flash_set('error', 'Data pelanggan untuk akun Anda tidak ditemukan. Hubungi Administrator.');
            redirect('/pelanggan/profil');
            return;
        }

        $newPhone = is_string($_POST['nomor_whatsapp_baru'] ?? null)
            ? trim((string) $_POST['nomor_whatsapp_baru'])
            : '';
        $password = is_string($_POST['password'] ?? null) ? (string) $_POST['password'] : '';

        $result = PelangganWhatsAppVerificationService::requestChange($user, $pelanggan, $newPhone, $password);
        if (!empty($result['ok'])) {
            flash_set(
                'sukses',
                (string) ($result['message'] ?? 'Permintaan pengiriman OTP diterima provider.')
                . ' Nomor lama tetap aktif sampai kode OTP diverifikasi.'
            );
            redirect('/pelanggan/profil?ganti_nomor=1');
            return;
        }

        $message = (string) ($result['message'] ?? 'Kode OTP gagal dikirim.');
        $retryAfter = (int) ($result['retry_after'] ?? 0);
        if ($retryAfter > 0) {
            $message .= ' Coba lagi dalam ' . $retryAfter . ' detik.';
        }
        flash_set('error', $message);
        Session::flashInput(['nomor_whatsapp_baru' => $newPhone]);
        redirect('/pelanggan/profil?ganti_nomor=1');
    }

    /**
     * Kirim ulang OTP ke nomor baru yang sudah diverifikasi password.
     */
    public function whatsappGantiNomorKirimUlang(): void
    {
        if (!csrf_verify(isset($_POST['_token']) ? (string) $_POST['_token'] : null)) {
            render_error('Sesi formulir tidak valid', 'Token keamanan (CSRF) tidak cocok. Silakan muat ulang halaman.', 419);
            return;
        }

        $user = auth_user();
        if ($user === null) {
            redirect('/login');
            return;
        }

        $pelanggan = Pelanggan::findByUserId((int) $user['id']);
        if ($pelanggan === null) {
            flash_set('error', 'Data pelanggan untuk akun Anda tidak ditemukan. Hubungi Administrator.');
            redirect('/pelanggan/profil');
            return;
        }

        $result = PelangganWhatsAppVerificationService::resendChange($user, $pelanggan);
        if (!empty($result['ok'])) {
            flash_set(
                'sukses',
                (string) ($result['message'] ?? 'Permintaan pengiriman OTP diterima provider.')
                . ' Nomor lama tetap aktif sampai kode OTP diverifikasi.'
            );
            redirect('/pelanggan/profil?ganti_nomor=1');
            return;
        }

        $message = (string) ($result['message'] ?? 'Kode OTP gagal dikirim.');
        $retryAfter = (int) ($result['retry_after'] ?? 0);
        if ($retryAfter > 0) {
            $message .= ' Coba lagi dalam ' . $retryAfter . ' detik.';
        }
        flash_set('error', $message);
        redirect('/pelanggan/profil?ganti_nomor=1');
    }

    /**
     * Verifikasi OTP nomor baru lalu simpan nomor secara atomik.
     */
    public function whatsappGantiNomorVerifikasi(): void
    {
        if (!csrf_verify(isset($_POST['_token']) ? (string) $_POST['_token'] : null)) {
            render_error('Sesi formulir tidak valid', 'Token keamanan (CSRF) tidak cocok. Silakan muat ulang halaman.', 419);
            return;
        }

        $user = auth_user();
        if ($user === null) {
            redirect('/login');
            return;
        }

        $pelanggan = Pelanggan::findByUserId((int) $user['id']);
        if ($pelanggan === null) {
            flash_set('error', 'Data pelanggan untuk akun Anda tidak ditemukan. Hubungi Administrator.');
            redirect('/pelanggan/profil');
            return;
        }

        $code = is_string($_POST['kode'] ?? null) ? (string) $_POST['kode'] : '';
        $result = PelangganWhatsAppVerificationService::verifyChange($user, $pelanggan, $code);
        if (!empty($result['ok'])) {
            flash_set('sukses', 'Nomor WhatsApp berhasil diganti dan diverifikasi.');
            redirect('/pelanggan/profil');
            return;
        }

        flash_set('error', (string) ($result['message'] ?? 'Kode OTP tidak valid.'));
        $change = PelangganWhatsAppVerificationService::changeState($pelanggan);
        redirect(!empty($change['pending']) ? '/pelanggan/profil?ganti_nomor=1' : '/pelanggan/profil');
    }

    /**
     * Batalkan permintaan ganti nomor dan OTP nomor baru.
     */
    public function whatsappGantiNomorBatal(): void
    {
        if (!csrf_verify(isset($_POST['_token']) ? (string) $_POST['_token'] : null)) {
            render_error('Sesi formulir tidak valid', 'Token keamanan (CSRF) tidak cocok. Silakan muat ulang halaman.', 419);
            return;
        }

        $user = auth_user();
        if ($user === null) {
            redirect('/login');
            return;
        }

        $pelanggan = Pelanggan::findByUserId((int) $user['id']);
        if ($pelanggan === null) {
            flash_set('error', 'Data pelanggan untuk akun Anda tidak ditemukan. Hubungi Administrator.');
            redirect('/pelanggan/profil');
            return;
        }

        $result = PelangganWhatsAppVerificationService::cancelChange($user, $pelanggan);
        flash_set(!empty($result['ok']) ? 'sukses' : 'error', (string) ($result['message'] ?? 'Permintaan ganti nomor dibatalkan.'));
        redirect('/pelanggan/profil');
    }

    /**
     * Ganti password WiFiCare milik pelanggan yang sedang masuk.
     */
    public function passwordSimpan(): void
    {
        if (!csrf_verify(isset($_POST['_token']) ? (string) $_POST['_token'] : null)) {
            render_error('Sesi formulir tidak valid', 'Token keamanan (CSRF) tidak cocok. Silakan muat ulang halaman.', 419);
            return;
        }

        $user = auth_user();
        if ($user === null) {
            redirect('/login');
            return;
        }

        $userId = (int) $user['id'];
        $target = User::findById($userId);
        if ($target === null) {
            redirect('/login');
            return;
        }

        $current = (string) ($_POST['password_lama'] ?? '');
        $baru = (string) ($_POST['password_baru'] ?? '');
        $konfirmasi = (string) ($_POST['password_konfirmasi'] ?? '');

        $errors = [];
        if ($current === '' || !password_verify($current, (string) $target['password_hash'])) {
            $errors[] = 'Password saat ini tidak sesuai.';
        }
        if (mb_strlen($baru) < 8) {
            $errors[] = 'Password baru minimal 8 karakter.';
        } elseif (!preg_match('/[A-Za-z]/', $baru) || !preg_match('/[0-9]/', $baru)) {
            $errors[] = 'Password baru harus memuat huruf dan angka.';
        }
        if ($baru !== $konfirmasi) {
            $errors[] = 'Konfirmasi password baru tidak sama.';
        }

        if (!empty($errors)) {
            flash_set('error', implode(' ', $errors));
            redirect('/pelanggan/profil');
            return;
        }

        try {
            User::updatePassword($userId, password_hash($baru, PASSWORD_DEFAULT));
            AuditLog::record($userId, 'password_diubah', 'users', $userId, ['oleh' => 'pelanggan']);
            flash_set('sukses', 'Password WiFiCare berhasil diperbarui.');
        } catch (Throwable $exception) {
            app_log('Gagal mengubah password user ' . $userId . ': ' . $exception->getMessage());
            flash_set('error', 'Password gagal diperbarui. Silakan coba beberapa saat lagi.');
        }

        redirect('/pelanggan/profil');
    }

    public function rating(): void
    {
        if (!rating_layanan_enabled()) {
            render_error(
                'Fitur tidak tersedia',
                'Rating Layanan sedang dinonaktifkan sementara. Riwayat pengaduan tetap dapat Anda akses seperti biasa.',
                404
            );
            return;
        }

        $pelangganId = (int) (auth_user()['pelanggan_id'] ?? 0);

        $this->renderRole('pelanggan/rating', 'pelanggan', 'rating', 'Rating Layanan', [
            ['label' => 'Pelanggan', 'url' => url('/pelanggan/dashboard')],
            ['label' => 'Rating Layanan'],
        ], [
            'tickets' => Pengaduan::completedForPelanggan($pelangganId),
            'flash_sukses' => flash_get('sukses'),
            'flash_error' => flash_get('error'),
            'csrf' => csrf_token(),
        ]);
    }

    /**
     * Simpan rating pelanggan untuk tiket selesai miliknya.
     *
     * @param array<string, string> $params
     */
    public function ratingSimpan(array $params = []): void
    {
        if (!rating_layanan_enabled()) {
            render_error(
                'Fitur tidak tersedia',
                'Rating Layanan sedang dinonaktifkan sementara. Data rating lama tetap tersimpan.',
                404
            );
            return;
        }

        $id = (int) ($params['id'] ?? 0);

        if (!csrf_verify(isset($_POST['_token']) ? (string) $_POST['_token'] : null)) {
            render_error('Sesi formulir tidak valid', 'Token keamanan (CSRF) tidak cocok. Silakan muat ulang halaman.', 419);
            return;
        }

        $actor = auth_user();
        if ($actor === null) {
            redirect('/login');
            return;
        }

        try {
            RatingService::submit($actor, $id, [
                'rating' => (int) ($_POST['rating'] ?? 0),
                'ulasan' => (string) ($_POST['ulasan'] ?? ''),
            ]);
            flash_set('sukses', 'Terima kasih! Rating Anda telah tersimpan.');
        } catch (ValidationException $exception) {
            flash_set('error', $exception->errorsAsString());
        } catch (Throwable $exception) {
            app_log('Gagal menyimpan rating (pengaduan ' . $id . '): ' . $exception->getMessage());
            flash_set('error', 'Rating gagal disimpan. Silakan coba beberapa saat lagi.');
        }

        redirect($id > 0 ? '/pelanggan/pengaduan/' . $id : '/pelanggan/rating');
    }

    public function ai(): void
    {
        // AI Assistant kini tersedia untuk semua peran pada rute /ai (Tahap 9).
        redirect('/ai');
    }
}
