<?php
/**
 * Monitoring penggunaan API terpadu (AI + WhatsApp).
 *
 * Sumber data nyata:
 * - AI: `ai_usage_log` (sudah ada).
 * - WhatsApp: `api_usage_log` + `whatsapp_outbox`.
 *
 * Estimasi biaya hanya ditampilkan bila datanya tersedia; saat ini tidak ada
 * tarif resmi yang disimpan, jadi kolom biaya dikosongkan (bukan dikarang).
 */

declare(strict_types=1);

final class ApiMonitoringService
{
    /**
     * @return array<string, mixed>
     */
    public static function pageData(string $feature = '', string $provider = '', string $period = 'bulan_ini'): array
    {
        [$from, $to] = self::periodRange($period);
        $feature = ApiFeatureRegistry::exists($feature) ? $feature : '';
        $provider = trim($provider);
        $rows = [];

        if ($feature === '' || $feature === ApiFeatureRegistry::AI) {
            foreach (self::aiRows($from, $to, $provider) as $row) {
                $rows[] = $row;
            }
        }

        $whatsappFeatures = [
            ApiFeatureRegistry::WHATSAPP_GATEWAY,
            ApiFeatureRegistry::WHATSAPP_OTP,
            ApiFeatureRegistry::WHATSAPP_NOTIFICATION,
            ApiFeatureRegistry::WHATSAPP_REPORT,
        ];
        foreach ($whatsappFeatures as $key) {
            if ($feature !== '' && $feature !== $key) {
                continue;
            }
            foreach (self::whatsappRows($key, $from, $to, $provider) as $row) {
                $rows[] = $row;
            }
        }

        usort($rows, static function (array $a, array $b): int {
            $order = array_flip(array_keys(ApiFeatureRegistry::all()));

            return ($order[$a['feature_key']] ?? 99) <=> ($order[$b['feature_key']] ?? 99);
        });

        return [
            'rows' => $rows,
            'summary' => self::summary($from, $to),
            'filters' => [
                'feature' => $feature,
                'provider' => $provider,
                'period' => $period,
            ],
            'feature_options' => ApiFeatureRegistry::labels(),
            'provider_options' => self::providerOptions(),
            'period_options' => [
                'hari_ini' => 'Hari ini',
                '7_hari' => '7 hari terakhir',
                'bulan_ini' => 'Bulan ini',
                'bulan_lalu' => 'Bulan lalu',
                'semua' => 'Semua periode',
            ],
            'range' => [$from, $to],
            'cost_note' => 'Estimasi biaya belum ditampilkan karena tarif resmi per provider tidak disimpan. Tidak ada angka kuota/biaya yang dikarang.',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function aiRows(string $from, string $to, string $providerFilter): array
    {
        $view = AiSettingsService::viewData();
        $providers = is_array($view['providers'] ?? null) ? $view['providers'] : [];
        $rows = [];

        foreach ($providers as $id => $meta) {
            $provider = (string) $id;
            if ($providerFilter !== '' && $providerFilter !== $provider) {
                continue;
            }

            $dbToday = self::dbToday();
            $period = self::aiStats($provider, $from, $to);
            $today = self::aiStats($provider, $dbToday, $dbToday);
            $month = self::aiStats($provider, substr($dbToday, 0, 7) . '-01', $dbToday);
            $lastTest = self::lastAiTest($provider);

            $rows[] = [
                'feature_key' => ApiFeatureRegistry::AI,
                'feature_label' => ApiFeatureRegistry::label(ApiFeatureRegistry::AI),
                'provider' => $provider,
                'provider_label' => (string) ($meta['label'] ?? $provider),
                'model' => (string) ($meta['model'] ?? ''),
                'configured' => !empty($meta['configured']),
                'today_requests' => $today['total'],
                'month_requests' => $month['total'],
                'period_requests' => $period['total'],
                'success' => $period['success'],
                'failed' => $period['failed'],
                'tokens' => $period['tokens'],
                'messages' => 0,
                'messages_accepted' => 0,
                'messages_delivered' => 0,
                'messages_pending' => 0,
                'messages_failed' => 0,
                'last_activity' => $period['last_activity'],
                'last_connection' => $lastTest,
                'estimated_cost' => null,
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function whatsappRows(string $feature, string $from, string $to, string $providerFilter): array
    {
        $connection = WhatsAppMessagingService::connectionForFeature($feature);
        $provider = (string) ($connection['provider'] ?? 'meta_cloud');
        if ($providerFilter !== '' && $providerFilter !== $provider) {
            return [];
        }

        $dbToday = self::dbToday();
        $period = self::waStats($feature, $from, $to);
        $today = self::waStats($feature, $dbToday, $dbToday);
        $month = self::waStats($feature, substr($dbToday, 0, 7) . '-01', $dbToday);
        $outbox = WhatsAppOutbox::groupedByFeature($from, $to)[$feature] ?? [];

        $pending = (int) ($outbox['pending'] ?? 0) + (int) ($outbox['processing'] ?? 0);
        $accepted = (int) ($outbox['accepted'] ?? 0);
        $delivered = (int) ($outbox['delivered'] ?? 0) + (int) ($outbox['read'] ?? 0);
        $failed = (int) ($outbox['failed'] ?? 0);
        $messageTotal = $pending + $accepted + $delivered + $failed;

        $lastTest = self::lastWhatsAppTest();

        return [[
            'feature_key' => $feature,
            'feature_label' => ApiFeatureRegistry::label($feature),
            'provider' => $provider,
            'provider_label' => WhatsAppProviderRegistry::labels()[$provider] ?? 'Belum dikonfigurasi',
            'model' => '',
            'configured' => $connection !== null,
            'today_requests' => $today['total'],
            'month_requests' => $month['total'],
            'period_requests' => $period['total'],
            'success' => $period['success'],
            'failed' => $period['failed'],
            'tokens' => 0,
            'messages' => $messageTotal,
            'messages_accepted' => $accepted,
            'messages_delivered' => $delivered,
            'messages_pending' => $pending,
            'messages_failed' => $failed,
            'last_activity' => $period['last_activity'],
            'last_connection' => $feature === ApiFeatureRegistry::WHATSAPP_GATEWAY ? $lastTest : 'Mengikuti gateway',
            'estimated_cost' => null,
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function summary(string $from, string $to): array
    {
        $dbToday = self::dbToday();
        $today = self::combinedStats($dbToday, $dbToday);
        $month = self::combinedStats(substr($dbToday, 0, 7) . '-01', $dbToday);
        $period = self::combinedStats($from, $to);
        $outbox = WhatsAppOutbox::groupedByFeature($from, $to);

        $pending = 0;
        $accepted = 0;
        $delivered = 0;
        $failedMessages = 0;
        foreach ($outbox as $statuses) {
            $pending += (int) ($statuses['pending'] ?? 0) + (int) ($statuses['processing'] ?? 0);
            $accepted += (int) ($statuses['accepted'] ?? 0);
            $delivered += (int) ($statuses['delivered'] ?? 0) + (int) ($statuses['read'] ?? 0);
            $failedMessages += (int) ($statuses['failed'] ?? 0);
        }

        return [
            'today_requests' => $today['total'],
            'month_requests' => $month['total'],
            'period_requests' => $period['total'],
            'success' => $period['success'],
            'failed' => $period['failed'],
            'tokens' => $period['tokens'],
            'messages_pending' => $pending,
            'messages_accepted' => $accepted,
            'messages_delivered' => $delivered,
            'messages_failed' => $failedMessages,
            'estimated_cost' => null,
        ];
    }

    /**
     * @return array{total:int,success:int,failed:int,tokens:int,last_activity:string}
     */
    private static function aiStats(string $provider, string $from, string $to): array
    {
        $where = 'provider = :provider';
        $params = ['provider' => $provider];
        if ($from !== '') {
            $where .= ' AND created_at >= :from_date';
            $params['from_date'] = $from . ' 00:00:00';
        }
        if ($to !== '') {
            $where .= ' AND created_at <= :to_date';
            $params['to_date'] = $to . ' 23:59:59';
        }

        $statement = Database::pdo()->prepare(
            'SELECT COUNT(*) AS total,
                    COALESCE(SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END), 0) AS success,
                    COALESCE(SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END), 0) AS failed,
                    COALESCE(SUM(total_tokens), 0) AS tokens,
                    MAX(created_at) AS last_activity
             FROM ai_usage_log WHERE ' . $where
        );
        $statement->execute($params);
        $row = $statement->fetch() ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'success' => (int) ($row['success'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'tokens' => (int) ($row['tokens'] ?? 0),
            'last_activity' => (string) ($row['last_activity'] ?? ''),
        ];
    }

    /**
     * @return array{total:int,success:int,failed:int,tokens:int,last_activity:string}
     */
    private static function waStats(string $feature, string $from, string $to): array
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

        $statement = Database::pdo()->prepare(
            'SELECT COUNT(*) AS total,
                    COALESCE(SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END), 0) AS success,
                    COALESCE(SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END), 0) AS failed,
                    COALESCE(SUM(total_tokens), 0) AS tokens,
                    MAX(created_at) AS last_activity
             FROM api_usage_log WHERE ' . $where
        );
        $statement->execute($params);
        $row = $statement->fetch() ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'success' => (int) ($row['success'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'tokens' => (int) ($row['tokens'] ?? 0),
            'last_activity' => (string) ($row['last_activity'] ?? ''),
        ];
    }

    /**
     * @return array{total:int,success:int,failed:int,tokens:int}
     */
    private static function combinedStats(string $from, string $to): array
    {
        $ai = self::allAiStats($from, $to);
        $wa = self::allWaStats($from, $to);

        return [
            'total' => $ai['total'] + $wa['total'],
            'success' => $ai['success'] + $wa['success'],
            'failed' => $ai['failed'] + $wa['failed'],
            'tokens' => $ai['tokens'] + $wa['tokens'],
        ];
    }

    /** @return array{total:int,success:int,failed:int,tokens:int} */
    private static function allAiStats(string $from, string $to): array
    {
        $sql = 'SELECT COUNT(*) AS total,
                       COALESCE(SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END), 0) AS success,
                       COALESCE(SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END), 0) AS failed,
                       COALESCE(SUM(total_tokens), 0) AS tokens
                FROM ai_usage_log WHERE 1 = 1';
        $params = [];
        if ($from !== '') {
            $sql .= ' AND created_at >= :from_date';
            $params['from_date'] = $from . ' 00:00:00';
        }
        if ($to !== '') {
            $sql .= ' AND created_at <= :to_date';
            $params['to_date'] = $to . ' 23:59:59';
        }
        $statement = Database::pdo()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch() ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'success' => (int) ($row['success'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'tokens' => (int) ($row['tokens'] ?? 0),
        ];
    }

    /** @return array{total:int,success:int,failed:int,tokens:int} */
    private static function allWaStats(string $from, string $to): array
    {
        $sql = 'SELECT COUNT(*) AS total,
                       COALESCE(SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END), 0) AS success,
                       COALESCE(SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END), 0) AS failed,
                       COALESCE(SUM(total_tokens), 0) AS tokens
                FROM api_usage_log WHERE 1 = 1';
        $params = [];
        if ($from !== '') {
            $sql .= ' AND created_at >= :from_date';
            $params['from_date'] = $from . ' 00:00:00';
        }
        if ($to !== '') {
            $sql .= ' AND created_at <= :to_date';
            $params['to_date'] = $to . ' 23:59:59';
        }
        $statement = Database::pdo()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch() ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'success' => (int) ($row['success'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'tokens' => (int) ($row['tokens'] ?? 0),
        ];
    }

    private static function lastAiTest(string $provider): string
    {
        $statement = Database::pdo()->prepare(
            "SELECT success, created_at FROM ai_usage_log
             WHERE provider = :provider AND request_type = 'test'
             ORDER BY id DESC LIMIT 1"
        );
        $statement->execute(['provider' => $provider]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return 'Belum diuji';
        }

        return ((int) $row['success'] === 1 ? 'Terhubung' : 'Gagal')
            . ' · ' . (string) $row['created_at'];
    }

    private static function lastWhatsAppTest(): string
    {
        $rows = ApiCredentialService::whatsappConnections();
        $latest = null;
        foreach ($rows as $row) {
            if (!empty($row['last_tested_at']) && ($latest === null || $row['last_tested_at'] > $latest['last_tested_at'])) {
                $latest = $row;
            }
        }
        if ($latest === null) {
            return 'Belum diuji';
        }

        return ($latest['last_test_status'] === 'connected' ? 'Terhubung' : 'Gagal')
            . ' · ' . (string) $latest['last_tested_at'];
    }

    private static function dbToday(): string
    {
        $value = Database::pdo()->query('SELECT CURRENT_DATE')->fetchColumn();

        return is_string($value) && $value !== '' ? $value : date('Y-m-d');
    }

    /**
     * Rentang periode memakai tanggal database agar konsisten dengan timestamp
     * yang ditulis MySQL.
     *
     * @return array{0:string,1:string}
     */
    private static function periodRange(string $period): array
    {
        $today = self::dbToday();
        $monthStart = substr($today, 0, 7) . '-01';

        switch ($period) {
            case 'hari_ini':
                return [$today, $today];
            case '7_hari':
                return [date('Y-m-d', strtotime($today . ' -6 days')), $today];
            case 'bulan_ini':
                return [$monthStart, $today];
            case 'bulan_lalu':
                $lastEnd = date('Y-m-d', strtotime($monthStart . ' -1 day'));
                return [substr($lastEnd, 0, 7) . '-01', $lastEnd];
            case 'semua':
            default:
                return ['', ''];
        }
    }

    /** @return array<string, string> */
    private static function providerOptions(): array
    {
        $options = [];
        foreach (AiProviderRegistry::metadata() as $id => $meta) {
            $options[(string) $id] = (string) ($meta['label'] ?? $id);
        }
        foreach (WhatsAppProviderRegistry::labels() as $id => $label) {
            $options[(string) $id] = $label;
        }

        return $options;
    }
}
