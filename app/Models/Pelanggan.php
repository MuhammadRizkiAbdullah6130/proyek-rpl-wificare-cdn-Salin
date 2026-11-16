<?php
/**
 * Model pelanggan.
 *
 * Menyimpan data pelanggan beserta Username PPPoE. Password jaringan PPPoE
 * TIDAK pernah disimpan di sini.
 */

declare(strict_types=1);

final class Pelanggan
{
    /**
     * Buat data pelanggan untuk sebuah user.
     *
     * @param array{user_id: int, username_pppoe: string, nomor_whatsapp: string, alamat?: string|null} $data
     */
    public static function create(array $data): int
    {
        $sql = 'INSERT INTO pelanggan (user_id, username_pppoe, nomor_whatsapp, alamat)
                VALUES (:user_id, :username_pppoe, :nomor_whatsapp, :alamat)';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute([
            'user_id' => $data['user_id'],
            'username_pppoe' => $data['username_pppoe'],
            'nomor_whatsapp' => $data['nomor_whatsapp'],
            'alamat' => $data['alamat'] ?? null,
        ]);

        return (int) Database::pdo()->lastInsertId();
    }

    /**
     * Cari pelanggan berdasarkan ID user.
     *
     * @return array<string, mixed>|null
     */
    public static function findByUserId(int $userId): ?array
    {
        $statement = Database::pdo()->prepare('SELECT * FROM pelanggan WHERE user_id = :user_id LIMIT 1');
        $statement->execute(['user_id' => $userId]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Apakah username PPPoE sudah dipakai.
     */
    public static function pppoeExists(string $usernamePppoe): bool
    {
        $statement = Database::pdo()->prepare('SELECT 1 FROM pelanggan WHERE username_pppoe = :pppoe LIMIT 1');
        $statement->execute(['pppoe' => $usernamePppoe]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Perbarui nomor WhatsApp utama pelanggan.
     *
     * Method ini TIDAK boleh dipanggil dari alur pembuatan pengaduan atau
     * formulir profil biasa. Perubahan nomor oleh pelanggan memakai alur
     * terverifikasi (password + OTP) melalui replaceWhatsappVerified().
     * ID pelanggan harus berasal dari session/data server, bukan input browser.
     */
    public static function updateWhatsapp(int $pelangganId, string $whatsapp): void
    {
        $statement = Database::pdo()->prepare(
            'UPDATE pelanggan SET
                nomor_whatsapp = :whatsapp,
                nomor_whatsapp_verified_at = NULL,
                nomor_whatsapp_verified_fingerprint = NULL
             WHERE id = :id'
        );
        $statement->execute(['whatsapp' => $whatsapp, 'id' => $pelangganId]);
    }

    /**
     * Ganti nomor WhatsApp utama dan langsung tandai terverifikasi.
     *
     * Dipakai HANYA oleh alur ganti nomor setelah OTP nomor baru terbukti.
     * Syarat `nomor_whatsapp = :old_phone` membuat perubahan bersifat
     * optimistis: bila nomor berubah di tengah proses, update gagal dan
     * transaksi OTP dibatalkan.
     */
    public static function replaceWhatsappVerified(
        int $pelangganId,
        string $oldPhone,
        string $newPhone,
        string $fingerprint
    ): bool {
        if ($pelangganId <= 0 || $oldPhone === '' || $newPhone === '' || $fingerprint === '') {
            return false;
        }

        $statement = Database::pdo()->prepare(
            'UPDATE pelanggan SET
                nomor_whatsapp = :new_phone,
                nomor_whatsapp_verified_at = CURRENT_TIMESTAMP,
                nomor_whatsapp_verified_fingerprint = :fingerprint
             WHERE id = :id AND nomor_whatsapp = :old_phone'
        );
        $statement->execute([
            'new_phone' => $newPhone,
            'fingerprint' => $fingerprint,
            'id' => $pelangganId,
            'old_phone' => $oldPhone,
        ]);

        return $statement->rowCount() === 1;
    }

    /**
     * Tandai nomor WhatsApp utama sebagai terverifikasi.
     *
     * Update hanya berhasil bila nomor pada baris masih sama dengan nomor
     * yang diverifikasi. Ini mencegah status terpasang ke nomor yang berubah
     * di tengah proses OTP.
     */
    public static function markWhatsappVerified(
        int $pelangganId,
        string $currentPhone,
        string $fingerprint
    ): bool {
        if ($pelangganId <= 0 || $currentPhone === '' || $fingerprint === '') {
            return false;
        }

        $statement = Database::pdo()->prepare(
            'UPDATE pelanggan SET
                nomor_whatsapp_verified_at = CURRENT_TIMESTAMP,
                nomor_whatsapp_verified_fingerprint = :fingerprint
             WHERE id = :id AND nomor_whatsapp = :current_phone'
        );
        $statement->execute([
            'fingerprint' => $fingerprint,
            'id' => $pelangganId,
            'current_phone' => $currentPhone,
        ]);

        return $statement->rowCount() === 1;
    }

    /* =====================================================================
     * Query untuk modul Customer Service (Tahap 6)
     * =================================================================== */

    /**
     * Daftar pelanggan beserta ringkasan tiket (untuk CS).
     *
     * @param array{q?: string} $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int, pages: int, page: int, per_page: int}
     */
    public static function paginateForCs(array $filters, int $page, int $perPage = 10): array
    {
        $where = ['1 = 1'];
        $params = [];

        $keyword = trim((string) ($filters['q'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . self::escapeLike($keyword) . '%';
            $where[] = '(u.nama LIKE :q1 OR p.username_pppoe LIKE :q2)';
            $params['q1'] = $like;
            $params['q2'] = $like;
        }

        $whereSql = implode(' AND ', $where);

        $countStatement = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM pelanggan p INNER JOIN users u ON u.id = p.user_id WHERE ' . $whereSql
        );
        $countStatement->execute($params);
        $total = (int) $countStatement->fetchColumn();

        $perPage = max(1, min(50, $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        $activeIn = implode(', ', array_map(static fn (string $s): string => "'" . $s . "'", active_statuses()));

        // $activeIn berasal dari daftar status internal; $perPage/$offset integer tervalidasi.
        $sql = 'SELECT p.id, u.nama, p.username_pppoe, p.nomor_whatsapp,
                       COUNT(a.id) AS total_tiket,
                       COALESCE(SUM(CASE WHEN a.is_cancelled = 0 AND a.status IN (' . $activeIn . ') THEN 1 ELSE 0 END), 0) AS tiket_aktif,
                       (SELECT a2.status FROM pengaduan a2
                         WHERE a2.pelanggan_id = p.id AND a2.is_cancelled = 0
                         ORDER BY a2.created_at DESC, a2.id DESC LIMIT 1) AS status_terakhir,
                       MAX(a.created_at) AS terakhir
                FROM pelanggan p
                INNER JOIN users u ON u.id = p.user_id
                LEFT JOIN pengaduan a ON a.pelanggan_id = p.id
                WHERE ' . $whereSql . '
                GROUP BY p.id, u.nama, p.username_pppoe, p.nomor_whatsapp
                ORDER BY terakhir DESC, u.nama ASC
                LIMIT ' . $perPage . ' OFFSET ' . $offset;

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
     * Data identitas pelanggan untuk halaman riwayat CS.
     *
     * @return array<string, mixed>|null
     */
    public static function findForCs(int $pelangganId): ?array
    {
        $sql = 'SELECT p.id, u.nama, p.username_pppoe, p.nomor_whatsapp, p.alamat
                FROM pelanggan p
                INNER JOIN users u ON u.id = p.user_id
                WHERE p.id = :id
                LIMIT 1';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['id' => $pelangganId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Riwayat tiket seorang pelanggan (untuk CS).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function ticketsForCs(int $pelangganId): array
    {
        $sql = 'SELECT a.id, a.nomor_tiket, a.status, a.is_cancelled, a.created_at,
                       k.nama AS kategori, t.nama AS teknisi_nama,
                       COALESCE(l.completed_at, c.completed_at) AS completed_at,
                       l.tindakan_perbaikan AS hasil_teknisi, c.solusi AS hasil_cs
                FROM pengaduan a
                LEFT JOIN kategori_gangguan k ON k.id = a.kategori_id
                LEFT JOIN penugasan_teknisi pt ON pt.pengaduan_id = a.id AND pt.is_active = 1
                LEFT JOIN users t ON t.id = pt.teknisi_id
                LEFT JOIN laporan_teknisi l ON l.pengaduan_id = a.id
                LEFT JOIN penyelesaian_cs c ON c.pengaduan_id = a.id
                WHERE a.pelanggan_id = :pelanggan_id
                ORDER BY a.created_at DESC, a.id DESC';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['pelanggan_id' => $pelangganId]);

        return $statement->fetchAll();
    }

    /* =====================================================================
     * Query & mutasi manajemen pelanggan (Admin — Tahap 8)
     * =================================================================== */

    /**
     * Daftar pelanggan dengan filter & pagination (Admin).
     *
     * @param array{q?: string, status?: string} $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int, pages: int, page: int, per_page: int}
     */
    public static function paginateForAdmin(array $filters, int $page, int $perPage = 10): array
    {
        $where = ['1 = 1'];
        $params = [];

        $keyword = trim((string) ($filters['q'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . self::escapeLike($keyword) . '%';
            $where[] = '(u.nama LIKE :q1 OR p.username_pppoe LIKE :q2 OR p.nomor_whatsapp LIKE :q3)';
            $params['q1'] = $like;
            $params['q2'] = $like;
            $params['q3'] = $like;
        }

        $status = (string) ($filters['status'] ?? '');
        if ($status === 'aktif') {
            $where[] = 'u.is_active = 1';
        } elseif ($status === 'nonaktif') {
            $where[] = 'u.is_active = 0';
        }

        $whereSql = implode(' AND ', $where);

        $countStatement = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM pelanggan p INNER JOIN users u ON u.id = p.user_id WHERE ' . $whereSql
        );
        $countStatement->execute($params);
        $total = (int) $countStatement->fetchColumn();

        $perPage = max(1, min(50, $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        // $perPage dan $offset adalah integer tervalidasi, aman di-inline.
        $sql = 'SELECT p.id, p.user_id, p.username_pppoe, p.nomor_whatsapp, p.alamat,
                       u.nama, u.username, u.is_active, u.last_login_at,
                       (SELECT COUNT(*) FROM pengaduan a WHERE a.pelanggan_id = p.id) AS total_tiket
                FROM pelanggan p
                INNER JOIN users u ON u.id = p.user_id
                WHERE ' . $whereSql . '
                ORDER BY u.nama ASC
                LIMIT ' . $perPage . ' OFFSET ' . $offset;

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
     * Detail pelanggan untuk Admin.
     *
     * @return array<string, mixed>|null
     */
    public static function findForAdmin(int $pelangganId): ?array
    {
        $sql = 'SELECT p.id, p.user_id, p.username_pppoe, p.nomor_whatsapp, p.alamat,
                       u.nama, u.username, u.is_active, u.last_login_at
                FROM pelanggan p
                INNER JOIN users u ON u.id = p.user_id
                WHERE p.id = :id
                LIMIT 1';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['id' => $pelangganId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Apakah Username PPPoE sudah dipakai pelanggan lain.
     */
    public static function pppoeExistsExcept(string $usernamePppoe, int $exceptPelangganId): bool
    {
        $statement = Database::pdo()->prepare(
            'SELECT 1 FROM pelanggan WHERE username_pppoe = :pppoe AND id <> :id LIMIT 1'
        );
        $statement->execute(['pppoe' => $usernamePppoe, 'id' => $exceptPelangganId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Perbarui data pelanggan (Username PPPoE, WhatsApp, alamat).
     */
    public static function update(int $pelangganId, string $usernamePppoe, string $whatsapp, ?string $alamat): void
    {
        $statement = Database::pdo()->prepare(
            'UPDATE pelanggan SET
                nomor_whatsapp_verified_at = IF(nomor_whatsapp = :compare_phone1, nomor_whatsapp_verified_at, NULL),
                nomor_whatsapp_verified_fingerprint = IF(nomor_whatsapp = :compare_phone2, nomor_whatsapp_verified_fingerprint, NULL),
                username_pppoe = :pppoe,
                nomor_whatsapp = :whatsapp,
                alamat = :alamat
             WHERE id = :id'
        );
        $statement->execute([
            'compare_phone1' => $whatsapp,
            'compare_phone2' => $whatsapp,
            'pppoe' => $usernamePppoe,
            'whatsapp' => $whatsapp,
            'alamat' => $alamat,
            'id' => $pelangganId,
        ]);
    }

    /**
     * Escape karakter khusus untuk pencarian LIKE.
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
