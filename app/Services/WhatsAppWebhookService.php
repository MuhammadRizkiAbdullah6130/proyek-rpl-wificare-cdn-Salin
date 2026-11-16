<?php
/**
 * Webhook WhatsApp Cloud API.
 *
 * GET  : verifikasi hub.verify_token (dari kredensial terenkripsi).
 * POST : wajib signature X-Hub-Signature-256 yang valid; hanya status delivery
 *        yang diproses. Isi pesan masuk tidak disimpan.
 */

declare(strict_types=1);

final class WhatsAppWebhookService
{
    /**
     * @return array{ok:bool,code:string,message:string}
     */
    public static function verifyChallenge(int $connectionId, string $mode, string $token, string $challenge): array
    {
        $connection = ApiCredentialService::connection($connectionId);
        if ($connection === null) {
            return ['ok' => false, 'code' => 'not_found', 'message' => 'Koneksi tidak ditemukan.'];
        }

        $expected = (string) ($connection['webhook_verify_token'] ?? '');
        if ($expected === '') {
            return ['ok' => false, 'code' => 'not_configured', 'message' => 'Webhook verification token belum diatur.'];
        }
        if ($mode !== 'subscribe') {
            return ['ok' => false, 'code' => 'invalid_mode', 'message' => 'Mode verifikasi tidak valid.'];
        }
        if (!hash_equals($expected, $token)) {
            return ['ok' => false, 'code' => 'invalid_token', 'message' => 'Verification token tidak cocok.'];
        }
        if ($challenge === '' || !preg_match('/^[A-Za-z0-9._-]{1,255}$/', $challenge)) {
            return ['ok' => false, 'code' => 'invalid_challenge', 'message' => 'Challenge tidak valid.'];
        }

        return ['ok' => true, 'code' => 'verified', 'message' => $challenge];
    }

    /**
     * @return array{ok:bool,code:string,message:string,updated:int}
     */
    public static function handle(int $connectionId, string $rawBody, string $signature): array
    {
        $connection = ApiCredentialService::connection($connectionId);
        if ($connection === null) {
            return ['ok' => false, 'code' => 'not_found', 'message' => 'Koneksi tidak ditemukan.', 'updated' => 0];
        }

        $secret = (string) ($connection['webhook_secret'] ?? '');
        if ($secret === '') {
            return [
                'ok' => false,
                'code' => 'webhook_secret_missing',
                'message' => 'Webhook secret belum dikonfigurasi.',
                'updated' => 0,
            ];
        }

        if (!WhatsAppCloudProvider::verifySignature($rawBody, $signature, $secret)) {
            return ['ok' => false, 'code' => 'invalid_signature', 'message' => 'Signature tidak valid.', 'updated' => 0];
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return ['ok' => false, 'code' => 'invalid_payload', 'message' => 'Payload tidak valid.', 'updated' => 0];
        }

        $statuses = self::statuses($payload);
        $updated = 0;
        foreach ($statuses as $status) {
            $id = (string) ($status['provider_message_id'] ?? '');
            $name = (string) ($status['status'] ?? '');
            if ($id === '' || $name === '') {
                continue;
            }

            if (WhatsAppOutbox::applyProviderStatus($id, $name, (string) ($status['error_code'] ?? ''))) {
                $updated++;
            }
            if ($name === 'failed') {
                WhatsAppOtp::updateSendStatusByProviderId($id, 'failed');
            }
        }

        return ['ok' => true, 'code' => 'processed', 'message' => 'Webhook diproses.', 'updated' => $updated];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array{provider_message_id:string,status:string,error_code:string}>
     */
    public static function statuses(array $payload): array
    {
        $result = [];
        $entries = is_array($payload['entry'] ?? null) ? $payload['entry'] : [];
        foreach ($entries as $entry) {
            $changes = is_array($entry['changes'] ?? null) ? $entry['changes'] : [];
            foreach ($changes as $change) {
                $value = is_array($change['value'] ?? null) ? $change['value'] : [];
                $statuses = is_array($value['statuses'] ?? null) ? $value['statuses'] : [];
                foreach ($statuses as $status) {
                    $id = (string) ($status['id'] ?? '');
                    $name = strtolower((string) ($status['status'] ?? ''));
                    if ($id === '' || !in_array($name, ['sent', 'delivered', 'read', 'failed'], true)) {
                        continue;
                    }
                    $errorCode = '';
                    if ($name === 'failed' && is_array($status['errors'][0] ?? null)) {
                        $errorCode = (string) ($status['errors'][0]['code'] ?? 'provider_failed');
                    }
                    $result[] = [
                        'provider_message_id' => $id,
                        'status' => $name,
                        'error_code' => $errorCode,
                    ];
                }
            }
        }

        return $result;
    }
}
