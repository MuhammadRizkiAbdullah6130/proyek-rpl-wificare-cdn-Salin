<?php
/**
 * Monitoring penggunaan API non-AI (`api_usage_log`).
 *
 * Hanya metadata yang disimpan: fitur, provider, event, status, kode error,
 * durasi, dan jumlah token bila ada. Isi pesan, OTP, token, dan data sensitif
 * tidak pernah ditulis.
 */

declare(strict_types=1);

final class ApiUsageLog
{
    /**
     * Catat satu aktivitas API. Kegagalan pencatatan tidak boleh menggagalkan
     * alur utama.
     *
     * @param array{prompt?: int, completion?: int, total?: int} $tokens
     */
    public static function record(
        string $feature,
        string $provider,
        ?int $connectionId,
        string $eventType,
        string $status,
        bool $success,
        string $externalId = '',
        string $errorCode = '',
        int $durationMs = 0,
        array $tokens = []
    ): void {
        try {
            $statement = Database::pdo()->prepare(
                'INSERT INTO api_usage_log
                    (feature_key, provider, connection_id, event_type, status, success,
                     external_id, error_code, duration_ms, prompt_tokens, completion_tokens, total_tokens)
                 VALUES
                    (:feature_key, :provider, :connection_id, :event_type, :status, :success,
                     :external_id, :error_code, :duration_ms, :prompt_tokens, :completion_tokens, :total_tokens)'
            );
            $statement->execute([
                'feature_key' => mb_substr($feature, 0, 40),
                'provider' => mb_substr($provider, 0, 40),
                'connection_id' => $connectionId !== null && $connectionId > 0 ? $connectionId : null,
                'event_type' => mb_substr($eventType, 0, 60),
                'status' => mb_substr($status, 0, 20),
                'success' => $success ? 1 : 0,
                'external_id' => $externalId !== '' ? mb_substr($externalId, 0, 128) : null,
                'error_code' => $errorCode !== '' ? mb_substr($errorCode, 0, 64) : null,
                'duration_ms' => max(0, $durationMs),
                'prompt_tokens' => isset($tokens['prompt']) ? (int) $tokens['prompt'] : null,
                'completion_tokens' => isset($tokens['completion']) ? (int) $tokens['completion'] : null,
                'total_tokens' => isset($tokens['total']) ? (int) $tokens['total'] : null,
            ]);
        } catch (Throwable $exception) {
            app_log('Gagal mencatat penggunaan API ' . $feature . '.');
        }
    }

    /**
     * @return array{total:int,success:int,failed:int,by_status:array<string,int>,tokens:int}
     */
    public static function summary(string $feature, string $from = '', string $to = ''): array
    {
        [$where, $params] = self::rangeWhere($feature, $from, $to);
        $sql = 'SELECT COUNT(*) AS total,
                       SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) AS success,
                       SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) AS failed,
                       COALESCE(SUM(total_tokens), 0) AS tokens
                FROM api_usage_log
                WHERE ' . $where;
        $statement = Database::pdo()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch() ?: [];

        $statusStatement = Database::pdo()->prepare(
            'SELECT status, COUNT(*) AS jumlah
             FROM api_usage_log
             WHERE ' . $where . '
             GROUP BY status'
        );
        $statusStatement->execute($params);
        $byStatus = [];
        foreach ($statusStatement->fetchAll() as $item) {
            $byStatus[(string) $item['status']] = (int) $item['jumlah'];
        }

        return [
            'total' => (int) ($row['total'] ?? 0),
            'success' => (int) ($row['success'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'by_status' => $byStatus,
            'tokens' => (int) ($row['tokens'] ?? 0),
        ];
    }

    public static function countEvent(
        string $feature,
        string $eventType,
        string $from = '',
        string $to = '',
        ?bool $success = null
    ): int {
        [$where, $params] = self::rangeWhere($feature, $from, $to);
        $where .= ' AND event_type = :event_type';
        $params['event_type'] = $eventType;
        if ($success !== null) {
            $where .= ' AND success = :success';
            $params['success'] = $success ? 1 : 0;
        }

        $statement = Database::pdo()->prepare('SELECT COUNT(*) FROM api_usage_log WHERE ' . $where);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array<int, string> $eventTypes
     */
    public static function countEvents(
        string $feature,
        array $eventTypes,
        string $from = '',
        string $to = '',
        ?bool $success = null
    ): int {
        if ($eventTypes === []) {
            return 0;
        }

        [$where, $params] = self::rangeWhere($feature, $from, $to);
        $placeholders = [];
        foreach (array_values($eventTypes) as $index => $event) {
            $name = 'e' . $index;
            $placeholders[] = ':' . $name;
            $params[$name] = $event;
        }
        $where .= ' AND event_type IN (' . implode(', ', $placeholders) . ')';
        if ($success !== null) {
            $where .= ' AND success = :success';
            $params['success'] = $success ? 1 : 0;
        }

        $statement = Database::pdo()->prepare('SELECT COUNT(*) FROM api_usage_log WHERE ' . $where);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    /**
     * Statistik per fitur/provider untuk tabel monitoring.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function grouped(string $from = '', string $to = '', string $feature = '', string $provider = ''): array
    {
        $where = '1 = 1';
        $params = [];
        if ($from !== '') {
            $where .= ' AND created_at >= :from_date';
            $params['from_date'] = $from . ' 00:00:00';
        }
        if ($to !== '') {
            $where .= ' AND created_at <= :to_date';
            $params['to_date'] = $to . ' 23:59:59';
        }
        if ($feature !== '') {
            $where .= ' AND feature_key = :feature';
            $params['feature'] = $feature;
        }
        if ($provider !== '') {
            $where .= ' AND provider = :provider';
            $params['provider'] = $provider;
        }

        $statement = Database::pdo()->prepare(
            'SELECT feature_key, provider,
                    COUNT(*) AS requests,
                    SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) AS success,
                    SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) AS failed,
                    COALESCE(SUM(total_tokens), 0) AS tokens,
                    MAX(created_at) AS last_activity
             FROM api_usage_log
             WHERE ' . $where . '
             GROUP BY feature_key, provider
             ORDER BY requests DESC'
        );
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public static function recent(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $statement = Database::pdo()->query(
            'SELECT feature_key, provider, event_type, status, success, error_code, created_at
             FROM api_usage_log
             ORDER BY id DESC
             LIMIT ' . $limit
        );

        return $statement->fetchAll();
    }

    /**
     * @return array{0:string,1:array<string,mixed>}
     */
    private static function rangeWhere(string $feature, string $from, string $to): array
    {
        $where = 'feature_key = :feature';
        $params = ['feature' => $feature];
        if ($from !== '') {
            $where .= ' AND created_at >= :from_date';
            $params['from_date'] = $from . ' 00:00:00';
        }
        if ($to !== '') {
            $where .= ' AND created_at <= :to_date';
            $params['to_date'] = $to . ' 23:59:59';
        }

        return [$where, $params];
    }
}
