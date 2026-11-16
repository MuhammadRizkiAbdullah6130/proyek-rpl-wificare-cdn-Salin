<?php
/**
 * Antrean WhatsApp idempoten (`whatsapp_outbox`).
 *
 * `event_key` unik mencegah satu peristiwa terkirim dua kali. Isi pesan
 * dienkripsi pada `payload_encrypted`. Status delivered/read HANYA berasal dari
 * webhook provider terverifikasi.
 */

declare(strict_types=1);

final class WhatsAppOutbox
{
    /**
     * Tambahkan pesan ke antrean. Mengembalikan id + apakah baru dibuat.
     *
     * @param array<string, mixed> $payload
     * @return array{id:int,created:bool}
     */
    public static function enqueue(
        string $feature,
        string $eventKey,
        ?int $connectionId,
        string $recipient,
        string $sendMode,
        string $templateName,
        string $languageCode,
        array $payload,
        int $maxAttempts = 3,
        string $templateKey = ''
    ): array {
        $encryptedRecipient = Crypto::encrypt($recipient);
        $encryptedPayload = Crypto::encrypt(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');

        $statement = Database::pdo()->prepare(
            'INSERT IGNORE INTO whatsapp_outbox
                (feature_key, event_key, connection_id, recipient_encrypted, template_key,
                 send_mode, template_name, language_code, payload_encrypted, status,
                 attempts, max_attempts, next_attempt_at)
             VALUES
                (:feature_key, :event_key, :connection_id, :recipient, :template_key,
                 :send_mode, :template_name, :language_code, :payload, :status,
                 0, :max_attempts, CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            'feature_key' => $feature,
            'event_key' => mb_substr($eventKey, 0, 150),
            'connection_id' => $connectionId !== null && $connectionId > 0 ? $connectionId : null,
            'recipient' => $encryptedRecipient,
            'template_key' => $templateKey !== '' ? mb_substr($templateKey, 0, 60) : null,
            'send_mode' => $sendMode === 'template' ? 'template' : 'text',
            'template_name' => $templateName !== '' ? mb_substr($templateName, 0, 120) : null,
            'language_code' => $languageCode !== '' ? mb_substr($languageCode, 0, 20) : null,
            'payload' => $encryptedPayload,
            'status' => 'pending',
            'max_attempts' => max(1, min(10, $maxAttempts)),
        ]);

        if ($statement->rowCount() === 1) {
            return ['id' => (int) Database::pdo()->lastInsertId(), 'created' => true];
        }

        $find = Database::pdo()->prepare('SELECT id FROM whatsapp_outbox WHERE event_key = :event_key LIMIT 1');
        $find->execute(['event_key' => mb_substr($eventKey, 0, 150)]);

        return ['id' => (int) $find->fetchColumn(), 'created' => false];
    }

    /**
     * Simpan pesan yang langsung diterima provider (mis. OTP) agar status
     * delivery tetap dapat dilacak lewat webhook.
     *
     * @param array<string, mixed> $payload
     */
    public static function recordAccepted(
        string $feature,
        string $eventKey,
        ?int $connectionId,
        string $recipient,
        string $sendMode,
        string $templateName,
        string $languageCode,
        array $payload,
        string $providerMessageId,
        string $templateKey = ''
    ): int {
        $queued = self::enqueue(
            $feature,
            $eventKey,
            $connectionId,
            $recipient,
            $sendMode,
            $templateName,
            $languageCode,
            $payload,
            1,
            $templateKey
        );
        if (!empty($queued['created'])) {
            $statement = Database::pdo()->prepare(
                "UPDATE whatsapp_outbox SET
                    status = 'accepted',
                    attempts = 1,
                    provider_message_id = :provider_message_id,
                    accepted_at = CURRENT_TIMESTAMP,
                    last_error_code = NULL,
                    last_error_message = NULL,
                    next_attempt_at = NULL
                 WHERE id = :id"
            );
            $statement->execute([
                'id' => (int) $queued['id'],
                'provider_message_id' => mb_substr($providerMessageId, 0, 128),
            ]);
        }

        return (int) $queued['id'];
    }

    /**
     * Klaim antrean. Update bersyarat memastikan satu baris hanya diproses
     * satu worker.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function claim(int $limit = 10, string $feature = ''): array
    {
        $limit = max(1, min(50, $limit));
        $sql = "SELECT id FROM whatsapp_outbox
                WHERE status = 'pending'
                  AND (next_attempt_at IS NULL OR next_attempt_at <= CURRENT_TIMESTAMP)";
        $params = [];
        if ($feature !== '') {
            $sql .= ' AND feature_key = :feature';
            $params['feature'] = $feature;
        }
        $sql .= ' ORDER BY id ASC LIMIT ' . $limit;

        $statement = Database::pdo()->prepare($sql);
        $statement->execute($params);
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $statement->fetchAll());

        $claimed = [];
        $claim = Database::pdo()->prepare(
            "UPDATE whatsapp_outbox
             SET status = 'processing'
             WHERE id = :id AND status = 'pending'"
        );
        $find = Database::pdo()->prepare('SELECT * FROM whatsapp_outbox WHERE id = :id LIMIT 1');

        foreach ($ids as $id) {
            $claim->execute(['id' => $id]);
            if ($claim->rowCount() !== 1) {
                continue;
            }
            $find->execute(['id' => $id]);
            $row = $find->fetch();
            if (is_array($row)) {
                $claimed[] = $row;
            }
        }

        return $claimed;
    }

    public static function markAccepted(int $id, string $providerMessageId): void
    {
        $statement = Database::pdo()->prepare(
            "UPDATE whatsapp_outbox SET
                status = 'accepted',
                attempts = attempts + 1,
                provider_message_id = :provider_message_id,
                accepted_at = CURRENT_TIMESTAMP,
                last_error_code = NULL,
                last_error_message = NULL,
                next_attempt_at = NULL
             WHERE id = :id AND status IN ('pending','processing','accepted')"
        );
        $statement->execute([
            'id' => $id,
            'provider_message_id' => mb_substr($providerMessageId, 0, 128),
        ]);
    }

    /**
     * Tandai pesan tidak dikirim karena konfigurasi Admin berubah (mis. event
     * dinonaktifkan). Status akhir `failed` dipakai agar tidak menjadi pending
     * permanen; tidak ada klaim delivery.
     */
    public static function markSkipped(int $id, string $code, string $message): void
    {
        $statement = Database::pdo()->prepare(
            "UPDATE whatsapp_outbox SET
                attempts = max_attempts,
                status = 'failed',
                last_error_code = :last_error_code,
                last_error_message = :last_error_message,
                next_attempt_at = NULL
             WHERE id = :id AND status IN ('processing','pending')"
        );
        $statement->execute([
            'id' => $id,
            'last_error_code' => mb_substr($code, 0, 64),
            'last_error_message' => mb_substr($message, 0, 255),
        ]);
    }

    public static function markFailure(int $id, string $code, string $message): void
    {
        $find = Database::pdo()->prepare('SELECT attempts, max_attempts FROM whatsapp_outbox WHERE id = :id LIMIT 1');
        $find->execute(['id' => $id]);
        $row = $find->fetch();
        if (!is_array($row)) {
            return;
        }

        $attempts = (int) $row['attempts'] + 1;
        $max = max(1, (int) $row['max_attempts']);
        $retry = $attempts < $max;
        $nextSql = $retry
            ? 'DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ' . min(3600, 60 * $attempts) . ' SECOND)'
            : 'NULL';

        $statement = Database::pdo()->prepare(
            "UPDATE whatsapp_outbox SET
                attempts = :attempts,
                status = :status,
                last_error_code = :last_error_code,
                last_error_message = :last_error_message,
                next_attempt_at = " . $nextSql . "
             WHERE id = :id AND status IN ('processing','pending')"
        );
        $statement->execute([
            'id' => $id,
            'attempts' => $attempts,
            'status' => $retry ? 'pending' : 'failed',
            'last_error_code' => mb_substr($code, 0, 64),
            'last_error_message' => mb_substr($message, 0, 255),
        ]);
    }

    /**
     * Terapkan status dari webhook. Tidak pernah menaikkan status ke delivered
     * tanpa konfirmasi provider.
     */
    public static function applyProviderStatus(string $providerMessageId, string $status, string $errorCode = ''): bool
    {
        $providerMessageId = trim($providerMessageId);
        if ($providerMessageId === '') {
            return false;
        }

        $status = strtolower(trim($status));
        $map = [
            'sent' => 'accepted',
            'accepted' => 'accepted',
            'delivered' => 'delivered',
            'read' => 'read',
            'failed' => 'failed',
        ];
        $target = $map[$status] ?? '';
        if ($target === '') {
            return false;
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $find = $pdo->prepare(
                'SELECT id, status FROM whatsapp_outbox WHERE provider_message_id = :provider_message_id ORDER BY id DESC LIMIT 1 FOR UPDATE'
            );
            $find->execute(['provider_message_id' => mb_substr($providerMessageId, 0, 128)]);
            $row = $find->fetch();
            if (!is_array($row)) {
                $pdo->rollBack();

                return false;
            }

            $current = (string) $row['status'];
            if ($current === 'failed' && $target !== 'failed') {
                $pdo->rollBack();

                return false;
            }
            if ($current === 'read' && $target !== 'failed') {
                $pdo->rollBack();

                return false;
            }
            if ($current === 'delivered' && in_array($target, ['accepted'], true)) {
                $pdo->rollBack();

                return false;
            }

            $sets = ['status = :status'];
            $params = ['id' => (int) $row['id'], 'status' => $target];
            if ($target === 'accepted') {
                $sets[] = 'accepted_at = COALESCE(accepted_at, CURRENT_TIMESTAMP)';
            } elseif ($target === 'delivered') {
                $sets[] = 'delivered_at = COALESCE(delivered_at, CURRENT_TIMESTAMP)';
            } elseif ($target === 'read') {
                $sets[] = 'delivered_at = COALESCE(delivered_at, CURRENT_TIMESTAMP)';
                $sets[] = 'read_at = COALESCE(read_at, CURRENT_TIMESTAMP)';
            } elseif ($target === 'failed') {
                $sets[] = 'last_error_code = :error_code';
                $params['error_code'] = $errorCode !== '' ? mb_substr($errorCode, 0, 64) : 'provider_failed';
            }

            $update = $pdo->prepare('UPDATE whatsapp_outbox SET ' . implode(', ', $sets) . ' WHERE id = :id');
            $update->execute($params);
            $pdo->commit();

            return true;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            app_log('Gagal memperbarui status delivery WhatsApp.');

            return false;
        }
    }

    public static function countToday(string $feature = ''): int
    {
        $sql = 'SELECT COUNT(*) FROM whatsapp_outbox WHERE DATE(created_at) = CURRENT_DATE';
        $params = [];
        if ($feature !== '') {
            $sql .= ' AND feature_key = :feature';
            $params['feature'] = $feature;
        }

        $statement = Database::pdo()->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    /** @return array<string, int> */
    public static function statusCounts(string $feature = ''): array
    {
        $where = '1 = 1';
        $params = [];
        if ($feature !== '') {
            $where .= ' AND feature_key = :feature';
            $params['feature'] = $feature;
        }

        $statement = Database::pdo()->prepare(
            'SELECT status, COUNT(*) AS jumlah FROM whatsapp_outbox WHERE ' . $where . ' GROUP BY status'
        );
        $statement->execute($params);
        $counts = [];
        foreach ($statement->fetchAll() as $row) {
            $counts[(string) $row['status']] = (int) $row['jumlah'];
        }

        return $counts;
    }

    /**
     * @return array<string, array<string, int>>
     */
    public static function groupedByFeature(string $from = '', string $to = ''): array
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

        $statement = Database::pdo()->prepare(
            'SELECT feature_key, status, COUNT(*) AS jumlah
             FROM whatsapp_outbox
             WHERE ' . $where . '
             GROUP BY feature_key, status'
        );
        $statement->execute($params);
        $result = [];
        foreach ($statement->fetchAll() as $row) {
            $feature = (string) $row['feature_key'];
            if (!isset($result[$feature])) {
                $result[$feature] = [];
            }
            $result[$feature][(string) $row['status']] = (int) $row['jumlah'];
        }

        return $result;
    }

    /**
     * Statistik per template/event untuk fitur.
     *
     * @return array<string, array<string, int>>
     */
    public static function groupedByTemplate(string $feature): array
    {
        $statement = Database::pdo()->prepare(
            'SELECT COALESCE(template_key, \'\') AS template_key, status, COUNT(*) AS jumlah
             FROM whatsapp_outbox
             WHERE feature_key = :feature
             GROUP BY template_key, status'
        );
        $statement->execute(['feature' => $feature]);
        $result = [];
        foreach ($statement->fetchAll() as $row) {
            $key = (string) $row['template_key'];
            if (!isset($result[$key])) {
                $result[$key] = [];
            }
            $result[$key][(string) $row['status']] = (int) $row['jumlah'];
        }

        return $result;
    }

    /**
     * Riwayat pengiriman terbaru untuk satu fitur.
     *
     * Isi pesan tidak dikembalikan utuh ke view; service hanya memakai
     * metadata aman (jenis laporan, status, waktu, penerima terenkripsi yang
     * didekripsi internal untuk ditampilkan dalam bentuk tersamarkan).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function recentForFeature(string $feature, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $statement = Database::pdo()->prepare(
            'SELECT * FROM whatsapp_outbox
             WHERE feature_key = :feature
             ORDER BY id DESC
             LIMIT ' . $limit
        );
        $statement->execute(['feature' => $feature]);
        $rows = $statement->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $decoded = self::decode($row);
            $payload = is_array($decoded['payload'] ?? null) ? $decoded['payload'] : [];

            $result[] = [
                'id' => (int) ($row['id'] ?? 0),
                'connection_id' => $row['connection_id'] === null ? null : (int) $row['connection_id'],
                'recipient' => (string) ($decoded['recipient'] ?? ''),
                'template_key' => (string) ($row['template_key'] ?? ''),
                'report_type' => (string) ($payload['report_type'] ?? ''),
                'period_label' => (string) ($payload['period_label'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'attempts' => (int) ($row['attempts'] ?? 0),
                'last_error_code' => (string) ($row['last_error_code'] ?? ''),
                'last_error_message' => (string) ($row['last_error_message'] ?? ''),
                'provider_message_id' => (string) ($row['provider_message_id'] ?? ''),
                'created_at' => $row['created_at'] ?? null,
                'accepted_at' => $row['accepted_at'] ?? null,
                'delivered_at' => $row['delivered_at'] ?? null,
                'read_at' => $row['read_at'] ?? null,
            ];
        }

        return $result;
    }

    /** @return array<string, mixed>|null */
    public static function decode(array $row): ?array
    {
        $recipient = Crypto::decrypt((string) ($row['recipient_encrypted'] ?? ''));
        $payload = Crypto::decrypt((string) ($row['payload_encrypted'] ?? ''));
        if ($recipient === null || $payload === null) {
            return null;
        }

        $decoded = json_decode($payload, true);

        return [
            'id' => (int) $row['id'],
            'feature_key' => (string) $row['feature_key'],
            'connection_id' => $row['connection_id'] === null ? null : (int) $row['connection_id'],
            'recipient' => $recipient,
            'send_mode' => (string) ($row['send_mode'] ?? 'text'),
            'template_name' => (string) ($row['template_name'] ?? ''),
            'language_code' => (string) ($row['language_code'] ?? ''),
            'payload' => is_array($decoded) ? $decoded : [],
            'status' => (string) $row['status'],
            'attempts' => (int) $row['attempts'],
            'max_attempts' => (int) $row['max_attempts'],
            'provider_message_id' => (string) ($row['provider_message_id'] ?? ''),
        ];
    }
}
