<?php
/**
 * Service notifikasi in-app WiFiCare CDN (Tahap 8).
 *
 * Memusatkan penetapan PENERIMA notifikasi untuk setiap peristiwa tiket agar
 * logika tidak diduplikasi di controller/service lain.
 *
 * PENTING: seluruh metode di sini HANYA melakukan INSERT (tanpa membuka
 * transaksi sendiri) sehingga dapat dipanggil DI DALAM transaksi perubahan
 * tiket. Dengan begitu status tiket dan notifikasi selalu konsisten.
 *
 * Tidak ada pengiriman WhatsApp/email otomatis — hanya notifikasi dalam
 * aplikasi yang tersimpan di MySQL.
 */

declare(strict_types=1);

final class NotificationService
{
    /** Tiket baru dibuat → CS + Admin (sesuai PRD). */
    public static function tiketBaru(int $pengaduanId, string $nomorTiket, string $pelangganNama): void
    {
        $judul = 'Tiket baru ' . $nomorTiket;
        $pesan = 'Pelanggan ' . $pelangganNama . ' membuat pengaduan baru yang menunggu verifikasi.';

        self::notifyMany(self::csUserIds(), $pengaduanId, $judul, $pesan);
        self::notifyMany(self::adminUserIds(), $pengaduanId, $judul, $pesan);
    }

    /** Tiket diverifikasi (diproses_cs) → Pelanggan. */
    public static function diverifikasi(int $pengaduanId, string $nomorTiket): void
    {
        $userId = self::pelangganUserId($pengaduanId);
        if ($userId === null) {
            return;
        }

        self::notify(
            $userId,
            $pengaduanId,
            'Tiket ' . $nomorTiket . ' diverifikasi',
            'Pengaduan Anda telah diverifikasi Customer Service dan sedang diproses.'
        );
    }

    /** Tiket ditolak → Pelanggan. */
    public static function ditolak(int $pengaduanId, string $nomorTiket, string $alasan): void
    {
        $userId = self::pelangganUserId($pengaduanId);
        if ($userId === null) {
            return;
        }

        self::notify(
            $userId,
            $pengaduanId,
            'Tiket ' . $nomorTiket . ' ditolak',
            'Pengaduan Anda ditolak. Alasan: ' . $alasan
        );
    }

    /** Penugasan teknisi → Teknisi + Pelanggan. */
    public static function ditugaskan(int $pengaduanId, string $nomorTiket, int $teknisiId, string $teknisiNama): void
    {
        self::notify(
            $teknisiId,
            $pengaduanId,
            'Tugas baru ' . $nomorTiket,
            'Anda ditugaskan untuk menangani tiket ' . $nomorTiket . '.'
        );

        $pelangganUserId = self::pelangganUserId($pengaduanId);
        if ($pelangganUserId !== null) {
            self::notify(
                $pelangganUserId,
                $pengaduanId,
                'Tiket ' . $nomorTiket . ' ditugaskan',
                'Teknisi ' . $teknisiNama . ' akan menangani pengaduan Anda.'
            );
        }
    }

    /** Mulai penanganan → CS + Pelanggan. */
    public static function mulaiPenanganan(int $pengaduanId, string $nomorTiket, string $teknisiNama): void
    {
        $judul = 'Penanganan dimulai ' . $nomorTiket;
        $pesan = 'Teknisi ' . $teknisiNama . ' mulai menangani tiket ' . $nomorTiket . '.';

        self::notifyMany(self::csUserIds(), $pengaduanId, $judul, $pesan);

        $pelangganUserId = self::pelangganUserId($pengaduanId);
        if ($pelangganUserId !== null) {
            self::notify($pelangganUserId, $pengaduanId, $judul, $pesan);
        }
    }

    /** Tiket selesai → CS + Pelanggan. */
    public static function selesai(int $pengaduanId, string $nomorTiket): void
    {
        $judul = 'Tiket ' . $nomorTiket . ' selesai';
        $pesan = 'Pengaduan ' . $nomorTiket . ' telah selesai ditangani. Silakan beri rating layanan kami.';

        self::notifyMany(self::csUserIds(), $pengaduanId, $judul, 'Tiket ' . $nomorTiket . ' telah selesai.');

        $pelangganUserId = self::pelangganUserId($pengaduanId);
        if ($pelangganUserId !== null) {
            self::notify($pelangganUserId, $pengaduanId, $judul, $pesan);
        }
    }

    /** Tiket dibatalkan → Pelanggan + Teknisi aktif + CS. */
    public static function dibatalkan(int $pengaduanId, string $nomorTiket, string $alasan): void
    {
        $judul = 'Tiket ' . $nomorTiket . ' dibatalkan';
        $pesan = 'Tiket ' . $nomorTiket . ' dibatalkan. Alasan: ' . $alasan;

        $pelangganUserId = self::pelangganUserId($pengaduanId);
        if ($pelangganUserId !== null) {
            self::notify($pelangganUserId, $pengaduanId, $judul, $pesan);
        }

        // Teknisi aktif (bila ada) difoto SEBELUM penugasan dinonaktifkan pemanggil.
        $teknisiId = self::activeTeknisiUserId($pengaduanId);
        if ($teknisiId !== null) {
            self::notify($teknisiId, $pengaduanId, $judul, $pesan);
        }

        self::notifyMany(self::csUserIds(), $pengaduanId, $judul, $pesan);
    }

    /* ---------------------------------------------------------------------
     * Pembantu internal
     * ------------------------------------------------------------------- */

    private static function notify(int $userId, int $pengaduanId, string $judul, string $pesan): void
    {
        if ($userId <= 0) {
            return;
        }

        Notifikasi::create($userId, $pengaduanId, $judul, $pesan);
    }

    /**
     * Kirim ke banyak penerima sekaligus (ID duplikat/nol diabaikan).
     *
     * @param array<int, int> $userIds
     */
    private static function notifyMany(array $userIds, int $pengaduanId, string $judul, string $pesan): void
    {
        foreach (array_unique($userIds) as $userId) {
            self::notify((int) $userId, $pengaduanId, $judul, $pesan);
        }
    }

    /**
     * ID user pemilik tiket (pelanggan).
     */
    private static function pelangganUserId(int $pengaduanId): ?int
    {
        $sql = 'SELECT p.user_id
                FROM pengaduan a
                INNER JOIN pelanggan p ON p.id = a.pelanggan_id
                WHERE a.id = :id
                LIMIT 1';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['id' => $pengaduanId]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    /**
     * ID seluruh user CS aktif.
     *
     * @return array<int, int>
     */
    private static function csUserIds(): array
    {
        return array_map(static fn (array $row): int => (int) $row['id'], User::activeByRole('cs'));
    }

    /**
     * ID seluruh user Admin aktif.
     *
     * @return array<int, int>
     */
    private static function adminUserIds(): array
    {
        return array_map(static fn (array $row): int => (int) $row['id'], User::activeByRole('admin'));
    }

    /**
     * ID user teknisi yang ditugaskan aktif pada tiket (bila ada).
     */
    private static function activeTeknisiUserId(int $pengaduanId): ?int
    {
        $sql = 'SELECT pt.teknisi_id
                FROM penugasan_teknisi pt
                WHERE pt.pengaduan_id = :id AND pt.is_active = 1
                ORDER BY pt.assigned_at DESC, pt.id DESC
                LIMIT 1';

        $statement = Database::pdo()->prepare($sql);
        $statement->execute(['id' => $pengaduanId]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (int) $value;
    }
}
