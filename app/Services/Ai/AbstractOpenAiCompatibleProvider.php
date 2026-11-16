<?php
/**
 * Dasar adapter provider kompatibel OpenAI (OpenAI, DeepSeek).
 *
 * Format request: POST {base}/chat/completions
 * Header: Authorization: Bearer {key}
 * Body: model, messages, max_tokens, temperature.
 */

declare(strict_types=1);

abstract class AbstractOpenAiCompatibleProvider implements AiProviderInterface
{
    public function defaultModel(): string
    {
        return 'gpt-4o-mini';
    }

    public function defaultBase(): string
    {
        return 'https://api.openai.com/v1';
    }

    public function suggestedModels(): array
    {
        return [];
    }

    public function chatEndpoint(string $base): string
    {
        $base = rtrim(trim($base), '/');
        if (str_ends_with($base, '/chat/completions')) {
            return $base;
        }

        return $base . '/chat/completions';
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
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => 'ping'],
            ],
            'max_tokens' => 8,
            'temperature' => 0,
        ];

        $result = $this->post(
            $this->chatEndpoint($base),
            $payload,
            ['Authorization: Bearer ' . $apiKey],
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
            return ['ok' => true, 'code' => 'ok', 'message' => 'Koneksi ' . $this->label() . ' berhasil.'];
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
            'message' => 'Gagal terhubung ke layanan ' . $this->label() . '. '
                . (string) ($classified['message'] ?? ''),
            'error_type' => (string) ($classified['error_type'] ?? 'provider_error'),
            'http_status' => $classified['http_status'] ?? null,
            'retry_after' => $classified['retry_after'] ?? null,
            'provider_code' => (string) ($classified['provider_code'] ?? ''),
        ];
    }

    /**
     * @return array{ok: bool, code: string, message: string}
     */
    protected function mapTestError(int $status, string $raw): array
    {
        $decoded = json_decode($raw, true);
        $detail = '';
        if (is_array($decoded) && isset($decoded['error'])) {
            $error = $decoded['error'];
            $detail = is_array($error)
                ? (string) ($error['message'] ?? $error['type'] ?? '')
                : (string) $error;
        }
        $haystack = strtolower($detail);

        if ($status === 401 || $status === 403
            || str_contains($haystack, 'invalid api key')
            || str_contains($haystack, 'incorrect api key')
            || str_contains($haystack, 'authentication')) {
            return ['ok' => false, 'code' => 'invalid_key', 'message' => 'API Key tidak valid atau tidak memiliki izin.'];
        }

        if ($status === 404
            || str_contains($haystack, 'model not found')
            || str_contains($haystack, 'does not exist')
            || str_contains($haystack, 'not available')) {
            return ['ok' => false, 'code' => 'model_unavailable', 'message' => 'Model tidak tersedia.'];
        }

        if ($status === 429
            || str_contains($haystack, 'quota')
            || str_contains($haystack, 'rate limit')
            || str_contains($haystack, 'insufficient balance')) {
            return ['ok' => false, 'code' => 'quota', 'message' => 'Kuota atau batas permintaan provider telah tercapai.'];
        }

        if ($status >= 500) {
            return ['ok' => false, 'code' => 'server', 'message' => 'Gagal terhubung ke layanan ' . $this->label() . ' (server sedang bermasalah).'];
        }

        if ($status === 400 || $status === 422) {
            return ['ok' => false, 'code' => 'bad_request', 'message' => 'Permintaan pengujian tidak valid. Periksa nama model.'];
        }

        return ['ok' => false, 'code' => 'error', 'message' => 'Gagal terhubung ke layanan ' . $this->label() . '.'];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $headers
     * @return array{raw: string|bool, errno: int, status: int, headers: array<string, string>}
     */
    protected function post(string $endpoint, array $payload, array $headers, int $timeout): array
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
