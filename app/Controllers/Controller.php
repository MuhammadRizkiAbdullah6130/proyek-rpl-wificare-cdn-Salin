<?php
/**
 * Base controller WiFiCare CDN.
 *
 * Menyediakan pembungkus render view publik dan view per peran.
 * Hak akses ditegakkan oleh middleware (lihat routes/web.php).
 */

declare(strict_types=1);

abstract class Controller
{
    /**
     * Render view dengan layout publik.
     *
     * @param array<string, mixed> $data
     */
    protected function render(string $view, array $data = []): void
    {
        render_view($view, $data);
    }

    /**
     * Render view dengan layout autentikasi (tanpa sidebar/navbar dashboard).
     *
     * @param array<string, mixed> $data
     */
    protected function renderAuth(string $view, array $data = []): void
    {
        render_view($view, $data, 'layouts/auth');
    }

    /**
     * Render view per peran menggunakan layout aplikasi.
     *
     * @param array<int, array{label: string, url?: string|null}> $breadcrumb
     * @param array<string, mixed> $extra Data tambahan untuk view.
     */
    protected function renderRole(
        string $view,
        string $role,
        string $active,
        string $pageTitle,
        array $breadcrumb = [],
        array $extra = []
    ): void {
        // Ambil pengguna nyata dari session; peran mengikuti akun yang masuk
        // agar tampilan tidak pernah tidak sinkron dengan hak akses.
        $user = auth_user();
        $role = $user['role'] ?? $role;

        render_view($view, array_merge([
            'title' => $pageTitle,
            'role' => $role,
            'active' => $active,
            'pageTitle' => $pageTitle,
            'breadcrumb' => $breadcrumb,
            'user' => $user === null ? null : [
                'nama' => (string) $user['nama'],
                'role' => (string) $user['role'],
                'username' => (string) $user['username'],
            ],
        ], $extra), 'layouts/app');
    }
}
