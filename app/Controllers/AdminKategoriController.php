<?php
/**
 * Controller Admin — Manajemen Kategori Gangguan (Tahap 8).
 */

declare(strict_types=1);

require_once __DIR__ . '/AdminBaseController.php';

final class AdminKategoriController extends AdminBaseController
{
    private const PER_HALAMAN = 10;

    public function kategori(): void
    {
        $filters = $this->readFilters();
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $result = Kategori::paginateForAdmin($filters, $page, self::PER_HALAMAN);

        $this->renderRole('admin/kategori', 'admin', 'kategori', 'Kategori Gangguan', [
            ['label' => 'Admin', 'url' => url('/admin/dashboard')],
            ['label' => 'Kategori Gangguan'],
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

    public function kategoriSimpan(): void
    {
        $this->actAdmin(
            'Kategori berhasil ditambahkan.',
            $this->listUrl('/admin/kategori', $this->readFilters()),
            static function (array $actor): void {
                KategoriService::create($actor, [
                    'nama' => (string) ($_POST['nama'] ?? ''),
                    'deskripsi' => (string) ($_POST['deskripsi'] ?? ''),
                ]);
            }
        );
    }

    public function kategoriUpdate(): void
    {
        $id = (int) ($_POST['id'] ?? 0);

        $this->actAdmin(
            'Kategori berhasil diperbarui.',
            $this->listUrl('/admin/kategori', $this->readFilters()),
            static function (array $actor) use ($id): void {
                KategoriService::update($actor, $id, [
                    'nama' => (string) ($_POST['nama'] ?? ''),
                    'deskripsi' => (string) ($_POST['deskripsi'] ?? ''),
                ]);
            }
        );
    }

    public function kategoriAktif(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        $isActive = (int) ($_POST['is_active'] ?? 0);

        $this->actAdmin(
            $isActive === 1 ? 'Kategori diaktifkan.' : 'Kategori dinonaktifkan.',
            $this->listUrl('/admin/kategori', $this->readFilters()),
            static function (array $actor) use ($id, $isActive): void {
                KategoriService::setActive($actor, $id, $isActive);
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
