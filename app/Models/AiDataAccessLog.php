<?php
/**
 * Audit akses data oleh AI (read-only) — WiFiCare CDN Tahap 18.
 *
 * Hanya metadata yang dicatat: pengguna, peran, sumber jawaban (local/tool),
 * nama intent, dataset, jumlah baris hasil, dan status. Isi percakapan, kata
 * kunci pencarian, nomor WhatsApp, alamat, dan data pelanggan TIDAK disimpan.
 */

declare(strict_types=1);

final class AiDataAccessLog
{
    /**
     * Catat akses data. Kegagalan audit tidak boleh menggagalkan jawaban chat.
     *
     * @param array<string, mixed> $user
     */
    public static function record(
        array $user,
        string $source,
        string $intent,
        string $dataset,
        int $resultCount,
        bool $success = true
    ): void {
        try {
            $statement = Database::pdo()->prepare(
                'INSERT INTO ai_data_access_log
                    (user_id, role, source, intent, dataset, result_count, success)
                 VALUES
                    (:user_id, :role, :source, :intent, :dataset, :result_count, :success)'
            );
            $statement->execute([
                'user_id' => (int) ($user['id'] ?? 0) > 0 ? (int) $user['id'] : null,
                'role' => mb_substr((string) ($user['role'] ?? ''), 0, 20),
                'source' => mb_substr($source, 0, 20),
                'intent' => mb_substr($intent, 0, 80),
                'dataset' => mb_substr($dataset, 0, 80),
                'result_count' => max(0, $resultCount),
                'success' => $success ? 1 : 0,
            ]);
        } catch (Throwable $exception) {
            app_log('Gagal mencatat audit akses data AI: ' . $exception->getMessage());
        }
    }

    /**
     * Ringkasan sumber jawaban lokal dan intent tak dikenal. Hanya agregat,
     * tanpa isi percakapan atau filter pengguna.
     *
     * @return array<string, int>
     */
    public static function stats(int $days = 30): array
    {
        $empty = [
            'total' => 0,
            'local' => 0,
            'tool' => 0,
            'failed' => 0,
            'unknown_intent' => 0,
        ];

        try {
            $start = (new DateTimeImmutable('now'))
                ->modify('-' . max(1, min(365, $days)) . ' days')
                ->setTime(0, 0, 0)
                ->format('Y-m-d H:i:s');
            $statement = Database::pdo()->prepare(
                'SELECT COUNT(*) AS total,
                        COALESCE(SUM(source = \'local\'), 0) AS lokal,
                        COALESCE(SUM(source = \'tool\'), 0) AS tool,
                        COALESCE(SUM(success = 0), 0) AS gagal,
                        COALESCE(SUM(intent IN (\'intent_not_recognized\', \'unsupported\')), 0) AS unknown_intent
                 FROM ai_data_access_log
                 WHERE created_at >= :start'
            );
            $statement->execute(['start' => $start]);
            $row = $statement->fetch() ?: [];

            return [
                'total' => (int) ($row['total'] ?? 0),
                'local' => (int) ($row['lokal'] ?? 0),
                'tool' => (int) ($row['tool'] ?? 0),
                'failed' => (int) ($row['gagal'] ?? 0),
                'unknown_intent' => (int) ($row['unknown_intent'] ?? 0),
            ];
        } catch (Throwable $exception) {
            app_log('Gagal membaca ringkasan audit akses data AI: ' . $exception->getMessage());

            return $empty;
        }
    }

    /**
     * Ringkasan audit untuk halaman Admin (tanpa data sensitif).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function recent(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));

        try {
            $statement = Database::pdo()->prepare(
                'SELECT l.id, l.user_id, COALESCE(u.nama, \'(pengguna dihapus)\') AS nama,
                        COALESCE(l.role, u.role, \'-\') AS role, l.source, l.intent,
                        l.dataset, l.result_count, l.success, l.created_at
                 FROM ai_data_access_log l
                 LEFT JOIN users u ON u.id = l.user_id
                 ORDER BY l.id DESC
                 LIMIT ' . $limit
            );
            $statement->execute();

            return $statement->fetchAll();
        } catch (Throwable $exception) {
            app_log('Gagal membaca audit akses data AI: ' . $exception->getMessage());

            return [];
        }
    }
}
