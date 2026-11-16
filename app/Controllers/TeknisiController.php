<?php
/**
 * Controller area Teknisi (Tahap 7).
 *
 * Halaman baca (dashboard, daftar tugas, detail tugas, form laporan, riwayat)
 * dan aksi tulis (mulai penanganan, kirim laporan perbaikan).
 *
 * - Identitas teknisi SELALU diambil dari session (bukan parameter URL).
 * - Setiap akses tiket divalidasi kepemilikannya di backend (level query).
 * - Aksi tulis: controller → PengaduanFlowService (transisi + transaksi).
 * - Field rahasia (mis. catatan internal CS) tidak pernah dimuat ke halaman.
 */

declare(strict_types=1);

require_once __DIR__ . '/Controller.php';

final class TeknisiController extends Controller
{
    /** Jumlah baris per halaman pada daftar/riwayat teknisi. */
    private const PER_HALAMAN = 10;

    /* ---------------------------------------------------------------------
     * Halaman baca
     * ------------------------------------------------------------------- */

    public function dashboard(): void
    {
        $teknisiId = $this->currentTeknisiId();

        $data = DashboardService::teknisi($teknisiId);
        $data['includeCharts'] = true;

        $this->renderRole('teknisi/dashboard', 'teknisi', 'dashboard', 'Dashboard Teknisi', [
            ['label' => 'Teknisi'],
            ['label' => 'Dashboard'],
        ], $data);
    }

    public function tugas(): void
    {
        $teknisiId = $this->currentTeknisiId();
        $filters = $this->readFilters();
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $result = Penugasan::paginateForTeknisi($teknisiId, $filters, $page, self::PER_HALAMAN);

        $this->renderRole('teknisi/tugas', 'teknisi', 'tugas', 'Tugas Saya', [
            ['label' => 'Teknisi', 'url' => url('/teknisi/dashboard')],
            ['label' => 'Tugas Saya'],
        ], [
            'items' => $result['rows'],
            'total' => $result['total'],
            'pages' => $result['pages'],
            'page' => $result['page'],
            'filters' => $filters,
            'kategoriOptions' => Kategori::allActive(),
            'flash_sukses' => flash_get('sukses'),
            'flash_error' => flash_get('error'),
        ]);
    }

    /**
     * Detail tugas milik teknisi yang sedang masuk.
     *
     * @param array<string, string> $params
     */
    public function detail(array $params = []): void
    {
        $teknisiId = $this->currentTeknisiId();
        $id = (int) ($params['id'] ?? 0);

        $pengaduan = $this->resolveTugas($id, $teknisiId);
        if ($pengaduan === null) {
            return;
        }

        $laporan = LaporanTeknisi::findByPengaduan($id);
        $laporanFotos = is_array($laporan) ? LaporanTeknisi::fotosFor((int) $laporan['id']) : [];

        $this->renderRole('teknisi/detail', 'teknisi', 'tugas', 'Detail Tugas ' . $pengaduan['nomor_tiket'], [
            ['label' => 'Teknisi', 'url' => url('/teknisi/dashboard')],
            ['label' => 'Tugas Saya', 'url' => url('/teknisi/tugas')],
            ['label' => (string) $pengaduan['nomor_tiket']],
        ], [
            'pengaduan' => $pengaduan,
            'penugasanAktif' => Penugasan::activeForPengaduan($id),
            'fotos' => Pengaduan::fotosFor($id),
            'riwayat' => Pengaduan::riwayatFor($id),
            'laporan' => $laporan,
            'laporanFotos' => $laporanFotos,
            'teknisiAllowedTargets' => PengaduanFlowService::teknisiAllowedTargets((string) $pengaduan['status']),
            'flash_sukses' => flash_get('sukses'),
            'flash_error' => flash_get('error'),
        ]);
    }

    /**
     * Form laporan perbaikan (hanya saat status Dalam Penanganan).
     *
     * @param array<string, string> $params
     */
    public function laporanForm(array $params = []): void
    {
        $teknisiId = $this->currentTeknisiId();
        $id = (int) ($params['id'] ?? 0);

        $pengaduan = $this->resolveTugas($id, $teknisiId);
        if ($pengaduan === null) {
            return;
        }

        if ((string) $pengaduan['status'] !== 'dalam_penanganan') {
            flash_set('error', 'Laporan hanya dapat diisi saat tiket berstatus Dalam Penanganan.');
            redirect('/teknisi/tugas/' . $id);
            return;
        }
        if (LaporanTeknisi::existsForPengaduan($id)) {
            flash_set('error', 'Laporan untuk tiket ini sudah pernah dikirim.');
            redirect('/teknisi/tugas/' . $id);
            return;
        }

        $this->renderRole('teknisi/laporan', 'teknisi', 'tugas', 'Form Laporan Perbaikan', [
            ['label' => 'Teknisi', 'url' => url('/teknisi/dashboard')],
            ['label' => 'Tugas Saya', 'url' => url('/teknisi/tugas')],
            ['label' => 'Laporan ' . $pengaduan['nomor_tiket']],
        ], [
            'pengaduan' => $pengaduan,
            'penugasanAktif' => Penugasan::activeForPengaduan($id),
            'old' => Session::pullInput(),
            'flash_error' => flash_get('error'),
        ]);
    }

    public function riwayat(): void
    {
        $teknisiId = $this->currentTeknisiId();
        $filters = $this->readFilters();
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $result = LaporanTeknisi::paginateHistoryForTeknisi($teknisiId, $filters, $page, self::PER_HALAMAN);

        $this->renderRole('teknisi/riwayat', 'teknisi', 'riwayat', 'Riwayat Pekerjaan', [
            ['label' => 'Teknisi', 'url' => url('/teknisi/dashboard')],
            ['label' => 'Riwayat Pekerjaan'],
        ], [
            'items' => $result['rows'],
            'total' => $result['total'],
            'pages' => $result['pages'],
            'page' => $result['page'],
            'filters' => $filters,
            'kategoriOptions' => Kategori::allActive(),
            'flash_sukses' => flash_get('sukses'),
            'flash_error' => flash_get('error'),
        ]);
    }

    /* ---------------------------------------------------------------------
     * Aksi tulis (POST) — CSRF + RBAC (middleware) + validasi kepemilikan
     * ------------------------------------------------------------------- */

    /**
     * Mulai penanganan: Menunggu Teknisi → Dalam Penanganan.
     *
     * @param array<string, string> $params
     */
    public function mulai(array $params = []): void
    {
        $teknisiId = $this->currentTeknisiId();
        $id = (int) ($params['id'] ?? 0);

        if (!$this->assertTugasMilikSaya($id, $teknisiId)) {
            return;
        }

        $this->act($id, static function (array $actor) use ($id): void {
            PengaduanFlowService::mulaiPenanganan($actor, $id);
        }, 'Penanganan dimulai. Status tiket berubah menjadi Dalam Penanganan.');
    }

    /**
     * Kirim laporan perbaikan: Dalam Penanganan → Selesai (laporan lengkap).
     *
     * @param array<string, string> $params
     */
    public function laporanSimpan(array $params = []): void
    {
        $teknisiId = $this->currentTeknisiId();
        $id = (int) ($params['id'] ?? 0);

        if (!$this->assertTugasMilikSaya($id, $teknisiId)) {
            return;
        }

        $input = [
            'penyebab_gangguan' => (string) ($_POST['penyebab_gangguan'] ?? ''),
            'tindakan_perbaikan' => (string) ($_POST['tindakan_perbaikan'] ?? ''),
            'catatan' => (string) ($_POST['catatan'] ?? ''),
        ];
        $files = Upload::normalizeFiles(isset($_FILES['foto']) && is_array($_FILES['foto']) ? $_FILES['foto'] : null);

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
            PengaduanFlowService::selesaikanDenganLaporan($actor, $id, $input, $files);
            flash_set('sukses', 'Laporan perbaikan tersimpan. Tiket otomatis berstatus Selesai.');
        } catch (ValidationException $exception) {
            Session::flashInput($input);
            flash_set('error', $exception->errorsAsString());
            // Kembali ke form agar pengguna dapat memperbaiki input.
            redirect('/teknisi/tugas/' . $id . '/laporan');
            return;
        } catch (Throwable $exception) {
            app_log('Gagal menyimpan laporan teknisi (pengaduan ' . $id . '): ' . $exception->getMessage());
            Session::flashInput($input);
            flash_set('error', 'Laporan gagal disimpan. Status tiket tidak berubah. Silakan coba lagi.');
            redirect('/teknisi/tugas/' . $id . '/laporan');
            return;
        }

        redirect('/teknisi/tugas/' . $id);
    }

    /* ---------------------------------------------------------------------
     * Rute lama (kompatibilitas) — diarahkan ke daftar tugas.
     * ------------------------------------------------------------------- */

    public function tugasDetailLegacy(): void
    {
        redirect('/teknisi/tugas');
    }

    public function laporan(): void
    {
        redirect('/teknisi/tugas');
    }

    /* ---------------------------------------------------------------------
     * Pembantu
     * ------------------------------------------------------------------- */

    /** ID teknisi dari session. */
    private function currentTeknisiId(): int
    {
        return (int) (auth_user()['id'] ?? 0);
    }

    /**
     * Ambil tiket yang berhak diakses teknisi; render 404/403 bila tidak.
     *
     * @return array<string, mixed>|null
     */
    private function resolveTugas(int $id, int $teknisiId): ?array
    {
        if ($id <= 0) {
            render_error('Tiket tidak ditemukan', 'Nomor tiket tidak valid.', 404);
            return null;
        }

        if (Pengaduan::findById($id) === null) {
            render_error('Tiket tidak ditemukan', 'Tiket yang Anda cari tidak tersedia.', 404);
            return null;
        }

        $tugas = Pengaduan::findForTeknisi($id, $teknisiId);
        if ($tugas === null) {
            render_forbidden('Tiket ini bukan tugas aktif Anda.');
            return null;
        }

        return $tugas;
    }

    /**
     * Pastikan tiket adalah tugas aktif teknisi ini (untuk aksi POST).
     */
    private function assertTugasMilikSaya(int $id, int $teknisiId): bool
    {
        if ($id <= 0 || Pengaduan::findById($id) === null) {
            render_error('Tiket tidak ditemukan', 'Nomor tiket tidak valid.', 404);
            return false;
        }

        if (Penugasan::activeForTeknisi($id, $teknisiId) === null) {
            render_forbidden('Tiket ini bukan tugas aktif Anda.');
            return false;
        }

        return true;
    }

    /**
     * Baca filter daftar/riwayat dari query string (server-side).
     *
     * @return array<string, mixed>
     */
    private function readFilters(): array
    {
        return [
            'q' => trim((string) ($_GET['q'] ?? '')),
            'status' => (string) ($_GET['status'] ?? ''),
            'kategori_id' => (int) ($_GET['kategori_id'] ?? 0),
            'date_from' => (string) ($_GET['date_from'] ?? ''),
            'date_to' => (string) ($_GET['date_to'] ?? ''),
        ];
    }

    /**
     * Jalankan aksi tulis (tanpa berkas) dengan CSRF + penanganan kesalahan seragam.
     *
     * @param callable(array<string, mixed>): void $handler
     */
    private function act(int $pengaduanId, callable $handler, string $successMessage): void
    {
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
            $handler($actor);
            flash_set('sukses', $successMessage);
        } catch (ValidationException $exception) {
            flash_set('error', $exception->errorsAsString());
        } catch (Throwable $exception) {
            app_log('Aksi teknisi gagal (pengaduan ' . $pengaduanId . '): ' . $exception->getMessage());
            flash_set('error', 'Tindakan gagal diproses. Silakan coba beberapa saat lagi.');
        }

        if ($pengaduanId > 0) {
            redirect('/teknisi/tugas/' . $pengaduanId);
        }
        redirect('/teknisi/tugas');
    }
}
