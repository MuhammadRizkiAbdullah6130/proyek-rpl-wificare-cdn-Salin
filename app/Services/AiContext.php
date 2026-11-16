<?php
/**
 * Konteks percakapan terstruktur untuk asisten database lokal.
 *
 * Hanya menyimpan referensi entitas yang sudah diotorisasi (mis. pelanggan
 * yang baru dicari oleh pengguna) di session server. Tidak ada isi database
 * mentah, kredensial, atau data pengguna lain. Konteks kedaluwarsa otomatis.
 */

declare(strict_types=1);

final class AiContext
{
    private const KEY = 'ai_db_context';
    private const TTL_SECONDS = 1800;

    /** @return array<string, mixed> */
    public static function get(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $state = $_SESSION[self::KEY] ?? null;
        if (!is_array($state) || (int) ($state['user_id'] ?? 0) !== $userId) {
            return [];
        }

        if (time() - (int) ($state['updated_at'] ?? 0) > self::TTL_SECONDS) {
            self::clear($userId);

            return [];
        }

        return is_array($state['data'] ?? null) ? $state['data'] : [];
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public static function remember(int $userId, array $values): array
    {
        if ($userId <= 0) {
            return [];
        }

        $data = array_merge(self::get($userId), $values);
        // Batasi nilai agar session tetap kecil dan tidak menyimpan data sensitif.
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = mb_substr($value, 0, 160);
            } elseif (!is_int($value) && !is_bool($value) && $value !== null) {
                unset($data[$key]);
            }
        }

        $_SESSION[self::KEY] = [
            'user_id' => $userId,
            'updated_at' => time(),
            'data' => $data,
        ];

        return $data;
    }

    public static function clear(int $userId): void
    {
        $state = $_SESSION[self::KEY] ?? null;
        if (is_array($state) && (int) ($state['user_id'] ?? 0) === $userId) {
            unset($_SESSION[self::KEY]);
        }
    }

    /**
     * Hapus konteks milik akun mana pun sebelum sesi berganti/dihapus.
     * Dipanggil saat login/logout agar konteks tidak pernah terbawa antar akun.
     */
    public static function clearAll(): void
    {
        unset($_SESSION[self::KEY]);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function promptContext(array $context): string
    {
        $parts = [];
        if (!empty($context['last_customer_name'])) {
            $parts[] = 'Pelanggan yang terakhir dibahas: ' . (string) $context['last_customer_name'];
        }
        if (!empty($context['last_customer_pppoe'])) {
            $parts[] = 'Username PPPoE pelanggan tersebut: ' . (string) $context['last_customer_pppoe'];
        }
        if (!empty($context['last_ticket'])) {
            $parts[] = 'Nomor tiket terakhir yang dibahas: ' . (string) $context['last_ticket'];
        }
        if (!empty($context['last_period'])) {
            $parts[] = 'Periode terakhir yang dibahas: ' . (string) $context['last_period'];
        }

        return $parts === [] ? '' : 'KONTEKS DATABASE TERAKHIR (hanya referensi, tetap ikuti RBAC): '
            . implode('; ', $parts) . '.';
    }
}
