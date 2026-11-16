<?php
/**
 * WiFiCare CDN — Tahap 11: uji unit enkripsi & mode read-only AI.
 *
 * Berjalan di CLI (tanpa server HTTP). Memverifikasi:
 * - libsodium tersedia & master key terbaca dari environment.
 * - Enkripsi/dekripsi API key (secretbox) berputar dengan benar.
 * - Nonce acak per enkripsi (ciphertext berbeda untuk plaintext sama).
 * - Ciphertext tidak memuat plaintext & berformat berversi.
 * - Master key salah → dekripsi gagal dengan aman (null).
 * - Layanan AI tetap READ-ONLY (tidak ada tool tulis).
 *
 * Penggunaan:
 *   $env:DB_NAME='wificare_cdn_test'; php tests/tahap11/crypto.php
 */

declare(strict_types=1);

require __DIR__ . '/../../app/bootstrap.php';

$PASS = 0;
$FAIL = 0;
$FAILED = [];

function check(string $name, bool $ok, string $detail = ''): void
{
    global $PASS, $FAIL, $FAILED;
    if ($ok) {
        $PASS++;
        echo "PASS  {$name}\n";
    } else {
        $FAIL++;
        $FAILED[] = $name . ($detail !== '' ? " :: {$detail}" : '');
        echo "FAIL  {$name}" . ($detail !== '' ? " :: {$detail}" : '') . "\n";
    }
}

echo "==== TAHAP 11 — ENKRIPSI API KEY & READ-ONLY (CLI) ====\n";

// --- libsodium + master key ---------------------------------------------------
check('libsodium tersedia', Crypto::available());
check('master key (AI_SETTINGS_KEY) tersedia', Crypto::hasMasterKey());
check('fasilitas enkripsi siap', Crypto::isReady());

// --- roundtrip ----------------------------------------------------------------
$plain = 'dummy-api-key-for-crypto-roundtrip';
$cipherA = '';
$cipherB = '';
try {
    $cipherA = Crypto::encrypt($plain);
    $cipherB = Crypto::encrypt($plain);
} catch (Throwable $exception) {
    check('enkripsi berjalan', false, $exception->getMessage());
}

check('ciphertext berformat v1:', str_starts_with($cipherA, 'v1:'));
check('ciphertext tidak memuat plaintext', $cipherA !== '' && !str_contains($cipherA, $plain));
check('ciphertext tidak memuat potongan plaintext', $cipherA !== '' && !str_contains($cipherA, 'AIzaSy'));
check('nonce acak: dua ciphertext berbeda', $cipherA !== '' && $cipherB !== '' && $cipherA !== $cipherB);
check('dekripsi mengembalikan plaintext', Crypto::decrypt($cipherA) === $plain);
check('dekripsi ciphertext kedua benar', Crypto::decrypt($cipherB) === $plain);

// --- input tidak valid --------------------------------------------------------
check('dekripsi null -> null', Crypto::decrypt(null) === null);
check('dekripsi string acak -> null', Crypto::decrypt('bukan-ciphertext') === null);
check('dekripsi prefix salah -> null', Crypto::decrypt('v9:AAAA') === null);
check('dekripsi payload rusak -> null', Crypto::decrypt('v1:!!!bukanbase64!!!') === null);

// --- master key salah → gagal aman -------------------------------------------
$originalKey = (string) config('ai_settings.encryption_key', '');
$GLOBALS['app_config']['ai_settings']['encryption_key'] = base64_encode(random_bytes(32));
check('master key salah -> dekripsi null', Crypto::decrypt($cipherA) === null);
$GLOBALS['app_config']['ai_settings']['encryption_key'] = $originalKey;
check('master key benar dipulihkan -> dekripsi berhasil', Crypto::decrypt($cipherA) === $plain);

// --- layanan AI tetap read-only ----------------------------------------------
$adminUser = ['id' => 1, 'role' => 'admin', 'nama' => 'Uji'];
$allowed = AiToolService::allowedNames('admin');
check('tool read-only tersedia untuk admin', in_array('get_ticket_status', $allowed, true));

$writeToolsFound = false;
foreach ($allowed as $toolName) {
    if (preg_match('/insert|update|delete|drop|alter|truncate|create|grant|exec|sql/i', $toolName) === 1) {
        $writeToolsFound = true;
        break;
    }
}
check('tidak ada tool tulis pada allowlist', !$writeToolsFound);

$result = AiToolService::execute('run_sql', ['query' => 'DROP TABLE users'], $adminUser);
check('tool tidak dikenal ditolak (bukan dieksekusi)', isset($result['error']));

$result2 = AiToolService::execute('delete_ticket', ['id' => 1], $adminUser);
check('tool tulis fiktif ditolak', isset($result2['error']));

echo "----------------------------------------------------------------\n";
echo "TOTAL: " . ($PASS + $FAIL) . " | PASS: {$PASS} | FAIL: {$FAIL}\n";
if ($FAIL > 0) {
    echo "GAGAL:\n";
    foreach ($FAILED as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}
echo "SEMUA TES ENKRIPSI & READ-ONLY LULUS.\n";
exit(0);
