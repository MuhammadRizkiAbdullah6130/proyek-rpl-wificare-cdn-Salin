<?php
/**
 * Bootstrap PHPUnit WiFiCare CDN.
 *
 * Seluruh test WAJIB memakai database uji terpisah. Bootstrap ini menolak
 * berjalan bila nama database tidak berakhiran `_test` atau `_phpunit`,
 * sehingga database aktif tidak mungkin tersentuh secara tidak sengaja.
 */

declare(strict_types=1);

$root = dirname(__DIR__);

require_once $root . '/vendor/autoload.php';

$testDatabase = getenv('PHPUNIT_DB_NAME');
if ($testDatabase === false || $testDatabase === '') {
    $testDatabase = 'wificare_cdn_phpunit';
}

if (!preg_match('/_(test|phpunit)$/', $testDatabase)) {
    fwrite(STDERR, "PHPUnit dibatalkan: database uji harus berakhiran _test atau _phpunit.\n");
    exit(1);
}

putenv('APP_ENV=testing');
putenv('DB_NAME=' . $testDatabase);
putenv('APP_BASE_PATH=');

$_ENV['APP_ENV'] = 'testing';
$_ENV['DB_NAME'] = $testDatabase;
$_SERVER['APP_ENV'] = 'testing';
$_SERVER['DB_NAME'] = $testDatabase;
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

require_once $root . '/app/bootstrap.php';
