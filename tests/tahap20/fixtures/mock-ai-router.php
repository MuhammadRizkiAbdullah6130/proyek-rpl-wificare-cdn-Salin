<?php
/**
 * Mock provider AI untuk pengujian Tahap 20.
 *
 * Router ini meniru format error Gemini dan OpenAI-compatible. Nama model
 * menentukan skenario: success, quota, rate, ratequota, auth, permission,
 * server, model, timeout. Tidak ada kredensial nyata yang dipakai.
 */

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$raw = file_get_contents('php://input');
$body = json_decode((string) $raw, true);
$isGemini = str_contains($path, ':generateContent');
$model = '';

if ($isGemini && preg_match('#/models/([^/:]+):generateContent#', $path, $match) === 1) {
    $model = rawurldecode($match[1]);
} elseif (is_array($body)) {
    $model = (string) ($body['model'] ?? '');
}

$mode = $model !== '' ? strtolower($model) : 'success';
foreach (['ratequota', 'permission', 'timeout', 'success', 'quota', 'rate', 'auth', 'model', 'server'] as $candidate) {
    if (str_starts_with($mode, $candidate)) {
        $mode = $candidate;
        break;
    }
}
$logFile = trim((string) getenv('MOCK_AI_LOG'));
if ($logFile !== '') {
    file_put_contents(
        $logFile,
        json_encode([
            'time' => date('c'),
            'path' => $path,
            'provider' => $isGemini ? 'gemini' : 'openai-compatible',
            'model' => $mode,
        ], JSON_UNESCAPED_SLASHES) . "\n",
        FILE_APPEND | LOCK_EX
    );
}

header('Content-Type: application/json');

if ($mode === 'timeout') {
    sleep(7);
}

if ($mode === 'quota') {
    http_response_code(429);
    if ($isGemini) {
        echo json_encode([
            'error' => [
                'code' => 429,
                'status' => 'RESOURCE_EXHAUSTED',
                'message' => 'You exceeded your current quota. Please check your plan and billing details.',
                'details' => [[
                    '@type' => 'type.googleapis.com/google.rpc.QuotaFailure',
                    'violations' => [[
                        'quotaId' => 'GenerateRequestsPerDayPerProjectPerModel-FreeTier',
                    ]],
                ]],
            ],
        ]);
    } else {
        echo json_encode([
            'error' => [
                'message' => 'You exceeded your current quota, please check your plan and billing details.',
                'type' => 'insufficient_quota',
                'code' => 'insufficient_quota',
            ],
        ]);
    }
    exit;
}

if ($mode === 'ratequota') {
    http_response_code(429);
    header('Retry-After: 20');
    echo json_encode([
        'error' => [
            'code' => 429,
            'status' => 'RESOURCE_EXHAUSTED',
            'message' => "Quota exceeded for quota metric 'Generate Content API requests per minute'.",
            'details' => [[
                '@type' => 'type.googleapis.com/google.rpc.QuotaFailure',
                'violations' => [[
                    'quotaId' => 'GenerateContentRequestsPerMinutePerProjectPerProject',
                ]],
            ], [
                '@type' => 'type.googleapis.com/google.rpc.RetryInfo',
                'retryDelay' => '20s',
            ]],
        ],
    ]);
    exit;
}

if ($mode === 'rate') {
    http_response_code(429);
    header('Retry-After: 30');
    if ($isGemini) {
        echo json_encode([
            'error' => [
                'code' => 429,
                'status' => 'RESOURCE_EXHAUSTED',
                'message' => 'Too many requests. Rate limit exceeded for requests per minute.',
            ],
        ]);
    } else {
        echo json_encode([
            'error' => [
                'message' => 'Rate limit reached for requests. Please try again later.',
                'type' => 'requests',
                'code' => 'rate_limit_exceeded',
            ],
        ]);
    }
    exit;
}

if ($mode === 'auth') {
    http_response_code(401);
    if ($isGemini) {
        echo json_encode(['error' => [
            'code' => 401,
            'status' => 'UNAUTHENTICATED',
            'message' => 'API key not valid. Please pass a valid API key.',
        ]]);
    } else {
        echo json_encode(['error' => [
            'message' => 'Incorrect API key provided.',
            'type' => 'invalid_request_error',
            'code' => 'invalid_api_key',
        ]]);
    }
    exit;
}

if ($mode === 'permission') {
    http_response_code(403);
    if ($isGemini) {
        echo json_encode(['error' => [
            'code' => 403,
            'status' => 'PERMISSION_DENIED',
            'message' => 'Permission denied for this model.',
        ]]);
    } else {
        echo json_encode(['error' => [
            'message' => 'You do not have permission to access this model.',
            'type' => 'permission_error',
            'code' => 'permission_denied',
        ]]);
    }
    exit;
}

if ($mode === 'model') {
    http_response_code(404);
    echo json_encode(['error' => [
        'code' => 404,
        'status' => 'NOT_FOUND',
        'message' => 'Model not found.',
    ]]);
    exit;
}

if ($mode === 'server') {
    http_response_code(500);
    echo json_encode(['error' => [
        'code' => 500,
        'status' => 'INTERNAL',
        'message' => 'Internal server error.',
    ]]);
    exit;
}

http_response_code(200);

if ($isGemini) {
    echo json_encode([
        'candidates' => [[
            'content' => ['parts' => [['text' => 'Jawaban mock dari Gemini.']]],
            'finishReason' => 'STOP',
        ]],
        'usageMetadata' => [
            'promptTokenCount' => 12,
            'candidatesTokenCount' => 7,
            'totalTokenCount' => 19,
        ],
    ]);
    exit;
}

echo json_encode([
    'choices' => [[
        'index' => 0,
        'message' => ['role' => 'assistant', 'content' => 'Jawaban mock dari provider.'],
        'finish_reason' => 'stop',
    ]],
    'usage' => [
        'prompt_tokens' => 11,
        'completion_tokens' => 6,
        'total_tokens' => 17,
    ],
]);
