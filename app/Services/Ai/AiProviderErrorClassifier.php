<?php
/**
 * Klasifikasi error provider AI (Gemini, DeepSeek, OpenAI) secara terpusat.
 *
 * Tujuan:
 * - membedakan authentication, permission, rate limit sementara, kuota/kredit
 *   habis, timeout, gangguan jaringan, model tidak tersedia, dan server error;
 * - TIDAK menganggap semua HTTP 429 sebagai kuota habis;
 * - hanya memakai kode/status/metadata yang benar-benar dikirim provider;
 * - tidak menyimpan atau mengembalikan API key, header Authorization, atau
 *   respons mentah.
 */

declare(strict_types=1);

final class AiProviderErrorClassifier
{
    /**
     * Klasifikasi kegagalan transport cURL (tidak ada respons HTTP).
     *
     * @return array<string, mixed>
     */
    public static function transport(string $provider, int $errno): array
    {
        $type = $errno === 28 ? 'timeout' : 'network_error';

        return self::result(
            $provider,
            $type,
            $errno === 28 ? 'timeout' : 'network',
            $errno === 28
                ? 'Layanan AI tidak merespons tepat waktu. Jawaban database lokal tetap tersedia.'
                : 'Layanan AI tidak dapat dihubungi. Periksa koneksi internet. Jawaban database lokal tetap tersedia.',
            null,
            null,
            ''
        );
    }

    /**
     * Klasifikasi respons HTTP error dari provider.
     *
     * @param array<string, mixed> $decoded Body JSON yang sudah didekode (bila valid).
     * @param array<string, string|int> $headers Header respons (nama huruf kecil).
     * @return array<string, mixed>
     */
    public static function http(string $provider, int $status, array $decoded, array $headers = []): array
    {
        $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        $reason = strtoupper(trim((string) (
            $error['status']
            ?? $error['type']
            ?? $error['code']
            ?? ''
        )));
        $providerMessage = trim((string) ($error['message'] ?? ''));
        $providerCode = self::providerCode($error, $reason);
        $retryAfter = self::retryAfter($headers, $decoded);
        $haystack = self::haystack($reason, $providerMessage, $error);

        if (self::isAuthentication($status, $haystack)) {
            return self::result(
                $provider,
                'authentication_error',
                'invalid_key',
                'Kunci API layanan AI tidak valid. Hubungi Administrator.',
                $status,
                $retryAfter,
                $providerCode
            );
        }

        if (self::isQuotaExceeded($status, $haystack)) {
            return self::result(
                $provider,
                'quota_exceeded',
                'quota_exceeded',
                'Kuota atau kredit layanan AI telah habis. Jawaban database lokal tetap tersedia.',
                $status,
                $retryAfter,
                $providerCode
            );
        }

        if (self::isPermission($status, $haystack)) {
            return self::result(
                $provider,
                'permission_error',
                'permission_denied',
                'Kunci API layanan AI tidak memiliki izin untuk model atau aksi ini. Hubungi Administrator.',
                $status,
                $retryAfter,
                $providerCode
            );
        }

        if ($status === 429 || self::hasAny($haystack, ['rate limit', 'too many requests', 'requests per minute', 'tokens per minute', 'rpm', 'tpm'])) {
            $message = 'Batas permintaan layanan AI tercapai sementara.';
            $message .= $retryAfter !== null
                ? ' Coba lagi dalam ' . $retryAfter . ' detik.'
                : ' Coba lagi beberapa saat lagi.';

            return self::result(
                $provider,
                'rate_limit',
                'rate_limit',
                $message,
                $status,
                $retryAfter,
                $providerCode
            );
        }

        if ($status === 404 || self::hasAny($haystack, [
            'model_not_found', 'model not found', 'no such model', 'model does not exist',
            'does not exist', 'not supported model', 'unsupported model', 'model is not available',
            'not available for',
        ])) {
            return self::result(
                $provider,
                'model_unavailable',
                'model_unavailable',
                'Model AI tidak ditemukan atau tidak tersedia. Hubungi Administrator.',
                $status,
                $retryAfter,
                $providerCode
            );
        }

        if ($status >= 500) {
            return self::result(
                $provider,
                'server_error',
                'server',
                'Layanan AI sedang bermasalah. Silakan coba lagi beberapa saat.',
                $status,
                $retryAfter,
                $providerCode
            );
        }

        if ($status === 400 || $status === 422) {
            return self::result(
                $provider,
                'bad_request',
                'bad_request',
                'Permintaan ke layanan AI tidak valid. Periksa konfigurasi model.',
                $status,
                $retryAfter,
                $providerCode
            );
        }

        return self::result(
            $provider,
            'provider_error',
            'provider_error',
            'Layanan AI sedang bermasalah. Silakan coba lagi nanti.',
            $status,
            $retryAfter,
            $providerCode
        );
    }

    /**
     * Pesan aman untuk hasil uji koneksi provider.
     *
     * @param array<string, mixed> $failure
     */
    public static function testMessage(array $failure): string
    {
        return (string) ($failure['error'] ?? 'Gagal terhubung ke layanan AI.');
    }

    /**
     * @param array<string, mixed> $error
     */
    private static function providerCode(array $error, string $reason): string
    {
        $candidates = [
            $reason,
            (string) ($error['code'] ?? ''),
            (string) ($error['type'] ?? ''),
        ];
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '') {
                return mb_substr($candidate, 0, 120);
            }
        }

        return '';
    }

    /**
     * @param array<string, string|int> $headers
     * @param array<string, mixed> $decoded
     */
    private static function retryAfter(array $headers, array $decoded): ?int
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = is_array($value)
                ? (string) ($value[0] ?? '')
                : (string) $value;
        }

        foreach (['retry-after-ms', 'retryafterms'] as $key) {
            if (isset($normalized[$key]) && is_numeric($normalized[$key])) {
                return max(0, (int) ceil(((float) $normalized[$key]) / 1000));
            }
        }

        $retryAfter = trim((string) ($normalized['retry-after'] ?? ''));
        if ($retryAfter !== '') {
            if (is_numeric($retryAfter)) {
                return max(0, (int) ceil((float) $retryAfter));
            }
            $timestamp = strtotime($retryAfter);
            if ($timestamp !== false) {
                return max(0, $timestamp - time());
            }
        }

        // Gemini kadang menyertakan retryDelay pada error.details.
        $details = self::flatten($decoded['error']['details'] ?? []);
        if (preg_match('/retryDelay["\']?\s*[:=]\s*["\']?([0-9]+(?:\.[0-9]+)?)s/i', $details, $match) === 1) {
            return max(0, (int) ceil((float) $match[1]));
        }
        if (preg_match('/retry_after["\']?\s*[:=]\s*["\']?([0-9]+)/i', $details, $match) === 1) {
            return max(0, (int) $match[1]);
        }

        return null;
    }

    private static function isAuthentication(int $status, string $haystack): bool
    {
        if ($status === 401) {
            return true;
        }

        return self::hasAny($haystack, [
            'unauthenticated',
            'invalid_api_key',
            'api_key_invalid',
            'api key not valid',
            'api key invalid',
            'invalid api key',
            'incorrect api key',
            'invalid authentication',
            'authentication failed',
            'access token expired',
        ]);
    }

    private static function isPermission(int $status, string $haystack): bool
    {
        if ($status === 403) {
            // 403 dengan pesan kunci tidak valid tetap dianggap autentikasi.
            return !self::isAuthentication($status, $haystack);
        }

        return self::hasAny($haystack, [
            'permission_denied',
            'permission denied',
            'does not have permission',
            'caller does not have permission',
            'not authorized for',
            'forbidden',
        ]);
    }

    private static function isQuotaExceeded(int $status, string $haystack): bool
    {
        if ($status === 402) {
            return true;
        }

        // Sinyal kuota/kredit yang keras: saldo habis, billing, atau batas
        // harian/free-tier. "quota exceeded" saja TIDAK cukup karena Gemini
        // juga memakai frasa itu untuk batas per menit.
        $hardQuota = [
            'insufficient_quota',
            'insufficient quota',
            'insufficient balance',
            'insufficient_credit',
            'insufficient credit',
            'credit balance',
            'billing_hard_limit',
            'billing hard limit',
            'exceeded your current quota',
            'exceeded your quota',
            'quota_exceeded',
            'quota exhausted',
            'quota has been exceeded',
            'daily limit',
            'per day',
            'perday',
            'free tier',
            'out of credits',
            'payment required',
        ];
        if (self::hasAny($haystack, $hardQuota)) {
            return true;
        }

        // Metadata batas per menit/detik menunjukkan rate limit sementara,
        // bukan kuota bulanan/harian yang habis.
        $rateEvidence = [
            'per minute',
            'perminute',
            'per second',
            'persecond',
            'requests per minute',
            'tokens per minute',
            'rpm',
            'tpm',
            'rate limit',
            'too many requests',
            'retrydelay',
        ];
        if (self::hasAny($haystack, $rateEvidence)) {
            return false;
        }

        // Frasa kuota generik hanya dipakai bila tidak ada bukti rate limit.
        return self::hasAny($haystack, ['quota exceeded', 'resource_exhausted']);
    }

    /**
     * @param array<string, mixed> $error
     */
    private static function haystack(string $reason, string $message, array $error): string
    {
        return strtolower($reason . ' ' . $message . ' ' . self::flatten($error));
    }

    private static function flatten(mixed $value, int $depth = 0): string
    {
        if ($depth > 6 || $value === null) {
            return '';
        }
        if (is_scalar($value)) {
            return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }
        if (!is_array($value)) {
            return '';
        }

        $parts = [];
        foreach ($value as $key => $item) {
            $parts[] = is_string($key) ? $key . '=' . self::flatten($item, $depth + 1) : self::flatten($item, $depth + 1);
        }

        return mb_substr(implode(' ', $parts), 0, 4000);
    }

    /**
     * @param array<int, string> $needles
     */
    private static function hasAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private static function result(
        string $provider,
        string $type,
        string $code,
        string $message,
        ?int $httpStatus,
        ?int $retryAfter,
        string $providerCode
    ): array {
        return [
            'ok' => false,
            'code' => $code,
            'error_type' => $type,
            'error' => $message,
            'message' => $message,
            'retry' => in_array($type, ['rate_limit', 'server_error', 'model_unavailable'], true),
            'http_status' => $httpStatus,
            'retry_after' => $retryAfter,
            'provider_code' => mb_substr($providerCode, 0, 120),
            'provider' => $provider,
        ];
    }
}
