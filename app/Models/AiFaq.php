<?php
/**
 * Model FAQ AI (`ai_faq`).
 *
 * FAQ dikelola khusus Admin. Isi FAQ adalah DATA referensi untuk prompt, bukan
 * instruksi yang dapat mengubah aturan keamanan/RBAC.
 */

declare(strict_types=1);

final class AiFaq
{
    /** @return array<int, array<string, mixed>> */
    public static function all(string $search = '', string $category = ''): array
    {
        $where = [];
        $params = [];

        if (trim($search) !== '') {
            $where[] = '(pertanyaan LIKE :q1 OR jawaban LIKE :q2 OR kategori LIKE :q3)';
            $like = '%' . trim($search) . '%';
            $params['q1'] = $like;
            $params['q2'] = $like;
            $params['q3'] = $like;
        }
        if (trim($category) !== '') {
            $where[] = 'kategori = :kategori';
            $params['kategori'] = trim($category);
        }

        $sql = 'SELECT * FROM ai_faq';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY is_active DESC, urutan ASC, id ASC';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public static function active(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $statement = Database::pdo()->prepare(
            'SELECT id, pertanyaan, jawaban, kategori, urutan
             FROM ai_faq
             WHERE is_active = 1
             ORDER BY urutan ASC, id ASC
             LIMIT ' . $limit
        );
        $statement->execute();

        return $statement->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $statement = Database::pdo()->prepare('SELECT * FROM ai_faq WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<int, string> */
    public static function categories(): array
    {
        try {
            $rows = Database::pdo()->query(
                'SELECT DISTINCT kategori FROM ai_faq WHERE kategori <> \'\' ORDER BY kategori ASC'
            )->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $exception) {
            return [];
        }

        return array_values(array_map('strval', $rows));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<int, string>
     */
    public static function validate(array $input): array
    {
        $errors = [];
        $pertanyaan = trim((string) ($input['pertanyaan'] ?? ''));
        $jawaban = trim((string) ($input['jawaban'] ?? ''));
        $kategori = trim((string) ($input['kategori'] ?? 'Umum'));

        if ($pertanyaan === '') {
            $errors['pertanyaan'] = 'Pertanyaan wajib diisi.';
        } elseif (mb_strlen($pertanyaan) > 500) {
            $errors['pertanyaan'] = 'Pertanyaan maksimal 500 karakter.';
        }

        if ($jawaban === '') {
            $errors['jawaban'] = 'Jawaban wajib diisi.';
        } elseif (mb_strlen($jawaban) > 5000) {
            $errors['jawaban'] = 'Jawaban maksimal 5000 karakter.';
        }

        if (mb_strlen($kategori) > 100) {
            $errors['kategori'] = 'Kategori maksimal 100 karakter.';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function save(array $input, ?int $actorId): int
    {
        $id = (int) ($input['id'] ?? 0);
        $pertanyaan = trim((string) ($input['pertanyaan'] ?? ''));
        $jawaban = trim((string) ($input['jawaban'] ?? ''));
        $kategori = trim((string) ($input['kategori'] ?? ''));
        if ($kategori === '') {
            $kategori = 'Umum';
        }
        $isActive = !empty($input['is_active']) ? 1 : 0;
        $urutan = (int) ($input['urutan'] ?? 0);

        if ($id > 0) {
            $statement = Database::pdo()->prepare(
                'UPDATE ai_faq
                 SET pertanyaan = :pertanyaan, jawaban = :jawaban, kategori = :kategori,
                     is_active = :is_active, urutan = :urutan, updated_by = :updated_by
                 WHERE id = :id'
            );
            $statement->execute([
                'pertanyaan' => $pertanyaan,
                'jawaban' => $jawaban,
                'kategori' => $kategori,
                'is_active' => $isActive,
                'urutan' => $urutan,
                'updated_by' => $actorId,
                'id' => $id,
            ]);

            return $id;
        }

        $statement = Database::pdo()->prepare(
            'INSERT INTO ai_faq (pertanyaan, jawaban, kategori, is_active, urutan, updated_by)
             VALUES (:pertanyaan, :jawaban, :kategori, :is_active, :urutan, :updated_by)'
        );
        $statement->execute([
            'pertanyaan' => $pertanyaan,
            'jawaban' => $jawaban,
            'kategori' => $kategori,
            'is_active' => $isActive,
            'urutan' => $urutan,
            'updated_by' => $actorId,
        ]);

        return (int) Database::pdo()->lastInsertId();
    }

    public static function setActive(int $id, bool $active, ?int $actorId): void
    {
        $statement = Database::pdo()->prepare(
            'UPDATE ai_faq SET is_active = :is_active, updated_by = :updated_by WHERE id = :id'
        );
        $statement->execute([
            'is_active' => $active ? 1 : 0,
            'updated_by' => $actorId,
            'id' => $id,
        ]);
    }

    public static function delete(int $id): void
    {
        $statement = Database::pdo()->prepare('DELETE FROM ai_faq WHERE id = :id');
        $statement->execute(['id' => $id]);
    }
}
