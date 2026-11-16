<?php
/**
 * Controller AI Assistant (Tahap 9).
 *
 * Menyediakan antarmuka chat (semua peran yang sudah masuk) dan endpoint
 * backend yang memanggil AI Assistant Service. Kunci API tidak pernah sampai ke
 * browser: seluruh pemanggilan provider dilakukan di sisi server.
 */

declare(strict_types=1);

require_once __DIR__ . '/Controller.php';

final class AiController extends Controller
{
    public function index(): void
    {
        $user = auth_user();
        if ($user === null) {
            redirect('/login');
            return;
        }

        $role = (string) $user['role'];

        $this->renderRole('ai/index', $role, 'ai', 'AI Assistant', [
            ['label' => AiKnowledge::roleLabel($role)],
            ['label' => 'AI Assistant'],
        ], [
            'includeAi' => true,
            'configured' => AiAssistantService::isAvailable(),
            'aiState' => AiSettingsService::state(),
            'quickQuestions' => AiKnowledge::quickQuestions($role),
            'csrf' => csrf_token(),
            'chatUrl' => url('/ai/chat'),
        ]);
    }

    public function chat(): void
    {
        // Identitas HANYA dari sesi login + database (bukan input browser).
        // fromSession() membaca ulang nama/username/role terbaru dan menolak
        // akun yang sudah dihapus/nonaktif/berole invalid.
        $user = AiIdentity::fromSession();
        if ($user === null) {
            $this->json([
                'ok' => false,
                'error' => 'Sesi Anda tidak valid atau telah berakhir. Silakan masuk kembali.',
                'code' => 'identity_invalid',
            ], 401);
            return;
        }

        $payload = $this->readPayload();
        $token = $this->readToken($payload);

        if (!csrf_verify($token)) {
            $this->json(['ok' => false, 'error' => 'Token keamanan tidak valid. Muat ulang halaman.'], 419);
            return;
        }

        $limit = AiRateLimiter::hit((int) $user['id']);
        if (!$limit['allowed']) {
            $this->json([
                'ok' => false,
                'error' => 'Terlalu banyak permintaan. Coba lagi dalam ' . $limit['retry_after'] . ' detik.',
                'code' => 'rate_limit',
                'retry_after' => $limit['retry_after'],
            ], 429);
            return;
        }

        // Batas harian per pengguna (server-side, dari log nyata).
        $dailyLimit = AiSettingsService::dailyLimit();
        $usedToday = AiUsage::countUserChatToday((int) $user['id']);
        if ($usedToday >= $dailyLimit) {
            $this->json([
                'ok' => false,
                'error' => 'Batas penggunaan AI harian Anda telah tercapai (' . $dailyLimit . ' permintaan). Silakan coba lagi besok.',
                'code' => 'daily_limit',
            ], 429);
            return;
        }

        $message = trim((string) ($payload['message'] ?? ''));

        if ($message === '') {
            $this->json(['ok' => false, 'error' => 'Pesan tidak boleh kosong.', 'code' => 'validation'], 422);
            return;
        }

        $maxLength = max(1, (int) AiSettingsService::runtime()['max_message']);
        if (mb_strlen($message) > $maxLength) {
            $this->json(['ok' => false, 'error' => 'Pesan terlalu panjang (maksimal ' . $maxLength . ' karakter).', 'code' => 'validation'], 422);
            return;
        }

        $startedAt = microtime(true);
        $result = AiAssistantService::chat($user, $message);
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        $provider = (string) ($result['provider'] ?? AiAssistantService::provider());
        $model = (string) ($result['model'] ?? (AiAssistantService::credentials()['model'] ?? ''));
        $requestType = (string) ($result['request_type'] ?? 'chat');
        // Jawaban database lokal adalah permintaan asisten yang sah. Hitung
        // sebagai "chat" agar batas harian Admin berlaku konsisten, sementara
        // detail sumber tetap tercatat di ai_data_access_log.
        if ($requestType === 'database') {
            $requestType = 'chat';
        }
        $source = match (true) {
            in_array($provider, ['gemini', 'deepseek', 'openai'], true) => 'cloud_ai',
            $provider === 'faq' || $model === 'faq' => 'local_faq',
            default => 'local_database',
        };
        AiUsage::record(
            $user,
            $provider,
            $model,
            $requestType,
            !empty($result['ok']),
            !empty($result['ok']) ? '' : (string) ($result['code'] ?? 'provider_error'),
            $durationMs,
            is_array($result['usage'] ?? null) ? $result['usage'] : [],
            $source
        );

        if (!$result['ok']) {
            $code = (string) ($result['code'] ?? 'provider_error');
            $status = $code === 'identity_invalid' ? 401 : 200;
            $payload = [
                'ok' => false,
                'error' => (string) ($result['error'] ?? 'Layanan AI bermasalah.'),
                'code' => $code,
            ];
            if (!empty($result['error_type'])) {
                $payload['error_type'] = (string) $result['error_type'];
            }
            if (isset($result['retry_after']) && is_numeric($result['retry_after'])) {
                $payload['retry_after'] = max(0, (int) $result['retry_after']);
            }
            $this->json($payload, $status);
            return;
        }

        $this->json([
            'ok' => true,
            'reply' => (string) $result['reply'],
            'tickets' => isset($result['tickets']) && is_array($result['tickets']) ? array_values($result['tickets']) : [],
            'tables' => isset($result['tables']) && is_array($result['tables']) ? array_values($result['tables']) : [],
            'remaining' => $limit['remaining'],
            'daily_remaining' => max(0, $dailyLimit - $usedToday - ($requestType === 'chat' ? 1 : 0)),
        ]);
    }

    /**
     * Bersihkan konteks percakapan sementara milik pengguna yang sedang masuk.
     * Tidak menghapus data aplikasi maupun log metadata penggunaan.
     */
    public function clear(): void
    {
        $user = auth_user();
        if ($user === null) {
            $this->json(['ok' => false, 'error' => 'Sesi Anda telah berakhir. Silakan masuk kembali.'], 401);
            return;
        }

        $payload = $this->readPayload();
        if (!csrf_verify($this->readToken($payload))) {
            $this->json(['ok' => false, 'error' => 'Token keamanan tidak valid. Muat ulang halaman.'], 419);
            return;
        }

        AiConversation::clear((int) $user['id']);
        AiContext::clear((int) $user['id']);
        $this->json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function readPayload(): array
    {
        $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');

        if (stripos($contentType, 'application/json') !== false) {
            $raw = file_get_contents('php://input');
            $decoded = json_decode($raw === false ? '' : $raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return $_POST;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function readToken(array $payload): ?string
    {
        $header = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($header !== '') {
            return $header;
        }

        return isset($payload['_token']) ? (string) $payload['_token'] : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status = 200): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
