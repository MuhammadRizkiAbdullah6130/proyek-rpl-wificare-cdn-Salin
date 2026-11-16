<?php
/**
 * Service alur pengaduan Customer Service WiFiCare CDN.
 *
 * Satu-satunya tempat yang memvalidasi & mengeksekusi transisi status resmi
 * (Tahap 6) agar logika tidak tersebar di controller:
 *
 *   menunggu_verifikasi → diproses_cs     (diterima CS)
 *   menunggu_verifikasi → ditolak         (ditolak CS, wajib alasan)
 *   diproses_cs         → selesai         (diselesaikan langsung oleh CS)
 *   diproses_cs         → menunggu_teknisi (ditugaskan ke teknisi)
 *
 * Transisi teknisi (Tahap 7) memakai service yang sama agar logika status tidak
 * diduplikasi:
 *
 *   menunggu_teknisi    → dalam_penanganan (teknisi mulai bekerja)
 *   dalam_penanganan    → selesai          (laporan perbaikan lengkap dikirim)
 *
 * Peta transisi dipisah per pelaku (CS vs Teknisi) sehingga CS tidak dapat
 * memakai jalur transisi milik teknisi, dan sebaliknya.
 *
 * Jaminan integritas:
 * - Setiap aksi berjalan dalam transaksi + SELECT ... FOR UPDATE pada baris
 *   pengaduan, sehingga klik ganda / permintaan bersamaan tidak membuat
 *   perubahan status ganda.
 * - Identitas petugas selalu berasal dari session (parameter $actor),
 *   tidak pernah dari input browser.
 * - Setiap perubahan status dicatat pada riwayat_status dan audit_log.
 */

declare(strict_types=1);

final class PengaduanFlowService
{
    /** Transisi status yang diizinkan untuk Customer Service (Tahap 6). */
    private const CS_ALLOWED = [
        'menunggu_verifikasi' => ['diproses_cs', 'ditolak'],
        'diproses_cs' => ['menunggu_teknisi', 'selesai'],
    ];

    /** Transisi status yang diizinkan untuk Teknisi (Tahap 7). */
    private const TEKNISI_ALLOWED = [
        'menunggu_teknisi' => ['dalam_penanganan'],
        'dalam_penanganan' => ['selesai'],
    ];

    /** Batas panjang alasan/catatan teks. */
    private const MAX_TEXT = 2000;

    /**
     * Status tujuan yang sah dari sebuah status untuk pelaku CS.
     *
     * @return array<int, string>
     */
    public static function csAllowedTargets(?string $from): array
    {
        return self::CS_ALLOWED[(string) $from] ?? [];
    }

    /**
     * Status tujuan yang sah dari sebuah status untuk pelaku Teknisi.
     *
     * @return array<int, string>
     */
    public static function teknisiAllowedTargets(?string $from): array
    {
        return self::TEKNISI_ALLOWED[(string) $from] ?? [];
    }

    /**
     * Pastikan transisi status sah menurut peta pelaku, atau lempar error.
     *
     * @param array<int, string> $targets
     */
    private static function assertIn(array $targets, string $from, string $to): void
    {
        if (!in_array($to, $targets, true)) {
            throw new ValidationException([
                'status' => 'Transisi status tidak diizinkan: '
                    . status_label($from) . ' → ' . status_label($to) . '.',
            ]);
        }
    }

    /**
     * Terima pengaduan: menunggu_verifikasi → diproses_cs.
     *
     * @param array<string, mixed> $actor Pengguna aktif (dari session).
     */
    public static function terima(array $actor, int $pengaduanId): void
    {
        self::withLock($pengaduanId, function (array $row) use ($actor): void {
            self::assertIn(self::CS_ALLOWED[(string) $row['status']] ?? [], (string) $row['status'], 'diproses_cs');

            Pengaduan::setStatus((int) $row['id'], 'diproses_cs');
            Pengaduan::insertRiwayat(
                (int) $row['id'],
                (string) $row['status'],
                'diproses_cs',
                (int) $actor['id'],
                'Pengaduan diterima dan diverifikasi CS.'
            );
            self::audit($actor, 'pengaduan_diterima', (int) $row['id'], $row, 'diproses_cs');
            NotificationService::diverifikasi((int) $row['id'], (string) $row['nomor_tiket']);
            WhatsAppNotificationService::ticketEvent('tiket_diverifikasi', (int) $row['id'], [
                'nomor_tiket' => (string) $row['nomor_tiket'],
                'nama_pelanggan' => (string) $row['nama_pelanggan_snapshot'],
            ], 'status:diproses_cs');
        });
    }

    /**
     * Tolak pengaduan: menunggu_verifikasi → ditolak (alasan wajib).
     *
     * @param array<string, mixed> $actor
     */
    public static function tolak(array $actor, int $pengaduanId, string $reason): void
    {
        $reason = self::validText($reason, 'alasan', 'Alasan penolakan wajib diisi.');

        self::withLock($pengaduanId, function (array $row) use ($actor, $reason): void {
            self::assertIn(self::CS_ALLOWED[(string) $row['status']] ?? [], (string) $row['status'], 'ditolak');

            Pengaduan::setRejected((int) $row['id'], $reason);
            Pengaduan::insertRiwayat(
                (int) $row['id'],
                (string) $row['status'],
                'ditolak',
                (int) $actor['id'],
                'Pengaduan ditolak CS: ' . $reason
            );
            self::audit($actor, 'pengaduan_ditolak', (int) $row['id'], $row, 'ditolak', ['alasan' => $reason]);
            NotificationService::ditolak((int) $row['id'], (string) $row['nomor_tiket'], $reason);
            WhatsAppNotificationService::ticketEvent('tiket_ditolak', (int) $row['id'], [
                'nomor_tiket' => (string) $row['nomor_tiket'],
                'nama_pelanggan' => (string) $row['nama_pelanggan_snapshot'],
                'alasan' => $reason,
            ], 'status:ditolak');
        });
    }

    /**
     * Selesaikan langsung oleh CS: diproses_cs → selesai (solusi wajib).
     *
     * @param array<string, mixed> $actor
     */
    public static function selesaikan(array $actor, int $pengaduanId, string $solution): void
    {
        $solution = self::validText($solution, 'solusi', 'Solusi atau tindakan penyelesaian wajib diisi.');

        self::withLock($pengaduanId, function (array $row) use ($actor, $solution): void {
            self::assertIn(self::CS_ALLOWED[(string) $row['status']] ?? [], (string) $row['status'], 'selesai');

            PenyelesaianCs::create((int) $row['id'], (int) $actor['id'], $solution);
            Pengaduan::setStatus((int) $row['id'], 'selesai');
            Pengaduan::insertRiwayat(
                (int) $row['id'],
                (string) $row['status'],
                'selesai',
                (int) $actor['id'],
                'Diselesaikan langsung oleh CS.'
            );
            self::audit($actor, 'pengaduan_diselesaikan_cs', (int) $row['id'], $row, 'selesai', ['solusi' => $solution]);
            NotificationService::selesai((int) $row['id'], (string) $row['nomor_tiket']);
            WhatsAppNotificationService::ticketEvent('tiket_selesai', (int) $row['id'], [
                'nomor_tiket' => (string) $row['nomor_tiket'],
                'nama_pelanggan' => (string) $row['nama_pelanggan_snapshot'],
            ], 'status:selesai:cs');
        });
    }

    /**
     * Tugaskan teknisi: diproses_cs → menunggu_teknisi.
     *
     * @param array<string, mixed> $actor
     */
    public static function tugaskan(array $actor, int $pengaduanId, int $teknisiId, ?string $note): void
    {
        $teknisi = User::findById($teknisiId);
        if ($teknisi === null || (string) $teknisi['role'] !== 'teknisi') {
            throw new ValidationException(['teknisi_id' => 'Teknisi yang dipilih tidak valid.']);
        }
        if ((int) $teknisi['is_active'] !== 1) {
            throw new ValidationException(['teknisi_id' => 'Akun teknisi tidak aktif dan tidak dapat ditugaskan.']);
        }

        $note = $note === null ? null : trim($note);
        if ($note === '') {
            $note = null;
        } elseif (mb_strlen($note) > self::MAX_TEXT) {
            throw new ValidationException(['catatan' => 'Instruksi penugasan maksimal ' . self::MAX_TEXT . ' karakter.']);
        }

        self::withLock($pengaduanId, function (array $row) use ($actor, $teknisi, $teknisiId, $note): void {
            self::assertIn(self::CS_ALLOWED[(string) $row['status']] ?? [], (string) $row['status'], 'menunggu_teknisi');

            // Satu tiket hanya satu penugasan aktif; histori lama tetap disimpan.
            Penugasan::deactivateForPengaduan((int) $row['id']);
            $penugasanId = Penugasan::create((int) $row['id'], $teknisiId, (int) $actor['id'], $note);

            Pengaduan::setStatus((int) $row['id'], 'menunggu_teknisi');
            Pengaduan::insertRiwayat(
                (int) $row['id'],
                (string) $row['status'],
                'menunggu_teknisi',
                (int) $actor['id'],
                'Ditugaskan kepada teknisi ' . (string) $teknisi['nama'] . '.'
            );
            self::audit($actor, 'pengaduan_ditugaskan', (int) $row['id'], $row, 'menunggu_teknisi', [
                'teknisi_id' => $teknisiId,
                'teknisi_nama' => (string) $teknisi['nama'],
                'instruksi' => $note,
            ]);
            NotificationService::ditugaskan(
                (int) $row['id'],
                (string) $row['nomor_tiket'],
                $teknisiId,
                (string) $teknisi['nama']
            );
            WhatsAppNotificationService::ticketEvent('penugasan_teknisi', (int) $row['id'], [
                'nomor_tiket' => (string) $row['nomor_tiket'],
                'nama_pelanggan' => (string) $row['nama_pelanggan_snapshot'],
                'teknisi' => (string) $teknisi['nama'],
            ], 'penugasan:' . $penugasanId);
        });
    }

    /**
     * Batalkan pengaduan (atribut terpisah; status utama tidak berubah).
     *
     * Hanya tiket yang masih aktif yang boleh dibatalkan.
     *
     * @param array<string, mixed> $actor
     */
    public static function batalkan(array $actor, int $pengaduanId, string $reason): void
    {
        $reason = self::validText($reason, 'alasan', 'Alasan pembatalan wajib diisi.');

        self::withLock($pengaduanId, function (array $row) use ($actor, $reason): void {
            if (!status_is_active((string) $row['status'])) {
                throw new ValidationException([
                    'status' => 'Tiket yang sudah selesai atau ditolak tidak dapat dibatalkan.',
                ]);
            }

            Pengaduan::markCancelled((int) $row['id'], (int) $actor['id'], $reason);
            // Notifikasi pihak terkait difoto sebelum penugasan dinonaktifkan.
            NotificationService::dibatalkan((int) $row['id'], (string) $row['nomor_tiket'], $reason);
            WhatsAppNotificationService::ticketEvent('tiket_dibatalkan', (int) $row['id'], [
                'nomor_tiket' => (string) $row['nomor_tiket'],
                'nama_pelanggan' => (string) $row['nama_pelanggan_snapshot'],
                'alasan' => $reason,
            ], 'dibatalkan');
            // Tiket dibatalkan tidak boleh terus dikerjakan teknisi.
            Penugasan::deactivateForPengaduan((int) $row['id']);

            // Pembatalan BUKAN status ketujuh: tidak menambah entri riwayat status.
            self::audit($actor, 'pengaduan_dibatalkan', (int) $row['id'], $row, (string) $row['status'], [
                'alasan' => $reason,
            ]);
        });
    }

    /**
     * Tambah catatan internal CS (tidak mengubah status tiket).
     *
     * @param array<string, mixed> $actor
     */
    public static function tambahCatatan(array $actor, int $pengaduanId, string $catatan): void
    {
        $catatan = self::validText($catatan, 'catatan', 'Isi catatan wajib diisi.');

        // Catatan boleh ditambahkan walau tiket sudah dibatalkan (arsip internal).
        self::withLock($pengaduanId, function (array $row) use ($actor, $catatan): void {
            CatatanCs::create((int) $row['id'], (int) $actor['id'], $catatan);
            self::audit($actor, 'catatan_cs_ditambah', (int) $row['id'], $row, (string) $row['status']);
        }, true);
    }

    /* =====================================================================
     * Transisi oleh Teknisi (Tahap 7)
     * =================================================================== */

    /**
     * Mulai penanganan: menunggu_teknisi → dalam_penanganan.
     *
     * Hanya teknisi yang memiliki penugasan AKTIF pada tiket ini yang boleh.
     * Identitas teknisi diambil dari session ($actor), bukan input browser.
     *
     * @param array<string, mixed> $actor Pengguna aktif (dari session).
     */
    public static function mulaiPenanganan(array $actor, int $pengaduanId): void
    {
        $teknisiId = (int) ($actor['id'] ?? 0);
        if ($teknisiId <= 0) {
            throw new ValidationException(['tugas' => 'Sesi tidak valid. Silakan masuk kembali.']);
        }

        self::withLock($pengaduanId, function (array $row) use ($actor, $teknisiId): void {
            if (Penugasan::activeForTeknisi((int) $row['id'], $teknisiId) === null) {
                throw new ValidationException(['tugas' => 'Tiket ini bukan tugas aktif Anda.']);
            }

            self::assertIn(
                self::TEKNISI_ALLOWED[(string) $row['status']] ?? [],
                (string) $row['status'],
                'dalam_penanganan'
            );

            Penugasan::markStarted((int) $row['id'], $teknisiId);
            Pengaduan::setStatus((int) $row['id'], 'dalam_penanganan');
            Pengaduan::insertRiwayat(
                (int) $row['id'],
                (string) $row['status'],
                'dalam_penanganan',
                $teknisiId,
                'Penanganan dimulai oleh teknisi ' . (string) ($actor['nama'] ?? '') . '.'
            );
            self::audit($actor, 'penanganan_dimulai', (int) $row['id'], $row, 'dalam_penanganan');
            NotificationService::mulaiPenanganan(
                (int) $row['id'],
                (string) $row['nomor_tiket'],
                (string) ($actor['nama'] ?? '')
            );
            WhatsAppNotificationService::ticketEvent('penanganan_dimulai', (int) $row['id'], [
                'nomor_tiket' => (string) $row['nomor_tiket'],
                'nama_pelanggan' => (string) $row['nama_pelanggan_snapshot'],
                'teknisi' => (string) ($actor['nama'] ?? ''),
            ], 'status:dalam_penanganan');
        });
    }

    /**
     * Selesaikan tiket melalui laporan perbaikan: dalam_penanganan → selesai.
     *
     * Alur atomik:
     * 1) Validasi input teks + keberadaan foto.
     * 2) Simpan berkas foto ke disk (di luar document root).
     * 3) Di dalam transaksi + kunci baris pengaduan: validasi ulang kepemilikan,
     *    status, dan cegah laporan ganda, lalu simpan laporan + referensi foto.
     * 4) Status HANYA berubah menjadi `selesai` setelah laporan & foto tersimpan.
     * 5) Bila transaksi gagal, status tetap `dalam_penanganan` dan berkas
     *    yatim dibersihkan.
     *
     * @param array<string, mixed> $actor  Pengguna aktif (dari session).
     * @param array<string, mixed> $input  Data formulir laporan.
     * @param array<int, array<string, mixed>> $files Daftar berkas (Upload::normalizeFiles()).
     */
    public static function selesaikanDenganLaporan(array $actor, int $pengaduanId, array $input, array $files): void
    {
        $teknisiId = (int) ($actor['id'] ?? 0);
        if ($teknisiId <= 0) {
            throw new ValidationException(['tugas' => 'Sesi tidak valid. Silakan masuk kembali.']);
        }

        $penyebab = self::validText(
            (string) ($input['penyebab_gangguan'] ?? ''),
            'penyebab_gangguan',
            'Penyebab gangguan wajib diisi.'
        );
        $tindakan = self::validText(
            (string) ($input['tindakan_perbaikan'] ?? ''),
            'tindakan_perbaikan',
            'Tindakan perbaikan wajib diisi.'
        );

        $catatan = trim((string) ($input['catatan'] ?? ''));
        if ($catatan === '') {
            $catatan = null;
        } elseif (mb_strlen($catatan) > self::MAX_TEXT) {
            throw new ValidationException(['catatan' => 'Catatan tambahan maksimal ' . self::MAX_TEXT . ' karakter.']);
        }

        if (empty($files)) {
            throw new ValidationException(['foto' => 'Foto bukti setelah perbaikan wajib diunggah.']);
        }

        // Pra-validasi (di luar transaksi) agar permintaan yang tidak sah tidak
        // sempat menulis berkas ke disk. Validasi otoritatif diulang di dalam
        // transaksi dengan kunci baris.
        $pra = Pengaduan::findById($pengaduanId);
        if ($pra === null) {
            throw new ValidationException(['pengaduan' => 'Pengaduan tidak ditemukan.']);
        }
        if ((int) $pra['is_cancelled'] === 1) {
            throw new ValidationException(['pengaduan' => 'Pengaduan sudah dibatalkan dan tidak dapat diselesaikan.']);
        }
        if (Penugasan::activeForTeknisi($pengaduanId, $teknisiId) === null) {
            throw new ValidationException(['tugas' => 'Tiket ini bukan tugas aktif Anda.']);
        }
        self::assertIn(
            self::TEKNISI_ALLOWED[(string) $pra['status']] ?? [],
            (string) $pra['status'],
            'selesai'
        );
        if (LaporanTeknisi::existsForPengaduan($pengaduanId)) {
            throw new ValidationException([
                'laporan' => 'Laporan untuk tiket ini sudah pernah dikirim. Tugas telah selesai.',
            ]);
        }

        $stored = [];

        try {
            // 1) Simpan berkas lebih dahulu (di luar transaksi DB).
            foreach ($files as $file) {
                $stored[] = Upload::store($file, Upload::SUBDIR_LAPORAN);
            }

            $pdo = Database::pdo();
            $pdo->beginTransaction();

            try {
                $row = Pengaduan::lockRow($pengaduanId);
                if ($row === null) {
                    throw new ValidationException(['pengaduan' => 'Pengaduan tidak ditemukan.']);
                }
                if ((int) $row['is_cancelled'] === 1) {
                    throw new ValidationException([
                        'pengaduan' => 'Pengaduan sudah dibatalkan dan tidak dapat diselesaikan.',
                    ]);
                }
                if (Penugasan::activeForTeknisi($pengaduanId, $teknisiId) === null) {
                    throw new ValidationException(['tugas' => 'Tiket ini bukan tugas aktif Anda.']);
                }

                self::assertIn(
                    self::TEKNISI_ALLOWED[(string) $row['status']] ?? [],
                    (string) $row['status'],
                    'selesai'
                );

                if (LaporanTeknisi::existsForPengaduan($pengaduanId)) {
                    throw new ValidationException([
                        'laporan' => 'Laporan untuk tiket ini sudah pernah dikirim. Tugas telah selesai.',
                    ]);
                }

                // 2) Simpan laporan + referensi foto (di dalam transaksi).
                $laporanId = LaporanTeknisi::create(
                    $pengaduanId,
                    $teknisiId,
                    $penyebab,
                    $tindakan,
                    $catatan
                );
                foreach ($stored as $foto) {
                    LaporanTeknisi::insertFoto($laporanId, $foto);
                }

                // 3) Baru setelah laporan & foto tersimpan, status menjadi selesai.
                Pengaduan::setStatus($pengaduanId, 'selesai');
                Pengaduan::insertRiwayat(
                    $pengaduanId,
                    (string) $row['status'],
                    'selesai',
                    $teknisiId,
                    'Perbaikan dilaporkan oleh teknisi ' . (string) ($actor['nama'] ?? '') . '.'
                );
                self::audit($actor, 'laporan_perbaikan_dikirim', $pengaduanId, $row, 'selesai', [
                    'laporan_id' => $laporanId,
                    'jumlah_foto' => count($stored),
                ]);
                NotificationService::selesai($pengaduanId, (string) $row['nomor_tiket']);
                WhatsAppNotificationService::ticketEvent('tiket_selesai', $pengaduanId, [
                    'nomor_tiket' => (string) $row['nomor_tiket'],
                    'nama_pelanggan' => (string) $row['nama_pelanggan_snapshot'],
                ], 'status:selesai:teknisi');

                $pdo->commit();

                // Notifikasi baru diproses setelah data tiket benar-benar
                // commit. Kegagalan provider tidak membatalkan tiket.
                WhatsAppNotificationService::dispatchAfterCommit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        } catch (Throwable $exception) {
            // Bersihkan berkas yatim agar tidak menjadi sampah.
            foreach ($stored as $foto) {
                Upload::delete((string) $foto['path']);
            }
            throw $exception;
        }
    }

    /**
     * Jalankan aksi di dalam transaksi dengan kunci baris pengaduan.
     *
     * @param callable(array<string, mixed>): void $handler
     */
    private static function withLock(int $pengaduanId, callable $handler, bool $allowCancelled = false): void
    {
        if ($pengaduanId <= 0) {
            throw new ValidationException(['pengaduan' => 'Pengaduan tidak ditemukan.']);
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $row = Pengaduan::lockRow($pengaduanId);
            if ($row === null) {
                throw new ValidationException(['pengaduan' => 'Pengaduan tidak ditemukan.']);
            }
            if (!$allowCancelled && (int) $row['is_cancelled'] === 1) {
                throw new ValidationException([
                    'pengaduan' => 'Pengaduan sudah dibatalkan dan tidak dapat diproses melalui alur normal.',
                ]);
            }

            $handler($row);
            $pdo->commit();

            // Kirim otomatis setelah commit; tidak perlu tombol manual.
            WhatsAppNotificationService::dispatchAfterCommit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Validasi teks wajib (alasan/solusi/catatan).
     */
    private static function validText(string $value, string $field, string $requiredMessage): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new ValidationException([$field => $requiredMessage]);
        }
        if (mb_strlen($value) > self::MAX_TEXT) {
            throw new ValidationException([$field => 'Maksimal ' . self::MAX_TEXT . ' karakter.']);
        }

        return $value;
    }

    /**
     * Catat aksi penting ke audit_log.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $extra
     */
    private static function audit(
        array $actor,
        string $aksi,
        int $pengaduanId,
        array $row,
        string $statusBaru,
        ?array $extra = null
    ): void {
        $metadata = [
            'nomor_tiket' => (string) ($row['nomor_tiket'] ?? ''),
            'status_lama' => (string) ($row['status'] ?? ''),
            'status_baru' => $statusBaru,
        ];
        if ($extra !== null) {
            $metadata += $extra;
        }

        AuditLog::record((int) ($actor['id'] ?? 0), $aksi, 'pengaduan', $pengaduanId, $metadata);
    }
}
