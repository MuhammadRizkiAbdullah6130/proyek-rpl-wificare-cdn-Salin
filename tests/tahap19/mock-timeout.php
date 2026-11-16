<?php
/**
 * Mock provider lambat untuk menguji timeout cloud tanpa API key nyata.
 * Sengaja tidur lebih lama daripada timeout yang diuji. Jawaban tidak pernah
 * dipakai oleh suite; yang diuji adalah jalur timeout dan fallback lokal.
 */

declare(strict_types=1);

sleep(8);

header('Content-Type: application/json');
http_response_code(200);
echo json_encode([
    'candidates' => [[
        'content' => ['parts' => [['text' => 'MOCK_TIMEOUT_SHOULD_NOT_ARRIVE']]],
        'finishReason' => 'STOP',
    ]],
]);
