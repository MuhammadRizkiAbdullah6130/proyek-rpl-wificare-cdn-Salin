<?php
/**
 * Service session aman WiFiCare CDN.
 *
 * Mengatur cookie HttpOnly + SameSite, mode ketat, dan regenerasi ID.
 */

declare(strict_types=1);

final class Session
{
    private static bool $started = false;

    /**
     * Mulai session dengan pengaturan aman.
     */
    public static function start(): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }

        $name = (string) config('session.name', 'wificare_session');
        $lifetime = (int) config('session.lifetime', 7200);
        $sameSite = (string) config('session.samesite', 'Lax');
        if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            $sameSite = 'Lax';
        }

        // Cookie Secure otomatis aktif saat HTTPS, dan tidak pernah dipaksa
        // aktif pada HTTP lokal (agar login XAMPP tetap berfungsi).
        $secure = (bool) config('session.cookie_secure', false) || self::isHttps();

        session_name($name);

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $sameSite,
        ]);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', $sameSite);
        if ($secure) {
            ini_set('session.cookie_secure', '1');
        }
        ini_set('session.gc_maxlifetime', (string) $lifetime);

        session_start();
        self::$started = true;

        // Perbarui waktu aktivitas untuk membatasi sesi yang menganggur (idle).
        // _started_at  : waktu sesi dimulai (referensi).
        // _last_activity: waktu request terakhir; diperbarui setiap request.
        $now = time();
        if (!isset($_SESSION['_started_at'])) {
            $_SESSION['_started_at'] = $now;
            $_SESSION['_last_activity'] = $now;
        } elseif ($now - (int) ($_SESSION['_last_activity'] ?? $_SESSION['_started_at']) > $lifetime) {
            // Sesi kedaluwarsa: hapus total, lalu mulai sesi baru yang bersih.
            self::destroy();
            session_start();
            $_SESSION['_started_at'] = $now;
            $_SESSION['_last_activity'] = $now;
        } else {
            $_SESSION['_last_activity'] = $now;
        }

        // Rotasi ID sesi berkala selama pengguna aktif, untuk membatasi dampak
        // session fixation/hijacking tanpa memutus permintaan yang berjalan.
        $rotateEvery = max(300, (int) config('session.regenerate_every', 1800));
        if (isset($_SESSION['user_id'])) {
            $lastRotation = (int) ($_SESSION['_regenerated_at'] ?? 0);
            if ($lastRotation === 0) {
                $_SESSION['_regenerated_at'] = $now;
            } elseif ($now - $lastRotation >= $rotateEvery) {
                session_regenerate_id(true);
                $_SESSION['_regenerated_at'] = $now;
            }
        }
    }

    /**
     * Apakah permintaan berjalan melalui HTTPS.
     */
    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        return (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }

    /**
     * Regenerasi ID session (dipakai setelah login berhasil).
     */
    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /**
     * Simpan nilai ke session.
     */
    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    /**
     * Ambil nilai session.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Hapus satu nilai session.
     */
    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Simpan pesan sekali-tampil (flash).
     */
    public static function flash(string $key, string $message): void
    {
        $_SESSION['_flash'][$key] = $message;
    }

    /**
     * Ambil dan hapus pesan flash.
     */
    public static function pullFlash(string $key): ?string
    {
        $message = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);

        return is_string($message) ? $message : null;
    }

    /**
     * Simpan input lama formulir (sekali-tampil).
     *
     * @param array<string, mixed> $values
     */
    public static function flashInput(array $values): void
    {
        $_SESSION['_flash_input'] = $values;
    }

    /**
     * Ambil input lama formulir.
     *
     * @return array<string, mixed>
     */
    public static function pullInput(): array
    {
        $values = $_SESSION['_flash_input'] ?? [];
        unset($_SESSION['_flash_input']);

        return is_array($values) ? $values : [];
    }

    /**
     * Hapus seluruh data session dan cookie session.
     */
    public static function destroy(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'] ?: '/',
                'domain' => $params['domain'] ?? '',
                'secure' => (bool) $params['secure'],
                'httponly' => (bool) $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
        self::$started = false;
    }
}
