<?php
/**
 * Monitoring penggunaan AI (`ai_usage_log`).
 *
 * Hanya metadata yang disimpan: provider, model, jenis request, status, kode
 * error aman, durasi, dan jumlah token bila provider menyediakannya. Isi
 * percakapan, API key, dan data pribadi TIDAK PERNAH dicatat di sini.
 */

declare(strict_types=1);

final class AiUsage
{
    /**
     * Catat satu request AI. Kegagalan pencatatan tidak boleh menggagalkan chat.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $result hasil AiAssistantService (opsional)
     * @param array{prompt?: int, completion?: int, total?: int} $usage
     */
    public static function record(
        array $user,
        string $provider,
        string $model,
        string $type,
        bool $success,
        string $errorCode,
        int $durationMs,
        array $usage = [],
        ?string $source = null
    ): void {
        try {
            $source = $source ?? self::sourceFor($provider, $model);
            $statement = Database::pdo()->prepare(
                'INSERT INTO ai_usage_log
                    (user_id, role, provider, model, request_type, source, success, error_code,
                     duration_ms, prompt_tokens, completion_tokens, total_tokens)
                 VALUES
                    (:user_id, :role, :provider, :model, :request_type, :source, :success, :error_code,
                     :duration_ms, :prompt_tokens, :completion_tokens, :total_tokens)'
            );
            $statement->execute([
                'user_id' => (int) ($user['id'] ?? 0) > 0 ? (int) $user['id'] : null,
                'role' => mb_substr((string) ($user['role'] ?? ''), 0, 20),
                'provider' => mb_substr($provider, 0, 32),
                'model' => mb_substr($model, 0, 120),
                'request_type' => mb_substr($type, 0, 20),
                'source' => mb_substr($source, 0, 20),
                'success' => $success ? 1 : 0,
                'error_code' => $errorCode === '' ? null : mb_substr($errorCode, 0, 64),
                'duration_ms' => max(0, $durationMs),
                'prompt_tokens' => max(0, (int) ($usage['prompt'] ?? 0)),
                'completion_tokens' => max(0, (int) ($usage['completion'] ?? 0)),
                'total_tokens' => max(0, (int) ($usage['total'] ?? 0)),
            ]);
        } catch (Throwable $exception) {
            app_log('Gagal mencatat penggunaan AI: ' . $exception->getMessage());
        }
    }

    /**
     * Jumlah request chat pengguna hari ini (untuk batas harian server-side).
     */
    public static function countUserChatToday(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        try {
            [$start, $end] = self::dayRange(new DateTimeImmutable('now'));
            $statement = Database::pdo()->prepare(
                'SELECT COUNT(*) FROM ai_usage_log
                 WHERE user_id = :user_id AND request_type = \'chat\'
                   AND created_at >= :start AND created_at < :end'
            );
            $statement->execute(['user_id' => $userId, 'start' => $start, 'end' => $end]);

            return (int) $statement->fetchColumn();
        } catch (Throwable $exception) {
            app_log('Gagal menghitung kuota AI harian: ' . $exception->getMessage());

            return 0;
        }
    }

    /**
     * Statistik monitoring untuk halaman Admin. Angka dihitung dari log nyata.
     *
     * @return array<string, mixed>
     */
    public static function stats(int $dailyLimit): array
    {
        $now = new DateTimeImmutable('now');
        [$todayStart, $todayEnd] = self::dayRange($now);
        [$monthStart, $monthEnd] = self::monthRange($now);

        $empty = [
            'today_total' => 0,
            'today_success' => 0,
            'today_failed' => 0,
            'month_total' => 0,
            'month_success' => 0,
            'month_failed' => 0,
            'tokens_month' => 0,
            'last_test' => null,
            'today_by_user' => [],
            'local_month' => 0,
            'local_database_month' => 0,
            'local_faq_month' => 0,
            'cloud_month' => 0,
            'cloud_ai_month' => 0,
            'timeout_month' => 0,
            'local_database_month' => 0,
            'providers_month' => [],
            'daily_limit' => $dailyLimit,
            'estimated_cost' => null,
            'cost_note' => 'Estimasi biaya tidak ditampilkan karena tarif provider belum diverifikasi aplikasi.',
        ];

        try {
            $pdo = Database::pdo();

            $statement = $pdo->prepare(
                'SELECT COUNT(*) AS total,
                        COALESCE(SUM(success = 1), 0) AS sukses,
                        COALESCE(SUM(success = 0), 0) AS gagal
                 FROM ai_usage_log
                 WHERE created_at >= :start AND created_at < :end'
            );
            $statement->execute(['start' => $todayStart, 'end' => $todayEnd]);
            $today = $statement->fetch() ?: [];

            $statement = $pdo->prepare(
                'SELECT l.user_id,
                        COALESCE(u.nama, \'(pengguna dihapus)\') AS nama,
                        COALESCE(u.role, l.role, \'-\') AS role,
                        COUNT(*) AS total,
                        COALESCE(SUM(l.success = 0), 0) AS gagal,
                        MAX(l.created_at) AS terakhir
                 FROM ai_usage_log l
                 LEFT JOIN users u ON u.id = l.user_id
                 WHERE l.created_at >= :start AND l.created_at < :end
                   AND l.request_type = \'chat\'
                 GROUP BY l.user_id, u.nama, u.role, l.role
                 ORDER BY total DESC, terakhir DESC
                 LIMIT 20'
            );
            $statement->execute(['start' => $todayStart, 'end' => $todayEnd]);
            $todayByUser = $statement->fetchAll(PDO::FETCH_ASSOC);

            $statement = $pdo->prepare(
                'SELECT COUNT(*) AS total,
                        COALESCE(SUM(success = 1), 0) AS sukses,
                        COALESCE(SUM(success = 0), 0) AS gagal,
                        COALESCE(SUM(total_tokens), 0) AS token,
                        COALESCE(SUM(provider IN (\'local\', \'faq\', \'ollama\')), 0) AS lokal,
                        COALESCE(SUM(provider = \'local\' AND model = \'database-assistant\'), 0) AS lokal_db,
                        COALESCE(SUM(provider = \'local\' AND model = \'faq\'), 0) AS lokal_faq,
                        COALESCE(SUM(provider IN (\'gemini\', \'deepseek\', \'openai\')), 0) AS cloud,
                        COALESCE(SUM(provider IN (\'gemini\', \'deepseek\', \'openai\')), 0) AS cloud_ai,
                        COALESCE(SUM(success = 0 AND error_code IN (\'timeout\', \'network\')), 0) AS timeout,
                        COALESCE(SUM(source = \'local_database\'), 0) AS local_database
                 FROM ai_usage_log
                 WHERE created_at >= :start AND created_at < :end'
            );
            $statement->execute(['start' => $monthStart, 'end' => $monthEnd]);
            $month = $statement->fetch() ?: [];

            $statement = $pdo->prepare(
                'SELECT l.provider, l.model, l.source,
                        COUNT(*) AS total,
                        COALESCE(SUM(l.success = 1), 0) AS success,
                        COALESCE(SUM(l.success = 0), 0) AS failed,
                        COALESCE(SUM(l.prompt_tokens), 0) AS prompt_tokens,
                        COALESCE(SUM(l.completion_tokens), 0) AS completion_tokens,
                        COALESCE(SUM(l.total_tokens), 0) AS total_tokens,
                        COALESCE(SUM(l.total_tokens > 0), 0) AS token_reports,
                        COALESCE(AVG(l.duration_ms), 0) AS avg_duration_ms,
                        MAX(l.duration_ms) AS max_duration_ms,
                        MAX(l.created_at) AS last_at,
                        (SELECT l2.error_code FROM ai_usage_log l2
                         WHERE l2.provider = l.provider AND l2.model = l.model AND l2.success = 0
                         ORDER BY l2.id DESC LIMIT 1) AS last_error
                 FROM ai_usage_log l
                 WHERE l.created_at >= :start AND l.created_at < :end
                 GROUP BY l.provider, l.model, l.source
                 ORDER BY total DESC, last_at DESC
                 LIMIT 30'
            );
            $statement->execute(['start' => $monthStart, 'end' => $monthEnd]);
            $providersMonth = $statement->fetchAll(PDO::FETCH_ASSOC);

            $statement = $pdo->query(
                "SELECT provider, model, success, error_code, created_at
                 FROM ai_usage_log
                 WHERE request_type = 'test'
                 ORDER BY id DESC LIMIT 1"
            );
            $lastTest = $statement->fetch();

            return array_merge($empty, [
                'today_total' => (int) ($today['total'] ?? 0),
                'today_success' => (int) ($today['sukses'] ?? 0),
                'today_failed' => (int) ($today['gagal'] ?? 0),
                'month_total' => (int) ($month['total'] ?? 0),
                'month_success' => (int) ($month['sukses'] ?? 0),
                'month_failed' => (int) ($month['gagal'] ?? 0),
                'tokens_month' => (int) ($month['token'] ?? 0),
                'local_month' => (int) ($month['lokal'] ?? 0),
                'local_database_month' => (int) ($month['lokal_db'] ?? 0),
                'local_faq_month' => (int) ($month['lokal_faq'] ?? 0),
                'cloud_month' => (int) ($month['cloud'] ?? 0),
                'cloud_ai_month' => (int) ($month['cloud_ai'] ?? 0),
                'timeout_month' => (int) ($month['timeout'] ?? 0),
                'local_database_month' => (int) ($month['local_database'] ?? 0),
                'providers_month' => is_array($providersMonth) ? $providersMonth : [],
                'last_test' => is_array($lastTest) ? $lastTest : null,
                'today_by_user' => is_array($todayByUser) ? $todayByUser : [],
            ]);
        } catch (Throwable $exception) {
            app_log('Gagal membaca statistik penggunaan AI: ' . $exception->getMessage());

            return $empty;
        }
    }

    /**
     * Sumber permintaan yang dicatat eksplisit: local_database untuk jawaban
     * MySQL lokal, local_faq untuk FAQ, dan cloud_ai untuk provider cloud.
     */
    private static function sourceFor(string $provider, string $model): string
    {
        $provider = strtolower(trim($provider));
        if ($provider === 'faq' || str_contains(strtolower($model), 'faq')) {
            return 'local_faq';
        }
        if (in_array($provider, ['local', 'mysql-local', 'ollama'], true)) {
            return 'local_database';
        }
        if (in_array($provider, ['gemini', 'deepseek', 'openai'], true)) {
            return 'cloud_ai';
        }

        return 'other';
    }

    /** @return array{0: string, 1: string} */
    private static function dayRange(DateTimeImmutable $now): array
    {
        $start = $now->setTime(0, 0, 0);
        $end = $start->modify('+1 day');

        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
    }

    /** @return array{0: string, 1: string} */
    private static function monthRange(DateTimeImmutable $now): array
    {
        $start = $now->modify('first day of this month')->setTime(0, 0, 0);
        $end = $start->modify('+1 month');

        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
    }
}
