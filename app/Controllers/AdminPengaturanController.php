<?php
/**
 * Controller Admin — Pengaturan Aplikasi (Tahap 8).
 * Hanya data NONRAHASIA; kredensial tidak pernah ditampilkan.
 */

declare(strict_types=1);

require_once __DIR__ . '/AdminBaseController.php';

final class AdminPengaturanController extends AdminBaseController
{
    public function pengaturan(): void
    {
        $this->renderRole('admin/pengaturan', 'admin', 'pengaturan', 'Pengaturan Aplikasi', [
            ['label' => 'Admin', 'url' => url('/admin/dashboard')],
            ['label' => 'Pengaturan Aplikasi'],
        ], [
            'settings' => Pengaturan::all(),
            'flash_sukses' => flash_get('sukses'),
            'flash_error' => flash_get('error'),
            'csrf' => csrf_token(),
        ]);
    }

    public function pengaturanSimpan(): void
    {
        $this->actAdmin(
            'Pengaturan berhasil disimpan.',
            '/admin/pengaturan',
            static function (array $actor): void {
                PengaturanService::save($actor, [
                    'app_name' => (string) ($_POST['app_name'] ?? ''),
                    'isp_name' => (string) ($_POST['isp_name'] ?? ''),
                    'contact_email' => (string) ($_POST['contact_email'] ?? ''),
                    'contact_phone' => (string) ($_POST['contact_phone'] ?? ''),
                    'contact_whatsapp' => (string) ($_POST['contact_whatsapp'] ?? ''),
                    'upload_max_mb' => (string) ($_POST['upload_max_mb'] ?? ''),
                ]);
            }
        );
    }
}
