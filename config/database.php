<?php
/**
 * Konfigurasi database MySQL.
 *
 * Kredensial dibaca dari environment (.env). Jangan menulis kredensial asli
 * pada berkas ini.
 */

declare(strict_types=1);

return [
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => (int) env('DB_PORT', '3306'),
    'database' => env('DB_NAME', env('DB_DATABASE', 'wificare_cdn')),
    'username' => env('DB_USER', env('DB_USERNAME', 'root')),
    'password' => env('DB_PASSWORD', '') ?? '',
    // Akun read-only opsional untuk AI Assistant (kosong = pakai akun utama).
    'read_username' => env('DB_RO_USER', ''),
    'read_password' => env('DB_RO_PASSWORD', ''),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
];
