<?php
/**
 * Laporan WhatsApp manual (teks).
 *
 * Alur:
 *   1. Admin memilih koneksi aktif, jenis laporan, periode, dan penerima.
 *   2. `preview()` membaca data aktual, menyusun pesan teks, lalu menyimpan
 *      token pratinjau di session (tanpa menyimpan isi pesan).
 *   3. `sendManual()` membangun ulang pesan dari data aktual, membandingkan
 *      hash, lalu mengirim tepat satu kali melalui `WhatsAppMessagingService`.
 *
 * Tidak ada scheduler, PDF/Excel, retry otomatis, atau pengiriman saat halaman
 * dibuka. Penerima selalu diambil dari daftar penerima berwenang di settings;
 * request hanya membawa indeks, bukan nomor bebas.
 */

declare(strict_types=1);

final class WhatsAppReportService
{
    /** Batas aman di bawah batas teks Meta (4096 karakter). */
    public const MAX_MESSAGE_LENGTH = 4000;

    private const PREVIEW_TTL = 1800;
    private const MAX_STORED_PREVIEWS = 8;
    private const MAX_TECHNICIAN_LINES = 12;
    private const MAX_CATEGORY_LINES = 8;
    private const HISTORY_LIMIT = 10;

    /** @return array<string, string> */
    public static function reportTypes(): array
    {
        return WhatsAppSettingsService::reportTypes();
    }

    /**
     * Hanya koneksi WhatsApp aktif dan belum dicabut yang boleh dipilih.
     *
     * @return array<int, array{id:int,label:string,provider:string}>
     */
    public static function activeConnections(): array
    {
        $result = [];
        foreach (ApiCredentialService::activeConnections() as $row) {
            if (!empty($row['is_revoked']) || empty($row['is_active'])) {
                continue;
            }
            $provider = (string) ($row['provider'] ?? '');
            $providerLabel = WhatsAppProviderRegistry::labels()[$provider] ?? $provider;
            $sender = trim((string) ($row['sender_number'] ?? ''));
            $label = trim((string) ($row['name'] ?? 'Koneksi WhatsApp'));
            if ($providerLabel !== '') {
                $label .= ' - ' . $providerLabel;
            }
            if ($sender !== '') {
                $label .= ' (' . $sender . ')';
            }
            $result[] = [
                'id' => (int) ($row['id'] ?? 0),
                'label' => $label,
                'provider' => $provider,
            ];
        }

        return $result;
    }

    /**
     * Daftar penerima berwenang yang sudah dinormalisasi.
     *
     * @return array<int, array{index:int,name:string,number:string,masked:string}>
     */
    public static function recipients(): array
    {
        $settings = ApiIntegrationSetting::get(ApiFeatureRegistry::WHATSAPP_REPORT)['settings'];
        $stored = is_array($settings['recipients'] ?? null) ? $settings['recipients'] : [];
        $result = [];

        foreach ($stored as $recipient) {
            if (!is_array($recipient)) {
                continue;
            }
            $number = whatsapp_number((string) ($recipient['number'] ?? ''));
            if ($number === null) {
                continue;
            }
            $name = trim((string) ($recipient['name'] ?? ''));
            $result[] = [
                'index' => count($result),
                'name' => $name !== '' ? mb_substr($name, 0, 80) : 'Penerima',
                'number' => $number,
                'masked' => self::maskNumber($number),
            ];
        }

        return $result;
    }

    /**
     * Data halaman: pilihan form, konfigurasi aman, riwayat, dan preview bila
     * diminta melalui GET (?preview=1). Membuka halaman biasa tidak memanggil
     * API provider dan tidak menyimpan/mengirim apa pun.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public static function pageData(array $query): array
    {
        $types = self::reportTypes();
        $defaultType = (string) array_key_first($types);
        $type = (string) ($query['report_type'] ?? $defaultType);
        if (!isset($types[$type])) {
            $type = $defaultType;
        }

        $filters = LaporanService::buildFilters($query)['filters'];
        $connections = self::activeConnections();
        $recipients = self::recipients();

        $settings = ApiIntegrationSetting::get(ApiFeatureRegistry::WHATSAPP_REPORT);
        $storedConnectionId = (int) ($settings['connection_id'] ?? 0);
        $connectionId = (int) ($query['connection_id'] ?? 0);
        $activeConnectionIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            $connections
        );
        if (!in_array($connectionId, $activeConnectionIds, true)) {
            $connectionId = in_array($storedConnectionId, $activeConnectionIds, true)
                ? $storedConnectionId
                : (int) ($activeConnectionIds[0] ?? 0);
        }

        $recipientIndex = (int) ($query['recipient_index'] ?? 0);
        if (!isset($recipients[$recipientIndex])) {
            $recipientIndex = 0;
        }

        $preview = null;
        $previewError = '';
        if ((string) ($query['preview'] ?? '') === '1') {
            try {
                $preview = self::preview([
                    'report_type' => $type,
                    'rentang' => (string) ($filters['rentang'] ?? 'bulan_ini'),
                    'date_from' => (string) ($filters['date_from'] ?? ''),
                    'date_to' => (string) ($filters['date_to'] ?? ''),
                    'connection_id' => $connectionId,
                    'recipient_index' => $recipientIndex,
                ]);
            } catch (ValidationException $exception) {
                $previewError = $exception->errorsAsString();
            } catch (Throwable $exception) {
                app_log('Preview laporan WhatsApp gagal: ' . $exception->getMessage());
                $previewError = 'Pratinjau laporan tidak dapat dibuat. Silakan periksa pilihan dan coba lagi.';
            }
        }

        $limit = max(0, (int) ($settings['settings']['daily_limit'] ?? 0));
        $used = WhatsAppOutbox::countToday(ApiFeatureRegistry::WHATSAPP_REPORT);
        $history = self::history();

        return [
            'report' => $settings,
            'reportTypes' => $types,
            'reportConnections' => $connections,
            'reportRecipients' => $recipients,
            'reportFilters' => [
                'rentang' => (string) ($filters['rentang'] ?? 'bulan_ini'),
                'date_from' => (string) ($filters['date_from'] ?? ''),
                'date_to' => (string) ($filters['date_to'] ?? ''),
                'report_type' => $type,
                'connection_id' => $connectionId,
                'recipient_index' => $recipientIndex,
            ],
            'reportRentangLabels' => LaporanService::rentangLabels(),
            'reportPreview' => $preview,
            'reportPreviewError' => $previewError,
            'reportHistory' => $history,
            'reportLast' => $history[0] ?? null,
            'reportUsage' => [
                'used' => $used,
                'limit' => $limit,
                'remaining' => $limit > 0 ? max(0, $limit - $used) : null,
                'reached' => $limit > 0 && $used >= $limit,
            ],
            'reportGatewayReady' => ApiIntegrationSetting::isEnabled(ApiFeatureRegistry::WHATSAPP_GATEWAY),
        ];
    }

    /**
     * Buat pratinjau dan token sekali pakai. Tidak memanggil provider.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function preview(array $input): array
    {
        if (!ApiIntegrationSetting::isEnabled(ApiFeatureRegistry::WHATSAPP_GATEWAY)) {
            throw new ValidationException(['koneksi' => 'Aktifkan WhatsApp Gateway terlebih dahulu.']);
        }
        if (!Crypto::isReady()) {
            throw new ValidationException(['koneksi' => 'Master encryption key belum siap.']);
        }

        $type = (string) ($input['report_type'] ?? '');
        $types = self::reportTypes();
        if (!isset($types[$type])) {
            throw new ValidationException(['report_type' => 'Jenis laporan tidak valid.']);
        }

        $filters = self::normalizeFilters($input);
        $connection = self::resolveConnection((int) ($input['connection_id'] ?? 0));
        $recipient = self::resolveRecipient((int) ($input['recipient_index'] ?? -1));

        $message = self::buildMessage($type, $filters);
        $tooLong = mb_strlen($message, 'UTF-8') > self::MAX_MESSAGE_LENGTH;
        $hash = self::fingerprint($type, $filters, $connection['id'], $recipient['number'], $message);
        $token = bin2hex(random_bytes(32));
        $actor = auth_user();

        self::storePreview($token, [
            'actor_id' => (int) ($actor['id'] ?? 0),
            'report_type' => $type,
            'filters' => $filters,
            'connection_id' => (int) $connection['id'],
            'recipient_index' => (int) $recipient['index'],
            'hash' => $hash,
            'created_at' => time(),
            'status' => 'previewed',
        ]);

        $usage = self::dailyUsage();

        return [
            'token' => $token,
            'hash' => $hash,
            'report_type' => $type,
            'report_type_label' => $types[$type],
            'period_label' => self::periodLabel($filters),
            'message' => $message,
            'length' => mb_strlen($message, 'UTF-8'),
            'max_length' => self::MAX_MESSAGE_LENGTH,
            'too_long' => $tooLong,
            'connection_id' => (int) $connection['id'],
            'connection_label' => $connection['label'],
            'recipient_index' => (int) $recipient['index'],
            'recipient_name' => $recipient['name'],
            'recipient_masked' => $recipient['masked'],
            'usage' => $usage,
            'can_send' => !$tooLong && !$usage['reached'],
        ];
    }

    /**
     * Kirim satu laporan yang sudah dipratinjau.
     *
     * Token bersifat sekali pakai dan terikat ke Admin pembuat preview. Pesan
     * dibangun ulang dari database; bila hash berubah, pengiriman ditolak agar
     * isi yang dikirim tidak berbeda dari yang disetujui Admin.
     *
     * @param array<string, mixed> $actor
     * @return array{ok:bool,status:string,code:string,message:string,preview:array<string,mixed>|null}
     */
    public static function sendManual(array $actor, string $token): array
    {
        $token = strtolower(trim($token));
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            throw new ValidationException([
                'kirim' => 'Token pratinjau tidak valid. Muat ulang pratinjau sebelum mengirim.',
            ]);
        }

        $state = self::previewState($token);
        $actorId = (int) ($actor['id'] ?? 0);
        if ($state === null) {
            throw new ValidationException([
                'kirim' => 'Pratinjau laporan tidak ditemukan atau sudah kedaluwarsa. Muat ulang pratinjau.',
            ]);
        }
        if ((int) ($state['actor_id'] ?? 0) !== $actorId) {
            throw new ValidationException([
                'kirim' => 'Pratinjau ini bukan milik sesi Admin yang sedang aktif.',
            ]);
        }
        if ((string) ($state['status'] ?? '') !== 'previewed') {
            throw new ValidationException([
                'kirim' => 'Pratinjau ini sudah digunakan. Muat ulang pratinjau untuk mengirim lagi.',
            ]);
        }
        if (time() - (int) ($state['created_at'] ?? 0) > self::PREVIEW_TTL) {
            self::updatePreviewState($token, ['status' => 'expired']);
            throw new ValidationException([
                'kirim' => 'Pratinjau sudah kedaluwarsa. Muat ulang pratinjau sebelum mengirim.',
            ]);
        }

        // Kunci token sebelum memanggil provider. Request kedua (double click)
        // akan ditolak meskipun request pertama masih berjalan.
        self::updatePreviewState($token, ['status' => 'sending']);

        try {
            $type = (string) ($state['report_type'] ?? '');
            $types = self::reportTypes();
            if (!isset($types[$type])) {
                throw new ValidationException(['kirim' => 'Jenis laporan pada pratinjau tidak valid.']);
            }

            $filters = self::normalizeFilters(is_array($state['filters'] ?? null) ? $state['filters'] : []);
            $connection = self::resolveConnection((int) ($state['connection_id'] ?? 0));
            $recipient = self::resolveRecipient((int) ($state['recipient_index'] ?? -1));
            $message = self::buildMessage($type, $filters);

            if (mb_strlen($message, 'UTF-8') > self::MAX_MESSAGE_LENGTH) {
                throw new ValidationException([
                    'kirim' => 'Pesan laporan melebihi batas ' . self::MAX_MESSAGE_LENGTH
                        . ' karakter. Persempit periode lalu buat pratinjau baru.',
                ]);
            }

            $currentHash = self::fingerprint(
                $type,
                $filters,
                (int) $connection['id'],
                $recipient['number'],
                $message
            );
            if (!hash_equals((string) ($state['hash'] ?? ''), $currentHash)) {
                self::updatePreviewState($token, ['status' => 'stale']);
                throw new ValidationException([
                    'kirim' => 'Data laporan berubah sejak pratinjau dibuat. Muat ulang pratinjau dan periksa kembali sebelum mengirim.',
                ]);
            }

            $usage = self::dailyUsage();
            if ($usage['reached']) {
                throw new ValidationException([
                    'kirim' => 'Batas pengiriman laporan harian sudah tercapai. Coba lagi setelah batas harian diperbarui.',
                ]);
            }

            $result = WhatsAppMessagingService::sendImmediate(
                ApiFeatureRegistry::WHATSAPP_REPORT,
                'manual_report_' . $token,
                $recipient['number'],
                [
                    'body' => $message,
                    'report_type' => $type,
                    'period_label' => self::periodLabel($filters),
                    'manual' => true,
                ],
                [
                    'send_mode' => 'text',
                    'template_key' => $type,
                    'language_code' => 'id',
                    'event_type' => 'manual_report_sent',
                    'connection_id' => (int) $connection['id'],
                    'require_feature_enabled' => false,
                ]
            );

            $errorCode = (string) ($result['code'] ?? '');
            $friendly = (string) ($result['message'] ?? '');
            if (empty($result['ok'])) {
                $friendly = self::friendlySendError($errorCode, $friendly);
                self::updatePreviewState($token, [
                    'status' => 'failed',
                    'code' => $errorCode,
                ]);
                self::audit($actorId, 'gagal', $type, $recipient['masked'], $errorCode);
                return [
                    'ok' => false,
                    'status' => 'failed',
                    'code' => $errorCode,
                    'message' => $friendly,
                    'preview' => null,
                ];
            }

            self::updatePreviewState($token, [
                'status' => 'sent',
                'code' => 'accepted',
                'outbox_id' => (int) ($result['outbox_id'] ?? 0),
            ]);
            self::audit($actorId, 'accepted', $type, $recipient['masked'], 'accepted');

            return [
                'ok' => true,
                'status' => 'accepted',
                'code' => 'accepted',
                'message' => 'Permintaan laporan diterima provider. Status delivery belum tentu sudah diterima penerima.',
                'preview' => null,
            ];
        } catch (ValidationException $exception) {
            $current = self::previewState($token);
            if ($current !== null && (string) ($current['status'] ?? '') === 'sending') {
                self::updatePreviewState($token, ['status' => 'failed']);
            }
            throw $exception;
        } catch (Throwable $exception) {
            self::updatePreviewState($token, ['status' => 'failed']);
            app_log('Pengiriman laporan WhatsApp gagal: ' . $exception->getMessage());

            $message = 'Laporan gagal dikirim. Periksa koneksi WhatsApp lalu buat pratinjau baru.';
            if ($exception->getMessage() === 'encryption_not_ready') {
                $message = 'Master encryption key belum siap. Laporan tidak dikirim.';
            } elseif ($exception->getMessage() === 'gateway_disabled') {
                $message = 'WhatsApp Gateway belum aktif. Laporan tidak dikirim.';
            }

            $type = (string) ($state['report_type'] ?? '');
            self::audit($actorId, 'gagal', $type, '', 'exception');

            return [
                'ok' => false,
                'status' => 'failed',
                'code' => 'exception',
                'message' => $message,
                'preview' => null,
            ];
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function normalizeFilters(array $input): array
    {
        $rentang = (string) ($input['rentang'] ?? 'bulan_ini');
        if (!array_key_exists($rentang, LaporanService::rentangLabels())) {
            throw new ValidationException(['periode' => 'Pilihan periode laporan tidak valid.']);
        }

        $rawFrom = trim((string) ($input['date_from'] ?? ''));
        $rawTo = trim((string) ($input['date_to'] ?? ''));
        if ($rentang === 'pilih_tanggal') {
            foreach (['date_from' => $rawFrom, 'date_to' => $rawTo] as $field => $value) {
                if ($value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
                    throw new ValidationException(['periode' => 'Format tanggal laporan tidak valid.']);
                }
            }
        }

        $built = LaporanService::buildFilters([
            'rentang' => $rentang,
            'date_from' => $rawFrom,
            'date_to' => $rawTo,
        ]);
        if (($built['errors'] ?? []) !== []) {
            throw new ValidationException(['periode' => implode(' ', $built['errors'])]);
        }

        $filters = $built['filters'];
        // Laporan manual hanya memakai periode; filter status/kategori/teknisi
        // tidak boleh mengubah angka ringkasan secara tersembunyi.
        $filters['status'] = '';
        $filters['kategori_id'] = 0;
        $filters['teknisi_id'] = 0;
        $filters['pelanggan_id'] = 0;
        $filters['cancelled'] = 'semua';
        $filters['arsip'] = 'semua';

        return $filters;
    }

    /**
     * @param array<string, mixed> $filters
     */
    public static function periodLabel(array $filters): string
    {
        $from = (string) ($filters['date_from'] ?? '');
        $to = (string) ($filters['date_to'] ?? '');
        if ($from === '' || $to === '') {
            return 'Semua Waktu';
        }

        $fromTime = strtotime($from);
        $toTime = strtotime($to);
        if ($fromTime === false || $toTime === false) {
            return format_tanggal($from) . ' s.d. ' . format_tanggal($to);
        }

        if (date('Y-m', $fromTime) === date('Y-m', $toTime)) {
            $month = (int) date('n', $fromTime);
            return (bulan_panjang()[$month] ?? date('F', $fromTime)) . ' ' . date('Y', $fromTime)
                . ' (' . format_tanggal($from) . ' s.d. ' . format_tanggal($to) . ')';
        }

        return format_tanggal($from) . ' s.d. ' . format_tanggal($to);
    }

    /**
     * Susun teks final dari data aktual.
     *
     * @param array<string, mixed> $filters
     */
    public static function buildMessage(string $type, array $filters): string
    {
        $types = self::reportTypes();
        if (!isset($types[$type])) {
            throw new ValidationException(['report_type' => 'Jenis laporan tidak valid.']);
        }

        $label = mb_strtoupper($types[$type], 'UTF-8');
        $lines = [
            '*WiFiCare CDN*',
            '*' . $label . '*',
            '',
            'Periode: ' . self::periodLabel($filters),
            '',
        ];

        if ($type === 'ringkasan_pengaduan') {
            $lines = array_merge($lines, self::ringkasanLines($filters));
        } elseif ($type === 'kinerja_teknisi') {
            $lines = array_merge($lines, self::teknisiLines($filters));
        } else {
            $lines = array_merge($lines, self::bulananLines($filters));
        }

        $lines[] = '';
        $lines[] = 'Laporan dikirim secara manual melalui WiFiCare CDN.';

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, string>
     */
    private static function ringkasanLines(array $filters): array
    {
        $summary = Laporan::summary($filters);
        $status = Laporan::statusDistribution(array_merge($filters, ['cancelled' => 'tidak']));

        $lines = [
            'Total pengaduan: ' . self::number($summary['total']),
            '',
        ];
        foreach (status_keys() as $key) {
            $lines[] = status_label($key) . ': ' . self::number((int) ($status[$key] ?? 0));
        }
        if ($summary['dibatalkan'] > 0) {
            $lines[] = '';
            $lines[] = 'Dibatalkan (terpisah, bukan status ketujuh): ' . self::number($summary['dibatalkan']);
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, string>
     */
    private static function teknisiLines(array $filters): array
    {
        // Tiket dibatalkan tidak dihitung sebagai beban/kinerja teknisi.
        $rows = Laporan::technicianPerformance(array_merge($filters, ['cancelled' => 'tidak']));
        $totalTasks = 0;
        foreach ($rows as $row) {
            $totalTasks += (int) ($row['total_tugas'] ?? 0);
        }

        $lines = [
            'Total penugasan: ' . self::number($totalTasks),
            'Jumlah teknisi: ' . self::number(count($rows)),
            '',
            '*Rincian Teknisi*',
        ];

        if ($rows === []) {
            $lines[] = 'Belum ada data teknisi pada periode ini.';
            return $lines;
        }

        $shown = array_slice($rows, 0, self::MAX_TECHNICIAN_LINES);
        foreach ($shown as $row) {
            $name = trim((string) ($row['nama'] ?? 'Teknisi'));
            $assigned = (int) ($row['total_tugas'] ?? 0);
            $done = (int) ($row['selesai'] ?? 0);
            $active = (int) ($row['aktif'] ?? 0);
            $line = '- ' . $name . ': ' . self::number($assigned) . ' tugas, '
                . self::number($done) . ' selesai';
            if ($active > 0) {
                $line .= ', ' . self::number($active) . ' dalam penanganan';
            }
            $lines[] = $line;
        }

        $remaining = count($rows) - count($shown);
        if ($remaining > 0) {
            $lines[] = '- ... dan ' . self::number($remaining) . ' teknisi lainnya (daftar dibatasi agar pesan tetap ringkas).';
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, string>
     */
    private static function bulananLines(array $filters): array
    {
        $summary = Laporan::summary($filters);
        $status = Laporan::statusDistribution(array_merge($filters, ['cancelled' => 'tidak']));
        $selesai = (int) ($status['selesai'] ?? 0);
        $ditolak = (int) ($status['ditolak'] ?? 0);
        $nonCancelled = max(0, $summary['total'] - $summary['dibatalkan']);
        $unfinished = max(0, $nonCancelled - $selesai - $ditolak);
        $categories = Laporan::categoryDistribution(array_merge($filters, ['cancelled' => 'tidak']));

        $lines = [
            'Total pengaduan: ' . self::number($summary['total']),
            'Pengaduan selesai: ' . self::number($selesai),
            'Pengaduan belum selesai: ' . self::number($unfinished),
            'Pengaduan ditolak: ' . self::number($ditolak),
        ];
        if ($summary['dibatalkan'] > 0) {
            $lines[] = 'Dibatalkan (terpisah): ' . self::number($summary['dibatalkan']);
        }

        $lines[] = '';
        $lines[] = '*Ringkasan Kategori*';
        if ($categories === []) {
            $lines[] = 'Belum ada data kategori pada periode ini.';
            return $lines;
        }

        $shown = array_slice($categories, 0, self::MAX_CATEGORY_LINES);
        foreach ($shown as $row) {
            $name = trim((string) ($row['nama'] ?? 'Tanpa kategori'));
            if ($name === '') {
                $name = 'Tanpa kategori';
            }
            $lines[] = '- ' . $name . ': ' . self::number((int) ($row['jumlah'] ?? 0));
        }

        $remaining = count($categories) - count($shown);
        if ($remaining > 0) {
            $lines[] = '- ... dan ' . self::number($remaining) . ' kategori lainnya (daftar dibatasi agar pesan tetap ringkas).';
        }

        return $lines;
    }

    /**
     * Riwayat pengiriman aman untuk view.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function history(): array
    {
        $types = self::reportTypes();
        $result = [];
        foreach (WhatsAppOutbox::recentForFeature(ApiFeatureRegistry::WHATSAPP_REPORT, self::HISTORY_LIMIT) as $row) {
            $type = (string) ($row['report_type'] ?? '');
            if ($type === '') {
                $type = (string) ($row['template_key'] ?? '');
            }
            $status = (string) ($row['status'] ?? '');
            $recipient = (string) ($row['recipient'] ?? '');

            $result[] = [
                'id' => (int) ($row['id'] ?? 0),
                'type' => $type,
                'type_label' => $types[$type] ?? 'Laporan WhatsApp',
                'period_label' => (string) ($row['period_label'] ?? ''),
                'recipient_masked' => $recipient !== '' ? self::maskNumber($recipient) : '-',
                'status' => $status,
                'status_label' => self::statusLabel($status),
                'status_note' => self::statusNote($status),
                'status_class' => self::statusClass($status),
                'error_code' => (string) ($row['last_error_code'] ?? ''),
                'created_at' => $row['created_at'] ?? null,
                'accepted_at' => $row['accepted_at'] ?? null,
                'delivered_at' => $row['delivered_at'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * @return array{used:int,limit:int,remaining:int|null,reached:bool}
     */
    public static function dailyUsage(): array
    {
        $settings = ApiIntegrationSetting::get(ApiFeatureRegistry::WHATSAPP_REPORT)['settings'];
        $limit = max(0, (int) ($settings['daily_limit'] ?? 0));
        $used = WhatsAppOutbox::countToday(ApiFeatureRegistry::WHATSAPP_REPORT);

        return [
            'used' => $used,
            'limit' => $limit,
            'remaining' => $limit > 0 ? max(0, $limit - $used) : null,
            'reached' => $limit > 0 && $used >= $limit,
        ];
    }

    /**
     * @return array{id:int,label:string,provider:string}
     */
    private static function resolveConnection(int $connectionId): array
    {
        foreach (self::activeConnections() as $connection) {
            if ((int) $connection['id'] === $connectionId) {
                return $connection;
            }
        }

        throw new ValidationException(['koneksi' => 'Pilih koneksi WhatsApp aktif yang tersedia.']);
    }

    /**
     * @return array{index:int,name:string,number:string,masked:string}
     */
    private static function resolveRecipient(int $index): array
    {
        $recipients = self::recipients();
        if ($index < 0 || !isset($recipients[$index])) {
            throw new ValidationException([
                'penerima' => 'Penerima laporan tidak valid atau tidak termasuk daftar penerima berwenang.',
            ]);
        }

        return $recipients[$index];
    }

    /**
     * @param array<string, mixed> $filters
     */
    private static function fingerprint(
        string $type,
        array $filters,
        int $connectionId,
        string $recipientNumber,
        string $message
    ): string {
        $material = json_encode([
            'v' => 1,
            'type' => $type,
            'filters' => $filters,
            'connection_id' => $connectionId,
            'recipient' => $recipientNumber,
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', (string) $material);
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function storePreview(string $token, array $state): void
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            $_SESSION = [];
        }
        $previews = is_array($_SESSION['wa_report_previews'] ?? null)
            ? $_SESSION['wa_report_previews']
            : [];
        $now = time();
        foreach ($previews as $key => $preview) {
            $created = (int) ($preview['created_at'] ?? 0);
            if ($created > 0 && $now - $created > 3600) {
                unset($previews[$key]);
            }
        }
        if (count($previews) >= self::MAX_STORED_PREVIEWS) {
            uasort($previews, static fn (array $a, array $b): int =>
                (int) ($b['created_at'] ?? 0) <=> (int) ($a['created_at'] ?? 0));
            $previews = array_slice($previews, 0, self::MAX_STORED_PREVIEWS - 1, true);
        }
        $previews[$token] = $state;
        $_SESSION['wa_report_previews'] = $previews;
    }

    /** @return array<string, mixed>|null */
    private static function previewState(string $token): ?array
    {
        $previews = $_SESSION['wa_report_previews'] ?? null;
        if (!is_array($previews) || !isset($previews[$token]) || !is_array($previews[$token])) {
            return null;
        }

        return $previews[$token];
    }

    /**
     * @param array<string, mixed> $changes
     */
    private static function updatePreviewState(string $token, array $changes): void
    {
        $state = self::previewState($token);
        if ($state === null) {
            return;
        }
        $previews = $_SESSION['wa_report_previews'];
        $previews[$token] = array_merge($state, $changes);
        $_SESSION['wa_report_previews'] = $previews;
    }

    private static function audit(int $actorId, string $result, string $type, string $recipientMasked, string $code): void
    {
        try {
            AuditLog::record($actorId, 'whatsapp_laporan_dikirim', 'whatsapp_report', null, [
                'jenis_laporan' => self::reportTypes()[$type] ?? ($type !== '' ? $type : '-'),
                'penerima' => $recipientMasked !== '' ? $recipientMasked : '-',
                'status_kirim' => $result,
                'kode' => $code,
            ]);
        } catch (Throwable $exception) {
            app_log('Audit pengiriman laporan WhatsApp gagal dicatat.');
        }
    }

    private static function friendlySendError(string $code, string $providerMessage): string
    {
        $messages = [
            'feature_disabled' => 'Fitur laporan WhatsApp belum aktif.',
            'connection_unavailable' => 'Koneksi WhatsApp aktif tidak tersedia.',
            'invalid_recipient' => 'Nomor penerima laporan tidak valid.',
            'rate_limited' => 'Batas pengiriman harian sudah tercapai.',
            'provider_unsupported' => 'Provider WhatsApp tidak didukung.',
            'template_not_supported' => 'Provider ini hanya mendukung pesan teks.',
            'template_missing' => 'Template pesan tidak lengkap.',
        ];
        if (isset($messages[$code])) {
            return $messages[$code];
        }

        $providerMessage = trim($providerMessage);
        if ($providerMessage !== '') {
            return 'Provider WhatsApp menolak permintaan: ' . mb_substr($providerMessage, 0, 180);
        }

        return 'Provider WhatsApp menolak permintaan pengiriman. Periksa koneksi lalu coba lagi dengan pratinjau baru.';
    }

    private static function statusLabel(string $status): string
    {
        return [
            'accepted' => 'Diterima provider',
            'delivered' => 'Terkirim ke penerima',
            'read' => 'Dibaca',
            'failed' => 'Gagal',
            'pending' => 'Menunggu antrean',
            'processing' => 'Sedang diproses',
        ][$status] ?? 'Status tidak diketahui';
    }

    private static function statusNote(string $status): string
    {
        return [
            'accepted' => 'Provider menerima request; delivery belum tentu sudah terjadi.',
            'delivered' => 'Konfirmasi delivery dari webhook provider.',
            'read' => 'Konfirmasi baca dari webhook provider.',
            'failed' => 'Pengiriman gagal. Buat pratinjau baru sebelum mencoba lagi.',
            'pending' => 'Masih menunggu proses antrean.',
            'processing' => 'Sedang diproses.',
        ][$status] ?? '';
    }

    private static function statusClass(string $status): string
    {
        return [
            'accepted' => 'chip--info',
            'delivered' => 'chip--success',
            'read' => 'chip--success',
            'failed' => 'chip--danger',
            'pending' => 'chip--warning',
            'processing' => 'chip--warning',
        ][$status] ?? 'chip--muted';
    }

    private static function maskNumber(string $number): string
    {
        $digits = preg_replace('/\D+/', '', $number) ?? '';
        if ($digits === '') {
            return '-';
        }
        $tail = substr($digits, -4);

        return '****' . $tail;
    }

    private static function number(int $value): string
    {
        return number_format($value, 0, ',', '.');
    }
}
