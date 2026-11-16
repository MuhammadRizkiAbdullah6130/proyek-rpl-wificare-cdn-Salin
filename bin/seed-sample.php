<?php
/**
 * WiFiCare CDN — Data contoh pengaduan (untuk pengujian dashboard Tahap 4).
 *
 * PENTING: Ini HANYA data contoh untuk pengujian lokal. Aplikasi TIDAK pernah
 * membuat tiket dummy secara otomatis; skrip ini harus dijalankan manual.
 *
 * Penggunaan (dari direktori proyek):
 *   php bin/seed-sample.php            menolak bila sudah ada pengaduan
 *   php bin/seed-sample.php --force    tetap tambahkan walaupun sudah ada data
 *   php bin/seed-sample.php --reset    hapus seluruh data pengaduan, lalu isi contoh
 *
 * Skrip juga memastikan akun uji tambahan tersedia:
 *   - teknisi : tek.bayu   (untuk menguji isolasi data antar teknisi)
 *   - pelanggan: pppoe.agus (untuk menguji isolasi data antar pelanggan)
 *
 * Password akun uji dibaca dari environment T10_PW_CS, T10_PW_TEK, dan
 * T10_PW_PEL. Skrip berhenti tanpa membuat data bila variabel belum diisi dan
 * tidak pernah mencetak password ke console.
 *
 * Hanya dapat dijalankan dari CLI.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Skrip ini hanya dapat dijalankan melalui CLI.');
}

require __DIR__ . '/../app/bootstrap.php';

/**
 * Baca opsi baris perintah sederhana (--key=value atau --flag).
 *
 * @param array<int, string> $argv
 * @return array<string, string|bool>
 */
function wificare_seed_options(array $argv): array
{
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (!str_starts_with($argument, '--')) {
            continue;
        }

        $argument = substr($argument, 2);
        $equals = strpos($argument, '=');
        if ($equals === false) {
            $options[$argument] = true;
        } else {
            $options[substr($argument, 0, $equals)] = substr($argument, $equals + 1);
        }
    }

    return $options;
}

$options = wificare_seed_options($argv);
$reset = !empty($options['reset']);
$force = !empty($options['force']);

// Password akun sampel dibaca dari environment dan tidak pernah ditulis ke
// repository atau dicetak ke console. Set nilainya sebelum menjalankan skrip.
$seedPasswords = [];
$missingPasswords = [];
foreach (['T10_PW_CS', 'T10_PW_TEK', 'T10_PW_PEL'] as $passwordKey) {
    $passwordValue = getenv($passwordKey);
    if ($passwordValue === false || trim((string) $passwordValue) === '') {
        $missingPasswords[] = $passwordKey;
    } else {
        $seedPasswords[$passwordKey] = (string) $passwordValue;
    }
}

if ($missingPasswords !== []) {
    fwrite(STDERR, '[batal] Kredensial seed belum dikonfigurasi: '
        . implode(', ', $missingPasswords) . PHP_EOL);
    fwrite(STDERR, 'Set environment variable tersebut dengan password akun uji lokal. '
        . 'Jangan memakai password produksi.' . PHP_EOL);
    exit(2);
}

echo "WiFiCare CDN — Data Contoh Dashboard\n";
echo "==========================================\n";

try {
    $pdo = Database::pdo();

    // --- Pemastian akun uji ------------------------------------------------
    $ensureUser = static function (string $username, string $nama, string $role, string $password) use ($pdo): array {
        $statement = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
        $statement->execute(['username' => $username]);
        $existing = $statement->fetch();
        if ($existing !== false) {
            return [(int) $existing['id'], false];
        }

        $insert = $pdo->prepare(
            'INSERT INTO users (username, password_hash, nama, role, is_active)
             VALUES (:username, :password_hash, :nama, :role, 1)'
        );
        $insert->execute([
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'nama' => $nama,
            'role' => $role,
        ]);

        return [(int) $pdo->lastInsertId(), true];
    };

    $ensurePelanggan = static function (int $userId, string $pppoe, string $whatsapp, string $alamat) use ($pdo): int {
        $statement = $pdo->prepare(
            'SELECT id FROM pelanggan WHERE username_pppoe = :pppoe OR user_id = :user_id LIMIT 1'
        );
        $statement->execute(['pppoe' => $pppoe, 'user_id' => $userId]);
        $existing = $statement->fetch();
        if ($existing !== false) {
            return (int) $existing['id'];
        }

        $insert = $pdo->prepare(
            'INSERT INTO pelanggan (user_id, username_pppoe, nomor_whatsapp, alamat)
             VALUES (:user_id, :pppoe, :whatsapp, :alamat)'
        );
        $insert->execute([
            'user_id' => $userId,
            'pppoe' => $pppoe,
            'whatsapp' => $whatsapp,
            'alamat' => $alamat,
        ]);

        return (int) $pdo->lastInsertId();
    };

    [$teknisi1Id, $teknisi1Baru] = $ensureUser('tek.andi', 'Andi Pratama', 'teknisi', $seedPasswords['T10_PW_TEK']);
    [$csId, $csBaru] = $ensureUser('cs.dewi', 'Dewi Lestari', 'cs', $seedPasswords['T10_PW_CS']);
    [$teknisi2Id, $teknisi2Baru] = $ensureUser('tek.bayu', 'Bayu Nugroho', 'teknisi', $seedPasswords['T10_PW_TEK']);

    [$pelanggan1UserId, $p1Baru] = $ensureUser('pppoe.rina', 'Rina Marlina', 'pelanggan', $seedPasswords['T10_PW_PEL']);
    $pelanggan1Id = $ensurePelanggan($pelanggan1UserId, 'pppoe.rina', '081200000001', 'Jl. Melati No. 10');

    [$pelanggan2UserId, $p2Baru] = $ensureUser('pppoe.agus', 'Agus Setiawan', 'pelanggan', $seedPasswords['T10_PW_PEL']);
    $pelanggan2Id = $ensurePelanggan($pelanggan2UserId, 'pppoe.agus', '081200000002', 'Jl. Kenanga No. 22');

    if ($csBaru) {
        echo "[info] Akun CS dibuat: cs.dewi\n";
    }
    if ($teknisi1Baru) {
        echo "[info] Akun teknisi dibuat: tek.andi\n";
    }
    if ($teknisi2Baru) {
        echo "[info] Akun teknisi dibuat: tek.bayu\n";
    }
    if ($p1Baru) {
        echo "[info] Akun pelanggan dibuat: pppoe.rina\n";
    }
    if ($p2Baru) {
        echo "[info] Akun pelanggan dibuat: pppoe.agus\n";
    }

    // --- Peta pelanggan & kategori ----------------------------------------
    $pelangganMap = [1 => $pelanggan1Id, 2 => $pelanggan2Id];
    $pelangganInfo = [
        1 => ['nama' => 'Rina Marlina', 'pppoe' => 'pppoe.rina', 'wa' => '081200000001'],
        2 => ['nama' => 'Agus Setiawan', 'pppoe' => 'pppoe.agus', 'wa' => '081200000002'],
    ];

    $kategoriMap = [];
    foreach ($pdo->query('SELECT id, nama FROM kategori_gangguan') as $row) {
        $kategoriMap[(string) $row['nama']] = (int) $row['id'];
    }
    $kategoriFallback = $kategoriMap['Lainnya'] ?? (count($kategoriMap) > 0 ? (int) reset($kategoriMap) : 0);

    $kategoriIdOf = static function (string $nama) use ($kategoriMap, $kategoriFallback): int {
        return $kategoriMap[$nama] ?? $kategoriFallback;
    };

    // --- Pemulihan / penjagaan --------------------------------------------
    if ($reset) {
        echo "[info] Menghapus data pengaduan lama (mode --reset)...\n";
        $pdo->exec('DELETE FROM laporan_foto');
        $pdo->exec('DELETE FROM laporan_teknisi');
        $pdo->exec('DELETE FROM penugasan_teknisi');
        $pdo->exec('DELETE FROM pengaduan_foto');
        $pdo->exec('DELETE FROM riwayat_status');
        $pdo->exec('DELETE FROM catatan_cs');
        $pdo->exec('DELETE FROM penyelesaian_cs');
        $pdo->exec('DELETE FROM rating_layanan');
        $pdo->exec('DELETE FROM notifikasi');
        $pdo->exec('DELETE FROM pengaduan');
        echo "[ok]   Tabel pengaduan dibersihkan.\n";
    } else {
        $existingCount = (int) $pdo->query('SELECT COUNT(*) FROM pengaduan')->fetchColumn();
        if ($existingCount > 0 && !$force) {
            fwrite(STDERR, "[batal] Sudah ada {$existingCount} pengaduan. Gunakan --force atau --reset.\n");
            exit(1);
        }
    }

    // --- Definisi tiket contoh --------------------------------------------
    // days = berapa hari lalu tiket dibuat.
    $tickets = [
        ['status' => 'selesai', 'days' => 12, 'hour' => 9, 'kategori' => 'Gangguan Koneksi Internet', 'pelanggan' => 1, 'teknisi' => 1, 'laporan' => true, 'deskripsi' => 'Internet mati total sejak pagi, lampu indikator router tidak menyala.'],
        ['status' => 'selesai', 'days' => 9, 'hour' => 14, 'kategori' => 'Koneksi Lambat / Tidak Stabil', 'pelanggan' => 1, 'teknisi' => 1, 'laporan' => true, 'deskripsi' => 'Koneksi sering terputus dan lambat saat malam hari.'],
        ['status' => 'selesai', 'days' => 5, 'hour' => 10, 'kategori' => 'Masalah Perangkat / Router', 'pelanggan' => 2, 'teknisi' => 2, 'laporan' => true, 'deskripsi' => 'Perlu penggantian kabel LAN ke perangkat pelanggan.'],
        ['status' => 'dalam_penanganan', 'days' => 3, 'hour' => 8, 'kategori' => 'Gangguan Koneksi Internet', 'pelanggan' => 1, 'teknisi' => 1, 'laporan' => false, 'deskripsi' => 'Koneksi terputus sejak kemarin, sudah dilakukan pengecekan awal.'],
        ['status' => 'menunggu_teknisi', 'days' => 2, 'hour' => 11, 'kategori' => 'Koneksi Lambat / Tidak Stabil', 'pelanggan' => 2, 'teknisi' => 1, 'laporan' => false, 'deskripsi' => 'Kecepatan tidak sesuai paket yang diambil.'],
        ['status' => 'menunggu_teknisi', 'days' => 2, 'hour' => 16, 'kategori' => 'Masalah Perangkat / Router', 'pelanggan' => 1, 'teknisi' => 2, 'laporan' => false, 'deskripsi' => 'Router sering restart sendiri.'],
        ['status' => 'diproses_cs', 'days' => 1, 'hour' => 9, 'kategori' => 'Gangguan Koneksi Internet', 'pelanggan' => 1, 'teknisi' => null, 'laporan' => false, 'deskripsi' => 'Tidak bisa terhubung ke internet sejak malam.'],
        ['status' => 'menunggu_verifikasi', 'days' => 0, 'hour' => 8, 'kategori' => 'Tagihan dan Pembayaran', 'pelanggan' => 2, 'teknisi' => null, 'laporan' => false, 'deskripsi' => 'Menanyakan rincian tagihan bulan ini.'],
        ['status' => 'menunggu_verifikasi', 'days' => 0, 'hour' => 13, 'kategori' => 'Lainnya', 'pelanggan' => 1, 'teknisi' => null, 'laporan' => false, 'deskripsi' => 'Permintaan pemindahan lokasi perangkat.'],
        ['status' => 'ditolak', 'days' => 6, 'hour' => 15, 'kategori' => 'Lainnya', 'pelanggan' => 1, 'teknisi' => null, 'laporan' => false, 'deskripsi' => 'Pengaduan tanpa detail lokasi dan bukti.', 'alasan' => 'Data pengaduan tidak lengkap sehingga tidak dapat diproses.'],
        ['status' => 'diproses_cs', 'days' => 4, 'hour' => 12, 'kategori' => 'Gangguan Koneksi Internet', 'pelanggan' => 1, 'teknisi' => null, 'laporan' => false, 'deskripsi' => 'Tiket contoh yang dibatalkan oleh pelanggan.', 'cancelled' => true, 'cancel_reason' => 'Gangguan sudah teratasi sendiri oleh pelanggan.'],
    ];

    $insertPengaduan = $pdo->prepare(
        'INSERT INTO pengaduan
            (nomor_tiket, pelanggan_id, nama_pelanggan_snapshot, username_pppoe_snapshot,
             nomor_whatsapp_snapshot, kategori_id, deskripsi, status, alasan_penolakan,
             is_cancelled, cancelled_at, cancellation_reason, created_at)
         VALUES
            (:nomor_tiket, :pelanggan_id, :nama, :pppoe, :whatsapp, :kategori_id, :deskripsi, :status,
             :alasan, :is_cancelled, :cancelled_at, :cancel_reason, :created_at)'
    );

    $insertRiwayat = $pdo->prepare(
        'INSERT INTO riwayat_status (pengaduan_id, status_lama, status_baru, actor_id, catatan, created_at)
         VALUES (:pengaduan_id, :status_lama, :status_baru, :actor_id, :catatan, :created_at)'
    );

    $insertPenugasan = $pdo->prepare(
        'INSERT INTO penugasan_teknisi
            (pengaduan_id, teknisi_id, ditugaskan_oleh, catatan, is_active, assigned_at, started_at, created_at)
         VALUES
            (:pengaduan_id, :teknisi_id, :ditugaskan_oleh, :catatan, 1, :assigned_at, :started_at, :created_at)'
    );

    $insertLaporan = $pdo->prepare(
        'INSERT INTO laporan_teknisi (pengaduan_id, teknisi_id, penyebab_gangguan, tindakan_perbaikan, completed_at, created_at)
         VALUES (:pengaduan_id, :teknisi_id, :penyebab, :tindakan, :completed_at, :created_at)'
    );

    $nomorUrut = 0;
    $jumlahPembatalan = 0;

    $pdo->beginTransaction();
    try {
        foreach ($tickets as $ticket) {
            $nomorUrut++;
            $pelangganKey = (int) $ticket['pelanggan'];
            $info = $pelangganInfo[$pelangganKey];
            $createdAt = date('Y-m-d H:i:s', strtotime('-' . (int) $ticket['days'] . ' days ' . (int) $ticket['hour'] . ':00'));
            $nomorTiket = sprintf('WFC-%s-%06d', date('Ymd', strtotime($createdAt)), $nomorUrut);

            $isCancelled = !empty($ticket['cancelled']);
            if ($isCancelled) {
                $jumlahPembatalan++;
            }

            $insertPengaduan->execute([
                'nomor_tiket' => $nomorTiket,
                'pelanggan_id' => $pelangganMap[$pelangganKey],
                'nama' => $info['nama'],
                'pppoe' => $info['pppoe'],
                'whatsapp' => $info['wa'],
                'kategori_id' => $kategoriIdOf((string) $ticket['kategori']),
                'deskripsi' => (string) $ticket['deskripsi'],
                'status' => (string) $ticket['status'],
                'alasan' => $ticket['alasan'] ?? null,
                'is_cancelled' => $isCancelled ? 1 : 0,
                'cancelled_at' => $isCancelled ? $createdAt : null,
                'cancel_reason' => $ticket['cancel_reason'] ?? null,
                'created_at' => $createdAt,
            ]);

            $pengaduanId = (int) $pdo->lastInsertId();

            // Riwayat: masuk antrean verifikasi.
            $insertRiwayat->execute([
                'pengaduan_id' => $pengaduanId,
                'status_lama' => null,
                'status_baru' => 'menunggu_verifikasi',
                'actor_id' => null,
                'catatan' => 'Tiket dibuat oleh pelanggan.',
                'created_at' => $createdAt,
            ]);

            // Riwayat: perubahan status berikutnya bila bukan menunggu_verifikasi.
            if ((string) $ticket['status'] !== 'menunggu_verifikasi') {
                $insertRiwayat->execute([
                    'pengaduan_id' => $pengaduanId,
                    'status_lama' => 'menunggu_verifikasi',
                    'status_baru' => (string) $ticket['status'],
                    'actor_id' => $csId,
                    'catatan' => 'Perubahan status oleh Customer Service.',
                    'created_at' => date('Y-m-d H:i:s', strtotime($createdAt . ' +1 hour')),
                ]);
            }

            // Penugasan teknisi.
            if (!empty($ticket['teknisi'])) {
                $teknisiId = (int) $ticket['teknisi'] === 2 ? $teknisi2Id : $teknisi1Id;
                $status = (string) $ticket['status'];
                $startedAt = in_array($status, ['dalam_penanganan', 'selesai'], true)
                    ? date('Y-m-d H:i:s', strtotime($createdAt . ' +2 hours'))
                    : null;

                $insertPenugasan->execute([
                    'pengaduan_id' => $pengaduanId,
                    'teknisi_id' => $teknisiId,
                    'ditugaskan_oleh' => $csId,
                    'catatan' => 'Ditugaskan oleh Customer Service.',
                    'assigned_at' => date('Y-m-d H:i:s', strtotime($createdAt . ' +1 hour')),
                    'started_at' => $startedAt,
                    'created_at' => date('Y-m-d H:i:s', strtotime($createdAt . ' +1 hour')),
                ]);

                if (!empty($ticket['laporan'])) {
                    $insertLaporan->execute([
                        'pengaduan_id' => $pengaduanId,
                        'teknisi_id' => $teknisiId,
                        'penyebab' => 'Pengecekan lapangan menemukan gangguan pada ' . (string) $ticket['kategori'] . '.',
                        'tindakan' => 'Dilakukan perbaikan dan pengujian koneksi hingga normal.',
                        'completed_at' => date('Y-m-d H:i:s', strtotime($createdAt . ' +4 hours')),
                        'created_at' => date('Y-m-d H:i:s', strtotime($createdAt . ' +4 hours')),
                    ]);
                }
            }
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    $total = (int) $pdo->query('SELECT COUNT(*) FROM pengaduan')->fetchColumn();
    $aktif = (int) $pdo->query("SELECT COUNT(*) FROM pengaduan WHERE is_cancelled = 0 AND status IN ('menunggu_verifikasi','diproses_cs','menunggu_teknisi','dalam_penanganan')")->fetchColumn();

    echo "[ok]   " . count($tickets) . " tiket contoh dibuat (termasuk {$jumlahPembatalan} tiket dibatalkan).\n";
    echo "       Total pengaduan di database : {$total}\n";
    echo "       Tiket aktif (tanpa batal)   : {$aktif}\n";
    echo "==========================================\n";
    echo "Data contoh siap. Dashboard kini menampilkan statistik & grafik nyata.\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, "[error] " . $exception->getMessage() . "\n");
    exit(1);
}
