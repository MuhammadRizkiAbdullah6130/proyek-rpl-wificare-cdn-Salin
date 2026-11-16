<?php
/**
 * Service koneksi database (PDO).
 *
 * Selalu memakai prepared statement tanpa emulasi dan melempar RuntimeException
 * tanpa detail sensitif agar tidak bocor ke pengguna.
 */

declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;
    private static ?PDO $readPdo = null;

    /**
     * Koneksi PDO ke database aplikasi.
     */
    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string) db_config('host'),
            (int) db_config('port'),
            (string) db_config('database'),
            (string) db_config('charset', 'utf8mb4')
        );

        self::$pdo = self::connect($dsn);

        return self::$pdo;
    }

    /**
     * Koneksi khusus BACA untuk AI Assistant (read-only).
     *
     * Bila akun read-only dikonfigurasi (`DB_RO_USER`), koneksi ini memakai
     * akun tersebut sehingga pembacaan AI benar-benar hanya berizin SELECT.
     * Bila tidak dikonfigurasi, dipakai koneksi utama (semua query tetap
     * prepared statement dan hanya SELECT).
     */
    public static function readPdo(): PDO
    {
        if (self::$readPdo instanceof PDO) {
            return self::$readPdo;
        }

        $readUser = trim((string) db_config('read_username', ''));
        if ($readUser === '') {
            // Fallback: pakai koneksi utama (sudah aman untuk SELECT).
            return self::$readPdo = self::pdo();
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string) db_config('host'),
            (int) db_config('port'),
            (string) db_config('database'),
            (string) db_config('charset', 'utf8mb4')
        );

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];

        try {
            self::$readPdo = new PDO($dsn, $readUser, (string) db_config('read_password', ''), $options);
        } catch (PDOException $exception) {
            // Jangan gagalkan aplikasi: kembalikan ke koneksi utama, catat tanpa detail rahasia.
            app_log('Koneksi database read-only gagal; memakai koneksi utama.');
            self::$readPdo = self::pdo();
        }

        return self::$readPdo;
    }

    /**
     * Koneksi PDO tanpa memilih database (dipakai installer/migrasi).
     */
    public static function withoutDatabase(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;charset=%s',
            (string) db_config('host'),
            (int) db_config('port'),
            (string) db_config('charset', 'utf8mb4')
        );

        return self::connect($dsn);
    }

    /**
     * Buat koneksi PDO dengan opsi aman dan konsisten.
     */
    private static function connect(string $dsn): PDO
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];

        try {
            return new PDO($dsn, (string) db_config('username'), (string) db_config('password'), $options);
        } catch (PDOException $exception) {
            app_log('Koneksi database gagal: ' . $exception->getMessage());
            throw new RuntimeException('Koneksi database gagal. Periksa konfigurasi pada berkas .env.', 0, $exception);
        }
    }

    /**
     * Jalankan callable di dalam transaksi.
     *
     * @template T
     * @param callable(PDO): T $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();

        try {
            $result = $callback($pdo);
            $pdo->commit();

            return $result;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}
