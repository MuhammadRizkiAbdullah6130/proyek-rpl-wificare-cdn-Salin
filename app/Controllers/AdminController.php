<?php
/**
 * Controller area Administrator — Dashboard, monitoring pengaduan, monitoring
 * teknisi, dan laporan (Tahap 8).
 *
 * Manajemen pengguna/pelanggan/kategori/pengaturan/audit ditangani controller
 * terpisah agar tetap terfokus.
 */

declare(strict_types=1);

require_once __DIR__ . '/AdminBaseController.php';

final class AdminController extends AdminBaseController
{
    private const PER_HALAMAN = 10;

    private const PER_TEKNISI = 10;

    public function dashboard(): void
    {
        $data = DashboardService::admin();
        $data['includeCharts'] = true;

        $this->renderRole('admin/dashboard', 'admin', 'dashboard', 'Dashboard Admin', [
            ['label' => 'Admin'],
            ['label' => 'Dashboard'],
        ], $data);
    }

    public function pengaduan(): void
    {
        $filters = $this->readFilters();
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $result = Pengaduan::paginateForAdmin($filters, $page, self::PER_HALAMAN);

        $this->renderRole('admin/pengaduan', 'admin', 'pengaduan', 'Semua Pengaduan', [
            ['label' => 'Admin', 'url' => url('/admin/dashboard')],
            ['label' => 'Semua Pengaduan'],
        ], [
            'items' => $result['rows'],
            'total' => $result['total'],
            'pages' => $result['pages'],
            'page' => $result['page'],
            'filters' => $filters,
            'kategoriOptions' => Kategori::allActive(),
            'teknisiOptions' => User::activeByRole('teknisi'),
            'flash_sukses' => flash_get('sukses'),
            'flash_error' => flash_get('error'),
            'csrf' => csrf_token(),
        ]);
    }

    /** @param array<string, string> $params */
    public function pengaduanDetail(array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        $pengaduan = $id > 0 ? Pengaduan::findForAdmin($id) : null;
        if ($pengaduan === null) {
            render_error('Tiket tidak ditemukan', 'Tiket yang Anda cari tidak tersedia.', 404);
            return;
        }

        $laporan = LaporanTeknisi::findByPengaduan($id);

        $this->renderRole('admin/pengaduan_detail', 'admin', 'pengaduan', 'Detail Pengaduan ' . $pengaduan['nomor_tiket'], [
            ['label' => 'Admin', 'url' => url('/admin/dashboard')],
            ['label' => 'Semua Pengaduan', 'url' => url('/admin/pengaduan')],
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
            'rating' => Rating::forPengaduan($id),
            'flash_sukses' => flash_get('sukses'),
            'flash_error' => flash_get('error'),
            'csrf' => csrf_token(),
        ]);
    }

    /** @param array<string, string> $params */
    public function arsipkan(array $params = []): void
    {
        $id = (int) ($params['id'] ?? ($_POST['id'] ?? 0));
        $redirect = '/admin/pengaduan/' . $id;

        $this->actAdmin('Tiket berhasil diarsipkan.', $redirect, static function (array $actor) use ($id): void {
            ArsipService::archive($actor, $id);
        });
    }

    public function teknisi(): void
    {
        $filters = [
            'q' => $this->queryString('q'),
            'status' => $this->queryString('status'),
        ];
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $result = Penugasan::paginatePerformanceForAdmin($filters, $page, self::PER_TEKNISI);

        $this->renderRole('admin/teknisi', 'admin', 'teknisi', 'Monitoring Teknisi', [
            ['label' => 'Admin', 'url' => url('/admin/dashboard')],
            ['label' => 'Monitoring Teknisi'],
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

    /** @param array<string, string> $params */
    public function teknisiDetail(array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        $teknisi = $id > 0 ? Penugasan::performanceForTeknisi($id) : null;
        if ($teknisi === null) {
            render_error('Teknisi tidak ditemukan', 'Data teknisi yang Anda cari tidak tersedia.', 404);
            return;
        }

        $this->renderRole('admin/teknisi_detail', 'admin', 'teknisi', 'Kinerja Teknisi ' . $teknisi['nama'], [
            ['label' => 'Admin', 'url' => url('/admin/dashboard')],
            ['label' => 'Monitoring Teknisi', 'url' => url('/admin/teknisi')],
            ['label' => (string) $teknisi['nama']],
        ], [
            'teknisi' => $teknisi,
            'history' => Penugasan::historyForAdmin($id, 100),
            'rating_rata' => Rating::averageForTeknisi($id),
            'rating_jumlah' => Rating::countForTeknisi($id),
        ]);
    }

    public function laporan(): void
    {
        $distribution = Pengaduan::statusDistribution(false);

        $this->renderRole('admin/laporan', 'admin', 'laporan', 'Laporan Operasional', [
            ['label' => 'Admin', 'url' => url('/admin/dashboard')],
            ['label' => 'Laporan Operasional'],
        ], [
            'includeCharts' => true,
            'distribution' => $distribution,
            'total' => array_sum($distribution),
            'category' => Pengaduan::categoryDistribution(),
            'rating' => Rating::summary(),
            'rating_terbaru' => Rating::recent(10),
            'teknisi_performance' => Penugasan::performanceSummary(20),
            'archived_count' => Pengaduan::countArchived(),
            'cancelled_count' => Pengaduan::countCancelled(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function readFilters(): array
    {
        // Revisi 5: filter utama disederhanakan menjadi Cari + Status + Periode.
        // Data kategori/teknisi/arsip/pembatalan tetap tersimpan pada tiket dan
        // dapat dilihat lengkap pada halaman detail pengaduan.
        $periode = $this->queryString('periode', 'semua');
        if (!in_array($periode, ['semua', 'hari_ini', '7_hari', 'bulan_ini', 'bulan_lalu', 'tahun_ini'], true)) {
            $periode = 'semua';
        }
        [$from, $to] = periode_range($periode);

        return [
            'q' => $this->queryString('q'),
            'status' => $this->queryString('status'),
            'periode' => $periode,
            'date_from' => $from,
            'date_to' => $to,
            'kategori_id' => 0,
            'teknisi_id' => 0,
            'cancelled' => '',
            'arsip' => 'aktif',
        ];
    }
}
