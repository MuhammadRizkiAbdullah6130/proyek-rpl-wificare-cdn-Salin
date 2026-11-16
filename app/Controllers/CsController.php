<?php
/**
 * Controller area Customer Service (Tahap 6).
 *
 * Halaman baca (daftar, detail, monitoring, riwayat pelanggan, catatan) dan
 * aksi tulis (verifikasi, tolak, selesaikan langsung, penugasan teknisi,
 * pembatalan, catatan internal).
 *
 * - Read  : controller → model (query terpusat di model).
 * - Write : controller → PengaduanFlowService (validasi transisi + transaksi).
 * - Identitas petugas SELALU diambil dari session, bukan dari input browser.
 */

declare(strict_types=1);

require_once __DIR__ . '/Controller.php';

final class CsController extends Controller
{
    /** Jumlah baris per halaman pada daftar CS. */
    private const PER_HALAMAN = 10;

    /* ---------------------------------------------------------------------
     * Halaman baca
     * ------------------------------------------------------------------- */

    public function dashboard(): void
    {
        $data = DashboardService::customerService();
        $data['includeCharts'] = true;

        $this->renderRole('cs/dashboard', 'cs', 'dashboard', 'Dashboard Customer Service', [
            ['label' => 'Customer Service'],
            ['label' => 'Dashboard'],
        ], $data);
    }

    public function pengaduan(): void
    {
        $filters = $this->readFilters();
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $result = Pengaduan::paginateForCs($filters, $page, self::PER_HALAMAN);

        $this->renderRole('cs/pengaduan', 'cs', 'masuk', 'Pengaduan Masuk', [
            ['label' => 'Customer Service', 'url' => url('/cs/dashboard')],
            ['label' => 'Pengaduan Masuk'],
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

    public function verifikasi(): void
    {
        $filters = $this->readFilters();
        $filters['status'] = 'menunggu_verifikasi';
        $filters['exclude_cancelled'] = true;
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $result = Pengaduan::paginateForCs($filters, $page, self::PER_HALAMAN);

        $this->renderRole('cs/verifikasi', 'cs', 'verifikasi', 'Verifikasi Pengaduan', [
            ['label' => 'Customer Service', 'url' => url('/cs/dashboard')],
            ['label' => 'Verifikasi Pengaduan'],
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

    public function penugasan(): void
    {
        $filters = $this->readFilters();
        $filters['status'] = 'diproses_cs';
        $filters['exclude_cancelled'] = true;
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $result = Pengaduan::paginateForCs($filters, $page, self::PER_HALAMAN);

        $this->renderRole('cs/penugasan', 'cs', 'penugasan', 'Penugasan Teknisi', [
            ['label' => 'Customer Service', 'url' => url('/cs/dashboard')],
            ['label' => 'Penugasan Teknisi'],
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

    public function monitoring(): void
    {
        $filters = $this->readFilters();
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $result = Pengaduan::paginateForCs($filters, $page, self::PER_HALAMAN);
        $distribution = Pengaduan::statusDistribution(true);

        $this->renderRole('cs/monitoring', 'cs', 'monitoring', 'Monitoring Pengaduan', [
            ['label' => 'Customer Service', 'url' => url('/cs/dashboard')],
            ['label' => 'Monitoring Pengaduan'],
        ], [
            'items' => $result['rows'],
            'total' => $result['total'],
            'pages' => $result['pages'],
            'page' => $result['page'],
            'filters' => $filters,
            'kategoriOptions' => Kategori::allActive(),
            'teknisiOptions' => User::activeByRole('teknisi'),
            'distribution' => $distribution,
            'cancelledCount' => Pengaduan::countCancelled(),
            'flash_sukses' => flash_get('sukses'),
            'flash_error' => flash_get('error'),
        ]);
    }

    public function pelanggan(): void
    {
        $filters = ['q' => trim((string) ($_GET['q'] ?? ''))];
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $result = Pelanggan::paginateForCs($filters, $page, self::PER_HALAMAN);

        $this->renderRole('cs/pelanggan', 'cs', 'pelanggan', 'Riwayat Pelanggan', [
            ['label' => 'Customer Service', 'url' => url('/cs/dashboard')],
            ['label' => 'Riwayat Pelanggan'],
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
     * Riwayat pengaduan satu pelanggan.
     *
     * @param array<string, string> $params
     */
    public function pelangganDetail(array $params = []): void
    {
        $pelangganId = (int) ($params['id'] ?? 0);
        if ($pelangganId <= 0) {
            render_error('Pelanggan tidak ditemukan', 'ID pelanggan tidak valid.', 404);
            return;
        }

        $pelanggan = Pelanggan::findForCs($pelangganId);
        if ($pelanggan === null) {
            render_error('Pelanggan tidak ditemukan', 'Data pelanggan yang Anda cari tidak tersedia.', 404);
            return;
        }

        $this->renderRole('cs/pelanggan_detail', 'cs', 'pelanggan', 'Riwayat Pelanggan ' . $pelanggan['nama'], [
            ['label' => 'Customer Service', 'url' => url('/cs/dashboard')],
            ['label' => 'Riwayat Pelanggan', 'url' => url('/cs/pelanggan')],
            ['label' => (string) $pelanggan['nama']],
        ], [
            'pelanggan' => $pelanggan,
            'tickets' => Pelanggan::ticketsForCs($pelangganId),
            'flash_sukses' => flash_get('sukses'),
            'flash_error' => flash_get('error'),
        ]);
    }

    /**
     * Detail tiket — pusat tindakan CS.
     *
     * @param array<string, string> $params
     */
    public function detail(array $params = []): void
    {
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

        $status = (string) $pengaduan['status'];

        $laporan = LaporanTeknisi::findByPengaduan($id);

        $this->renderRole('cs/detail', 'cs', 'masuk', 'Detail Pengaduan ' . $pengaduan['nomor_tiket'], [
            ['label' => 'Customer Service', 'url' => url('/cs/dashboard')],
            ['label' => 'Pengaduan Masuk', 'url' => url('/cs/pengaduan')],
            ['label' => (string) $pengaduan['nomor_tiket']],
        ], [
            'pengaduan' => $pengaduan,
            'fotos' => Pengaduan::fotosFor($id),
            'riwayat' => Pengaduan::riwayatFor($id),
            'penugasanAktif' => Penugasan::activeForPengaduan($id),
            'penugasanRiwayat' => Penugasan::historyForPengaduan($id),
            'laporan' => $laporan,
            'laporanFotos' => is_array($laporan) ? LaporanTeknisi::fotosFor((int) $laporan['id']) : [],
            'penyelesaian' => PenyelesaianCs::forPengaduan($id),
            'catatan' => CatatanCs::forPengaduan($id),
            'teknisiOptions' => User::activeByRole('teknisi'),
            'allowedTargets' => PengaduanFlowService::csAllowedTargets($status),
            'flash_sukses' => flash_get('sukses'),
            'flash_error' => flash_get('error'),
        ]);
    }

    public function catatan(): void
    {
        $this->renderRole('cs/catatan', 'cs', 'catatan', 'Catatan Internal', [
            ['label' => 'Customer Service', 'url' => url('/cs/dashboard')],
            ['label' => 'Catatan Internal'],
        ], [
            'notes' => CatatanCs::recent(100),
            'total' => CatatanCs::countAll(),
            'flash_sukses' => flash_get('sukses'),
            'flash_error' => flash_get('error'),
        ]);
    }

    /* ---------------------------------------------------------------------
     * Aksi tulis (POST) — semua memvalidasi CSRF + RBAC (middleware)
     * ------------------------------------------------------------------- */

    /** @param array<string, string> $params */
    public function terima(array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        $this->act($id, static function (array $actor) use ($id): void {
            PengaduanFlowService::terima($actor, $id);
        }, 'Pengaduan diterima. Status berubah menjadi Diproses CS.');
    }

    /** @param array<string, string> $params */
    public function tolak(array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        $reason = (string) ($_POST['alasan'] ?? '');
        $this->act($id, static function (array $actor) use ($id, $reason): void {
            PengaduanFlowService::tolak($actor, $id, $reason);
        }, 'Pengaduan ditolak.');
    }

    /** @param array<string, string> $params */
    public function selesaikan(array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        $solution = (string) ($_POST['solusi'] ?? '');
        $this->act($id, static function (array $actor) use ($id, $solution): void {
            PengaduanFlowService::selesaikan($actor, $id, $solution);
        }, 'Pengaduan diselesaikan langsung oleh CS.');
    }

    /** @param array<string, string> $params */
    public function tugaskan(array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        $teknisiId = (int) ($_POST['teknisi_id'] ?? 0);
        $note = (string) ($_POST['catatan'] ?? '');
        $this->act($id, static function (array $actor) use ($id, $teknisiId, $note): void {
            PengaduanFlowService::tugaskan($actor, $id, $teknisiId, $note);
        }, 'Teknisi berhasil ditugaskan. Status berubah menjadi Menunggu Teknisi.');
    }

    /** @param array<string, string> $params */
    public function batalkan(array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        $reason = (string) ($_POST['alasan'] ?? '');
        $this->act($id, static function (array $actor) use ($id, $reason): void {
            PengaduanFlowService::batalkan($actor, $id, $reason);
        }, 'Pengaduan dibatalkan.');
    }

    /** @param array<string, string> $params */
    public function catatanSimpan(array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        $catatan = (string) ($_POST['catatan'] ?? '');
        $this->act($id, static function (array $actor) use ($id, $catatan): void {
            PengaduanFlowService::tambahCatatan($actor, $id, $catatan);
        }, 'Catatan internal tersimpan.');
    }

    /* ---------------------------------------------------------------------
     * Pembantu
     * ------------------------------------------------------------------- */

    /**
     * Baca filter daftar CS dari query string (server-side).
     *
     * @return array<string, mixed>
     */
    private function readFilters(): array
    {
        return [
            'q' => trim((string) ($_GET['q'] ?? '')),
            'status' => (string) ($_GET['status'] ?? ''),
            'kategori_id' => (int) ($_GET['kategori_id'] ?? 0),
            'teknisi_id' => (int) ($_GET['teknisi_id'] ?? 0),
            'date_from' => (string) ($_GET['date_from'] ?? ''),
            'date_to' => (string) ($_GET['date_to'] ?? ''),
            'only_cancelled' => (string) ($_GET['cancel'] ?? '') === 'batal',
        ];
    }

    /**
     * Jalankan aksi tulis dengan CSRF + penanganan kesalahan yang seragam.
     *
     * Petugas diambil dari session di dalam helper ini.
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
            app_log('Aksi CS gagal (pengaduan ' . $pengaduanId . '): ' . $exception->getMessage());
            flash_set('error', 'Tindakan gagal diproses. Silakan coba beberapa saat lagi.');
        }

        $prefix = (string) (auth_role() ?? 'cs') === 'admin' ? '/admin/pengaduan' : '/cs/pengaduan';
        if ($pengaduanId > 0) {
            redirect($prefix . '/' . $pengaduanId);
        }
        redirect($prefix);
    }
}
