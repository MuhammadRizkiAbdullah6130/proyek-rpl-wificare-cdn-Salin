<?php
/**
 * Konfigurasi harness pengujian Tahap 10 (HANYA untuk pengujian lokal).
 *
 * Nilai dapat ditimpa lewat environment:
 *   T10_BASE, T10_DB_DSN, T10_DB_USER, T10_DB_PASS,
 *   T10_PW_ADMIN, T10_PW_CS, T10_PW_TEK, T10_PW_PEL
 *
 * Password TIDAK memiliki nilai bawaan dan TIDAK boleh ditulis di repository.
 * Isi environment variable dengan password akun uji lokal yang Anda buat
 * sendiri (jangan memakai password akun produksi). Bila belum diisi, harness
 * akan berhenti dengan status BLOCKED, bukan PASS palsu.
 */

declare(strict_types=1);

define('T10_BASE', getenv('T10_BASE') ?: 'http://localhost:8099');
define('T10_DB_DSN', getenv('T10_DB_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=wificare_cdn_test;charset=utf8mb4');
define('T10_DB_USER', getenv('T10_DB_USER') !== false ? getenv('T10_DB_USER') : 'root');
define('T10_DB_PASS', getenv('T10_DB_PASS') !== false ? getenv('T10_DB_PASS') : '');

// Sengaja tanpa fallback password. Jangan pernah menaruh password di sini.
define('T10_PW_ADMIN', getenv('T10_PW_ADMIN') !== false ? (string) getenv('T10_PW_ADMIN') : '');
define('T10_PW_CS', getenv('T10_PW_CS') !== false ? (string) getenv('T10_PW_CS') : '');
define('T10_PW_TEK', getenv('T10_PW_TEK') !== false ? (string) getenv('T10_PW_TEK') : '');
define('T10_PW_PEL', getenv('T10_PW_PEL') !== false ? (string) getenv('T10_PW_PEL') : '');

/**
 * Hentikan harness dengan status BLOCKED bila kredensial uji belum diisi.
 *
 * @param array<int, string> $keys
 */
function t10_require_passwords(array $keys): void
{
    $missing = [];
    foreach ($keys as $key) {
        $value = getenv($key);
        if ($value === false || trim((string) $value) === '') {
            $missing[] = $key;
        }
    }

    if ($missing === []) {
        return;
    }

    $message = 'BLOCKED: kredensial uji belum dikonfigurasi: ' . implode(', ', $missing);
    echo $message . PHP_EOL;
    fwrite(STDERR, 'Set environment variable tersebut dengan password akun uji lokal. '
        . 'Jangan memakai password produksi atau password sampel.' . PHP_EOL);
    exit(2);
}
