<?php
/**
 * Endpoint webhook WhatsApp Cloud API (publik, tanpa session).
 *
 * GET  diverifikasi dengan verify token koneksi.
 * POST wajib signature valid; tidak menyimpan isi pesan masuk.
 */

declare(strict_types=1);

final class WhatsAppWebhookController
{
    /**
     * @param array<string, string> $params
     */
    public function verify(array $params): void
    {
        $connectionId = (int) ($params['id'] ?? 0);
        $mode = (string) ($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '');
        $token = (string) ($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '');
        $challenge = (string) ($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '');

        $result = WhatsAppWebhookService::verifyChallenge($connectionId, $mode, $token, $challenge);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');

        if (empty($result['ok'])) {
            http_response_code(403);
            echo 'Forbidden';
            return;
        }

        http_response_code(200);
        echo (string) $result['message'];
    }

    /**
     * @param array<string, string> $params
     */
    public function receive(array $params): void
    {
        $connectionId = (int) ($params['id'] ?? 0);
        $raw = file_get_contents('php://input');
        $signature = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
        if ($signature === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strtolower((string) $name) === 'x-hub-signature-256') {
                    $signature = (string) $value;
                    break;
                }
            }
        }

        $result = WhatsAppWebhookService::handle($connectionId, $raw === false ? '' : $raw, $signature);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        http_response_code(!empty($result['ok']) ? 200 : 403);
        echo json_encode([
            'ok' => !empty($result['ok']),
            'code' => (string) ($result['code'] ?? ''),
            'updated' => (int) ($result['updated'] ?? 0),
        ], JSON_UNESCAPED_UNICODE);
    }
}
