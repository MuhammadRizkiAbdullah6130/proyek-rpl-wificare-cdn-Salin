<?php
/**
 * Notifikasi WhatsApp per peristiwa tiket.
 *
 * Antrean ditulis di dalam transaksi tiket, lalu diproses otomatis SETELAH
 * transaksi berhasil di-commit. Tidak ada HTTP call di dalam transaksi dan
 * tidak perlu Admin menekan tombol proses antrean.
 *
 * `event_key` idempoten (event + tiket + penerima + identitas kejadian)
 * mencegah event ganda. Kegagalan konfigurasi/pengiriman tidak boleh
 * menggagalkan alur tiket. Retry tetap diproses otomatis pada request
 * berikutnya atau oleh worker/cron untuk host tanpa trafik.
 */

declare(strict_types=1);

final class WhatsAppNotificationService
{
    /** Cegah pemrosesan otomatis bersarang dalam satu request. */
    private static bool $dispatching = false;

    /**
     * Dua event saat tiket dibuat:
     * - pengaduan_baru → admin/operasional
     * - konfirmasi_tiket → pelanggan
     *
     * @param array<string, mixed> $context
     */
    public static function ticketCreated(int $pengaduanId, array $context = []): void
    {
        self::queue('pengaduan_baru', $pengaduanId, $context, 'created');
        self::queue('konfirmasi_tiket', $pengaduanId, $context, 'created');
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function ticketEvent(
        string $eventKey,
        int $pengaduanId,
        array $context = [],
        ?string $occurrence = null
    ): void {
        self::queue($eventKey, $pengaduanId, $context, $occurrence);
    }

    /**
     * Proses outbox notifikasi setelah commit.
     *
     * Dipanggil tepat setelah transaksi tiket berhasil di-commit. Hanya
     * memproses baris yang sudah jatuh tempo; kegagalan provider tidak pernah
     * dilempar ke pemanggil sehingga status tiket yang sudah tersimpan tetap
     * utuh. Panggilan ini juga menjadi retry otomatis untuk baris pending.
     */
    public static function dispatchAfterCommit(int $limit = 5): void
    {
        self::dispatchAutomatically($limit);
    }

    /**
     * Pemrosesan otomatis oportunistik (setelah commit / akhir request web).
     * Worker `bin/whatsapp-worker.php` tetap tersedia untuk host tanpa trafik.
     */
    public static function dispatchAutomatically(int $limit = 3): void
    {
        if (self::$dispatching) {
            return;
        }
        if (!ApiIntegrationSetting::isEnabled(ApiFeatureRegistry::WHATSAPP_NOTIFICATION)) {
            return;
        }
        if (!WhatsAppMessagingService::gatewayEnabled()) {
            return;
        }

        self::$dispatching = true;
        try {
            WhatsAppMessagingService::processQueue(
                max(1, min(10, $limit)),
                ApiFeatureRegistry::WHATSAPP_NOTIFICATION,
                5
            );
        } catch (Throwable $exception) {
            // Notifikasi tidak boleh menggagalkan request tiket.
            app_log('Pemrosesan otomatis notifikasi WhatsApp dilewati.');
        } finally {
            self::$dispatching = false;
        }
    }

    /**
     * Apakah event masih aktif saat akan dikirim (bisa berubah setelah
     * antrean dibuat). Dipakai worker untuk menghormati pengaturan Admin.
     */
    public static function eventEnabled(string $eventKey): bool
    {
        if (!ApiIntegrationSetting::isEnabled(ApiFeatureRegistry::WHATSAPP_NOTIFICATION)) {
            return false;
        }

        $settings = ApiIntegrationSetting::get(ApiFeatureRegistry::WHATSAPP_NOTIFICATION)['settings'];
        $events = is_array($settings['events'] ?? null) ? $settings['events'] : [];
        $eventConfig = is_array($events[$eventKey] ?? null) ? $events[$eventKey] : [];

        return !empty($eventConfig['enabled']);
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function queue(
        string $eventKey,
        int $pengaduanId,
        array $context,
        ?string $occurrence = null
    ): void {
        if (!ApiIntegrationSetting::isEnabled(ApiFeatureRegistry::WHATSAPP_NOTIFICATION)) {
            return;
        }

        $definitions = ApiFeatureRegistry::notificationEvents();
        if (!isset($definitions[$eventKey])) {
            return;
        }

        $settings = ApiIntegrationSetting::get(ApiFeatureRegistry::WHATSAPP_NOTIFICATION)['settings'];
        $events = is_array($settings['events'] ?? null) ? $settings['events'] : [];
        $eventConfig = is_array($events[$eventKey] ?? null) ? $events[$eventKey] : [];
        if (empty($eventConfig['enabled'])) {
            return;
        }

        try {
            $ticket = Pengaduan::findById($pengaduanId);
            if ($ticket === null) {
                return;
            }

            $values = self::values($ticket, $context);
            $recipient = self::recipient($eventConfig, $settings, $ticket);
            $normalized = WhatsAppMessagingService::normalizeRecipient($recipient);
            if ($normalized === null) {
                return;
            }

            $template = (string) ($eventConfig['template'] ?? $definitions[$eventKey]['template']);
            $body = strtr($template, $values);
            $sendMode = (string) ($eventConfig['send_mode'] ?? 'text') === 'template' ? 'template' : 'text';
            $parameters = self::templateParameters($template, $values);
            // Body selalu disertakan agar antrean tetap dapat dikirim sebagai
            // teks bila koneksi beralih ke provider teks (Fonnte).
            $payload = ['body' => $body];
            if ($sendMode === 'template') {
                $payload['parameters'] = $parameters;
            }
            // Identitas kejadian harus stabil agar klik ganda/retry tidak
            // membuat pesan baru, tetapi perubahan penerima tetap dianggap
            // kejadian berbeda sesuai konfigurasi Admin.
            $identity = trim((string) $occurrence);
            if ($identity === '') {
                $identity = (string) ($ticket['status'] ?? '') . '|' . $body;
            }
            $hash = substr(hash('sha256', $identity . "\n" . $normalized), 0, 16);
            $eventId = 'wa_notif:' . $eventKey . ':' . $pengaduanId . ':' . $hash;

            WhatsAppMessagingService::enqueueEvent(
                ApiFeatureRegistry::WHATSAPP_NOTIFICATION,
                $eventId,
                $normalized,
                $payload,
                [
                    'send_mode' => $sendMode,
                    'template_name' => (string) ($eventConfig['template_name'] ?? ''),
                    'language_code' => (string) ($eventConfig['language_code'] ?? 'id'),
                    'max_attempts' => max(1, min(10, (int) ($settings['retry_max'] ?? 3))),
                    'template_key' => $eventKey,
                ]
            );
        } catch (Throwable $exception) {
            // Fitur notifikasi tidak boleh mematikan pembuatan/perubahan tiket.
            app_log('Notifikasi WhatsApp dilewati untuk event ' . $eventKey . '.');
        }
    }

    /**
     * Statistik nyata dari outbox + usage log.
     *
     * @return array<string, mixed>
     */
    public static function monitoring(string $from = '', string $to = ''): array
    {
        $feature = ApiFeatureRegistry::WHATSAPP_NOTIFICATION;
        $counts = WhatsAppOutbox::statusCounts($feature);
        $pending = (int) ($counts['pending'] ?? 0) + (int) ($counts['processing'] ?? 0);
        $accepted = (int) ($counts['accepted'] ?? 0);
        $delivered = (int) ($counts['delivered'] ?? 0) + (int) ($counts['read'] ?? 0);
        $failed = (int) ($counts['failed'] ?? 0);

        $perEvent = [];
        foreach (ApiFeatureRegistry::notificationEvents() as $key => $meta) {
            $perEvent[$key] = [
                'label' => (string) $meta['label'],
                'pending' => 0,
                'accepted' => 0,
                'delivered' => 0,
                'failed' => 0,
            ];
        }
        $grouped = WhatsAppOutbox::groupedByTemplate($feature);
        foreach ($grouped as $eventKey => $statuses) {
            if (!isset($perEvent[$eventKey])) {
                continue;
            }
            $perEvent[$eventKey]['pending'] = (int) ($statuses['pending'] ?? 0) + (int) ($statuses['processing'] ?? 0);
            $perEvent[$eventKey]['accepted'] = (int) ($statuses['accepted'] ?? 0);
            $perEvent[$eventKey]['delivered'] = (int) ($statuses['delivered'] ?? 0) + (int) ($statuses['read'] ?? 0);
            $perEvent[$eventKey]['failed'] = (int) ($statuses['failed'] ?? 0);
        }

        return [
            'requests' => ApiUsageLog::countEvent($feature, 'message_send', $from, $to),
            'pending' => $pending,
            'accepted' => $accepted,
            'delivered' => $delivered,
            'failed' => $failed,
            'per_event' => $perEvent,
        ];
    }

    /**
     * @param array<string, mixed> $ticket
     * @param array<string, mixed> $context
     * @return array<string, string>
     */
    private static function values(array $ticket, array $context): array
    {
        return [
            '{nomor_tiket}' => (string) ($context['nomor_tiket'] ?? $ticket['nomor_tiket'] ?? ''),
            '{nama_pelanggan}' => (string) ($context['nama_pelanggan'] ?? $ticket['nama_pelanggan_snapshot'] ?? ''),
            '{status}' => status_label((string) ($ticket['status'] ?? '')),
            '{alasan}' => (string) ($context['alasan'] ?? ''),
            '{teknisi}' => (string) ($context['teknisi'] ?? ''),
            '{nama_aplikasi}' => (string) (Pengaturan::get('app_name') ?? config('app.name', 'WiFiCare CDN')),
        ];
    }

    /**
     * @param array<string, mixed> $eventConfig
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $ticket
     */
    private static function recipient(array $eventConfig, array $settings, array $ticket): string
    {
        $mode = (string) ($eventConfig['recipient'] ?? 'pelanggan');

        if ($mode === 'admin') {
            return (string) ($settings['admin_number'] ?? '');
        }
        if ($mode === 'custom') {
            return (string) ($eventConfig['custom_number'] ?? '');
        }

        return (string) ($ticket['nomor_whatsapp_snapshot'] ?? '');
    }

    /**
     * @param array<string, string> $values
     * @return array<int, string>
     */
    private static function templateParameters(string $template, array $values): array
    {
        $parameters = [];
        foreach (ApiFeatureRegistry::placeholders() as $placeholder => $label) {
            if (str_contains($template, $placeholder)) {
                $parameters[] = (string) ($values[$placeholder] ?? '');
            }
        }

        return $parameters;
    }
}
