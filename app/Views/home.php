<?php
/**
 * Halaman utama WiFiCare CDN.
 *
 * Menampilkan sambutan dan nilai-nilai aplikasi pada tahap fondasi.
 * Auth dan fitur internal akan dikerjakan pada tahap berikutnya.
 */

declare(strict_types=1);

$features = [
    [
        'judul' => 'Tiket unik & riwayat lengkap',
        'deskripsi' => 'Setiap pengaduan disimpan sebagai tiket dengan nomor unik dan riwayat status yang dapat dilacak.',
    ],
    [
        'judul' => 'Alur verifikasi jelas',
        'deskripsi' => 'Customer Service memverifikasi, teknisi menangani, dan pelanggan melihat setiap perubahan secara real-time.',
    ],
    [
        'judul' => 'Pantau teknisi secara langsung',
        'deskripsi' => 'Penugasan dan progres teknisi tampil untuk Customer Service, Admin, dan pelanggan.',
    ],
    [
        'judul' => 'Notifikasi dalam aplikasi',
        'deskripsi' => 'Penerima notifikasi tepat waktu: tiket baru, perubahan status, hingga penyelesaian.',
    ],
];

$content = '';
?>
<section class="hero">
    <div class="container py-5">
        <div class="row align-items-center g-4">
            <div class="col-lg-7">
                <p class="hero-eyebrow">Sistem manajemen pengaduan pelanggan</p>
                <h1 class="display-4 hero-title">Siapa Ada di Sini? Kami siap membantu.</h1>
                <p class="lead text-secondary-emphasis">
                    WiFiCare CDN memusatkan pengaduan pelanggan WiFi menjadi satu tiket
                    yang terdokumentasi, terpantau, dan mudah diikuti dari awal sampai selesai.
                </p>
                <div class="d-flex flex-wrap gap-3">
                    <a class="btn btn-primary btn-lg" href="login">Login sebagai Pengguna</a>
                    <a class="btn btn-outline-light btn-lg" href="about">Lihat Fitur</a>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card hero-card">
                    <div class="card-body">
                        <h2 class="h5 card-title">Status Pengembangan</h2>
                        <ul class="list-group list-group-flush">
                            <li class="list-group-item d-flex justify-content-between">
                                <span>Fondasi &amp; halaman publik</span><span class="badge text-bg-success">Aktif</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between">
                                <span>Login &amp; RBAC</span><span class="badge text-bg-secondary">Segera</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between">
                                <span>Pengaduan &amp; Customer Service</span><span class="badge text-bg-secondary">Segera</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between">
                                <span>Teknisi &amp; laporan</span><span class="badge text-bg-secondary">Segera</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="container py-4">
        <h2 class="h3 section-title">Fokus WiFiCare CDN</h2>
        <div class="row g-4">
            <?php foreach ($features as $feature): ?>
                <div class="col-md-6 col-lg-4">
                    <article class="card feature-card h-100">
                        <div class="card-body">
                            <h3 class="h5 card-title"><?= htmlspecialchars((string) $feature['judul'], ENT_QUOTES, 'UTF-8') ?></h3>
                            <p class="card-text text-secondary-emphasis"><?= htmlspecialchars((string) $feature['deskripsi'], ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                    </article>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
