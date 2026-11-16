<?php
/**
 * Rate limiter AI Assistant (Tahap 9).
 *
 * Pembatasan berbasis session per pengguna untuk mencegah penyalahgunaan API
 * eksternal. Riwayat percakapan TIDAK disimpan; hanya penghitung jendela waktu.
 */

declare(strict_types=1);

final class AiRateLimiter
{
    /**
     * Periksa & catat satu permintaan.
     *
     * @return array{allowed: bool, remaining: int, retry_after: int}
     */
    public static function hit(int $userId): array
    {
        $runtime = AiSettingsService::runtime();
        $limit = max(1, (int) $runtime['rate_limit']);
        $window = max(1, (int) $runtime['rate_window']);
        $now = time();
        $key = 'ai_rate_' . $userId;

        $state = $_SESSION[$key] ?? null;
        if (!is_array($state) || !isset($state['start'], $state['count'])) {
            $state = ['start' => $now, 'count' => 0];
        }

        if (($now - (int) $state['start']) >= $window) {
            $state = ['start' => $now, 'count' => 0];
        }

        if ((int) $state['count'] >= $limit) {
            return [
                'allowed' => false,
                'remaining' => 0,
                'retry_after' => max(1, $window - ($now - (int) $state['start'])),
            ];
        }

        $state['count'] = (int) $state['count'] + 1;
        $_SESSION[$key] = $state;

        return [
            'allowed' => true,
            'remaining' => $limit - (int) $state['count'],
            'retry_after' => 0,
        ];
    }
}
