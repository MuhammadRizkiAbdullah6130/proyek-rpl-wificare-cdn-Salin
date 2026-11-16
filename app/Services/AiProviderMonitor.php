<?php
/**
 * Monitoring kegagalan provider AI dan notifikasi Admin.
 *
 * Data yang disimpan HANYA metadata aman: provider, model, tipe/status error,
 * HTTP status, retry-after bila provider mengirimkannya, dan waktu kejadian.
 * Pesan mentah provider, API key, header Authorization, prompt, serta data
 * pelanggan tidak disimpan.
 *
 * Notifikasi kuota/rate limit dikirim ke Admin sekali per jendela waktu untuk
 * mencegah banjir notifikasi. Kegagalan pencatatan tidak boleh menggagalkan
 * jawaban chat.
 */

declare(strict_types=1);

final class AiProviderMonitor
{
    /** Jendela deduplikasi notifikasi kuota (detik). */
    private const QUOTA_NOTIFICATION_WINDOW = 21600; // 6 jam

    /** Jendela deduplikasi notifikasi rate limit (detik). */
    private const RATE_NOTIFICATION_WINDOW = 3600; // 1 jam

    /** @var array<int, string> */
    private const PROVIDERS = ['gemini', 'deepseek', 'openai'];

    /**
     * Catat kegagalan provider dan, bila perlu, kirim notifikasi Admin.
     *
     * @param array<string, mixed> $failure Hasil AiProviderErrorClassifier.
     */
    public static function recordFailure(string $provider, string $model, array $failure): void
    {
        $provider = strtolower(trim($provider));
        $model = trim($model);
        if ($provider === '' || !in_array($provider, self::PROVIDERS, true)) {
            return;
        }

        $eventType = self::normalizeEventType((string) (
            $failure['error_type']
            ?? $failure['code']
            ?? 'provider_error'
        ));
        $httpStatus = isset($failure['http_status']) && is_numeric($failure['http_status'])
            ? (int) $failure['http_status']
            : null;
        $retryAfter = isset($failure['retry_after']) && is_numeric($failure['retry_after'])
            ? max(0, (int) $failure['retry_after'])
            : null;
        $providerCode = mb_substr((string) ($failure['provider_code'] ?? ''), 0, 120);
        $notifiable = in_array($eventType, ['quota_exceeded', 'rate_limit'], true);

        $dedupKey = null;
        if ($notifiable) {
            $window = $eventType === 'rate_limit'
                ? self::RATE_NOTIFICATION_WINDOW
                : self::QUOTA_NOTIFICATION_WINDOW;
            $dedupKey = hash(
                'sha256',
                $provider . '|' . $model . '|' . $eventType . '|' . intdiv(time(), $window)
            );
        }

        try {
            $statement = Database::pdo()->prepare(
                'INSERT IGNORE INTO ai_provider_events
                    (provider, model, event_type, error_code, http_status, retry_after_seconds,
                     provider_code, dedup_key, notification_sent)
                 VALUES
                    (:provider, :model, :event_type, :error_code, :http_status, :retry_after,
                     :provider_code, :dedup_key, 0)'
            );
            $statement->execute([
                'provider' => mb_substr($provider, 0, 32),
                'model' => mb_substr($model, 0, 120),
                'event_type' => mb_substr($eventType, 0, 40),
                'error_code' => mb_substr((string) ($failure['code'] ?? $eventType), 0, 64),
                'http_status' => $httpStatus,
                'retry_after' => $retryAfter,
                'provider_code' => $providerCode,
                'dedup_key' => $dedupKey,
            ]);

            if ($statement->rowCount() < 1) {
                return;
            }

            $eventId = (int) Database::pdo()->lastInsertId();
            if ($notifiable) {
                $sent = self::notifyAdmins($provider, $model, $eventType, $httpStatus, $retryAfter, $providerCode);
                if ($sent > 0 && $eventId > 0) {
                    $update = Database::pdo()->prepare(
                        'UPDATE ai_provider_events SET notification_sent = 1 WHERE id = :id'
                    );
                    $update->execute(['id' => $eventId]);
                }
            }
        } catch (Throwable $exception) {
            // Bila tabel migrasi belum ada, notifikasi tetap dicoba dengan
            // deduplikasi berbasis notifikasi terakhir. Kegagalan monitoring
            // tidak boleh menggagalkan chat.
            app_log('Gagal mencatat event provider AI: ' . $exception->getMessage());
            if ($notifiable) {
                self::notifyAdminsFallback($provider, $model, $eventType, $httpStatus, $retryAfter, $providerCode);
            }
        }
    }

    /**
     * Data monitoring aman untuk halaman Admin.
     *
     * @return array<string, mixed>
     */
    public static function overview(int $days = 30): array
    {
        $emptyCounts = [
            'total' => 0,
            'quota_exceeded' => 0,
            'rate_limit' => 0,
            'authentication_error' => 0,
            'permission_error' => 0,
            'timeout' => 0,
            'network_error' => 0,
            'model_unavailable' => 0,
            'server_error' => 0,
            'other' => 0,
        ];

        try {
            $statuses = [];
            foreach (self::PROVIDERS as $provider) {
                $statuses[] = self::providerStatus($provider);
            }

            $events = self::recentEvents(15);
            $counts = self::eventCounts($days);

            return [
                'available' => true,
                'statuses' => $statuses,
                'events' => $events,
                'counts' => $counts,
                'days' => $days,
            ];
        } catch (Throwable $exception) {
            app_log('Gagal membaca monitoring provider AI: ' . $exception->getMessage());

            return [
                'available' => false,
                'statuses' => [],
                'events' => [],
                'counts' => $emptyCounts,
                'days' => $days,
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function providerStatus(string $provider): array
    {
        $label = AiProviderRegistry::has($provider)
            ? AiProviderRegistry::label($provider)
            : ucfirst($provider);

        $event = self::latestEvent($provider);
        $usage = self::latestUsage($provider);

        $status = 'unknown';
        $statusLabel = 'Belum ada data';
        $tone = 'muted';
        $model = '';
        $lastAt = null;

        if ($usage !== null) {
            $model = (string) ($usage['model'] ?? '');
            $lastAt = (string) ($usage['created_at'] ?? '');
        }

        if ($event !== null) {
            $model = (string) ($event['model'] ?? $model);
            $lastAt = (string) ($event['created_at'] ?? $lastAt);
            $eventTime = strtotime((string) ($event['created_at'] ?? '')) ?: 0;
            $usageTime = $usage !== null ? (strtotime((string) ($usage['created_at'] ?? '')) ?: 0) : 0;
            $usageSuccess = $usage !== null && (int) ($usage['success'] ?? 0) === 1;

            if ($usageSuccess && $usageTime >= $eventTime) {
                $status = 'ok';
                $statusLabel = 'Normal';
                $tone = 'success';
            } else {
                [$status, $statusLabel, $tone] = self::eventStatus((string) ($event['event_type'] ?? ''));
            }
        } elseif ($usage !== null) {
            if ((int) ($usage['success'] ?? 0) === 1) {
                $status = 'ok';
                $statusLabel = 'Normal';
                $tone = 'success';
            } else {
                [$status, $statusLabel, $tone] = self::eventStatus(self::normalizeEventType((string) ($usage['error_code'] ?? '')));
            }
        }

        return [
            'provider' => $provider,
            'label' => $label,
            'model' => $model,
            'status' => $status,
            'status_label' => $statusLabel,
            'tone' => $tone,
            'last_event_at' => $lastAt,
            'last_event_label' => $event === null ? null : self::eventLabel((string) ($event['event_type'] ?? '')),
            'retry_after' => $event === null ? null : (int) ($event['retry_after_seconds'] ?? 0),
            'notification_sent' => $event !== null && (int) ($event['notification_sent'] ?? 0) === 1,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function latestEvent(string $provider): ?array
    {
        $statement = Database::pdo()->prepare(
            'SELECT provider, model, event_type, error_code, http_status, retry_after_seconds,
                    provider_code, notification_sent, created_at
             FROM ai_provider_events
             WHERE provider = :provider
             ORDER BY id DESC LIMIT 1'
        );
        $statement->execute(['provider' => $provider]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function latestUsage(string $provider): ?array
    {
        $statement = Database::pdo()->prepare(
            'SELECT model, success, error_code, created_at
             FROM ai_usage_log
             WHERE provider = :provider
             ORDER BY id DESC LIMIT 1'
        );
        $statement->execute(['provider' => $provider]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function recentEvents(int $limit): array
    {
        $limit = max(1, min(50, $limit));
        $statement = Database::pdo()->prepare(
            'SELECT provider, model, event_type, error_code, http_status, retry_after_seconds,
                    provider_code, notification_sent, created_at
             FROM ai_provider_events
             ORDER BY id DESC LIMIT ' . $limit
        );
        $statement->execute();
        $rows = $statement->fetchAll();

        $events = [];
        foreach ($rows as $row) {
            $events[] = [
                'provider' => (string) ($row['provider'] ?? ''),
                'provider_label' => AiProviderRegistry::has((string) ($row['provider'] ?? ''))
                    ? AiProviderRegistry::label((string) $row['provider'])
                    : (string) ($row['provider'] ?? ''),
                'model' => (string) ($row['model'] ?? ''),
                'event_type' => (string) ($row['event_type'] ?? ''),
                'event_label' => self::eventLabel((string) ($row['event_type'] ?? '')),
                'error_code' => (string) ($row['error_code'] ?? ''),
                'http_status' => $row['http_status'] === null ? null : (int) $row['http_status'],
                'retry_after' => $row['retry_after_seconds'] === null ? null : (int) $row['retry_after_seconds'],
                'provider_code' => (string) ($row['provider_code'] ?? ''),
                'notification_sent' => (int) ($row['notification_sent'] ?? 0) === 1,
                'created_at' => (string) ($row['created_at'] ?? ''),
                'created_at_label' => self::formatTime((string) ($row['created_at'] ?? '')),
            ];
        }

        return $events;
    }

    /**
     * @return array<string, int>
     */
    private static function eventCounts(int $days): array
    {
        $days = max(1, min(365, $days));
        $since = (new DateTimeImmutable('now'))
            ->modify('-' . $days . ' days')
            ->format('Y-m-d H:i:s');

        $statement = Database::pdo()->prepare(
            'SELECT event_type, COUNT(*) AS total
             FROM ai_provider_events
             WHERE created_at >= :since
             GROUP BY event_type'
        );
        $statement->execute(['since' => $since]);

        $counts = [
            'total' => 0,
            'quota_exceeded' => 0,
            'rate_limit' => 0,
            'authentication_error' => 0,
            'permission_error' => 0,
            'timeout' => 0,
            'network_error' => 0,
            'model_unavailable' => 0,
            'server_error' => 0,
            'other' => 0,
        ];
        foreach ($statement->fetchAll() as $row) {
            $type = self::normalizeEventType((string) ($row['event_type'] ?? ''));
            $total = (int) ($row['total'] ?? 0);
            $counts['total'] += $total;
            if (array_key_exists($type, $counts) && $type !== 'total') {
                $counts[$type] += $total;
            } else {
                $counts['other'] += $total;
            }
        }

        return $counts;
    }

    /**
     * @return int Jumlah notifikasi yang berhasil dibuat.
     */
    private static function notifyAdmins(
        string $provider,
        string $model,
        string $eventType,
        ?int $httpStatus,
        ?int $retryAfter,
        string $providerCode
    ): int {
        $label = AiProviderRegistry::has($provider)
            ? AiProviderRegistry::label($provider)
            : ucfirst($provider);
        $title = $eventType === 'quota_exceeded'
            ? 'Kuota atau Kredit AI Habis'
            : 'Batas Permintaan AI Tercapai';
        $message = self::notificationMessage(
            $label,
            $model,
            $eventType,
            $httpStatus,
            $retryAfter,
            $providerCode
        );

        $sent = 0;
        foreach (User::activeByRole('admin') as $admin) {
            $adminId = (int) ($admin['id'] ?? 0);
            if ($adminId <= 0) {
                continue;
            }
            try {
                Notifikasi::create($adminId, null, $title, $message);
                $sent++;
            } catch (Throwable $exception) {
                app_log('Gagal mengirim notifikasi provider AI ke Admin.');
            }
        }

        return $sent;
    }

    private static function notifyAdminsFallback(
        string $provider,
        string $model,
        string $eventType,
        ?int $httpStatus,
        ?int $retryAfter,
        string $providerCode
    ): void {
        try {
            $label = AiProviderRegistry::has($provider)
                ? AiProviderRegistry::label($provider)
                : ucfirst($provider);
            $title = $eventType === 'quota_exceeded'
                ? 'Kuota atau Kredit AI Habis'
                : 'Batas Permintaan AI Tercapai';
            $window = $eventType === 'rate_limit'
                ? self::RATE_NOTIFICATION_WINDOW
                : self::QUOTA_NOTIFICATION_WINDOW;
            $since = date('Y-m-d H:i:s', time() - $window);
            $marker = '%' . $label . ' (' . $model . ')%';

            $statement = Database::pdo()->prepare(
                'SELECT COUNT(*) FROM notifikasi
                 WHERE judul = :judul AND pesan LIKE :marker AND created_at >= :since'
            );
            $statement->execute(['judul' => $title, 'marker' => $marker, 'since' => $since]);
            if ((int) $statement->fetchColumn() > 0) {
                return;
            }

            $message = self::notificationMessage(
                $label,
                $model,
                $eventType,
                $httpStatus,
                $retryAfter,
                $providerCode
            );
            foreach (User::activeByRole('admin') as $admin) {
                $adminId = (int) ($admin['id'] ?? 0);
                if ($adminId > 0) {
                    Notifikasi::create($adminId, null, $title, $message);
                }
            }
        } catch (Throwable $exception) {
            app_log('Gagal mengirim notifikasi fallback provider AI.');
        }
    }

    private static function notificationMessage(
        string $providerLabel,
        string $model,
        string $eventType,
        ?int $httpStatus,
        ?int $retryAfter,
        string $providerCode
    ): string {
        $modelText = $model === '' ? 'model tidak tercatat' : 'model ' . $model;
        $statusText = $httpStatus === null ? '' : ' Status HTTP ' . $httpStatus . '.';
        if ($providerCode !== '') {
            $statusText .= ' Kode provider: ' . $providerCode . '.';
        }

        if ($eventType === 'quota_exceeded') {
            $message = $providerLabel . ' (' . $modelText . ') melaporkan kuota atau kredit habis pada '
                . format_waktu(date('Y-m-d H:i:s')) . '.'
                . $statusText
                . ' Pertanyaan database lokal tetap dapat dijawab tanpa provider cloud.';
        } else {
            $retryText = $retryAfter !== null
                ? ' Provider menyarankan mencoba lagi dalam ' . $retryAfter . ' detik.'
                : ' Provider tidak menyediakan waktu coba ulang.';
            $message = $providerLabel . ' (' . $modelText . ') sedang membatasi permintaan pada '
                . format_waktu(date('Y-m-d H:i:s')) . '.'
                . $statusText
                . $retryText;
        }

        return mb_substr($message, 0, 2000);
    }

    private static function normalizeEventType(string $type): string
    {
        $type = strtolower(trim($type));

        return match ($type) {
            'quota' => 'quota_exceeded',
            'invalid_key', 'auth', 'authentication' => 'authentication_error',
            'permission_denied', 'permission' => 'permission_error',
            'network' => 'network_error',
            'server' => 'server_error',
            'model' => 'model_unavailable',
            '' => 'provider_error',
            default => $type,
        };
    }

    private static function eventLabel(string $type): string
    {
        return match (self::normalizeEventType($type)) {
            'quota_exceeded' => 'Kuota/kredit habis',
            'rate_limit' => 'Rate limit sementara',
            'authentication_error' => 'Kunci API tidak valid',
            'permission_error' => 'Izin API ditolak',
            'timeout' => 'Timeout',
            'network_error' => 'Gangguan jaringan',
            'model_unavailable' => 'Model tidak tersedia',
            'server_error' => 'Gangguan server',
            'bad_request' => 'Permintaan tidak valid',
            default => 'Error provider',
        };
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private static function eventStatus(string $type): array
    {
        return match (self::normalizeEventType($type)) {
            'quota_exceeded' => ['quota_exceeded', 'Kuota/kredit habis', 'danger'],
            'rate_limit' => ['rate_limited', 'Dibatasi sementara', 'warning'],
            'authentication_error' => ['authentication_error', 'Kunci API tidak valid', 'danger'],
            'permission_error' => ['permission_error', 'Izin API ditolak', 'danger'],
            'timeout' => ['timeout', 'Timeout', 'warning'],
            'network_error' => ['network_error', 'Gangguan jaringan', 'warning'],
            'model_unavailable' => ['model_unavailable', 'Model tidak tersedia', 'danger'],
            'server_error' => ['server_error', 'Gangguan server', 'warning'],
            default => ['error', 'Bermasalah', 'warning'],
        };
    }

    private static function formatTime(string $value): string
    {
        if ($value === '') {
            return '-';
        }

        return function_exists('format_waktu') ? format_waktu($value) : $value;
    }
}
