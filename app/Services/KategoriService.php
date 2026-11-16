<?php
/**
 * Service manajemen kategori gangguan oleh Admin (Tahap 8).
 *
 * Kategori tidak pernah dihapus secara destruktif (tiket lama mereferensikannya);
 * hanya dinonaktifkan (soft-disable). Kategori nonaktif tidak muncul pada
 * formulir pengaduan baru (lihat Kategori::findActive()).
 */

declare(strict_types=1);

final class KategoriService
{
    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input
     */
    public static function create(array $actor, array $input): int
    {
        $nama = trim((string) ($input['nama'] ?? ''));
        $deskripsi = self::normalizeDeskripsi($input['deskripsi'] ?? '');

        $errors = self::validate($nama, $deskripsi);
        if (Kategori::nameExists($nama)) {
            $errors['nama'] = 'Nama kategori sudah ada.';
        }
        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $id = Kategori::create($nama, $deskripsi);
            AuditLog::record((int) ($actor['id'] ?? 0), 'kategori_dibuat', 'kategori_gangguan', $id, [
                'nama' => $nama,
            ]);
            $pdo->commit();

            return $id;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input
     */
    public static function update(array $actor, int $id, array $input): void
    {
        $existing = Kategori::find($id);
        if ($existing === null) {
            throw new ValidationException(['kategori' => 'Kategori tidak ditemukan.']);
        }

        $nama = trim((string) ($input['nama'] ?? ''));
        $deskripsi = self::normalizeDeskripsi($input['deskripsi'] ?? '');

        $errors = self::validate($nama, $deskripsi);
        if (Kategori::nameExists($nama, $id)) {
            $errors['nama'] = 'Nama kategori sudah ada.';
        }
        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            Kategori::update($id, $nama, $deskripsi);
            AuditLog::record((int) ($actor['id'] ?? 0), 'kategori_diperbarui', 'kategori_gangguan', $id, [
                'nama' => $nama,
                'nama_lama' => (string) $existing['nama'],
            ]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Aktifkan / nonaktifkan kategori (soft-disable).
     *
     * @param array<string, mixed> $actor
     */
    public static function setActive(array $actor, int $id, int $isActive): void
    {
        $existing = Kategori::find($id);
        if ($existing === null) {
            throw new ValidationException(['kategori' => 'Kategori tidak ditemukan.']);
        }

        $isActive = $isActive === 1 ? 1 : 0;

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            Kategori::setActive($id, $isActive);
            AuditLog::record(
                (int) ($actor['id'] ?? 0),
                $isActive === 1 ? 'kategori_diaktifkan' : 'kategori_dinonaktifkan',
                'kategori_gangguan',
                $id,
                ['nama' => (string) $existing['nama']]
            );
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @return array<string, string>
     */
    private static function validate(string $nama, ?string $deskripsi): array
    {
        $errors = [];

        if ($nama === '') {
            $errors['nama'] = 'Nama kategori wajib diisi.';
        } elseif (mb_strlen($nama) > 120) {
            $errors['nama'] = 'Nama kategori maksimal 120 karakter.';
        }

        if ($deskripsi !== null && mb_strlen($deskripsi) > 255) {
            $errors['deskripsi'] = 'Deskripsi maksimal 255 karakter.';
        }

        return $errors;
    }

    private static function normalizeDeskripsi(mixed $value): ?string
    {
        $deskripsi = trim((string) $value);

        return $deskripsi === '' ? null : $deskripsi;
    }
}
