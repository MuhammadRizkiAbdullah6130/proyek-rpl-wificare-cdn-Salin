<?php
/**
 * WiFiCare CDN — Tahap 10: mock Google Gemini (BUKAN bagian aplikasi).
 *
 * Dipakai oleh tests/tahap10/ai-gemini-resilience.php untuk menguji pemilihan
 * model, percobaan ulang (retry), dan fallback tanpa memakai jaringan/kunci
 * nyata. Skenario ditentukan dari NAMA MODEL yang diminta:
 *
 *   mock-ok*      -> 200 + jawaban
 *   mock-busy*    -> 503 UNAVAILABLE (sementara)
 *   mock-missing* -> 404 NOT_FOUND
 *   mock-bad*     -> 400 INVALID_ARGUMENT (permanen)
 *   mock-forbid*  -> 403 PERMISSION_DENIED (permanen)
 *   mock-safe*    -> 200 finishReason=SAFETY (jawaban kosong)
 *
 * Setiap permintaan dicatat (nama model per baris) ke berkas pada env
 * MOCK_GEMINI_LOG bila diset.
 *
 * Jalankan: php -S 127.0.0.1:8096 tests/tahap10/mock-gemini.php
 */

declare(strict_types=1);

$uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
$model = '';
if (preg_match('#/models/([^:]+):generateContent#', $uri, $m) === 1) {
    $model = rawurldecode($m[1]);
}

$log = getenv('MOCK_GEMINI_LOG');
if (is_string($log) && $log !== '') {
    @file_put_contents($log, $model . "\n", FILE_APPEND | LOCK_EX);
}

header('Content-Type: application/json');
$lower = strtolower($model);

// --- Skenario function calling (untuk uji offline tool loop) -----------------
$body = (string) file_get_contents('php://input');
$request = json_decode($body, true);
$contents = is_array($request['contents'] ?? null) ? $request['contents'] : [];

$functionResponses = [];
foreach ($contents as $content) {
    foreach ((array) ($content['parts'] ?? []) as $part) {
        if (isset($part['functionResponse'])) {
            $functionResponses[] = $part['functionResponse'];
        }
    }
}

// Putaran kedua: kembalikan jawaban final yang memuat hasil tool (untuk diuji).
if ($functionResponses !== []) {
    http_response_code(200);
    echo json_encode([
        'candidates' => [[
            'content' => ['parts' => [[
                'text' => 'FINAL_TOOL_RESULT=' . json_encode($functionResponses, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]]],
            'finishReason' => 'STOP',
        ]],
    ]);
    return;
}

// Putaran pertama: minta pemanggilan tool sesuai nama model.
if (str_contains($lower, 'mock-tool')) {
    $callName = 'get_ticket_status';
    $mockTicket = getenv('MOCK_TICKET_NUMBER');
    $callArgs = ['ticket_number' => is_string($mockTicket) && $mockTicket !== ''
        ? $mockTicket
        : 'WFC-20261016-000015'];
    if (str_contains($lower, 'count')) {
        $callName = 'get_my_active_ticket_count';
        $callArgs = []; // argumen kosong → harus dikodekan sebagai objek {}
    } elseif (str_contains($lower, 'customer')) {
        $callName = 'get_customer_count';
        $callArgs = [];
    } elseif (str_contains($lower, 'statistics')) {
        $callName = 'get_ticket_statistics';
        $callArgs = [];
    }

    http_response_code(200);
    echo json_encode([
        'candidates' => [[
            'content' => ['parts' => [[
                'functionCall' => ['name' => $callName, 'args' => $callArgs],
                'thoughtSignature' => 'bW9jay10aG91Z2h0LXNpZ25hdHVyZQ==',
            ]]],
            'finishReason' => 'STOP',
        ]],
    ]);
    return;
}
// -----------------------------------------------------------------------------

if (str_contains($lower, 'busy')) {
    http_response_code(503);
    echo json_encode(['error' => ['code' => 503, 'status' => 'UNAVAILABLE', 'message' => 'This model is currently experiencing high demand.']]);
    return;
}

if (str_contains($lower, 'missing')) {
    http_response_code(404);
    echo json_encode(['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'message' => 'model not found']]);
    return;
}

if (str_contains($lower, 'bad')) {
    http_response_code(400);
    echo json_encode(['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => 'bad request']]);
    return;
}

if (str_contains($lower, 'forbid')) {
    http_response_code(403);
    echo json_encode(['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'message' => 'api key invalid']]);
    return;
}

if (str_contains($lower, 'safe')) {
    http_response_code(200);
    echo json_encode(['candidates' => [['content' => ['parts' => []], 'finishReason' => 'SAFETY']]]);
    return;
}

http_response_code(200);
echo json_encode(['candidates' => [[
    'content' => ['parts' => [['text' => 'MOCK_OK:' . $model]]],
    'finishReason' => 'STOP',
]]]);
