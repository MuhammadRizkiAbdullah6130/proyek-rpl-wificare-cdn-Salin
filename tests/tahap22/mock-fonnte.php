<?php
/**
 * Mock Fonnte API untuk pengujian lokal (Tahap 22).
 *
 * Jalankan: php -S 127.0.0.1:8097 tests/tahap22/mock-fonnte.php
 * Log request: set FONNTE_MOCK_LOG ke path file JSON-lines.
 *
 * Mock ini TIDAK dipakai di produksi. Token tidak pernah ditulis ke log.
 */

declare(strict_types=1);

$logFile = getenv('FONNTE_MOCK_LOG') ?: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_fonnte_mock.log';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$headers = function_exists('getallheaders') ? getallheaders() : [];
$authorization = '';
foreach ($headers as $name => $value) {
    if (strtolower((string) $name) === 'authorization') {
        $authorization = trim((string) $value);
        break;
    }
}

$log = static function (array $data) use ($logFile): void {
    file_put_contents(
        $logFile,
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
        FILE_APPEND | LOCK_EX
    );
};

header('Content-Type: application/json; charset=utf-8');

if ($authorization === 'test-fonnte-http') {
    http_response_code(500);
    echo json_encode(['status' => false, 'reason' => 'mock server error']);
    exit;
}

if ($authorization === '' || $authorization === 'test-fonnte-bad') {
    echo json_encode(['status' => false, 'reason' => 'token invalid', 'requestid' => 1]);
    exit;
}

if ($method === 'POST' && $path === '/device') {
    $log(['method' => $method, 'path' => $path]);
    if ($authorization === 'test-fonnte-disconnect') {
        echo json_encode([
            'device' => '6281234567890',
            'device_status' => 'disconnect',
            'name' => 'WiFiCare CDN Gateway',
            'status' => true,
        ]);
        exit;
    }

    echo json_encode([
        'device' => '6281234567890',
        'device_status' => 'connect',
        'expired' => '18 November 2029',
        'messages' => 16785,
        'name' => 'WiFiCare CDN Gateway',
        'package' => 'Reguler',
        'quota' => '78',
        'status' => true,
    ]);
    exit;
}

if ($method === 'POST' && $path === '/send') {
    $payload = [];
    parse_str((string) file_get_contents('php://input'), $payload);
    $target = (string) ($payload['target'] ?? '');
    $message = (string) ($payload['message'] ?? '');

    // Jangan log token. Hanya field pesan yang diperlukan untuk verifikasi.
    $log([
        'method' => $method,
        'path' => $path,
        'target' => $target,
        'message' => $message,
        'countryCode' => (string) ($payload['countryCode'] ?? ''),
        'connectOnly' => (string) ($payload['connectOnly'] ?? ''),
        'preview' => (string) ($payload['preview'] ?? ''),
    ]);

    if (str_ends_with($target, '0000')) {
        echo json_encode(['status' => false, 'reason' => 'target invalid', 'requestid' => 2]);
        exit;
    }
    if (trim($message) === '') {
        echo json_encode(['status' => false, 'reason' => 'input invalid', 'requestid' => 3]);
        exit;
    }
    if ($authorization === 'test-fonnte-empty') {
        echo json_encode([
            'detail' => 'success! message in queue',
            'process' => 'pending',
            'requestid' => 4,
            'status' => true,
            'target' => [$target],
        ]);
        exit;
    }

    echo json_encode([
        'detail' => 'success! message in queue',
        'id' => ['mock-fonnte-' . bin2hex(random_bytes(6))],
        'process' => 'pending',
        'requestid' => 5,
        'status' => true,
        'target' => [$target],
    ]);
    exit;
}

http_response_code(404);
echo json_encode(['status' => false, 'reason' => 'not found']);
