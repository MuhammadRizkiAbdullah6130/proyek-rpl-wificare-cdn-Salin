<?php
/**
 * Service pengarsipan tiket (khusus Admin — Tahap 8).
 *
 * Pengarsipan BUKAN status ketujuh: hanya penanda `archived_at`/`archived_by`
 * pada baris pengaduan. Riwayat tiket tetap tersimpan dan dapat diakses Admin.
 */

declare(strict_types=1);

final class ArsipService
{
    /**
     * Arsipkan satu tiket. Hanya tiket yang tidak lagi aktif (selesai/ditolak)
     * atau yang sudah dibatalkan yang boleh diarsipkan.
     *
     * @param array<string, mixed> $actor
     */
    public static function archive(array $actor, int $pengaduanId): void
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $row = Pengaduan::lockRow($pengaduanId);
            if ($row === null) {
                throw new ValidationException(['pengaduan' => 'Pengaduan tidak ditemukan.']);
            }

            $existing = Pengaduan::findById($pengaduanId);
            if ($existing !== null && $existing['archived_at'] !== null) {
                throw new ValidationException(['pengaduan' => 'Tiket ini sudah diarsipkan.']);
            }

            $isCancelled = (int) $row['is_cancelled'] === 1;
            if (!$isCancelled && status_is_active((string) $row['status'])) {
                throw new ValidationException([
                    'pengaduan' => 'Tiket yang masih aktif belum dapat diarsipkan.',
                ]);
            }

            $archived = Pengaduan::markArchived($pengaduanId, (int) ($actor['id'] ?? 0));
            if (!$archived) {
                throw new ValidationException(['pengaduan' => 'Tiket ini sudah diarsipkan.']);
            }

            AuditLog::record((int) ($actor['id'] ?? 0), 'pengaduan_diarsipkan', 'pengaduan', $pengaduanId, [
                'nomor_tiket' => (string) ($row['nomor_tiket'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
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
