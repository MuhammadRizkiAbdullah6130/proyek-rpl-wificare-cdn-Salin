<?php
/**
 * Adapter Google Gemini (generateContent API).
 *
 * Format request/response Gemini BERBEDA dari OpenAI: contents +
 * systemInstruction, header x-goog-api-key, dan functionCall/functionResponse.
 * Detail transport chat tetap ditangani AiAssistantService agar thoughtSignature
 * pada function calling tidak hilang; adapter ini menangani metadata dan
 * pengujian koneksi nyata.
 */

declare(strict_types=1);

final class GeminiProvider implements AiProviderInterface
{
    public function id(): string
    {
        return 'gemini';
    }

    public function label(): string
    {
        return 'Google Gemini';
    }

    public function defaultModel(): string
    {
        return 'gemini-2.5-flash';
    }

    public function defaultBase(): string
    {
        return 'https://generativelanguage.googleapis.com/v1beta';
    }

    public function suggestedModels(): array
    {
        return ['gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-1.5-flash', 'gemini-1.5-pro', 'gemini-3.5-flash', 'gemini-flash-latest'];
    }

    public function endpoint(string $base, string $model): string
    {
        $base = rtrim(trim($base), '/');
        if (!str_contains($base, '/v1beta') && !str_contains($base, '/v1')) {
            $base .= '/v1beta';
        }

        return $base . '/models/' . rawurlencode($model) . ':generateContent';
    }

    public function testConnection(string $apiKey, string $model, string $base, int $timeout = 15): array
    {
        $apiKey = trim($apiKey);
        $model = trim($model);
        $base = trim($base);

        if ($apiKey === '' || $model === '' || $base === '') {
            return ['ok' => false, 'code' => 'incomplete', 'message' => 'Kunci, model, dan base URL wajib tersedia untuk pengujian.'];
        }

        $payload = [
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => 'ping']],
            ]],
            'generationConfig' => [
                'temperature' => 0,
                'maxOutputTokens' => 8,
            ],
        ];

        $result = $this->post(
            $this->endpoint($base, $model),
            $payload,
            ['x-goog-api-key: ' . $apiKey],
            max(5, min(30, $timeout))
        );

        $status = (int) $result['status'];
        $errno = (int) $result['errno'];
        $raw = $result['raw'];

        if ($errno !== 0 || $raw === false) {
            return $this->classifiedTestError(
                AiProviderErrorClassifier::transport($this->id(), $errno)
            );
        }

        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'code' => 'ok', 'message' => 'Koneksi Gemini berhasil.'];
        }

        $decoded = json_decode((string) $raw, true);

        return $this->classifiedTestError(AiProviderErrorClassifier::http(
            $this->id(),
            $status,
            is_array($decoded) ? $decoded : [],
            is_array($result['headers'] ?? null) ? $result['headers'] : []
        ));
    }

    /**
     * @param array<string, mixed> $classified
     * @return array<string, mixed>
     */
    private function classifiedTestError(array $classified): array
    {
        return [
            'ok' => false,
            'code' => (string) ($classified['code'] ?? 'error'),
            'message' => 'Gagal terhubung ke layanan Gemini. '
                . (string) ($classified['message'] ?? ''),
            'error_type' => (string) ($classified['error_type'] ?? 'provider_error'),
            'http_status' => $classified['http_status'] ?? null,
            'retry_after' => $classified['retry_after'] ?? null,
            'provider_code' => (string) ($classified['provider_code'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $headers
     * @return array{raw: string|bool, errno: int, status: int, headers: array<string, string>}
     */
    private function post(string $endpoint, array $payload, array $headers, int $timeout): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return ['raw' => false, 'errno' => -1, 'status' => 0, 'headers' => []];
        }

        $ch = curl_init($endpoint);
        if ($ch === false) {
            return ['raw' => false, 'errno' => -1, 'status' => 0, 'headers' => []];
        }

        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$responseHeaders): int {
                $length = strlen($header);
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return $length;
            },
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['raw' => $raw, 'errno' => $errno, 'status' => $status, 'headers' => $responseHeaders];
    }
}
