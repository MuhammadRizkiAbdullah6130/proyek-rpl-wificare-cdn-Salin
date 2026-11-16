<?php
/**
 * Controller Admin — Audit Log (Tahap 8).
 *
 * Audit log bersifat append-only: tidak ada aksi ubah/hapus melalui antarmuka.
 */

declare(strict_types=1);

require_once __DIR__ . '/AdminBaseController.php';

final class AdminAuditController extends AdminBaseController
{
    private const PER_HALAMAN = 15;

    public function audit(): void
    {
        $filters = [
            'q' => $this->queryString('q'),
            'aksi' => $this->queryString('aksi'),
            'actor_id' => (int) ($_GET['actor_id'] ?? 0),
            'date_from' => $this->queryString('date_from'),
            'date_to' => $this->queryString('date_to'),
        ];
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $result = AuditLog::paginateForAdmin($filters, $page, self::PER_HALAMAN);

        $this->renderRole('admin/audit', 'admin', 'audit', 'Audit Log', [
            ['label' => 'Admin', 'url' => url('/admin/dashboard')],
            ['label' => 'Audit Log'],
        ], [
            'items' => $result['rows'],
            'total' => $result['total'],
            'pages' => $result['pages'],
            'page' => $result['page'],
            'filters' => $filters,
            'actions' => AuditLog::distinctActions(),
            'actors' => AuditLog::actors(),
            'flash_sukses' => flash_get('sukses'),
            'flash_error' => flash_get('error'),
        ]);
    }
}
