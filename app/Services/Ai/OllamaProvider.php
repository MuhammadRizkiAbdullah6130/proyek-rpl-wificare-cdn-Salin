<?php
/**
 * Adapter Ollama lokal (opsional) — WiFiCare CDN Tahap 18.
 *
 * Ollama menyediakan endpoint kompatibel OpenAI pada /v1/chat/completions.
 * Tidak ada API key yang wajib; endpoint TIDAK boleh dibuka ke internet.
 * Adapter ini tidak mengunduh model apa pun dan tidak memberi akses SQL.
 */

declare(strict_types=1);

final class OllamaProvider extends AbstractOpenAiCompatibleProvider
{
    public function id(): string
    {
        return 'ollama';
    }

    public function label(): string
    {
        return 'Ollama (lokal)';
    }

    public function defaultModel(): string
    {
        return 'llama3.2:3b';
    }

    public function defaultBase(): string
    {
        return 'http://127.0.0.1:11434/v1';
    }

    public function suggestedModels(): array
    {
        return ['llama3.2:3b', 'qwen2.5:3b', 'gemma3:1b'];
    }

    /**
     * Uji koneksi nyata tanpa API key. Model harus sudah diunduh pengguna.
     *
     * @return array{ok: bool, code: string, message: string}
     */
    public function testConnection(string $apiKey, string $model, string $base, int $timeout = 15): array
    {
        $model = trim($model);
        $base = trim($base);

        if ($model === '' || $base === '') {
            return ['ok' => false, 'code' => 'incomplete', 'message' => 'Model dan base URL Ollama wajib diisi.'];
        }

        $result = $this->post(
            $this->chatEndpoint($base),
            [
                'model' => $model,
                'messages' => [['role' => 'user', 'content' => 'ping']],
                'max_tokens' => 8,
                'temperature' => 0,
                'stream' => false,
            ],
            [],
            max(5, min(60, $timeout))
        );

        $status = (int) $result['status'];
        $errno = (int) $result['errno'];
        $raw = $result['raw'];

        if ($errno === 28) {
            return ['ok' => false, 'code' => 'timeout', 'message' => 'Ollama tidak merespons tepat waktu.'];
        }

        if ($errno !== 0 || $raw === false) {
            return ['ok' => false, 'code' => 'network', 'message' => 'Ollama tidak dapat dihubungi. Pastikan layanan lokal aktif.'];
        }

        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'code' => 'ok', 'message' => 'Koneksi Ollama berhasil.'];
        }

        return $this->mapTestError($status, (string) $raw);
    }
}
