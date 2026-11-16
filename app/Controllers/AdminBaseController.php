<?php
/**
 * Base controller area Admin (Tahap 8).
 *
 * Menyediakan helper aksi tulis (POST) dengan CSRF + penanganan kesalahan yang
 * seragam. Otorisasi peran Admin sudah ditegakkan middleware route ('role' =>
 * 'admin'); helper di sini hanya menyiapkan data pelaku dari session.
 */

declare(strict_types=1);

require_once __DIR__ . '/Controller.php';

abstract class AdminBaseController extends Controller
{
    /**
     * Jalankan aksi tulis Admin dengan CSRF + flash message seragam.
     *
     * @param callable(array<string, mixed>): void $handler
     */
    protected function actAdmin(string $successMessage, string $redirectTo, callable $handler): void
    {
        if (!csrf_verify(isset($_POST['_token']) ? (string) $_POST['_token'] : null)) {
            render_error(
                'Sesi formulir tidak valid',
                'Token keamanan (CSRF) tidak cocok. Silakan muat ulang halaman.',
                419
            );
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
            app_log('Aksi admin gagal: ' . $exception->getMessage());
            flash_set('error', 'Tindakan gagal diproses. Silakan coba beberapa saat lagi.');
        }

        redirect($redirectTo);
    }

    /**
     * Baca kata kunci pencarian umum dari query string.
     */
    protected function queryString(string $key, string $default = ''): string
    {
        return trim((string) ($_GET[$key] ?? $default));
    }

    /**
     * Susun URL kembali ke halaman daftar dengan mempertahankan filter.
     *
     * @param array<string, mixed> $filters
     */
    protected function listUrl(string $base, array $filters): string
    {
        $query = array_filter($filters, static fn ($value): bool => $value !== '' && $value !== 0 && $value !== null);
        $url = $base;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }
}
