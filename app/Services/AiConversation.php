<?php
/**
 * Konteks percakapan AI sementara (per sesi login, per pengguna).
 *
 * Riwayat TIDAK disimpan ke MySQL. Data hanya berada di session server,
 * dibatasi jumlah/panjangnya, dan otomatis dianggap milik pengguna yang sedang
 * login. Bila id pengguna berubah, riwayat lama dibuang sehingga percakapan
 * akun sebelumnya tidak pernah muncul pada akun berikutnya.
 */

declare(strict_types=1);

final class AiConversation
{
    private const KEY = 'ai_conversation';
    private const MAX_MESSAGES = 12;
    private const MAX_CHARS = 2000;
    private const TTL_SECONDS = 1800;

    /**
     * @return array<int, array{role: string, content: string}>
     */
    public static function history(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $state = $_SESSION[self::KEY] ?? null;
        if (!is_array($state) || (int) ($state['user_id'] ?? 0) !== $userId) {
            return [];
        }

        if (time() - (int) ($state['updated_at'] ?? 0) > self::TTL_SECONDS) {
            unset($_SESSION[self::KEY]);

            return [];
        }

        $messages = [];
        foreach ((array) ($state['messages'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $role = (string) ($entry['role'] ?? '');
            $content = trim((string) ($entry['content'] ?? ''));
            if (($role === 'user' || $role === 'assistant') && $content !== '') {
                $messages[] = ['role' => $role, 'content' => $content];
            }
        }

        return $messages;
    }

    public static function append(int $userId, string $role, string $content): void
    {
        if ($userId <= 0 || ($role !== 'user' && $role !== 'assistant')) {
            return;
        }

        $content = trim($content);
        if ($content === '') {
            return;
        }

        $messages = self::history($userId);
        $messages[] = ['role' => $role, 'content' => mb_substr($content, 0, self::MAX_CHARS)];
        if (count($messages) > self::MAX_MESSAGES) {
            $messages = array_slice($messages, -self::MAX_MESSAGES);
        }

        $_SESSION[self::KEY] = [
            'user_id' => $userId,
            'updated_at' => time(),
            'messages' => $messages,
        ];
    }

    public static function clear(int $userId): void
    {
        $state = $_SESSION[self::KEY] ?? null;
        if (!is_array($state) || (int) ($state['user_id'] ?? 0) === $userId || $userId <= 0) {
            unset($_SESSION[self::KEY]);
        }
    }

    /**
     * Hapus konteks percakapan apa pun pada session ini.
     *
     * Dipanggil saat login dan logout agar akun berikutnya pada browser yang
     * sama tidak pernah mewarisi identitas/percakapan akun sebelumnya.
     */
    public static function clearAll(): void
    {
        unset($_SESSION[self::KEY]);
    }
}
