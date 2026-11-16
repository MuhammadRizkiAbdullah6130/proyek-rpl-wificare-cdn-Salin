<?php
/**
 * Worker antrean notifikasi WhatsApp (Tahap 17).
 *
 * Contoh:
 *   php bin/whatsapp-worker.php
 *   php bin/whatsapp-worker.php --limit=20 --loop=30
 *
 * Worker hanya memproses outbox. Status delivered/read TIDAK pernah ditulis di
 * sini; status itu hanya berasal dari webhook terverifikasi.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Worker hanya dapat dijalankan dari CLI.\n");
}

require_once __DIR__ . '/../app/bootstrap.php';

$options = getopt('', ['limit::', 'feature::', 'loop::', 'help']);
if (isset($options['help'])) {
    echo "Penggunaan: php bin/whatsapp-worker.php [--limit=10] [--feature=whatsapp_notification] [--loop=30]\n";
    exit(0);
}

$limit = max(1, min(50, (int) ($options['limit'] ?? 10)));
$feature = trim((string) ($options['feature'] ?? ''));
$loop = isset($options['loop']) ? max(5, min(3600, (int) $options['loop'])) : 0;

if ($feature !== '' && !ApiFeatureRegistry::exists($feature)) {
    fwrite(STDERR, "Fitur tidak dikenal.\n");
    exit(2);
}

do {
    $result = WhatsAppMessagingService::processQueue($limit, $feature);
    $timestamp = date('Y-m-d H:i:s');
    if (($result['error'] ?? '') !== '') {
        echo "[{$timestamp}] Dilewati: {$result['error']}\n";
    } else {
        echo sprintf(
            "[%s] processed=%d accepted=%d failed=%d\n",
            $timestamp,
            (int) $result['processed'],
            (int) $result['accepted'],
            (int) $result['failed']
        );
    }

    if ($loop > 0) {
        sleep($loop);
    }
} while ($loop > 0);
