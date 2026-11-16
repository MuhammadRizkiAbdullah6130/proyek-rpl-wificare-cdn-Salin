<?php
/**
 * Controller Admin — Data Karyawan (Tahap 8 / Revisi 4).
 *
 * Hanya mengelola akun STAF (admin/cs/teknisi). Akun pelanggan berada di
 * AdminPelangganController (menu Data Pelanggan).
 */

declare(strict_types=1);

require_once __DIR__ . '/AdminBaseController.php';

final class AdminUserController extends AdminBaseController
{
    private const PER_HALAMAN = 10;

    public function pengguna(): void
    {
        $filters = $this->readFilters();
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $result = User::paginateForAdmin($filters, $page, self::PER_HALAMAN);

        $this->renderRole('admin/pengguna', 'admin', 'pengguna', 'Data Karyawan', [
            ['label' => 'Admin', 'url' => url('/admin/dashboard')],
            ['label' => 'Data Karyawan'],
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

    public function penggunaSimpan(): void
    {
        $this->actAdmin(
            'Akun karyawan berhasil dibuat.',
            $this->listUrl('/admin/pengguna', $this->readFilters()),
            static function (array $actor): void {
                UserService::create($actor, [
                    'username' => (string) ($_POST['username'] ?? ''),
                    'nama' => (string) ($_POST['nama'] ?? ''),
                    'role' => (string) ($_POST['role'] ?? ''),
                    'password' => (string) ($_POST['password'] ?? ''),
                    'password_confirm' => (string) ($_POST['password_confirm'] ?? ''),
                    'is_active' => (string) ($_POST['is_active'] ?? '1') === '1' ? 1 : 0,
                ]);
            }
        );
    }

    public function penggunaUpdate(): void
    {
        $id = (int) ($_POST['id'] ?? 0);

        $this->actAdmin(
            'Data karyawan berhasil diperbarui.',
            $this->listUrl('/admin/pengguna', $this->readFilters()),
            static function (array $actor) use ($id): void {
                UserService::update($actor, $id, [
                    'username' => (string) ($_POST['username'] ?? ''),
                    'nama' => (string) ($_POST['nama'] ?? ''),
                    'role' => (string) ($_POST['role'] ?? ''),
                ]);
            }
        );
    }

    public function penggunaAktif(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        $isActive = (int) ($_POST['is_active'] ?? 0);

        $this->actAdmin(
            $isActive === 1 ? 'Akun diaktifkan.' : 'Akun dinonaktifkan.',
            $this->listUrl('/admin/pengguna', $this->readFilters()),
            static function (array $actor) use ($id, $isActive): void {
                UserService::setActive($actor, $id, $isActive);
            }
        );
    }

    public function penggunaReset(): void
    {
        $id = (int) ($_POST['id'] ?? 0);

        $this->actAdmin(
            'Password karyawan berhasil diatur ulang.',
            $this->listUrl('/admin/pengguna', $this->readFilters()),
            static function (array $actor) use ($id): void {
                UserService::resetPassword($actor, $id, [
                    'password' => (string) ($_POST['password'] ?? ''),
                    'password_confirm' => (string) ($_POST['password_confirm'] ?? ''),
                ]);
            }
        );
    }

    /**
     * @return array{q: string, role: string, status: string}
     */
    private function readFilters(): array
    {
        return [
            'q' => $this->queryString('q'),
            'role' => $this->queryString('role'),
            'status' => $this->queryString('status'),
        ];
    }
}
