<?php
/**
 * Model audit log.
 *
 * Tabel append-only untuk merekam aktivitas penting (login, logout, dsb).
 */

declare(strict_types=1);

final class AuditLog
{
    /**
     * Catat satu aktivitas.
     *
     * @param array<string, mixed>|null $metadata
     */
    public static function record(
        ?int $actorId,
        string $aksi,
        ?string $objek = null,
        ?int $objekId = null,
        ?array $metadata = null
    ): void {
        $sql = 'INSERT INTO audit_log (actor_id, aksi, objek, objek_id, metadata, ip_address)
                VALUES (:actor_id, :aksi, :objek, :objek_id, :metadata, :ip_address)';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute([
            'actor_id' => $actorId,
            'aksi' => $aksi,
            'objek' => $objek,
            'objek_id' => $objekId,
            'metadata' => $metadata === null ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE),
            'ip_address' => client_ip(),
        ]);
    }

    /**
     * Aktivitas audit terbaru (untuk ringkasan dashboard).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function recent(int $limit = 6): array
    {
        $limit = max(1, min(50, $limit));

        $sql = 'SELECT a.id, a.aksi, a.objek, a.objek_id, a.created_at, u.nama AS actor, u.role AS actor_role
                FROM audit_log a
                LEFT JOIN users u ON u.id = a.actor_id
                ORDER BY a.id DESC
                LIMIT ' . $limit;

        return Database::pdo()->query($sql)->fetchAll();
    }

    /* =====================================================================
     * Halaman Audit Log (Admin — Tahap 8)
     * =================================================================== */

    /**
     * Daftar audit log dengan filter & pagination (khusus Admin).
     *
     * @param array{q?: string, aksi?: string, actor_id?: int, date_from?: string, date_to?: string} $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int, pages: int, page: int, per_page: int}
     */
    public static function paginateForAdmin(array $filters, int $page, int $perPage = 15): array
    {
        $where = ['1 = 1'];
        $params = [];

        $keyword = trim((string) ($filters['q'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . self::escapeLike($keyword) . '%';
            $where[] = '(u.nama LIKE :q1 OR a.aksi LIKE :q2 OR a.objek LIKE :q3)';
            $params['q1'] = $like;
            $params['q2'] = $like;
            $params['q3'] = $like;
        }

        $aksi = (string) ($filters['aksi'] ?? '');
        if ($aksi !== '') {
            $where[] = 'a.aksi = :aksi';
            $params['aksi'] = $aksi;
        }

        $actorId = (int) ($filters['actor_id'] ?? 0);
        if ($actorId > 0) {
            $where[] = 'a.actor_id = :actor_id';
            $params['actor_id'] = $actorId;
        }

        $dateFrom = (string) ($filters['date_from'] ?? '');
        if (self::isValidDate($dateFrom)) {
            $where[] = 'a.created_at >= :date_from';
            $params['date_from'] = $dateFrom . ' 00:00:00';
        }

        $dateTo = (string) ($filters['date_to'] ?? '');
        if (self::isValidDate($dateTo)) {
            $where[] = 'a.created_at <= :date_to';
            $params['date_to'] = $dateTo . ' 23:59:59';
        }

        $whereSql = implode(' AND ', $where);
        $joins = ' FROM audit_log a LEFT JOIN users u ON u.id = a.actor_id';

        $countStatement = Database::pdo()->prepare('SELECT COUNT(*)' . $joins . ' WHERE ' . $whereSql);
        $countStatement->execute($params);
        $total = (int) $countStatement->fetchColumn();

        $perPage = max(1, min(50, $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        // $perPage dan $offset adalah integer tervalidasi, aman di-inline.
        $sql = 'SELECT a.id, a.aksi, a.objek, a.objek_id, a.metadata, a.ip_address, a.created_at,
                       u.nama AS actor, u.role AS actor_role'
            . $joins
            . ' WHERE ' . $whereSql
            . ' ORDER BY a.id DESC'
            . ' LIMIT ' . $perPage . ' OFFSET ' . $offset;

        $statement = Database::pdo()->prepare($sql);
        $statement->execute($params);

        return [
            'rows' => $statement->fetchAll(),
            'total' => $total,
            'pages' => $pages,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Daftar jenis aksi yang pernah tercatat (untuk filter).
     *
     * @return array<int, string>
     */
    public static function distinctActions(): array
    {
        $rows = Database::pdo()->query(
            'SELECT DISTINCT aksi FROM audit_log ORDER BY aksi ASC'
        )->fetchAll();

        return array_map(static fn (array $row): string => (string) $row['aksi'], $rows);
    }

    /**
     * Daftar pelaku yang pernah tercatat (untuk filter).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function actors(): array
    {
        $sql = 'SELECT DISTINCT u.id, u.nama, u.role
                FROM audit_log a
                INNER JOIN users u ON u.id = a.actor_id
                ORDER BY u.nama ASC';

        return Database::pdo()->query($sql)->fetchAll();
    }

    /**
     * Escape karakter khusus untuk pencarian LIKE.
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * Validasi format tanggal (Y-m-d).
     */
    private static function isValidDate(string $value): bool
    {
        if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year);
    }
}
