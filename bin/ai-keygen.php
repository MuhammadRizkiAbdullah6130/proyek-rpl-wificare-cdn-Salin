<?php
/**
 * WiFiCare CDN — Pembuat master key enkripsi API key AI (Tahap 11).
 *
 * Penggunaan (dari direktori proyek):
 *   php bin/ai-keygen.php
 *
 * Skrip ini menghasilkan 32 byte acak (base64) untuk dipasang sebagai
 * AI_SETTINGS_KEY pada berkas .env server. Master key TIDAK boleh disimpan di
 * database maupun repositori.
 *
 * Hanya dapat dijalankan dari CLI.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Skrip ini hanya dapat dijalankan melalui CLI.');
}

if (!extension_loaded('sodium') && !function_exists('random_bytes')) {
    fwrite(STDERR, 'Fungsi kriptografi tidak tersedia pada PHP ini.' . PHP_EOL);
    exit(1);
}

$key = base64_encode(random_bytes(32));

fwrite(STDOUT, "Master key enkripsi AI (32 byte, base64):" . PHP_EOL . PHP_EOL);
fwrite(STDOUT, 'AI_SETTINGS_KEY=' . $key . PHP_EOL . PHP_EOL);
fwrite(STDOUT, 'Simpan di berkas .env server. JANGAN commit ke repositori.' . PHP_EOL);
fwrite(STDOUT, 'Bila kunci ini hilang/berubah, API key yang tersimpan tidak dapat didekripsi.' . PHP_EOL);
