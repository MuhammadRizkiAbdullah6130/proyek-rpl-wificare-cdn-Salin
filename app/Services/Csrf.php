<?php
/**
 * Service CSRF token.
 *
 * Token disimpan di session dan dibandingkan dengan hash_equals().
 */

declare(strict_types=1);

final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    /**
     * Ambil token CSRF aktif (dibuat bila belum ada).
     */
    public static function token(): string
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * Bandingkan token yang dikirim dengan token session.
     */
    public static function verify(?string $token): bool
    {
        $sessionToken = $_SESSION[self::SESSION_KEY] ?? null;

        if (!is_string($sessionToken) || $sessionToken === '' || !is_string($token) || $token === '') {
            return false;
        }

        return hash_equals($sessionToken, $token);
    }

    /**
     * Buat token baru (dipakai setelah login/logout).
     */
    public static function rotate(): void
    {
        $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
    }
}
