<?php
/**
 * Audit konfigurasi API per fitur (`api_config_audit`).
 *
 * Hanya nama field yang berubah. Secret/isi pesan TIDAK PERNAH dicatat.
 */

declare(strict_types=1);

final class ApiConfigAudit
{
    /**
     * @param array<int, string> $changedFields
     */
    public static function record(?int $actorId, string $feature, string $action, array $changedFields = []): void
    {
        try {
            $statement = Database::pdo()->prepare(
                'INSERT INTO api_config_audit (actor_id, feature_key, action, changed_fields)
                 VALUES (:actor_id, :feature_key, :action, :changed_fields)'
            );
            $statement->execute([
                'actor_id' => $actorId !== null && $actorId > 0 ? $actorId : null,
                'feature_key' => mb_substr($feature, 0, 40),
                'action' => mb_substr($action, 0, 60),
                'changed_fields' => json_encode(
                    array_values(array_unique($changedFields)),
                    JSON_UNESCAPED_UNICODE
                ),
            ]);
        } catch (Throwable $exception) {
            // Audit tidak boleh mematikan fitur utama.
            app_log('Gagal menulis audit konfigurasi API.');
        }
    }

    /** @return array<int, array<string, mixed>> */
    public static function recent(string $feature, int $limit = 6): array
    {
        $limit = max(1, min(50, $limit));
        try {
            $statement = Database::pdo()->prepare(
                'SELECT a.*, u.nama AS actor_nama
                 FROM api_config_audit a
                 LEFT JOIN users u ON u.id = a.actor_id
                 WHERE a.feature_key = :feature
                 ORDER BY a.id DESC
                 LIMIT ' . $limit
            );
            $statement->execute(['feature' => $feature]);

            return $statement->fetchAll();
        } catch (Throwable $exception) {
            return [];
        }
    }
}
