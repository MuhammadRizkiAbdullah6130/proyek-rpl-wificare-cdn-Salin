<?php
/**
 * Adapter WhatsApp Business Cloud API (Meta Graph API).
 *
 * Endpoint yang dipakai:
 * - GET  /{phone-number-id}            → tes koneksi
 * - POST /{phone-number-id}/messages   → kirim teks/template/dokumen
 * - POST /{phone-number-id}/media      → unggah media sementara
 *
 * Status "accepted" berarti Graph API menerima request. Status delivered/read
 * hanya boleh berasal dari webhook terverifikasi.
 */

declare(strict_types=1);

final class WhatsAppCloudProvider implements WhatsAppProviderInterface
{
    public function id(): string
    {
        return 'meta_cloud';
    }

    public function label(): string
    {
        return 'WhatsApp Business Cloud API (Meta)';
    }

    public function defaultBase(): string
    {
        return rtrim((string) config('api_center.whatsapp_default_base', 'https://graph.facebook.com'), '/')
            . '/' . (string) config('api_center.whatsapp_api_version', 'v20.0');
    }

    public function supportsTemplates(): bool
    {
        return true;
    }

    public function testConnection(array $credential, int $timeout = 20): array
    {
        $started = microtime(true);
        $token = (string) ($credential['token'] ?? '');
        $phoneId = (string) ($credential['phone_number_id'] ?? '');

        if ($token === '' || $phoneId === '') {
            return $this->result(false, 'incomplete', 'Access token dan Phone Number ID wajib diisi.', '', $started);
        }

        $url = $this->base($credential) . '/' . rawurlencode($phoneId)
            . '?fields=verified_name,display_phone_number,quality_rating';
        $response = $this->request('GET', $url, $token, null, $timeout);

        if ($response['error'] !== '') {
            return $this->result(false, 'network', $response['error'], '', $started, $response);
        }

        if ($response['status'] !== 200) {
            return $this->result(
                false,
                'http_' . $response['status'],
                $this->errorMessage($response),
                '',
                $started,
                $response
            );
        }

        $json = $response['json'];
        $name = trim((string) ($json['verified_name'] ?? ''));
        $display = trim((string) ($json['display_phone_number'] ?? ''));
        $message = 'Terhubung ke WhatsApp Business';
        if ($name !== '') {
            $message .= ' "' . mb_substr($name, 0, 80) . '"';
        }
        if ($display !== '') {
            $message .= ' (' . mb_substr($display, 0, 30) . ')';
        }

        return $this->result(true, 'connected', $message . '.', '', $started, $response);
    }

    public function sendText(array $credential, string $to, string $body, int $timeout = 20): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => mb_substr($body, 0, 4096),
            ],
        ];

        return $this->sendMessage($credential, $payload, $timeout);
    }

    public function sendTemplate(
        array $credential,
        string $to,
        string $templateName,
        string $languageCode,
        array $parameters,
        int $timeout = 20,
        bool $authenticationTemplate = false
    ): array {
        $template = [
            'name' => $templateName,
            'language' => ['code' => $languageCode !== '' ? $languageCode : 'id'],
        ];

        if ($parameters !== []) {
            $values = array_values($parameters);
            $components = [[
                'type' => 'body',
                'parameters' => array_map(
                    static fn (string $value): array => ['type' => 'text', 'text' => mb_substr($value, 0, 1024)],
                    $values
                ),
            ]];

            // Template Authentication Meta membutuhkan kode yang sama pada
            // komponen body dan tombol OTP (copy code/one-tap autofill).
            if ($authenticationTemplate) {
                $components[] = [
                    'type' => 'button',
                    'sub_type' => 'url',
                    'index' => '0',
                    'parameters' => [[
                        'type' => 'text',
                        'text' => mb_substr((string) $values[0], 0, 15),
                    ]],
                ];
            }

            $template['components'] = $components;
        }

        return $this->sendMessage($credential, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'template',
            'template' => $template,
        ], $timeout);
    }

    public function sendDocument(
        array $credential,
        string $to,
        string $filePath,
        string $filename,
        string $caption = '',
        int $timeout = 30
    ): array {
        $started = microtime(true);

        if (!is_file($filePath) || !is_readable($filePath)) {
            return $this->result(false, 'missing_file', 'Berkas laporan tidak tersedia.', '', $started);
        }

        $upload = $this->uploadMedia($credential, $filePath, $timeout);
        if (empty($upload['ok']) || (string) ($upload['media_id'] ?? '') === '') {
            return $this->result(
                false,
                'media_upload_failed',
                (string) ($upload['message'] ?? 'Unggah media gagal.'),
                '',
                $started
            );
        }

        $document = [
            'id' => (string) $upload['media_id'],
            'filename' => mb_substr($filename, 0, 120),
        ];
        if ($caption !== '') {
            $document['caption'] = mb_substr($caption, 0, 1024);
        }

        return $this->sendMessage($credential, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'document',
            'document' => $document,
        ], $timeout);
    }

    /**
     * Apakah signature webhook valid (X-Hub-Signature-256).
     */
    public static function verifySignature(string $rawBody, string $header, string $secret): bool
    {
        if ($secret === '' || $header === '' || !str_starts_with($header, 'sha256=')) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $header);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok:bool,status:string,code:string,message:string,provider_message_id:string,duration_ms:int}
     */
    private function sendMessage(array $credential, array $payload, int $timeout): array
    {
        $started = microtime(true);
        $token = (string) ($credential['token'] ?? '');
        $phoneId = (string) ($credential['phone_number_id'] ?? '');

        if ($token === '' || $phoneId === '') {
            return $this->result(false, 'incomplete', 'Kredensial WhatsApp belum lengkap.', '', $started);
        }

        $url = $this->base($credential) . '/' . rawurlencode($phoneId) . '/messages';
        $response = $this->request(
            'POST',
            $url,
            $token,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $timeout
        );

        if ($response['error'] !== '') {
            return $this->result(false, 'network', $response['error'], '', $started, $response);
        }

        if ($response['status'] !== 200 && $response['status'] !== 201) {
            return $this->result(
                false,
                'provider_error',
                $this->errorMessage($response),
                '',
                $started,
                $response
            );
        }

        $messageId = (string) ($response['json']['messages'][0]['id'] ?? '');
        if ($messageId === '') {
            return $this->result(
                false,
                'invalid_response',
                'Provider menerima request tetapi tidak mengembalikan ID pesan.',
                '',
                $started,
                $response
            );
        }

        // PENTING: ini baru "accepted", bukan "delivered".
        return $this->result(true, 'accepted', 'Request pesan diterima provider.', $messageId, $started, $response);
    }

    /**
     * @param array<string, mixed> $credential
     * @return array{ok:bool,media_id:string,message:string}
     */
    private function uploadMedia(array $credential, string $filePath, int $timeout): array
    {
        $token = (string) ($credential['token'] ?? '');
        $phoneId = (string) ($credential['phone_number_id'] ?? '');
        if ($token === '' || $phoneId === '') {
            return ['ok' => false, 'media_id' => '', 'message' => 'Kredensial WhatsApp belum lengkap.'];
        }

        $url = $this->base($credential) . '/' . rawurlencode($phoneId) . '/media';
        $mime = function_exists('mime_content_type') ? (string) mime_content_type($filePath) : 'application/octet-stream';
        $post = [
            'messaging_product' => 'whatsapp',
            'file' => new CURLFile($filePath, $mime !== '' ? $mime : 'application/octet-stream', basename($filePath)),
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => max(10, $timeout),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $error !== '') {
            return ['ok' => false, 'media_id' => '', 'message' => 'Koneksi ke provider gagal.'];
        }

        $json = json_decode((string) $raw, true);
        if ($status < 200 || $status >= 300 || !is_array($json)) {
            $message = $this->errorMessage([
                'status' => $status,
                'json' => is_array($json) ? $json : [],
                'error' => '',
                'body' => (string) $raw,
            ]);

            return ['ok' => false, 'media_id' => '', 'message' => $message];
        }

        return [
            'ok' => true,
            'media_id' => (string) ($json['id'] ?? ''),
            'message' => 'Media diunggah.',
        ];
    }

    /**
     * @param array<string, mixed> $credential
     */
    private function base(array $credential): string
    {
        $base = trim((string) ($credential['base_url'] ?? ''));
        if ($base === '') {
            $base = $this->defaultBase();
        }

        return rtrim($base, '/');
    }

    /**
     * @return array{status:int,body:string,json:array<string,mixed>,error:string}
     */
    private function request(string $method, string $url, string $token, ?string $body, int $timeout): array
    {
        $ch = curl_init($url);
        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => min(10, max(3, $timeout)),
            CURLOPT_TIMEOUT => max(5, $timeout),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $error !== '') {
            return ['status' => $status, 'body' => '', 'json' => [], 'error' => 'Koneksi ke provider gagal.'];
        }

        $json = json_decode((string) $raw, true);

        return [
            'status' => $status,
            'body' => (string) $raw,
            'json' => is_array($json) ? $json : [],
            'error' => '',
        ];
    }

    /**
     * Ambil pesan error provider tanpa membocorkan token.
     *
     * @param array<string, mixed> $response
     */
    private function errorMessage(array $response): string
    {
        $json = is_array($response['json'] ?? null) ? $response['json'] : [];
        $message = (string) ($json['error']['message'] ?? '');
        $code = (string) ($json['error']['code'] ?? '');
        $status = (int) ($response['status'] ?? 0);

        if ($message === '') {
            return 'Provider menolak request (HTTP ' . $status . ').';
        }

        $safe = preg_replace('/\s+/', ' ', $message) ?? $message;
        $safe = mb_substr(trim($safe), 0, 180);

        return $code !== ''
            ? 'Provider error ' . mb_substr($code, 0, 20) . ': ' . $safe
            : 'Provider error: ' . $safe;
    }

    /**
     * @return array{ok:bool,status:string,code:string,message:string,provider_message_id:string,duration_ms:int}
     */
    private function result(
        bool $ok,
        string $code,
        string $message,
        string $providerMessageId,
        float $started,
        array $response = []
    ): array {
        if (isset($response['json']['error']['code'])) {
            $code = 'provider_' . (string) $response['json']['error']['code'];
        }

        return [
            'ok' => $ok,
            'status' => $ok ? 'accepted' : 'failed',
            'code' => mb_substr($code, 0, 64),
            'message' => mb_substr($message, 0, 255),
            'provider_message_id' => $providerMessageId,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }
}
