<?php
/**
 * Controller Admin — Manajemen Data Pelanggan (Tahap 8).
 */

declare(strict_types=1);

require_once __DIR__ . '/AdminBaseController.php';

final class AdminPelangganController extends AdminBaseController
{
    private const PER_HALAMAN = 10;

    public function pelanggan(): void
    {
        $filters = $this->readFilters();
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $result = Pelanggan::paginateForAdmin($filters, $page, self::PER_HALAMAN);

        $this->renderRole('admin/pelanggan', 'admin', 'pelanggan', 'Data Pelanggan', [
            ['label' => 'Admin', 'url' => url('/admin/dashboard')],
            ['label' => 'Data Pelanggan'],
        ], [
            'items' => $result['rows'],
            'total' => $result['total'],
            'pages' => $result['pages'],
            'page' => $result['page'],
            'filters' => $filters,
            'flash_sukses' => flash_get('sukses'),
            'flash_error' => flash_get('error'),
            'csrf' => csrf_token(),
        ]);
    }

    /** @param array<string, string> $params */
    public function pelangganDetail(array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        $pelanggan = $id > 0 ? Pelanggan::findForAdmin($id) : null;
        if ($pelanggan === null) {
            render_error('Pelanggan tidak ditemukan', 'Data pelanggan yang Anda cari tidak tersedia.', 404);
            return;
        }

        $this->renderRole('admin/pelanggan_detail', 'admin', 'pelanggan', 'Riwayat Pelanggan ' . $pelanggan['nama'], [
            ['label' => 'Admin', 'url' => url('/admin/dashboard')],
            ['label' => 'Data Pelanggan', 'url' => url('/admin/pelanggan')],
            ['label' => (string) $pelanggan['nama']],
        ], [
            'pelanggan' => $pelanggan,
            'tickets' => Pelanggan::ticketsForCs($id),
            'flash_sukses' => flash_get('sukses'),
            'flash_error' => flash_get('error'),
        ]);
    }

    public function pelangganSimpan(): void
    {
        $this->actAdmin(
            'Pelanggan berhasil ditambahkan.',
            $this->listUrl('/admin/pelanggan', $this->readFilters()),
            static function (array $actor): void {
                PelangganService::create($actor, [
                    'nama' => (string) ($_POST['nama'] ?? ''),
                    'username_pppoe' => (string) ($_POST['username_pppoe'] ?? ''),
                    'nomor_whatsapp' => (string) ($_POST['nomor_whatsapp'] ?? ''),
                    'alamat' => (string) ($_POST['alamat'] ?? ''),
                    'password' => (string) ($_POST['password'] ?? ''),
                    'password_confirm' => (string) ($_POST['password_confirm'] ?? ''),
                    'is_active' => (string) ($_POST['is_active'] ?? '1') === '1' ? 1 : 0,
                ]);
            }
        );
    }

    public function pelangganUpdate(): void
    {
        $id = (int) ($_POST['id'] ?? 0);

        $this->actAdmin(
            'Data pelanggan berhasil diperbarui.',
            $this->listUrl('/admin/pelanggan', $this->readFilters()),
            static function (array $actor) use ($id): void {
                PelangganService::update($actor, $id, [
                    'nama' => (string) ($_POST['nama'] ?? ''),
                    'username_pppoe' => (string) ($_POST['username_pppoe'] ?? ''),
                    'nomor_whatsapp' => (string) ($_POST['nomor_whatsapp'] ?? ''),
                    'alamat' => (string) ($_POST['alamat'] ?? ''),
                ]);
            }
        );
    }

    public function pelangganAktif(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        $isActive = (int) ($_POST['is_active'] ?? 0);

        $this->actAdmin(
            $isActive === 1 ? 'Akun pelanggan diaktifkan.' : 'Akun pelanggan dinonaktifkan.',
            $this->listUrl('/admin/pelanggan', $this->readFilters()),
            static function (array $actor) use ($id, $isActive): void {
                $pelanggan = Pelanggan::findForAdmin($id);
                if ($pelanggan === null) {
                    throw new ValidationException(['pelanggan' => 'Pelanggan tidak ditemukan.']);
                }
                UserService::setActive($actor, (int) $pelanggan['user_id'], $isActive);
            }
        );
    }

    /**
     * @return array{q: string, status: string}
     */
    private function readFilters(): array
    {
        return [
            'q' => $this->queryString('q'),
            'status' => $this->queryString('status'),
        ];
    }
}
