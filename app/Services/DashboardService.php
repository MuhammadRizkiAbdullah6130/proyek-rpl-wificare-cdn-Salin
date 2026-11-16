<?php
/**
 * Service dashboard WiFiCare CDN.
 *
 * Menyiapkan seluruh data statistik per peran. Controller hanya memanggil
 * metode di sini lalu meneruskan hasilnya ke view; view tidak menulis SQL.
 *
 * Catatan isolasi hak akses:
 * - Teknisi  : selalu difilter berdasarkan ID teknisi dari session.
 * - Pelanggan: selalu difilter berdasarkan ID pelanggan dari session.
 *
 * Catatan penamaan kunci: jumlah tiket aktif memakai kunci `active_count`
 * (bukan `active`) karena `active` dipakai layout untuk menandai menu sidebar
 * yang sedang terbuka; array_merge pada renderRole akan saling menimpa.
 */

declare(strict_types=1);

final class DashboardService
{
    private const TREND_DAYS = 14;

    /**
     * Ringkasan Dashboard Admin.
     *
     * @return array<string, mixed>
     */
    public static function admin(): array
    {
        $distribution = Pengaduan::statusDistribution();

        return [
            'total' => array_sum($distribution),
            'active_count' => status_sum_active($distribution),
            'selesai' => $distribution['selesai'] ?? 0,
            'ditolak' => $distribution['ditolak'] ?? 0,
            'distribution' => $distribution,
            'trend' => Pengaduan::dailyTrend(self::TREND_DAYS),
            'latest' => Pengaduan::latest(['limit' => 6]),
            'teknisi_performance' => Penugasan::performanceSummary(5),
            'activity' => AuditLog::recent(6),
            'rating' => Rating::summary(),
            'archived_count' => Pengaduan::countArchived(),
            'cancelled_count' => Pengaduan::countCancelled(),
        ];
    }

    /**
     * Ringkasan Dashboard Customer Service.
     *
     * @return array<string, mixed>
     */
    public static function customerService(): array
    {
        $distribution = Pengaduan::statusDistribution();

        return [
            'menunggu_verifikasi' => $distribution['menunggu_verifikasi'] ?? 0,
            'diproses_cs' => $distribution['diproses_cs'] ?? 0,
            'menunggu_teknisi' => $distribution['menunggu_teknisi'] ?? 0,
            'selesai' => $distribution['selesai'] ?? 0,
            'total' => array_sum($distribution),
            'active_count' => status_sum_active($distribution),
            'distribution' => $distribution,
            'trend' => Pengaduan::dailyTrend(self::TREND_DAYS),
            'antrian' => Pengaduan::latest(['limit' => 5, 'status' => ['menunggu_verifikasi']]),
            'latest' => Pengaduan::latest(['limit' => 6]),
            'teknisi_performance' => Penugasan::performanceSummary(4),
        ];
    }

    /**
     * Ringkasan Dashboard Teknisi (hanya tugas milik teknisi terkait).
     *
     * @return array<string, mixed>
     */
    public static function teknisi(int $teknisiId): array
    {
        return [
            'teknisi_id' => $teknisiId,
            'total' => Penugasan::countDistinctForTeknisi($teknisiId),
            'menunggu_teknisi' => Penugasan::countForTeknisiByStatus($teknisiId, ['menunggu_teknisi']),
            'dalam_penanganan' => Penugasan::countForTeknisiByStatus($teknisiId, ['dalam_penanganan']),
            'selesai' => Penugasan::countForTeknisiByStatus($teknisiId, ['selesai']),
            'completed_today' => LaporanTeknisi::countCompletedToday($teknisiId),
            'distribution' => Penugasan::statusDistributionForTeknisi($teknisiId),
            'latest' => Penugasan::latestForTeknisi($teknisiId, 6),
            'kategori' => Penugasan::categoryBreakdownForTeknisi($teknisiId),
            'reports' => LaporanTeknisi::recentForTeknisi($teknisiId, 5),
        ];
    }

    /**
     * Ringkasan Dashboard Pelanggan (hanya tiket milik pelanggan terkait).
     *
     * @return array<string, mixed>
     */
    public static function pelanggan(int $pelangganId): array
    {
        $distribution = Pengaduan::statusDistribution(true, $pelangganId);
        $latest = Pengaduan::latest(['limit' => 6, 'pelanggan_id' => $pelangganId]);

        return [
            'pelanggan_id' => $pelangganId,
            'total' => array_sum($distribution),
            'active_count' => status_sum_active($distribution),
            'selesai' => $distribution['selesai'] ?? 0,
            'ditolak' => $distribution['ditolak'] ?? 0,
            'distribution' => $distribution,
            'trend' => Pengaduan::dailyTrend(self::TREND_DAYS, true, $pelangganId),
            'latest' => $latest,
            'latest_ticket' => $latest[0] ?? null,
        ];
    }
}
