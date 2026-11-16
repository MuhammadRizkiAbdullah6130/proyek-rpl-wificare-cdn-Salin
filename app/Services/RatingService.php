<?php
/**
 * Service rating & ulasan pelanggan (Tahap 8).
 *
 * Kebijakan: SATU tiket maksimal satu rating (sesuai PRD). Rating tidak dapat
 * diubah setelah dikirim. Hanya pemilik tiket yang boleh menilai; identitas
 * pelanggan diambil dari session (bukan dari browser). Tiket harus berstatus
 * `selesai` dan tidak dibatalkan.
 */

declare(strict_types=1);

final class RatingService
{
    private const MAX_ULASAN = 1000;

    /**
     * Kirim rating untuk sebuah tiket.
     *
     * @param array<string, mixed> $actor Pengguna aktif (dari session).
     * @param array<string, mixed> $input Data formulir (rating, ulasan).
     */
    public static function submit(array $actor, int $pengaduanId, array $input): void
    {
        $pelangganId = (int) ($actor['pelanggan_id'] ?? 0);
        if ($pelangganId <= 0) {
            throw new ValidationException(['rating' => 'Akun Anda tidak terhubung dengan data pelanggan.']);
        }

        $rating = (int) ($input['rating'] ?? 0);
        $ulasan = trim((string) ($input['ulasan'] ?? ''));

        $errors = [];
        if ($rating < 1 || $rating > 5) {
            $errors['rating'] = 'Nilai rating harus antara 1 sampai 5.';
        }
        if (mb_strlen($ulasan) > self::MAX_ULASAN) {
            $errors['ulasan'] = 'Ulasan maksimal ' . self::MAX_ULASAN . ' karakter.';
        }
        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        $ulasanValue = $ulasan === '' ? null : $ulasan;

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $row = Pengaduan::lockRow($pengaduanId);
            if ($row === null) {
                throw new ValidationException(['pengaduan' => 'Pengaduan tidak ditemukan.']);
            }

            // Kepemilikan: pelanggan dari session, bukan dari browser.
            if ((int) $row['pelanggan_id'] !== $pelangganId) {
                throw new ValidationException(['pengaduan' => 'Tiket ini bukan milik Anda.']);
            }

            if ((int) $row['is_cancelled'] === 1) {
                throw new ValidationException(['pengaduan' => 'Tiket yang dibatalkan tidak dapat diberi rating.']);
            }

            if ((string) $row['status'] !== 'selesai') {
                throw new ValidationException(['pengaduan' => 'Rating hanya dapat diberikan untuk tiket yang sudah selesai.']);
            }

            if (Rating::existsForPengaduan($pengaduanId)) {
                throw new ValidationException(['rating' => 'Anda sudah memberikan rating untuk tiket ini.']);
            }

            Rating::create($pengaduanId, $pelangganId, $rating, $ulasanValue);

            AuditLog::record((int) ($actor['id'] ?? 0), 'rating_diberikan', 'pengaduan', $pengaduanId, [
                'nomor_tiket' => (string) ($row['nomor_tiket'] ?? ''),
                'rating' => $rating,
            ]);

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}
