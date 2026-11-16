<?php
/**
 * Model percobaan login (tabel pendukung pembatasan brute force).
 *
 * Bukan bagian dari relasi data pengaduan; murni untuk keamanan autentikasi.
 */

declare(strict_types=1);

final class LoginAttempt
{
    /**
     * Apakah identifier/IP melampaui batas percobaan gagal.
     */
    public static function tooMany(string $identifier, string $ip): bool
    {
        self::prune();

        $window = (int) config('security.login_window_minutes', 15);
        $maxUser = (int) config('security.login_max_attempts', 5);
        $maxIp = (int) config('security.login_max_ip_attempts', 10);

        $byUser = self::countFailures('username', $identifier, $window);
        if ($byUser >= $maxUser) {
            return true;
        }

        return self::countFailures('ip_address', $ip, $window) >= $maxIp;
    }

    /**
     * Catat satu percobaan login.
     */
    public static function record(string $identifier, string $ip, bool $success): void
    {
        $sql = 'INSERT INTO login_attempts (username, ip_address, success) VALUES (:username, :ip, :success)';
        $statement = Database::pdo()->prepare($sql);
        $statement->execute([
            'username' => $identifier,
            'ip' => $ip,
            'success' => $success ? 1 : 0,
        ]);
    }

    /**
     * Hitung percobaan gagal dalam jendela waktu tertentu.
     */
    private static function countFailures(string $column, string $value, int $windowMinutes): int
    {
        $allowed = ['username', 'ip_address'];
        if (!in_array($column, $allowed, true)) {
            return 0;
        }

        // Nilai jendela adalah integer tervalidasi dari konfigurasi, aman di-inline.
        $windowMinutes = max(1, (int) $windowMinutes);

        $sql = "SELECT COUNT(*) FROM login_attempts
                WHERE {$column} = :value
                  AND success = 0
                  AND attempted_at >= (NOW() - INTERVAL {$windowMinutes} MINUTE)";

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['value' => $value]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Bersihkan riwayat percobaan lama agar tabel tidak tumbuh tanpa batas.
     */
    private static function prune(): void
    {
        $statement = Database::pdo()->prepare('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
        $statement->execute();
    }
}
