<?php
/**
 * Service pengaduan pelanggan WiFiCare CDN.
 *
 * Mengorkestrasi validasi, penyimpanan berkas, dan penyimpanan tiket dalam
 * satu transaksi database. Identitas pelanggan SELALU berasal dari session
 * (diteruskan controller), tidak pernah dari input browser.
 *
 * Jaminan integritas:
 * - Berkas diunggah lebih dahulu; bila penyimpanan tiket gagal, berkas dibersihkan.
 * - Bila penyimpanan berkas gagal, tiket tidak dibuat.
 * - Nomor tiket dibentuk di dalam transaksi dari ID yang baru dibuat.
 * - Nomor WhatsApp pada tiket hanyalah snapshot kontak tiket. Service ini
 *   TIDAK pernah menulis ulang nomor WhatsApp utama pelanggan.
 */

declare(strict_types=1);

final class PengaduanService
{
    /** Status awal setiap pengaduan baru. */
    public const STATUS_AWAL = 'menunggu_verifikasi';

    /** Jumlah tiket per halaman pada riwayat pelanggan. */
    public const PER_HALAMAN = 10;

    /**
     * Validasi & simpan pengaduan baru milik pelanggan.
     *
     * @param array<string, mixed> $user Data pengguna aktif (dari session).
     * @param array<string, mixed> $input Data formulir yang sudah dipilih controller.
     * @param array<int, array<string, mixed>> $files Daftar berkas dari Upload::normalizeFiles().
     * @return array{id: int, nomor_tiket: string}
     *
     * @throws ValidationException Bila data formulir tidak valid.
     * @throws RuntimeException   Bila terjadi kegagalan teknis.
     */
    public static function createForPelanggan(array $user, array $input, array $files): array
    {
        // Identitas dari session, bukan dari browser.
        $pelangganId = (int) ($user['pelanggan_id'] ?? 0);
        if ($pelangganId <= 0) {
            throw new RuntimeException('Akun Anda tidak terhubung dengan data pelanggan.');
        }

        $nama = trim((string) ($user['nama'] ?? ''));
        $pppoe = trim((string) ($user['username_pppoe'] ?? ($user['username'] ?? '')));

        // Nomor kontak tiket bersifat opsional. Bila kosong, gunakan nomor
        // utama dari session sebagai nilai snapshot awal. Nilai ini hanya
        // disalin ke tiket dan tidak pernah ditulis balik ke tabel pelanggan.
        $whatsapp = trim((string) ($input['nomor_whatsapp'] ?? ''));
        if ($whatsapp === '') {
            $whatsapp = trim((string) ($user['nomor_whatsapp'] ?? ''));
        }

        $kategoriId = (int) ($input['kategori_id'] ?? 0);
        $deskripsi = trim((string) ($input['deskripsi'] ?? ''));

        $errors = [];

        if ($whatsapp === '') {
            $errors['nomor_whatsapp'] = 'Nomor WhatsApp kontak tiket wajib diisi atau nomor utama harus tersedia.';
        } elseif (!preg_match('/^[0-9+\-\s]{9,20}$/', $whatsapp)) {
            $errors['nomor_whatsapp'] = 'Format nomor WhatsApp tidak valid (9–20 digit).';
        }

        if (Kategori::findActive($kategoriId) === null) {
            $errors['kategori_id'] = 'Kategori gangguan yang dipilih tidak valid.';
        }

        if ($deskripsi === '') {
            $errors['deskripsi'] = 'Deskripsi gangguan wajib diisi.';
        } elseif (mb_strlen($deskripsi) < 10) {
            $errors['deskripsi'] = 'Deskripsi gangguan minimal 10 karakter.';
        } elseif (mb_strlen($deskripsi) > 2000) {
            $errors['deskripsi'] = 'Deskripsi gangguan maksimal 2000 karakter.';
        }

        if (empty($files)) {
            $errors['foto'] = 'Foto bukti gangguan wajib diunggah.';
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        $stored = [];

        try {
            // 1) Simpan berkas lebih dahulu (di luar transaksi DB).
            foreach ($files as $file) {
                $stored[] = Upload::store($file);
            }

            $pdo = Database::pdo();
            $pdo->beginTransaction();

            try {
                // 2) Simpan tiket + nomor tiket unik (di dalam transaksi).
                $created = Pengaduan::createWithTicket([
                    'pelanggan_id' => $pelangganId,
                    'nama_pelanggan_snapshot' => $nama,
                    'username_pppoe_snapshot' => $pppoe,
                    'nomor_whatsapp_snapshot' => $whatsapp,
                    'kategori_id' => $kategoriId,
                    'deskripsi' => $deskripsi,
                    'status' => self::STATUS_AWAL,
                ]);

                // 3) Simpan referensi foto.
                foreach ($stored as $foto) {
                    Pengaduan::insertFoto($created['id'], $foto);
                }

                // 4) Catat status awal pada riwayat_status.
                Pengaduan::insertRiwayat(
                    $created['id'],
                    null,
                    self::STATUS_AWAL,
                    (int) ($user['id'] ?? 0),
                    'Pengaduan dibuat oleh pelanggan.'
                );

                // 5) Notifikasi dalam aplikasi ke CS & Admin (satu transaksi).
                //    Nomor kontak hanya tersimpan sebagai snapshot di tiket.
                NotificationService::tiketBaru(
                    (int) $created['id'],
                    (string) $created['nomor_tiket'],
                    $nama !== '' ? $nama : $pppoe
                );

                // Outbox WhatsApp (idempoten). Service menelan kegagalan agar
                // pembuatan tiket tidak pernah bergantung pada provider.
                WhatsAppNotificationService::ticketCreated((int) $created['id'], [
                    'nomor_tiket' => (string) $created['nomor_tiket'],
                    'nama_pelanggan' => $nama !== '' ? $nama : $pppoe,
                ]);

                $pdo->commit();

                // Tiket sudah tersimpan: proses notifikasi otomatis setelah
                // commit. Kegagalan WhatsApp tidak boleh membatalkan tiket.
                WhatsAppNotificationService::dispatchAfterCommit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }

            AuditLog::record(
                (int) ($user['id'] ?? 0),
                'pengaduan_dibuat',
                'pengaduan',
                $created['id'],
                ['nomor_tiket' => $created['nomor_tiket']]
            );

            return $created;
        } catch (Throwable $exception) {
            // Bersihkan berkas yang terlanjur tersimpan agar tidak menjadi sampah.
            foreach ($stored as $foto) {
                Upload::delete((string) $foto['path']);
            }

            throw $exception;
        }
    }
}
