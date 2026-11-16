<?php
/**
 * Adapter Fonnte WhatsApp API.
 *
 * Kontrak resmi yang dipakai:
 * - POST https://api.fonnte.com/device  → tes koneksi/device profile
 * - POST https://api.fonnte.com/send    → kirim pesan teks
 *
 * Header Authorization berisi token Fonnte langsung (tanpa "Bearer").
 * Respons `status: true` berarti request diterima/diantrekan provider, BUKAN
 * bukti pesan sudah delivered ke pelanggan. Delivery tetap harus dikonfirmasi
 * melalui status/webhook provider.
 */

declare(strict_types=1);

final class WhatsAppFonnteProvider implements WhatsAppProviderInterface
{
    public function id(): string
    {
        return 'fonnte';
    }

    public function label(): string
    {
        return 'Fonnte WhatsApp API';
    }

    public function defaultBase(): string
    {
        return rtrim((string) config('api_center.fonnte_default_base', 'https://api.fonnte.com'), '/');
    }

    public function supportsTemplates(): bool
    {
        return false;
    }

    public function testConnection(array $credential, int $timeout = 20): array
    {
        $started = microtime(true);
        $token = trim((string) ($credential['token'] ?? ''));

        if ($token === '') {
            return $this->result(false, 'incomplete', 'API token Fonnte wajib diisi.', '', $started);
        }

        $response = $this->request('POST', $this->base($credential) . '/device', $token, '', $timeout);
        if ($response['error'] !== '') {
            return $this->result(false, 'network', $response['error'], '', $started);
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            return $this->result(
                false,
                'http_' . $response['status'],
                $this->errorMessage($response, $token),
                '',
                $started
            );
        }

        $json = $response['json'];
        if (!$this->providerOk($json)) {
            return $this->result(
                false,
                'provider_rejected',
                $this->errorMessage($response, $token),
                '',
                $started
            );
        }

        $deviceStatus = strtolower(trim((string) ($json['device_status'] ?? '')));
        if ($deviceStatus !== '' && $deviceStatus !== 'connect') {
            return $this->result(
                false,
                'device_disconnected',
                'Token valid, tetapi perangkat Fonnte sedang tidak terhubung.',
                '',
                $started
            );
        }

        $name = $this->safeText((string) ($json['name'] ?? ''), $token, 80);
        $device = $this->safeText((string) ($json['device'] ?? ''), $token, 30);
        $message = 'Terhubung ke Fonnte';
        if ($name !== '') {
            $message .= ' "' . $name . '"';
        }
        if ($device !== '') {
            $message .= ' (' . $this->maskNumber($device) . ')';
        }

        return $this->result(true, 'connected', $message . '.', '', $started);
    }

    public function sendText(array $credential, string $to, string $body, int $timeout = 20): array
    {
        $started = microtime(true);
        $token = trim((string) ($credential['token'] ?? ''));
        $target = whatsapp_number($to);

        if ($token === '') {
            return $this->result(false, 'incomplete', 'API token Fonnte wajib diisi.', '', $started);
        }
        if ($target === null) {
            return $this->result(false, 'invalid_recipient', 'Nomor WhatsApp tujuan tidak valid.', '', $started);
        }
        if (trim($body) === '') {
            return $this->result(false, 'empty_message', 'Isi pesan kosong.', '', $started);
        }

        $payload = [
            'target' => $target,
            'message' => mb_substr($body, 0, 60000),
            // Nomor sudah dinormalisasi ke E.164 tanpa "+". countryCode 0
            // mematikan penambahan kode negara otomatis milik Fonnte agar
            // nomor tidak berubah menjadi 6262....
            'countryCode' => '0',
            'connectOnly' => true,
            'preview' => false,
        ];

        $response = $this->request('POST', $this->base($credential) . '/send', $token, $payload, $timeout);
        if ($response['error'] !== '') {
            return $this->result(false, 'network', $response['error'], '', $started);
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            return $this->result(
                false,
                'http_' . $response['status'],
                $this->errorMessage($response, $token),
                '',
                $started
            );
        }

        $json = $response['json'];
        if (!$this->providerOk($json)) {
            return $this->result(
                false,
                'provider_rejected',
                $this->errorMessage($response, $token),
                '',
                $started
            );
        }

        $ids = $json['id'] ?? '';
        $messageId = is_array($ids)
            ? trim((string) ($ids[0] ?? ''))
            : trim((string) $ids);
        if ($messageId === '') {
            return $this->result(
                false,
                'invalid_response',
                'Provider menerima request tetapi tidak mengembalikan ID pesan.',
                '',
                $started
            );
        }

        $process = strtolower(trim((string) ($json['process'] ?? '')));
        $message = 'Permintaan pesan diterima Fonnte';
        if ($process !== '') {
            $message .= ' (status antrean: ' . $this->safeText($process, $token, 30) . ')';
        }

        // PENTING: accepted/pending bukan delivered.
        return $this->result(true, 'accepted', $message . '.', $messageId, $started);
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
        return $this->result(
            false,
            'template_not_supported',
            'Fonnte hanya mendukung pesan teks pada integrasi ini.',
            '',
            microtime(true)
        );
    }

    public function sendDocument(
        array $credential,
        string $to,
        string $filePath,
        string $filename,
        string $caption = '',
        int $timeout = 30
    ): array {
        return $this->result(
            false,
            'document_not_supported',
            'Pengiriman dokumen Fonnte belum diaktifkan pada integrasi ini.',
            '',
            microtime(true)
        );
    }

    /**
     * @param array<string, mixed> $credential
     */
    private function base(array $credential): string
    {
        $base = trim((string) ($credential['base_url'] ?? ''));

        return rtrim($base !== '' ? $base : $this->defaultBase(), '/');
    }

    /**
     * @param array<string, mixed>|string $body
     * @return array{status:int,body:string,json:array<string,mixed>,error:string}
     */
    private function request(string $method, string $url, string $token, array|string $body, int $timeout): array
    {
        $ch = curl_init($url);
        $headers = [
            // Fonnte memakai token langsung, bukan "Bearer <token>".
            'Authorization: ' . $token,
            'Accept: application/json',
        ];
        $encoded = is_array($body) ? http_build_query($body) : $body;
        if ($encoded !== '') {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
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
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $encoded;
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
     * Fonnte mengembalikan `status` boolean. Sebagian respons error lama
     * memakai `Status` kapital, jadi keduanya didukung.
     *
     * @param array<string, mixed> $json
     */
    private function providerOk(array $json): bool
    {
        $value = $json['status'] ?? $json['Status'] ?? null;

        if ($value === true || $value === 1) {
            return true;
        }

        return is_string($value) && in_array(strtolower(trim($value)), ['true', '1'], true);
    }

    /**
     * @param array<string, mixed> $response
     */
    private function errorMessage(array $response, string $token): string
    {
        $json = is_array($response['json'] ?? null) ? $response['json'] : [];
        $reason = (string) ($json['reason'] ?? $json['detail'] ?? '');
        $status = (int) ($response['status'] ?? 0);

        if ($reason === '') {
            return 'Provider menolak request (HTTP ' . $status . ').';
        }

        $safe = $this->safeText($reason, $token, 180);

        return 'Provider error: ' . ($safe !== '' ? $safe : 'request ditolak.');
    }

    private function safeText(string $value, string $token, int $max): string
    {
        $safe = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? $value;
        if ($token !== '') {
            $safe = str_replace($token, '[redacted]', $safe);
        }
        $safe = preg_replace('/\s+/', ' ', $safe) ?? $safe;

        return mb_substr(trim($safe), 0, $max);
    }

    private function maskNumber(string $number): string
    {
        $digits = preg_replace('/\D+/', '', $number) ?? '';
        if (strlen($digits) <= 4) {
            return $digits === '' ? '' : '••••';
        }

        return str_repeat('•', max(3, strlen($digits) - 4)) . substr($digits, -4);
    }

    /**
     * @return array{ok:bool,status:string,code:string,message:string,provider_message_id:string,duration_ms:int}
     */
    private function result(
        bool $ok,
        string $code,
        string $message,
        string $providerMessageId,
        float $started
    ): array {
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
