<?php
/**
 * Enkripsi kredensial sensitif (API key) — libsodium secretbox.
 *
 * WiFiCare CDN — Tahap 11 (Pengaturan AI Chatbot).
 *
 * Prinsip keamanan:
 * - Menggunakan pustaka kriptografi terpercaya (libsodium), BUKAN enkripsi
 *   buatan sendiri.
 * - Algoritma: crypto_secretbox (XSalsa20-Poly1305) dengan NONCE acak baru
 *   untuk setiap proses enkripsi (nonce disertakan pada ciphertext).
 * - Master key (32 byte) HANYA dibaca dari environment server
 *   (AI_SETTINGS_KEY) dan tidak pernah disimpan di database/repositori.
 * - Bila libsodium atau master key tidak tersedia, enkripsi/dekripsi gagal
 *   dengan aman (tidak pernah menyimpan API key tanpa enkripsi).
 *
 * Format nilai tersimpan: "v1:" . base64_encode( nonce(24) . ciphertext ).
 */

declare(strict_types=1);

final class Crypto
{
    /** Penanda versi format agar dapat dikembangkan tanpa merusak data lama. */
    private const PREFIX = 'v1:';

    /** libsodium secretbox: panjang nonce & kunci (byte). */
    private const NONCE_BYTES = 24;
    private const KEY_BYTES = 32;

    /**
     * Apakah primitif kriptografi libsodium tersedia di server.
     */
    public static function available(): bool
    {
        return function_exists('sodium_crypto_secretbox')
            && function_exists('sodium_crypto_secretbox_open');
    }

    /**
     * Apakah master key enkripsi (AI_SETTINGS_KEY) tersedia & valid.
     */
    public static function hasMasterKey(): bool
    {
        return self::masterKey() !== '';
    }

    /**
     * Fasilitas enkripsi siap dipakai (libsodium + master key valid).
     */
    public static function isReady(): bool
    {
        return self::available() && self::hasMasterKey();
    }

    /**
     * Enkripsi plaintext menjadi ciphertext ber-format "v1:...".
     *
     * @throws RuntimeException bila fasilitas enkripsi belum siap.
     */
    public static function encrypt(string $plaintext): string
    {
        if (!self::isReady()) {
            throw new RuntimeException('Fasilitas enkripsi belum siap.');
        }

        $nonce = random_bytes(self::NONCE_BYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, self::masterKey());

        return self::PREFIX . base64_encode($nonce . $cipher);
    }

    /**
     * Dekripsi ciphertext. Mengembalikan null bila gagal (kunci salah,
     * rusak, atau bukan format yang dikenali) — pemanggil tidak boleh
     * menampilkan detail kegagalan kepada pengguna.
     */
    public static function decrypt(?string $payload): ?string
    {
        if ($payload === null || $payload === '' || !self::isReady()) {
            return null;
        }

        if (!str_starts_with($payload, self::PREFIX)) {
            return null;
        }

        $decoded = base64_decode(substr($payload, strlen(self::PREFIX)), true);
        if ($decoded === false || strlen($decoded) <= self::NONCE_BYTES) {
            return null;
        }

        $nonce = substr($decoded, 0, self::NONCE_BYTES);
        $cipher = substr($decoded, self::NONCE_BYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, self::masterKey());

        return $plain === false ? null : $plain;
    }

    /**
     * Fingerprint deterministik untuk nilai yang perlu dibandingkan tanpa
     * menyimpan plaintext (mis. nomor tujuan OTP).
     *
     * Memakai HMAC-SHA256 dengan master key server, sehingga nomor telepon tidak
     * dapat dibalik hanya dari database. Mengembalikan string kosong bila
     * master key belum siap.
     */
    public static function fingerprint(string $value): string
    {
        if (!self::isReady()) {
            return '';
        }

        return hash_hmac('sha256', trim($value), self::masterKey());
    }

    /**
     * Ambil master key biner dari konfigurasi environment.
     *
     * Menerima kunci dalam bentuk base64 (disarankan) atau heksadesimal
     * (64 karakter). Mengembalikan string kosong bila tidak tersedia/tidak valid.
     */
    private static function masterKey(): string
    {
        // Kunci utama lama tetap dipertahankan agar ciphertext AI dapat dibaca.
        $raw = trim((string) config('ai_settings.encryption_key', ''));
        if ($raw === '') {
            $raw = trim((string) config('api_credentials.encryption_key', ''));
        }
        if ($raw === '') {
            return '';
        }

        $decoded = base64_decode($raw, true);
        if (is_string($decoded) && strlen($decoded) === self::KEY_BYTES) {
            return $decoded;
        }

        if (strlen($raw) === 64 && ctype_xdigit($raw)) {
            $binary = hex2bin($raw);
            if (is_string($binary) && strlen($binary) === self::KEY_BYTES) {
                return $binary;
            }
        }

        return '';
    }
}
