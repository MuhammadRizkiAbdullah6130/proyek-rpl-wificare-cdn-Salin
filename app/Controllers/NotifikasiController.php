<?php
/**
 * Controller notifikasi in-app (Tahap 8).
 *
 * Setiap pengguna hanya dapat membaca notifikasi miliknya (difilter user_id
 * dari session; pencegahan IDOR). Menandai dibaca selalu memverifikasi
 * kepemilikan terlebih dahulu.
 */

declare(strict_types=1);

require_once __DIR__ . '/Controller.php';

final class NotifikasiController extends Controller
{
    private const PER_HALAMAN = 15;

    public function index(): void
    {
        $userId = (int) (auth_id() ?? 0);
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $result = Notifikasi::paginateFor($userId, $page, self::PER_HALAMAN);

        $this->renderRole('notifikasi/index', 'pelanggan', 'notifikasi', 'Notifikasi', [
            ['label' => 'Notifikasi'],
        ], [
            'items' => $result['rows'],
            'total' => $result['total'],
            'pages' => $result['pages'],
            'page' => $result['page'],
            'unread' => Notifikasi::unreadCountFor($userId),
            'flash_sukses' => flash_get('sukses'),
            'flash_error' => flash_get('error'),
            'csrf' => csrf_token(),
        ]);
    }

    /** @param array<string, string> $params */
    public function baca(array $params = []): void
    {
        $this->assertCsrf();

        $userId = (int) (auth_id() ?? 0);
        $id = (int) ($params['id'] ?? 0);

        $notifikasi = $id > 0 ? Notifikasi::findForUser($id, $userId) : null;
        if ($notifikasi === null) {
            flash_set('error', 'Notifikasi tidak ditemukan.');
            redirect('/notifikasi');
            return;
        }

        Notifikasi::markRead($id, $userId);

        $pengaduanId = $notifikasi['pengaduan_id'] === null ? 0 : (int) $notifikasi['pengaduan_id'];
        redirect($this->ticketUrl($pengaduanId));
    }

    public function bacaSemua(): void
    {
        $this->assertCsrf();

        $userId = (int) (auth_id() ?? 0);
        Notifikasi::markAllRead($userId);

        flash_set('sukses', 'Semua notifikasi ditandai sudah dibaca.');
        redirect('/notifikasi');
    }

    /**
     * URL detail tiket sesuai peran pengguna yang masuk.
     */
    private function ticketUrl(int $pengaduanId): string
    {
        if ($pengaduanId <= 0) {
            return '/notifikasi';
        }

        return match ((string) (auth_role() ?? '')) {
            'admin' => '/admin/pengaduan/' . $pengaduanId,
            'cs' => '/cs/pengaduan/' . $pengaduanId,
            'teknisi' => '/teknisi/tugas/' . $pengaduanId,
            'pelanggan' => '/pelanggan/pengaduan/' . $pengaduanId,
            default => '/notifikasi',
        };
    }

    private function assertCsrf(): void
    {
        if (!csrf_verify(isset($_POST['_token']) ? (string) $_POST['_token'] : null)) {
            render_error(
                'Sesi formulir tidak valid',
                'Token keamanan (CSRF) tidak cocok. Silakan muat ulang halaman.',
                419
            );
            return;
        }
    }
}
