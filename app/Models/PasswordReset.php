<?php
/**
 * Model otorisasi reset password dan pembatas sumber permintaan.
 *
 * Tabel `password_reset_authorizations` tidak pernah menyimpan token reset
 * plaintext (hanya keyed HMAC). Tabel `password_reset_events` tidak menyimpan
 * username, nomor WhatsApp, OTP, atau password.
 */

declare(strict_types=1);

final class PasswordReset
{
    /**
     * Cari akun Pelanggan aktif yang memiliki baris pelanggan.
     *
     * Pemeriksaan status nomor WhatsApp terverifikasi dilakukan oleh service
     * karena membutuhkan fingerprint kriptografis nomor saat ini.
     *
     * @return array<string, mixed>|null
     */
    public static function findEligibleAccount(string $identifier): ?array
    {
        $sql = 'SELECT u.*, p.id AS pelanggan_id, p.username_pppoe, p.nomor_whatsapp,
                       p.nomor_whatsapp_verified_at, p.nomor_whatsapp_verified_fingerprint
                FROM users u
                INNER JOIN pelanggan p ON p.user_id = u.id
                WHERE (u.username = :username OR p.username_pppoe = :pppoe)
                  AND u.role = :role
                  AND u.is_active = 1
                LIMIT 1';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute([
            'username' => $identifier,
            'pppoe' => $identifier,
            'role' => 'pelanggan',
        ]);

        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * Ambil akun Pelanggan aktif berdasarkan ID.
     *
     * @return array<string, mixed>|null
     */
    public static function findAccountByUserId(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $sql = 'SELECT u.*, p.id AS pelanggan_id, p.username_pppoe, p.nomor_whatsapp,
                       p.nomor_whatsapp_verified_at, p.nomor_whatsapp_verified_fingerprint
                FROM users u
                INNER JOIN pelanggan p ON p.user_id = u.id
                WHERE u.id = :id AND u.role = :role AND u.is_active = 1
                LIMIT 1';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['id' => $userId, 'role' => 'pelanggan']);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * Simpan otorisasi reset baru dan batalkan otorisasi lama akun tersebut.
     *
     * Biasanya dipanggil di dalam transaksi konsumsi OTP agar otorisasi hanya
     * ada bila OTP benar-benar berhasil dipakai.
     */
    public static function createAuthorization(
        int $userId,
        string $tokenHash,
        ?int $otpId,
        int $ttlSeconds
    ): int {
        if ($userId <= 0 || $tokenHash === '') {
            throw new InvalidArgumentException('Otorisasi reset tidak valid.');
        }

        self::invalidateAuthorizations($userId);

        $ttl = max(60, min(1800, $ttlSeconds));
        $expiresAt = (string) Database::pdo()
            ->query('SELECT DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ' . $ttl . ' SECOND)')
            ->fetchColumn();

        $statement = Database::pdo()->prepare(
            'INSERT INTO password_reset_authorizations
                (user_id, otp_id, token_hash, expires_at)
             VALUES
                (:user_id, :otp_id, :token_hash, :expires_at)'
        );
        $statement->execute([
            'user_id' => $userId,
            'otp_id' => $otpId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
        ]);

        return (int) Database::pdo()->lastInsertId();
    }

    /**
     * Batalkan semua otorisasi reset akun yang belum dipakai.
     */
    public static function invalidateAuthorizations(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $statement = Database::pdo()->prepare(
            'UPDATE password_reset_authorizations
             SET consumed_at = CURRENT_TIMESTAMP
             WHERE user_id = :user_id AND consumed_at IS NULL'
        );
        $statement->execute(['user_id' => $userId]);
    }

    /**
     * Otorisasi yang masih valid untuk akun dan token tertentu.
     *
     * @return array<string, mixed>|null
     */
    public static function validAuthorization(int $userId, string $tokenHash): ?array
    {
        if ($userId <= 0 || $tokenHash === '') {
            return null;
        }

        $statement = Database::pdo()->prepare(
            'SELECT * FROM password_reset_authorizations
             WHERE user_id = :user_id AND token_hash = :token_hash
               AND consumed_at IS NULL
               AND expires_at > CURRENT_TIMESTAMP
             ORDER BY id DESC LIMIT 1'
        );
        $statement->execute(['user_id' => $userId, 'token_hash' => $tokenHash]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * Pakai otorisasi secara atomik. Hanya satu request yang dapat berhasil.
     */
    public static function consumeAuthorization(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        $statement = Database::pdo()->prepare(
            'UPDATE password_reset_authorizations
             SET consumed_at = CURRENT_TIMESTAMP
             WHERE id = :id AND consumed_at IS NULL
               AND expires_at > CURRENT_TIMESTAMP'
        );
        $statement->execute(['id' => $id]);

        return $statement->rowCount() === 1;
    }

    /**
     * Hash sumber permintaan (IP) tanpa menyimpan alamat plaintext.
     */
    public static function sourceHash(): string
    {
        $fingerprint = Crypto::fingerprint('password-reset-source:' . client_ip());
        if ($fingerprint !== '') {
            return $fingerprint;
        }

        // Fallback deterministik bila master key belum siap. Tetap tidak
        // menyimpan IP plaintext di tabel rate limit.
        return hash('sha256', 'password-reset-source:' . client_ip());
    }

    public static function recordEvent(
        ?int $userId,
        string $event,
        bool $success
    ): void {
        $event = mb_substr(trim($event), 0, 40);
        if ($event === '') {
            return;
        }

        $statement = Database::pdo()->prepare(
            'INSERT INTO password_reset_events (user_id, ip_hash, event, success)
             VALUES (:user_id, :ip_hash, :event, :success)'
        );
        $statement->execute([
            'user_id' => $userId !== null && $userId > 0 ? $userId : null,
            'ip_hash' => self::sourceHash(),
            'event' => $event,
            'success' => $success ? 1 : 0,
        ]);
    }

    public static function countRecentByIp(string $event, int $seconds): int
    {
        $seconds = max(1, min(86400, $seconds));
        $statement = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM password_reset_events
             WHERE ip_hash = :ip_hash AND event = :event
               AND created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ' . $seconds . ' SECOND)'
        );
        $statement->execute(['ip_hash' => self::sourceHash(), 'event' => $event]);

        return (int) $statement->fetchColumn();
    }

    public static function countRecentByUser(int $userId, string $event, int $seconds): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $seconds = max(1, min(86400, $seconds));
        $statement = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM password_reset_events
             WHERE user_id = :user_id AND event = :event
               AND created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ' . $seconds . ' SECOND)'
        );
        $statement->execute(['user_id' => $userId, 'event' => $event]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Bersihkan riwayat rate limit lama agar tabel tidak tumbuh tanpa batas.
     */
    public static function pruneEvents(int $days = 30): void
    {
        $days = max(1, min(365, $days));
        Database::pdo()
            ->exec('DELETE FROM password_reset_events WHERE created_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ' . $days . ' DAY)');
    }
}
