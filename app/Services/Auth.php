<?php
/**
 * Service autentikasi WiFiCare CDN.
 *
 * Menangani verifikasi kredensial, pembatasan percobaan login, session,
 * pembaruan waktu login terakhir, dan pencatatan audit.
 */

declare(strict_types=1);

final class Auth
{
    /**
     * Peran kanonik yang sah (sesuai kolom `users.role`).
     *
     * @var array<int, string>
     */
    public const ROLES = ['admin', 'cs', 'teknisi', 'pelanggan'];

    /** @var array<string, mixed>|null Cache data pengguna aktif. */
    private static ?array $user = null;

    private static bool $loaded = false;

    /**
     * Apakah sebuah nilai merupakan peran kanonik yang sah.
     */
    public static function isValidRole(string $role): bool
    {
        return in_array($role, self::ROLES, true);
    }

    /**
     * Validasi dasar input login sebelum menyentuh database.
     *
     * Dipisahkan dari controller agar aturan yang sama dapat diuji otomatis
     * tanpa mengirim request HTTP. Mengembalikan pesan galat, atau null bila
     * input lolos validasi.
     */
    public static function credentialInputError(string $username, string $password): ?string
    {
        $username = trim($username);

        if ($username === '' || $password === '') {
            return 'Username dan password wajib diisi.';
        }

        if (mb_strlen($username) > 64 || strlen($password) > 200) {
            return 'Username atau password tidak valid.';
        }

        return null;
    }

    /**
     * Apakah ada pengguna yang sedang masuk (berdasarkan session).
     */
    public static function check(): bool
    {
        return isset($_SESSION['user_id']) && (int) $_SESSION['user_id'] > 0;
    }

    /**
     * Data pengguna aktif dari database (di-cache per request).
     *
     * @return array<string, mixed>|null
     */
    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$user;
        }

        self::$loaded = true;

        if (!self::check()) {
            return null;
        }

        $user = User::findById((int) $_SESSION['user_id']);

        // Akun dihapus atau dinonaktifkan setelah login → akhiri sesi.
        if ($user === null || (int) $user['is_active'] !== 1) {
            self::logout();
            return null;
        }

        // Reset password mandiri menaikkan session_version. Sesi yang dibuat
        // sebelum reset tidak boleh lagi dianggap terautentikasi.
        $sessionVersion = (int) ($_SESSION['session_version'] ?? 0);
        if ((int) ($user['session_version'] ?? 0) !== $sessionVersion) {
            self::logout();
            return null;
        }

        self::$user = $user;

        return self::$user;
    }

    /**
     * ID pengguna aktif.
     */
    public static function id(): ?int
    {
        $user = self::user();

        return $user === null ? null : (int) $user['id'];
    }

    /**
     * Peran pengguna aktif (peran kanonik) — SATU-SATUNYA sumber kebenaran.
     *
     * Diambil dari database berdasarkan session `user_id` (bukan dari URL,
     * input, atau session lama). Mengembalikan null bila belum masuk ATAU bila
     * nilai peran tidak dikenali, sehingga pemanggil menolak akses dengan aman
     * (tidak pernah fallback ke Pelanggan).
     */
    public static function role(): ?string
    {
        $user = self::user();

        if ($user === null) {
            return null;
        }

        $role = (string) ($user['role'] ?? '');

        return self::isValidRole($role) ? $role : null;
    }

    /**
     * URL dashboard sesuai peran.
     */
    public static function dashboardUrl(string $role): string
    {
        $map = [
            'admin' => '/admin/dashboard',
            'cs' => '/cs/dashboard',
            'teknisi' => '/teknisi/dashboard',
            'pelanggan' => '/pelanggan/dashboard',
        ];

        return $map[$role] ?? '/login';
    }

    /**
     * Proses percobaan login.
     *
     * @return array{status: string, user?: array<string, mixed>}
     *         status: ok | invalid | inactive | locked
     */
    public static function attempt(string $identifier, string $password): array
    {
        $identifier = trim($identifier);
        $ip = client_ip();

        if (LoginAttempt::tooMany($identifier, $ip)) {
            AuditLog::record(null, 'login_diblokir', 'users', null, [
                'identifier' => self::maskIdentifier($identifier),
                'ip' => $ip,
            ]);

            return ['status' => 'locked'];
        }

        $user = User::findByLogin($identifier);

        // Kredensial tidak cocok (user tidak ada ATAU password salah) → pesan seragam.
        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
            LoginAttempt::record($identifier, $ip, false);
            AuditLog::record(
                $user === null ? null : (int) $user['id'],
                'login_gagal',
                'users',
                $user === null ? null : (int) $user['id'],
                ['identifier' => self::maskIdentifier($identifier), 'ip' => $ip]
            );

            return ['status' => 'invalid'];
        }

        // Password benar tetapi akun nonaktif → pesan khusus (tidak membocorkan
        // keberadaan akun bagi password yang salah).
        if ((int) $user['is_active'] !== 1) {
            LoginAttempt::record($identifier, $ip, false);
            AuditLog::record((int) $user['id'], 'login_nonaktif', 'users', (int) $user['id'], ['ip' => $ip]);

            return ['status' => 'inactive'];
        }

        // Peran tidak dikenal tidak boleh menghasilkan sesi (mencegah bypass
        // RBAC melalui data akun yang rusak/berbahaya).
        if (!self::isValidRole((string) ($user['role'] ?? ''))) {
            LoginAttempt::record($identifier, $ip, false);
            AuditLog::record((int) $user['id'], 'login_peran_invalid', 'users', (int) $user['id'], ['ip' => $ip]);

            return ['status' => 'invalid'];
        }

        LoginAttempt::record($identifier, $ip, true);

        return ['status' => 'ok', 'user' => $user];
    }

    /**
     * Tandai pengguna masuk: regenerasi session, set data, perbarui waktu login.
     *
     * @param array<string, mixed> $user
     */
    public static function login(array $user): void
    {
        // Jangan pernah mewarisi konteks AI dari session/akun sebelumnya.
        if (class_exists('AiConversation')) {
            AiConversation::clearAll();
        }
        if (class_exists('AiContext')) {
            AiContext::clearAll();
        }

        // Cegah session fixation.
        Session::regenerate();
        Csrf::rotate();

        // Sumber tunggal identitas: hanya user_id pada session. Peran SELALU
        // dibaca dari database (Auth::user()/role()), tidak disimpan terpisah
        // agar tidak pernah basi/tidak sinkron antar halaman.
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['session_version'] = (int) ($user['session_version'] ?? 0);
        $_SESSION['login_at'] = time();
        $_SESSION['_regenerated_at'] = time();

        User::updateLastLogin((int) $user['id']);
        AuditLog::record((int) $user['id'], 'login_berhasil', 'users', (int) $user['id'], ['ip' => client_ip()]);

        self::$loaded = false;
        self::$user = null;
    }

    /**
     * Akhiri sesi pengguna.
     */
    public static function logout(): void
    {
        $userId = self::id();

        if ($userId !== null) {
            AuditLog::record($userId, 'logout', 'users', $userId, ['ip' => client_ip()]);
        }

        // Bersihkan state chat/AI sebelum session dihancurkan.
        if (class_exists('AiConversation')) {
            AiConversation::clearAll();
        }
        if (class_exists('AiContext')) {
            AiContext::clearAll();
        }

        Session::destroy();
        self::$loaded = false;
        self::$user = null;
    }

    /**
     * Samarkan identifier untuk log (jangan simpan apa adanya).
     */
    private static function maskIdentifier(string $identifier): string
    {
        $length = strlen($identifier);
        if ($length <= 2) {
            return str_repeat('*', $length);
        }

        return substr($identifier, 0, 1) . str_repeat('*', max(1, $length - 2)) . substr($identifier, -1);
    }
}
