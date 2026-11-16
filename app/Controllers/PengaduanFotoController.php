<?php
/**
 * Controller penyaji foto bukti pengaduan.
 *
 * Berkas fisik disimpan di luar document root, sehingga hanya dapat diakses
 * melalui endpoint ini. Endpoint memeriksa autentikasi dan hak akses:
 * - Pelanggan : hanya foto milik tiketnya sendiri.
 * - Teknisi   : hanya foto tiket yang ditugaskan aktif kepadanya.
 * - Admin/CS  : seluruh foto (kebutuhan operasional).
 */

declare(strict_types=1);

require_once __DIR__ . '/Controller.php';

final class PengaduanFotoController extends Controller
{
    /** MIME gambar yang boleh disajikan inline. */
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * Sajikan satu berkas foto dengan pemeriksaan akses.
     *
     * @param array<string, string> $params Parameter route (id).
     */
    public function show(array $params = []): void
    {
        $user = auth_user();
        if ($user === null) {
            render_forbidden('Silakan masuk untuk melihat foto ini.');
            return;
        }

        $fotoId = (int) ($params['id'] ?? 0);
        $foto = $fotoId > 0 ? Pengaduan::findFoto($fotoId) : null;

        if ($foto === null) {
            render_error('Foto tidak ditemukan', 'Foto yang Anda minta tidak tersedia.', 404);
            return;
        }

        if (!$this->bolehMelihat($user, $foto)) {
            render_forbidden('Anda tidak berhak melihat foto ini.');
            return;
        }

        $absolute = Upload::resolve((string) $foto['file_path']);
        if ($absolute === null) {
            render_error('Foto tidak ditemukan', 'Berkas foto tidak tersedia di penyimpanan.', 404);
            return;
        }

        $mime = (string) $foto['mime_type'];
        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            $mime = 'application/octet-stream';
        }

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($absolute));
        header('Content-Disposition: inline; filename="' . basename((string) $foto['file_path']) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');

        readfile($absolute);
        exit;
    }

    /**
     * Tentukan apakah pengguna berhak melihat foto.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $foto
     */
    private function bolehMelihat(array $user, array $foto): bool
    {
        $role = (string) ($user['role'] ?? '');

        return match ($role) {
            'pelanggan' => (int) ($user['pelanggan_id'] ?? 0) > 0
                && (int) $user['pelanggan_id'] === (int) $foto['pelanggan_id'],
            'teknisi' => Pengaduan::isAssignedTo((int) $foto['pengaduan_id'], (int) ($user['id'] ?? 0)),
            'admin', 'cs' => true,
            default => false,
        };
    }
}
