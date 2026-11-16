<?php
/**
 * Mock WhatsApp Cloud API untuk pengujian lokal (Tahap 17).
 *
 * Jalankan: php -S 127.0.0.1:8098 tests/tahap17/mock-whatsapp.php
 * Log request: set WA_MOCK_LOG ke path file JSON-lines.
 *
 * Mock ini TIDAK dipakai di produksi.
 */

declare(strict_types=1);

$logFile = getenv('WA_MOCK_LOG') ?: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_wa_mock.log';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$headers = function_exists('getallheaders') ? getallheaders() : [];
$authorization = '';
foreach ($headers as $name => $value) {
    if (strtolower((string) $name) === 'authorization') {
        $authorization = (string) $value;
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

if (!str_starts_with($authorization, 'Bearer test-token')) {
    http_response_code(401);
    echo json_encode(['error' => ['message' => 'Invalid OAuth access token.', 'code' => 190]]);
    exit;
}

if ($method === 'GET' && preg_match('#^/v20\.0/([^/]+)$#', $path, $matches)) {
    $log(['method' => $method, 'path' => $path, 'phone_id' => $matches[1]]);
    if ($matches[1] === 'missing') {
        http_response_code(404);
        echo json_encode(['error' => ['message' => 'Object does not exist.', 'code' => 100]]);
        exit;
    }
    echo json_encode([
        'id' => $matches[1],
        'verified_name' => 'WiFiCare Mock',
        'display_phone_number' => '+62 812 0000 0000',
        'quality_rating' => 'GREEN',
    ]);
    exit;
}

if ($method === 'POST' && preg_match('#^/v20\.0/([^/]+)/messages$#', $path, $matches)) {
    $body = file_get_contents('php://input');
    $payload = json_decode((string) $body, true);
    $to = (string) ($payload['to'] ?? '');
    $log([
        'method' => $method,
        'path' => $path,
        'phone_id' => $matches[1],
        'payload' => $payload,
    ]);

    if (str_ends_with($to, '0000')) {
        http_response_code(400);
        echo json_encode(['error' => ['message' => 'Recipient phone number is not valid.', 'code' => 131026]]);
        exit;
    }

    echo json_encode([
        'messaging_product' => 'whatsapp',
        'contacts' => [['input' => $to, 'wa_id' => $to]],
        'messages' => [['id' => 'wamid.mock.' . bin2hex(random_bytes(6)), 'message_status' => 'accepted']],
    ]);
    exit;
}

if ($method === 'POST' && preg_match('#^/v20\.0/([^/]+)/media$#', $path, $matches)) {
    $log(['method' => $method, 'path' => $path, 'phone_id' => $matches[1], 'files' => array_keys($_FILES)]);
    echo json_encode(['id' => 'media.mock.' . bin2hex(random_bytes(4))]);
    exit;
}

http_response_code(404);
echo json_encode(['error' => ['message' => 'Not found', 'code' => 404]]);
