<?php
/**
 * Model OTP WhatsApp (`whatsapp_otp`).
 *
 * Tidak pernah menyimpan kode OTP plaintext (hanya keyed HMAC) atau nomor
 * tujuan plaintext (hanya HMAC fingerprint).
 */

declare(strict_types=1);

final class WhatsAppOtp
{
    public static function invalidateActive(int $userId, string $purpose): void
    {
        $statement = Database::pdo()->prepare(
            'UPDATE whatsapp_otp SET expires_at = CURRENT_TIMESTAMP
             WHERE user_id = :user_id AND purpose = :purpose
               AND consumed_at IS NULL AND expires_at > CURRENT_TIMESTAMP'
        );
        $statement->execute(['user_id' => $userId, 'purpose' => $purpose]);
    }

    /**
     * Batalkan OTP aktif hanya untuk satu nomor tujuan.
     *
     * Dipakai saat pelanggan membatalkan permintaan ganti nomor, tanpa
     * membatalkan OTP verifikasi nomor utama yang mungkin sedang aktif.
     */
    public static function invalidateActiveForPhone(
        int $userId,
        string $purpose,
        string $fingerprint
    ): void {
        if ($userId <= 0 || $fingerprint === '') {
            return;
        }

        $statement = Database::pdo()->prepare(
            'UPDATE whatsapp_otp SET expires_at = CURRENT_TIMESTAMP
             WHERE user_id = :user_id AND purpose = :purpose
               AND phone_fingerprint = :fingerprint
               AND consumed_at IS NULL AND expires_at > CURRENT_TIMESTAMP'
        );
        $statement->execute([
            'user_id' => $userId,
            'purpose' => $purpose,
            'fingerprint' => $fingerprint,
        ]);
    }

    public static function create(
        int $userId,
        string $purpose,
        string $fingerprint,
        string $codeHash,
        int $ttlSeconds,
        int $maxAttempts
    ): int {
        $ttl = max(30, min(1800, $ttlSeconds));
        // Waktu kedaluwarsa memakai jam database agar konsisten dengan
        // CURRENT_TIMESTAMP pada saat verifikasi.
        $expiresAt = (string) Database::pdo()
            ->query('SELECT DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ' . $ttl . ' SECOND)')
            ->fetchColumn();

        $statement = Database::pdo()->prepare(
            'INSERT INTO whatsapp_otp
                (user_id, purpose, phone_fingerprint, code_hash, send_status,
                 expires_at, max_attempts, attempts, last_sent_at)
             VALUES
                (:user_id, :purpose, :phone_fingerprint, :code_hash, :send_status,
                 :expires_at, :max_attempts, 0, CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            'user_id' => $userId,
            'purpose' => $purpose,
            'phone_fingerprint' => $fingerprint,
            'code_hash' => $codeHash,
            'send_status' => 'pending',
            'expires_at' => $expiresAt,
            'max_attempts' => max(1, min(10, $maxAttempts)),
        ]);

        return (int) Database::pdo()->lastInsertId();
    }

    public static function markSent(int $id, string $providerMessageId): void
    {
        $statement = Database::pdo()->prepare(
            "UPDATE whatsapp_otp SET
                send_status = 'accepted',
                provider_message_id = :provider_message_id,
                last_sent_at = CURRENT_TIMESTAMP
             WHERE id = :id"
        );
        $statement->execute([
            'id' => $id,
            'provider_message_id' => mb_substr($providerMessageId, 0, 128),
        ]);
    }

    public static function markSendFailed(int $id): void
    {
        $statement = Database::pdo()->prepare(
            "UPDATE whatsapp_otp SET
                send_status = 'failed',
                consumed_at = CURRENT_TIMESTAMP
             WHERE id = :id"
        );
        $statement->execute(['id' => $id]);
    }

    /** @return array<string, mixed>|null */
    public static function latestActive(int $userId, string $purpose, string $fingerprint): ?array
    {
        $statement = Database::pdo()->prepare(
            "SELECT * FROM whatsapp_otp
             WHERE user_id = :user_id AND purpose = :purpose
               AND phone_fingerprint = :fingerprint
               AND consumed_at IS NULL
               AND send_status = 'accepted'
               AND expires_at > CURRENT_TIMESTAMP
             ORDER BY id DESC LIMIT 1"
        );
        $statement->execute([
            'user_id' => $userId,
            'purpose' => $purpose,
            'fingerprint' => $fingerprint,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public static function latestFor(int $userId, string $purpose, string $fingerprint): ?array
    {
        $statement = Database::pdo()->prepare(
            'SELECT *, (expires_at <= CURRENT_TIMESTAMP) AS is_expired
             FROM whatsapp_otp
             WHERE user_id = :user_id AND purpose = :purpose AND phone_fingerprint = :fingerprint
             ORDER BY id DESC LIMIT 1'
        );
        $statement->execute([
            'user_id' => $userId,
            'purpose' => $purpose,
            'fingerprint' => $fingerprint,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public static function incrementAttempts(int $id): int
    {
        $statement = Database::pdo()->prepare(
            'UPDATE whatsapp_otp SET attempts = attempts + 1 WHERE id = :id AND consumed_at IS NULL'
        );
        $statement->execute(['id' => $id]);
        $find = Database::pdo()->prepare('SELECT attempts FROM whatsapp_otp WHERE id = :id LIMIT 1');
        $find->execute(['id' => $id]);

        return (int) $find->fetchColumn();
    }

    public static function consume(int $id): bool
    {
        $statement = Database::pdo()->prepare(
            'UPDATE whatsapp_otp SET consumed_at = CURRENT_TIMESTAMP
             WHERE id = :id AND consumed_at IS NULL'
        );
        $statement->execute(['id' => $id]);

        return $statement->rowCount() === 1;
    }

    /**
     * Tandai OTP kedaluwarsa sekali saja.
     */
    public static function markExpired(int $id): bool
    {
        $statement = Database::pdo()->prepare(
            'UPDATE whatsapp_otp SET consumed_at = CURRENT_TIMESTAMP
             WHERE id = :id AND consumed_at IS NULL AND expires_at <= CURRENT_TIMESTAMP'
        );
        $statement->execute(['id' => $id]);

        return $statement->rowCount() === 1;
    }

    /**
     * Detik sejak permintaan OTP terakhir untuk akun+purpose, memakai jam DB.
     */
    public static function secondsSinceLatest(int $userId, string $purpose): ?int
    {
        $statement = Database::pdo()->prepare(
            'SELECT TIMESTAMPDIFF(SECOND, created_at, CURRENT_TIMESTAMP)
             FROM whatsapp_otp
             WHERE user_id = :user_id AND purpose = :purpose
             ORDER BY id DESC LIMIT 1'
        );
        $statement->execute(['user_id' => $userId, 'purpose' => $purpose]);
        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : (int) $value;
    }

    public static function countForUserToday(int $userId): int
    {
        $statement = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM whatsapp_otp
             WHERE user_id = :user_id AND DATE(created_at) = CURRENT_DATE'
        );
        $statement->execute(['user_id' => $userId]);

        return (int) $statement->fetchColumn();
    }

    public static function countForPhoneToday(string $fingerprint): int
    {
        $statement = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM whatsapp_otp
             WHERE phone_fingerprint = :fingerprint AND DATE(created_at) = CURRENT_DATE'
        );
        $statement->execute(['fingerprint' => $fingerprint]);

        return (int) $statement->fetchColumn();
    }

    /**
     * OTP aktif terakhir untuk akun+purpose.
     *
     * Bila fingerprint diberikan, hasil dibatasi pada nomor tersebut sehingga
     * kode lama tidak tampil aktif setelah nomor utama berubah. Tidak pernah
     * mengembalikan kode atau nomor plaintext.
     *
     * @return array<string, mixed>|null
     */
    public static function activeForUser(
        int $userId,
        string $purpose,
        ?string $fingerprint = null
    ): ?array {
        $fingerprintSql = $fingerprint !== null && $fingerprint !== ''
            ? ' AND phone_fingerprint = :fingerprint'
            : '';
        $statement = Database::pdo()->prepare(
            "SELECT id, expires_at, max_attempts, attempts, last_sent_at,
                    TIMESTAMPDIFF(SECOND, CURRENT_TIMESTAMP, expires_at) AS seconds_remaining
             FROM whatsapp_otp
             WHERE user_id = :user_id AND purpose = :purpose
               AND consumed_at IS NULL
               AND send_status = 'accepted'
               AND expires_at > CURRENT_TIMESTAMP"
            . $fingerprintSql
            . ' ORDER BY id DESC LIMIT 1'
        );
        $params = ['user_id' => $userId, 'purpose' => $purpose];
        if ($fingerprintSql !== '') {
            $params['fingerprint'] = $fingerprint;
        }
        $statement->execute($params);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public static function latestForUser(int $userId, string $purpose): ?array
    {
        $statement = Database::pdo()->prepare(
            'SELECT * FROM whatsapp_otp
             WHERE user_id = :user_id AND purpose = :purpose
             ORDER BY id DESC LIMIT 1'
        );
        $statement->execute(['user_id' => $userId, 'purpose' => $purpose]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public static function updateSendStatusByProviderId(string $providerMessageId, string $status): void
    {
        if ($providerMessageId === '') {
            return;
        }
        $statement = Database::pdo()->prepare(
            'UPDATE whatsapp_otp SET send_status = :status
             WHERE provider_message_id = :provider_message_id'
        );
        $statement->execute([
            'status' => mb_substr($status, 0, 20),
            'provider_message_id' => mb_substr($providerMessageId, 0, 128),
        ]);
    }
}
