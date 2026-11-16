# PROGRESS — WiFiCare CDN

Catatan perkembangan implementasi WiFiCare CDN berdasarkan PRD v1.0.

## Tahap 1 — Fondasi (✅ selesai)

- [x] Struktur direktori projek modular sesuai arsitektur MVC sederhana.
- [x] Front controller `public/index.php` sebagai titik masuk utama.
- [x] Routing sederhana (hanya `GET`).
- [x] Konfigurasi aplikasi (`config/app.php`) dan konfigurasi database (`config/database.php`).
- [x] `.env.example` tanpa kredensial asli.
- [x] `.gitignore` untuk file rahasia, log, dan upload.
- [x] Halaman beranda dengan tema Dark Mode.
- [x] Halaman tentang dan halaman login.
- [x] Layout dasar, komponen CSS/JS dasar, responsif mobile.
- [x] `README.md` berisi cara menjalankan di XAMPP/Laragon.
- [x] PHP lolos lint dan halaman dapat diakses melalui server.

## Tahap 2 — UI/UX dan Dashboard (✅ selesai)

**Batasan yang dipatuhi:** tidak ada autentikasi nyata, tidak ada koneksi
database, tidak ada data dummy yang diklaim sebagai data asli, tidak ada
integrasi API AI, tidak ada perubahan status tiket nyata.

### Fondasi arsitektur tampilan

- [x] Helper komponen: `component()`, `component_html()`, `icon()`, `e()`, `asset()`, `url()`, `status_label()`, `status_class()`, `role_menu()`, `role_label()`.
- [x] Ikon SVG inline konsisten (tanpa dependensi eksternal).
- [x] Menu navigasi per peran pada `app/Helpers/menu.php`.
- [x] `base_path` opsional melalui `APP_BASE_PATH` untuk pemasangan di subfolder.
- [x] Routing diperluas menjadi 32 rute `GET` untuk empat peran.
- [x] `public/.htaccess` untuk URL bersih Apache dan header keamanan dasar.
- [x] Front controller menyajikan berkas statis pada server bawaan PHP (`php -S`).

### Layout

- [x] `layouts/base.php` — layout publik (beranda, tentang, login, halaman error).
- [x] `layouts/app.php` — layout area per peran: sidebar, header, breadcrumb, banner pratinjau, footer, modal global, container toast.

### Komponen reusable (16)

| Komponen | Berkas | Fungsi |
|---|---|---|
| Sidebar | `components/sidebar.php` | Navigasi kiri, menu per peran, tombol tutup mobile |
| Header/navbar | `components/header.php` | Judul, pencarian global, lonceng notifikasi, chip pengguna |
| Kepala halaman | `components/page-header.php` | Judul, subjudul, tombol aksi, label DEMO |
| Breadcrumb | `components/breadcrumb.php` | Jejak navigasi |
| Kartu statistik | `components/stat-card.php` | Metrik dengan ikon, tone, nilai kosong (—) |
| Badge status | `components/badge-status.php` | Enam status resmi, selalu menampilkan teks |
| Tabel responsif | `components/table.php` | Kolom, baris, pencarian, filter, pagination, empty state |
| Pagination | `components/pagination.php` | Navigasi halaman (pratinjau) |
| Search & filter | `components/search-filter.php` | Bilah pencarian dan filter mandiri |
| Modal konfirmasi | `components/modal-confirm.php` | Modal halaman + modal global `#appConfirmModal` |
| Toast | `components/toast.php` | Container + API `WifiCare.toast()` |
| Upload foto | `components/upload-photo.php` | Drag & drop, pratinjau, validasi ukuran/format |
| Empty state | `components/empty-state.php` | Tampilan kosong dan versi ringkas |
| Skeleton | `components/skeleton.php` | Loading state |
| Icon | helper `icon()` | 45+ ikon SVG konsisten |
| Tombol/input/select | CSS `style.css` | Gaya konsisten di seluruh halaman |

### Halaman (33)

- **Publik:** Beranda, Tentang, Login (penanda), Akses Ditolak (403), 404.
- **Admin (9):** Dashboard, Manajemen Pengguna, Data Pelanggan, Semua Pengaduan, Monitoring Teknisi, Laporan, Kategori Gangguan, Audit Log, Pengaturan.
- **Customer Service (7):** Dashboard, Pengaduan Masuk, Verifikasi, Penugasan Teknisi, Monitoring, Riwayat Pelanggan, Catatan Internal.
- **Teknisi (5):** Dashboard, Tugas Saya, Detail Tugas, Form Laporan Perbaikan, Riwayat Pekerjaan.
- **Pelanggan (7):** Dashboard, Buat Pengaduan, Riwayat Pengaduan, Detail Tiket, Profil, Rating Layanan, AI Assistant.

### Hasil pengujian

- [x] Lint PHP: 62 berkas, 0 error.
- [x] Semua 32 rute mengembalikan status benar (200; 403 untuk akses ditolak; 404 untuk rute tidak dikenal).
- [x] Tidak ada warning/error PHP pada respons halaman uji.
- [x] Tidak ada error JavaScript pada konsol browser.
- [x] Struktur tag HTML seimbang pada 12 halaman uji.
- [x] 33 tautan internal diperiksa, 0 tautan rusak.
- [x] Pencarian tabel berfungsi (5 baris → 1 baris saat mencari "dewi"; pesan "tidak ditemukan" muncul saat tanpa hasil).
- [x] Modal konfirmasi terbuka dari `data-confirm`, judul terisi dinamis, toast muncul setelah konfirmasi.
- [x] API `WifiCare.toast()` berfungsi.
- [x] Sidebar mobile dapat dibuka/ditutup, scrim muncul, tombol tutup berfungsi.
- [x] Upload foto: komponen hadir, menerima JPG/PNG/WebP, validasi ukuran 5 MB.
- [x] Rating bintang: memilih 4 dari 5 memperbarui status visual dan teks.
- [x] Validasi field wajib: menandai invalid saat blur, membersihkan setelah diisi.
- [x] Menu sidebar berbeda per peran dan menandai halaman aktif.

### Belum dikerjakan (sesuai batas Tahap 2)

- Autentikasi, session, RBAC, dan database (tahap fondasi lanjut).
- Aksi nyata: simpan pengaduan, verifikasi, penugasan, laporan, rating, audit.
- Notifikasi in-app dengan data nyata.
- Integrasi API AI, notifikasi WhatsApp/email, ekspor PDF/Excel.

## Tahap 3 — Database MySQL & Autentikasi (✅ selesai)

**Batasan yang dipatuhi:** tidak mengerjakan formulir pengaduan, verifikasi CS,
penugasan teknisi, laporan perbaikan, AI Assistant, notifikasi, dan ekspor
laporan. Dashboard hanya dibuka sesuai peran (statistik dinamis menyusul).

Database: `wificare_cdn_v1` (nama lama `wificare_cdn` sengaja tidak disentuh atas
permintaan pengguna). Engine InnoDB, charset `utf8mb4` / `utf8mb4_unicode_ci`.

### Struktur database (16 tabel)

| Tabel | Isi | Relasi utama |
|---|---|---|
| `users` | Akun keempat peran, hash password, status aktif | — |
| `pelanggan` | Profil pelanggan + Username PPPoE | `user_id` → `users` (RESTRICT) |
| `kategori_gangguan` | Master kategori | — |
| `pengaduan` | Tiket + snapshot pelanggan + atribut batal/arsip | `pelanggan_id`, `kategori_id`, `cancelled_by`, `archived_by` → `users` |
| `pengaduan_foto` | Lampiran pengaduan | `pengaduan_id` (RESTRICT) |
| `penugasan_teknisi` | Riwayat penugasan | `pengaduan_id`, `teknisi_id`, `ditugaskan_oleh` |
| `laporan_teknisi` | Laporan perbaikan (1 per tiket) | `pengaduan_id` (unggul), `teknisi_id` |
| `laporan_foto` | Lampiran laporan | `laporan_id` (RESTRICT) |
| `riwayat_status` | Jejak perubahan status | `pengaduan_id`, `actor_id` |
| `catatan_cs` | Catatan internal CS | `pengaduan_id`, `cs_id` |
| `notifikasi` | Notifikasi in-app | `user_id` (CASCADE), `pengaduan_id` (SET NULL) |
| `rating_layanan` | Rating 1–5 (1 per tiket selesai) | `pengaduan_id` (unggul), `pelanggan_id` |
| `audit_log` | Jejak audit append-only | `actor_id` (SET NULL) |
| `pengaturan` | Konfigurasi key/value | — |
| `login_attempts` | Pendukung pembatasan brute force | — |
| `migrations` | Pencatat migrasi yang sudah dijalankan | — |

Catatan relasi: penghapusan berantai dihindari pada data riwayat penting
(RESTRICT pada pengaduan, foto, laporan, rating, catatan). Hanya `notifikasi`
yang ikut terhapus bila user dihapus (bukan data riwayat; `audit_log` tetap
menyimpan jejak). Akun dinonaktifkan melalui `is_active`, bukan dihapus.
Password jaringan PPPoE tidak pernah disimpan.

### Backend & keamanan

- [x] Pemuat `.env` tanpa Composer (`app/Helpers/env.php`) + `app/bootstrap.php`.
- [x] Service `Database` (PDO, `ERRMODE_EXCEPTION`, `EMULATE_PREPARES=false`).
- [x] Service `Session` (HttpOnly, SameSite=Lax, `use_strict_mode`, batas idle, regenerasi).
- [x] Service `Csrf` (token session + `hash_equals`, rotasi saat login).
- [x] Service `Auth` (verifikasi, batas percobaan, audit, redirect per peran).
- [x] Middleware `AuthMiddleware` (`guest`, `auth`, `role`) diterapkan per route.
- [x] Model `User`, `Pelanggan`, `AuditLog`, `LoginAttempt` (PDO prepared statement).
- [x] Hash password `password_hash()` (bcrypt `$2y$`) dan `password_verify()`.
- [x] `POST /login` dan `POST /logout` (wajib CSRF).
- [x] Pesan error login aman (generik untuk kredensial salah; khusus hanya setelah password benar untuk akun nonaktif).
- [x] Pembatasan percobaan (5 per username / 10 per IP dalam 15 menit) → pesan "Terlalu banyak percobaan".
- [x] Detail error database tidak ditampilkan ke pengguna (dicatat ke `storage/logs/app.log`).

### Skrip CLI

- `bin/migrate.php` — membuat database, menjalankan migrasi (dicatat, tidak diulang), dan seeder idempoten.
- `bin/create-user.php` — membuat akun (default admin) dengan password dibuat otomatis bila tidak diberikan; menolak admin kedua tanpa `--force`; mendukung pelanggan (`--pppoe`, `--whatsapp`).

### Integrasi UI Tahap 2

- [x] Halaman login memakai form nyata (POST + CSRF) tanpa mengubah desain Dark Mode.
- [x] Header menampilkan pengguna nyata; tombol Keluar berupa form POST ber-CSRF.
- [x] Banner "mode pratinjau" di layout aplikasi dihapus (sudah terhubung backend).
- [x] Panel pratinjau per-peran (DEMO) pada halaman login dihapus.
- [x] Layout publik menampilkan chip pengguna bila sudah masuk.

### Hasil pengujian (Tahap 3)

- [x] Lint PHP: 76 berkas, 0 error.
- [x] Koneksi database & migrasi berhasil (16 tabel, InnoDB, utf8mb4).
- [x] Migrasi berulang aman (migrasi lama di-skip).
- [x] Admin pertama dibuat via CLI; admin kedua ditolak tanpa `--force`.
- [x] Login admin → `/admin/dashboard`; CS → `/cs/dashboard`; Teknisi → `/teknisi/dashboard`; Pelanggan (Username PPPoE) → `/pelanggan/dashboard`.
- [x] Password salah ditolak; pesan error generik.
- [x] Akun nonaktif (`is_active=0`) ditolak dengan pesan khusus.
- [x] Tanpa session → diarahkan ke `/login` (302).
- [x] Peran salah mengakses dashboard lain → HTTP 403.
- [x] Logout (POST + CSRF) mengakhiri session; dashboard kembali terkunci.
- [x] Token CSRF salah pada login → ditolak.
- [x] SQL injection pada field login tidak berhasil.
- [x] Session ID diregenerasi setelah login (session fixation dicegah).
- [x] Pembatasan brute force aktif (percobaan ke-6 diblokir).
- [x] Password tersimpan sebagai bcrypt; 0 password non-hash.
- [x] Audit log mencatat login berhasil/gagal/diblokir, logout, dan pembuatan akun.

### Belum dikerjakan (sesuai batas Tahap 3)

- Statistik dashboard dinamis (masih placeholder dari Tahap 2).
- Form pengaduan, upload foto, nomor tiket.
- Verifikasi CS, penugasan teknisi, laporan perbaikan, penutupan tiket.
- Rating, notifikasi in-app nyata, audit operasional penuh.
- Ekspor PDF/Excel dan AI Assistant.

## Tahap 4 — Dashboard Dinamis (✅ selesai)

**Batasan yang dipatuhi:** tidak mengerjakan formulir pengaduan baru, verifikasi
CS, penugasan teknisi, laporan perbaikan, notifikasi, rating, AI Assistant, dan
ekspor PDF/Excel. Tombol dashboard hanya berupa navigasi ke halaman yang sudah
ada; tidak ada aksi palsu.

Seluruh statistik dan grafik dashboard kini diambil dari MySQL melalui PDO
prepared statement. Aplikasi **tidak** pernah membuat tiket dummy otomatis.

### Arsitektur (pemisahan tanggung jawab)

| Lapisan | Berkas | Peran |
|---|---|---|
| Controller | `app/Controllers/{Admin,Cs,Teknisi,Pelanggan}Controller.php` | Terima request, ambil data dari service, teruskan ke view |
| Service statistik | `app/Services/DashboardService.php` | Menyusun ringkasan statistik per peran |
| Model/repository | `app/Models/{Pengaduan,Penugasan,LaporanTeknisi,Kategori}.php` | Menjalankan seluruh query agregasi |
| View | `app/Views/{admin,cs,teknisi,pelanggan}/dashboard.php` | Hanya menampilkan data |

- `app/Helpers/functions.php` ditambah helper terpusat: `active_statuses()`,
  `status_is_active()`, `status_sum_active()`, `status_color()`,
  `status_chart_data()`, `trend_chart_data()`, `chart_data_script()`,
  `format_tanggal()`, `format_waktu()`, `bulan_singkat()` — mencegah duplikasi
  logika status dan format tanggal.
- Grafik memakai Chart.js (CDN) + `public/assets/js/dashboard.js`; data grafik
  dikirim server sebagai JSON `#wificare-chart-data` dan hanya dibaca klien.
- Layout `layouts/app.php` memuat Chart.js hanya pada halaman yang meminta
  (`includeCharts`), sehingga halaman lain tetap ringan.

### Sumber data tiap statistik

| Dashboard | Statistik | Sumber |
|---|---|---|
| Admin | Total Pengaduan | `COUNT(*) pengaduan` (`is_cancelled = 0`) |
| Admin | Tiket Aktif | status ∈ {menunggu_verifikasi, diproses_cs, menunggu_teknisi, dalam_penanganan} |
| Admin | Tiket Selesai / Ditolak | status `selesai` / `ditolak` |
| Admin | Grafik distribusi | `GROUP BY status` (6 status) |
| Admin | Grafik tren 14 hari | `GROUP BY DATE(created_at)` + rentang harian dilengkapi 0 |
| Admin | Pengaduan terbaru | `ORDER BY created_at DESC` (join kategori) |
| Admin | Kinerja teknisi | `COUNT(DISTINCT)` tugas per teknisi (total/aktif/selesai) |
| Admin | Aktivitas sistem | `audit_log` terbaru (join aktor) |
| CS | Menunggu Verifikasi / Diproses CS / Menunggu Teknisi / Selesai | `GROUP BY status` |
| CS | Antrian verifikasi | status `menunggu_verifikasi` (5 terbaru) |
| CS | Ringkasan teknisi & distribusi | query yang sama dengan Admin |
| Teknisi | Total tugas | `COUNT(DISTINCT pengaduan_id) penugasan WHERE teknisi_id = session` |
| Teknisi | Menunggu/Dalam Penanganan/Selesai | join `pengaduan`, filter `teknisi_id = session` |
| Teknisi | Kategori gangguan | `GROUP BY kategori` untuk tugas milik teknisi |
| Teknisi | Ringkasan pekerjaan | `laporan_teknisi` terbaru milik teknisi |
| Pelanggan | Total/Aktif/Selesai/Ditolak | `pengaduan WHERE pelanggan_id = session` |
| Pelanggan | Tiket terbaru & rincian status | `pengaduan` milik pelanggan (session) |

### Isolasi hak akses data

- Teknisi: seluruh query difilter `teknisi_id` dari **session** (`auth_user()`),
  bukan parameter URL/query string.
- Pelanggan: seluruh query difilter `pelanggan_id` dari relasi session
  (`users` → `pelanggan`), bukan ID dari query string.
- Membuka dashboard tidak mengubah status tiket maupun data apa pun.

### Data contoh (opsional, untuk pengujian)

- `bin/seed-sample.php` — mengisi tiket contoh lintas status (termasuk 1 tiket
  dibatalkan) beserta penugasan & laporan, plus akun uji tambahan
  (`tek.bayu`, `pppoe.agus`) untuk menguji isolasi.
  Opsi: `--force` (tambahkan walau sudah ada data), `--reset` (bersihkan lalu isi ulang).
  Menolak berjalan bila sudah ada data tanpa `--force`.

### Hasil pengujian (Tahap 4)

- [x] Lint PHP: 82 berkas, 0 error.
- [x] Empat dashboard dapat dibuka sesuai peran (HTTP 200).
- [x] Statistik tampil = hitungan database (Admin `10,6,3,1`; CS `2,1,2,3`; Teknisi andi `4,1,1,2`; Pelanggan rina `7,4,2,1`).
- [x] Grafik distribusi tampil = distribusi SQL (`2,1,2,1,3,1`) dan grafik tren memakai data asli.
- [x] Kondisi database kosong: statistik 0, grafik empty state, tabel "Belum ada pengaduan", tanpa error PHP (diuji sebelum data contoh diisi).
- [x] Teknisi andi tidak melihat tugas bayu, dan sebaliknya (isolasi per teknisi).
- [x] Pelanggan rina tidak melihat tiket agus, dan sebaliknya (isolasi per pelanggan); tidak ada kebocoran nomor tiket.
- [x] Akses antar-peran ditolak (HTTP 403); tanpa login diarahkan ke `/login` (302).
- [x] Tiket dibatalkan (`is_cancelled = 1`) tidak dihitung sebagai tiket aktif/total.
- [x] Membuka keempat dashboard tidak mengubah data (fingerprint tabel sebelum = sesudah).
- [x] Tidak ada warning/error PHP pada respons dashboard.
- [x] Chart.js dimuat hanya pada halaman dashboard; halaman lain tetap 200 tanpa Chart.js.
- [x] Aset `dashboard.js` (200) dan Chart.js CDN (200) dapat diakses.

### Belum dikerjakan (sesuai batas Tahap 4)

- Form pengaduan, upload foto, penomoran tiket otomatis.
- Verifikasi CS, penugasan teknisi, laporan perbaikan, penutupan tiket.
- Notifikasi in-app nyata, rating, ekspor PDF/Excel, AI Assistant.
- Halaman detail tugas sudah ada sebagai placeholder; tombol "Detail" hanya bernavigasi.

## Tahap 5 — Sistem Pengaduan Pelanggan (✅ selesai)

**Batasan yang dipatuhi:** tidak mengerjakan verifikasi CS, penugasan teknisi,
laporan perbaikan teknisi, notifikasi otomatis, rating, AI Assistant, dan ekspor
PDF/Excel. Fokus hanya pada alur pengaduan dari sisi pelanggan.

### Fitur yang dibangun

- Formulir pengaduan: nama & Username PPPoE (read-only dari profil), nomor
  WhatsApp (dapat diperbarui), kategori dari MySQL, deskripsi, dan foto bukti wajib.
- Penyimpanan tiket dengan transaksi database dan nomor tiket otomatis unik.
- Status awal otomatis **Menunggu Verifikasi** + catatan pada `riwayat_status`.
- Peringatan tiket aktif (tidak memblokir pembuatan tiket baru).
- Riwayat pengaduan: pencarian nomor tiket, filter status, filter tanggal,
  pagination server-side, dan empty state.
- Detail tiket: informasi lengkap, foto bukti, timeline status dari database,
  teknisi (bila ada), hasil penanganan (bila ada), dan penanda pembatalan.
- Integrasi Dashboard Pelanggan (total/aktif/terbaru menyesuaikan otomatis).

### Berkas baru / diubah

| Berkas | Status | Fungsi |
|---|---|---|
| `app/Services/PengaduanService.php` | baru | Validasi, unggah berkas, penyimpanan tiket dalam transaksi |
| `app/Services/Upload.php` | baru | Validasi & penyimpanan foto (MIME asli, nama acak, di luar document root) |
| `app/Services/ValidationException.php` | baru | Kesalahan validasi per-field (aman ditampilkan) |
| `app/Controllers/PengaduanFotoController.php` | baru | Menyajikan foto dengan pemeriksaan autentikasi & hak akses |
| `app/Models/Pengaduan.php` | diubah | Tambah: `createWithTicket`, `insertFoto`, `insertRiwayat`, `activeForPelanggan`, `paginateForPelanggan`, `fotosFor`, `riwayatFor`, `findById`, `findFoto`, `assignedTeknisiFor`, `laporanFor`, `isAssignedTo` |
| `app/Models/Kategori.php` | diubah | Tambah `findActive()` |
| `app/Models/Pelanggan.php` | diubah | Tambah `updateWhatsapp()` |
| `app/Controllers/PelangganController.php` | diubah | `buat`, `simpan`, `riwayat`, `detail` nyata; `tiket` dialihkan ke riwayat |
| `app/Views/pelanggan/{buat,riwayat,tiket}.php` | diubah | Formulir, riwayat server-side, detail tiket dari database |
| `app/Views/pelanggan/dashboard.php` | diubah | Tautan tiket menuju detail by ID |
| `app/Views/components/pagination.php` | diubah | Mode tautan server-side (`$baseUrl`) |
| `app/Helpers/menu.php` | diubah | Menu "Detail Tiket" tanpa ID dihapus |
| `routes/web.php` | diubah | Dukungan parameter `{id}` + rute `POST /pelanggan/pengaduan`, `GET /pelanggan/pengaduan/{id}`, `GET /pengaduan/foto/{id}` |
| `public/assets/js/main.js`, `style.css` | diubah | Pagination server-side, bilah filter, galeri foto, badge pembatalan |
| `storage/uploads/pengaduan/.gitkeep` | baru | Folder penyimpanan foto |

### Alur penyimpanan tiket

1. Pelanggan login → identitas diambil dari **session** (bukan `pelanggan_id`/`user_id` browser).
2. Validasi CSRF (`_token`).
3. Validasi server-side: nomor WhatsApp, kategori (ada & aktif di MySQL), deskripsi (10–2000 karakter), dan foto wajib.
4. Foto disimpan di `storage/uploads/pengaduan/` (di luar document root).
5. Transaksi dimulai: `INSERT pengaduan` → bentuk nomor tiket → `UPDATE nomor_tiket`
   → `INSERT pengaduan_foto` per berkas → `INSERT riwayat_status` (status awal)
   → sinkronkan nomor WhatsApp profil → `COMMIT`.
6. Bila ada langkah gagal → `ROLLBACK` + berkas terunggah dibersihkan.
7. Sukses → notifikasi + diarahkan ke halaman detail tiket.

### Mekanisme nomor tiket

- Format: `WFC-YYYYMMDD-XXXXXX` (contoh `WFC-20261016-000012`).
- Nomor dibentuk dari **ID auto-increment** tiket (bukan `COUNT(*) + 1`), sehingga
  aman dari benturan saat dua pengaduan masuk bersamaan.
- Pembentukan dilakukan **di dalam transaksi**: baris disisipkan dengan nomor
  sementara unik (`TMP-<random>`), lalu `UPDATE` ke nomor final setelah ID didapat.
- Dijamin unik oleh `UNIQUE KEY uq_pengaduan_tiket` pada kolom `nomor_tiket`.
- Pelanggan tidak dapat mengubah nomor tiket.

### Penyimpanan foto

- Lokasi: `storage/uploads/pengaduan/` (di luar `public/`), nama file acak 32 hex.
- Hanya JPG/PNG/WebP; MIME asli diperiksa `finfo` + `getimagesize`; ekstensi
  ditentukan dari MIME (bukan nama klien) sehingga file `.php`/executable ditolak.
- Batas 5 MB per berkas; file yang gagal tidak menyimpan tiket.
- Penyajian melalui `GET /pengaduan/foto/{id}` yang memeriksa login & hak akses:
  pelanggan hanya foto miliknya, teknisi hanya tiket yang ditugaskan, admin/CS semua.

### Hasil pengujian (Tahap 5)

- [x] Lint PHP seluruh berkas terkait: 0 error; `node --check main.js` OK.
- [x] Pelanggan dapat membuka formulir; kategori berasal dari MySQL; nama & PPPoE dari profil.
- [x] Pengaduan valid (JPG) tersimpan; nomor tiket format `WFC-YYYYMMDD-XXXXXX`.
- [x] Upload JPG, PNG, dan WebP berhasil (3 tiket, nomor tiket unik berbeda).
- [x] File PHP ditolak; file > 5 MB ditolak; tanpa foto ditolak; tanpa deskripsi ditolak (0 tiket tercipta).
- [x] Status awal `menunggu_verifikasi`; `riwayat_status` awal tercatat; referensi foto tersimpan.
- [x] Peringatan tiket aktif muncul; pelanggan tetap dapat membuat tiket baru.
- [x] Riwayat memuat daftar tiket + filter status/tanggal + pencarian; pagination server-side.
- [x] Detail tiket memuat nomor, foto (via endpoint), dan timeline status dari database.
- [x] Dashboard menampilkan tiket terbaru dan tautan buat pengaduan.
- [x] Isolasi: tiket pelanggan lain → 403; foto pelanggan lain → 403; foto sendiri → 200; tanpa login → 302.
- [x] Rollback: kegagalan di tengah transaksi (trigger uji) → tidak ada baris baru, berkas terunggah dibersihkan.
- [x] Berkas tersimpan di `storage/uploads/pengaduan/` dengan nama acak + ekstensi terkontrol; akses langsung ditolak.
- [x] Total pengujian otomatis: **41/41 PASS** (34 skenario fungsional + 7 skenario rollback/isolasi penyimpanan).
- [ ] Responsif smartphone: diverifikasi melalui CSS (grid, filter bar, galeri) — disarankan cek visual di perangkat.

### Belum dikerjakan (sesuai batas Tahap 5)

- Verifikasi CS, penugasan teknisi, laporan perbaikan, penutupan tiket.
- Notifikasi in-app nyata, rating, ekspor PDF/Excel, AI Assistant.

## Tahap 6 — Modul Customer Service (✅ selesai)

**Batasan yang dipatuhi:** tidak mengerjakan proses penanganan oleh teknisi,
upload foto hasil perbaikan, laporan penyelesaian teknisi, rating, AI Assistant,
notifikasi otomatis, dan ekspor PDF/Excel. Fokus hanya pada alur Customer Service.

### Fitur yang dibangun

- Daftar pengaduan nyata: nomor tiket, pelanggan, PPPoE, WhatsApp, kategori,
  tanggal, status, teknisi, dengan pencarian nomor/pelanggan/PPPoE, filter status,
  filter kategori, filter tanggal, dan pagination server-side.
- Detail pengaduan: identitas pelanggan, PPPoE, WhatsApp, kategori, deskripsi,
  foto bukti (via endpoint berotorisasi), tanggal, status, timeline riwayat,
  teknisi yang ditugaskan, riwayat penanganan, catatan internal, dan info pembatalan.
- **Verifikasi**: terima (`Menunggu Verifikasi → Diproses CS`) atau tolak dengan
  alasan wajib (`→ Ditolak`).
- **Penanganan langsung CS**: selesaikan tiket (`Diproses CS → Selesai`) dengan
  solusi wajib, tanpa mewajibkan laporan teknisi.
- **Penugasan teknisi manual** (`Diproses CS → Menunggu Teknisi`) ke akun teknisi
  aktif, dengan instruksi opsional; hanya satu penugasan aktif per tiket, histori
  penugasan tetap disimpan.
- **Monitoring**: ringkasan jumlah tiket per status + jumlah dibatalkan, filter
  status/teknisi/tanggal, pencarian, kolom tanggal dibuat & selesai.
- **Riwayat pelanggan**: daftar pelanggan (jumlah tiket, tiket aktif, status
  terakhir) dan riwayat tiket per pelanggan beserta penyelesaian sebelumnya.
- **Catatan internal** (`catatan_cs`): tambah, riwayat, nama petugas & waktu,
  tidak terlihat pelanggan, tidak mengubah status.
- **Pembatalan**: atribut terpisah dari status (bukan status ketujuh), alasan
  wajib, tercatat di audit log, histori status dipertahankan.

### Berkas baru / diubah

| Berkas | Status | Fungsi |
|---|---|---|
| `database/migrations/004_penyelesaian_cs.sql` | baru | Tabel `penyelesaian_cs` (solusi CS langsung, satu per tiket) |
| `app/Services/PengaduanFlowService.php` | baru | Validasi transisi status + eksekusi aksi CS (transaksi + `FOR UPDATE`) |
| `app/Models/CatatanCs.php` | baru | Simpan/baca catatan internal (per tiket & terbaru lintas tiket) |
| `app/Models/PenyelesaianCs.php` | baru | Simpan/baca solusi penyelesaian langsung CS |
| `app/Controllers/CsController.php` | diubah | Halaman baca + aksi tulis (terima/tolak/selesai/tugaskan/batal/catatan) |
| `app/Models/Pengaduan.php` | diubah | Tambah `lockRow`, `setStatus`, `setRejected`, `markCancelled`, `paginateForCs`, `countCancelled` |
| `app/Models/Penugasan.php` | diubah | Tambah `activeForPengaduan`, `deactivateForPengaduan`, `create`, `historyForPengaduan` |
| `app/Models/Pelanggan.php` | diubah | Tambah `paginateForCs`, `findForCs`, `ticketsForCs` |
| `app/Models/User.php` | diubah | Tambah `activeByRole()` (daftar teknisi aktif) |
| `app/Helpers/functions.php` | diubah | Tambah `status_badge_html()` (badge status + penanda batal) |
| `app/Views/cs/detail.php` | baru | Detail pengaduan + panel tindakan sesuai status |
| `app/Views/cs/pelanggan_detail.php` | baru | Riwayat pengaduan per pelanggan |
| `app/Views/cs/{pengaduan,verifikasi,penugasan,monitoring,pelanggan,catatan,dashboard}.php` | diubah | Halaman CS dinamis dari MySQL |
| `app/Views/pelanggan/tiket.php` | diubah | Menampilkan solusi CS pada bagian hasil penanganan |
| `app/Controllers/PelangganController.php` | diubah | Meneruskan data penyelesaian CS ke detail pelanggan |
| `routes/web.php` | diubah | Rute CS `{id}` + aksi POST; dukungan `role` berupa array (`cs`,`admin`) |
| `app/bootstrap.php` | diubah | Memuat model & service baru |
| `public/assets/css/style.css` | diubah | Gaya catatan internal, ringkasan chip, sel bertumpuk, panel tindakan |

### Alur verifikasi

1. CS membuka Pengaduan Masuk / Verifikasi → memilih tiket `Menunggu Verifikasi`.
2. Pada detail, klik **Terima Pengaduan** → `POST /cs/pengaduan/{id}/terima`.
3. CSRF diperiksa, petugas diambil dari session, `PengaduanFlowService::terima`
   mengunci baris (`FOR UPDATE`) lalu memvalidasi transisi `menunggu_verifikasi → diproses_cs`.
4. Status diubah, `riwayat_status` dicatat, dan `audit_log` (`pengaduan_diterima`) ditulis; commit.

### Alur penolakan

1. Pada tiket `Menunggu Verifikasi`, CS mengisi **Alasan penolakan** lalu klik **Tolak**.
2. Alasan wajib; bila kosong → `ValidationException`, status tidak berubah.
3. Transisi `menunggu_verifikasi → ditolak` disimpan bersama `alasan_penolakan`,
   riwayat, dan audit (`pengaduan_ditolak`). Tiket ditolak tidak diproses lagi.

### Penanganan langsung oleh CS

1. Tiket sudah diverifikasi (`Diproses CS`).
2. CS mengisi **Solusi / tindakan** lalu klik **Selesaikan Langsung**.
3. Solusi wajib; transisi `diproses_cs → selesai`; solusi disimpan di
   `penyelesaian_cs`, status, riwayat, dan audit ditulis dalam satu transaksi.
   Tiket selesai oleh CS tidak memerlukan laporan teknisi.

### Penugasan teknisi

1. Tiket `Diproses CS` → CS memilih teknisi **aktif** (role `teknisi`, `is_active=1`)
   dan instruksi opsional, lalu klik **Tugaskan Teknisi**.
2. Teknisi nonaktif / bukan role teknisi ditolak.
3. Transaksi: kunci baris → validasi `diproses_cs → menunggu_teknisi` → nonaktifkan
   penugasan lama (histori disimpan) → `INSERT penugasan_teknisi` → ubah status →
   riwayat + audit. Karena baris dikunci, klik ganda tidak menghasilkan dua penugasan.
4. Tugas langsung terbaca oleh dashboard teknisi (membaca penugasan aktif).

### Mekanisme validasi status

- Sumber tunggal `PengaduanFlowService::ALLOWED`:
  `menunggu_verifikasi → {diproses_cs, ditolak}` dan
  `diproses_cs → {menunggu_teknisi, selesai}`.
- `assertTransition()` menolak transisi lain (mis. memproses tiket yang sudah
  ditolak/dibatalkan/diproses ganda).
- Setiap aksi berjalan dalam transaksi dengan `SELECT ... FOR UPDATE` pada baris
  pengaduan, sehingga status terkini di database selalu menjadi dasar keputusan.
- Tombol di UI hanya muncul untuk transisi sah, tetapi backend tetap memvalidasi ulang.

### Hasil pengujian (Tahap 6)

- [x] Lint PHP seluruh berkas: 0 error.
- [x] CS dapat membuka seluruh halaman (dashboard, pengaduan, verifikasi, penugasan, monitoring, pelanggan, catatan, detail, riwayat pelanggan) — 200, tanpa error PHP.
- [x] CS menerima pengaduan → status `diproses_cs`, riwayat & audit tercatat.
- [x] CS menolak tanpa alasan → ditolak (status tetap); dengan alasan → `ditolak` + alasan tersimpan.
- [x] CS menyelesaikan langsung tanpa solusi → ditolak; dengan solusi → `selesai` + `penyelesaian_cs` tersimpan.
- [x] Penugasan ke teknisi nonaktif & non-teknisi ditolak; ke teknisi aktif → `menunggu_teknisi` + penugasan aktif.
- [x] Penugasan ganda dicegah (tetap satu penugasan aktif; status tidak berubah).
- [x] Tugas muncul pada dashboard teknisi yang ditugaskan.
- [x] Pembatalan tanpa alasan ditolak; dengan alasan → `is_cancelled=1`, status utama tidak berubah, audit tercatat.
- [x] Tiket dibatalkan tidak dapat diproses melalui alur normal (terima ditolak).
- [x] Catatan internal tersimpan; **tidak terlihat** pelanggan (teks & label tidak muncul di halaman pelanggan).
- [x] RBAC: pelanggan & teknisi ditolak akses halaman CS (403); CS diizinkan; admin diizinkan pada operasi tulis.
- [x] CSRF: POST tanpa token → 419 dan status tidak berubah.
- [x] Audit & riwayat tercatat untuk seluruh aksi penting.
- [x] Total pengujian otomatis: **55/55 PASS**.
- [ ] Responsif smartphone: diverifikasi lewat CSS (grid, filter bar, tabel) — disarankan cek visual di perangkat.

### Belum dikerjakan (sesuai batas Tahap 6)

- Proses penanganan oleh teknisi, upload foto hasil perbaikan, dan laporan penyelesaian teknisi.
- Rating pelanggan, AI Assistant, notifikasi otomatis, ekspor PDF/Excel.

## Tahap 7 — Modul Teknisi (✅ selesai)

**Batasan yang dipatuhi:** tidak mengerjakan rating pelanggan, manajemen akun
admin, notifikasi otomatis, AI Assistant, ekspor PDF/Excel, dan fitur di luar
modul Teknisi. Fokus pada alur teknisi sampai tiket selesai.

### Fitur yang dibangun

- **Dashboard Teknisi** (data nyata, difilter per teknisi dari session): total
  tugas ditugaskan, jumlah Menunggu Teknisi, Dalam Penanganan, Selesai, tugas
  terbaru, ringkasan kategori, grafik status, tombol Lihat Tugas & Riwayat.
- **Tugas Saya**: nomor tiket, pelanggan, Username PPPoE, kategori, alamat,
  WhatsApp, tanggal pengaduan, tanggal penugasan, status, tombol detail; dengan
  pencarian nomor/pelanggan, filter status, kategori, rentang tanggal, pagination
  server-side, dan empty state.
- **Detail Tugas**: identitas pelanggan, PPPoE, WhatsApp, alamat, kategori,
  deskripsi, foto bukti (via endpoint berotorisasi), tanggal pengaduan, instruksi
  penugasan dari CS, tanggal penugasan, status, timeline, dan hasil laporan.
  Catatan internal CS tidak dimuat.
- **Hubungi Pelanggan**: tombol WhatsApp (`https://wa.me/<nomor>`) dengan nomor
  dinormalisasi ke format internasional (mis. `0812...` → `62812...`) dan pesan
  ter-URL-encode; jika nomor tidak valid, ditampilkan pesan dan tanpa tautan rusak.
- **Mulai Penanganan** (`Menunggu Teknisi → Dalam Penanganan`): validasi session,
  RBAC, kepemilikan tugas aktif, status, dan pembatalan; `started_at` dicatat;
  riwayat status + audit ditulis dalam satu transaksi.
- **Form Laporan Perbaikan**: penyebab (wajib), tindakan (wajib), catatan
  (opsional), dan foto bukti setelah perbaikan (wajib, JPG/PNG/WebP, maks 5 MB).
- **Penyelesaian otomatis** (`Dalam Penanganan → Selesai`): laporan + referensi
  foto disimpan lebih dahulu; status `selesai` baru diubah setelah semuanya
  berhasil; jika gagal → rollback, status tetap, berkas yatim dibersihkan.
- **Riwayat Pekerjaan**: nomor tiket, pelanggan, kategori, tanggal penugasan,
  mulai, selesai, penyebab, tindakan, status; dengan pencarian, filter kategori &
  tanggal, pagination, dan tombol detail.

### Berkas baru / diubah

| Berkas | Status | Fungsi |
|---|---|---|
| `app/Controllers/TeknisiController.php` | diubah | Halaman baca nyata + aksi mulai penanganan & kirim laporan |
| `app/Controllers/LaporanFotoController.php` | baru | Menyajikan foto hasil perbaikan dengan pemeriksaan hak akses |
| `app/Services/PengaduanFlowService.php` | diubah | Peta transisi dipisah per pelaku (CS vs Teknisi); tambah `mulaiPenanganan` & `selesaikanDenganLaporan` |
| `app/Services/Upload.php` | diubah | Dukungan direktori tujuan (`storage/uploads/laporan/`) |
| `app/Models/Penugasan.php` | diubah | Tambah `activeForTeknisi`, `markStarted`, `paginateForTeknisi` |
| `app/Models/LaporanTeknisi.php` | diubah | Tambah `existsForPengaduan`, `findByPengaduan`, `create`, `insertFoto`, `fotosFor`, `findFoto`, `paginateHistoryForTeknisi` |
| `app/Models/Pengaduan.php` | diubah | Tambah `findForTeknisi` (query ber-scope kepemilikan) |
| `app/Helpers/functions.php` | diubah | Tambah `whatsapp_number()` & `whatsapp_link()` |
| `app/Helpers/menu.php` | diubah | Menu teknisi: Dashboard, Tugas Saya, Riwayat Pekerjaan |
| `app/Views/teknisi/{dashboard,tugas,detail,laporan,riwayat}.php` | diubah | Halaman teknisi dinamis dari MySQL |
| `app/Views/pelanggan/tiket.php` | diubah | Menampilkan foto hasil perbaikan |
| `app/Views/cs/detail.php` | diubah | Menampilkan foto hasil perbaikan |
| `app/Controllers/PelangganController.php`, `app/Controllers/CsController.php` | diubah | Meneruskan laporan + foto hasil perbaikan |
| `routes/web.php` | diubah | Rute teknisi `{id}` (detail/mulai/laporan) + `GET /laporan/foto/{id}` |
| `storage/uploads/laporan/.gitkeep` | baru | Folder penyimpanan foto hasil perbaikan |

### Alur teknisi menerima tugas

1. CS menugaskan tiket (`Diproses CS → Menunggu Teknisi`) — lihat Tahap 6.
2. Dashboard & Tugas Saya teknisi membaca penugasan **aktif** miliknya
   (`Penugasan::latestForTeknisi` / `paginateForTeknisi`, difilter `teknisi_id`
   dari session) sehingga hanya tiket miliknya yang tampil.
3. Membuka detail: `Pengaduan::findForTeknisi($id, $teknisiId)` mengembalikan
   data hanya bila ada penugasan aktif milik teknisi; jika bukan → HTTP 403.

### Proses mulai penanganan

1. Teknisi klik **Mulai Penanganan** → `POST /teknisi/tugas/{id}/mulai`.
2. Middleware RBAC (`teknisi`) + CSRF diverifikasi; kepemilikan tugas dicek.
3. `PengaduanFlowService::mulaiPenanganan` mengunci baris (`FOR UPDATE`),
   memvalidasi penugasan aktif milik teknisi dan transisi
   `menunggu_teknisi → dalam_penanganan`, mencatat `started_at`, mengubah status,
   menulis `riwayat_status` + `audit_log`, lalu commit.

### Penyimpanan laporan perbaikan

1. Form hanya dapat dibuka saat status `Dalam Penanganan` (dan belum ada laporan).
2. Pasca-CSRF: validasi teks (penyebab & tindakan wajib, maks 2000 karakter),
   catatan opsional, dan minimal satu foto.
3. Pra-validasi kepemilikan + status (agar permintaan tak sah tidak menulis berkas).
4. Foto disimpan ke `storage/uploads/laporan/` (di luar document root) sebelum transaksi.
5. Di dalam transaksi + kunci baris: validasi ulang kepemilikan, status, dan cegah
   laporan ganda; `INSERT laporan_teknisi` + `INSERT laporan_foto` per berkas;
   status diubah ke `selesai`; `riwayat_status` + `audit_log` ditulis; commit.
6. Jika ada kegagalan → rollback, status tetap `dalam_penanganan`, berkas yatim dihapus.

### Mekanisme penyelesaian otomatis

- Status **hanya** berubah menjadi `Selesai` setelah baris laporan dan seluruh
  referensi foto berhasil disimpan di dalam satu transaksi.
- Laporan ganda dicegah berlapis: pra-validasi, pemeriksaan di dalam transaksi,
  `UNIQUE KEY uq_laporan_pengaduan`, dan kunci baris `FOR UPDATE`.

### Keamanan upload foto

- Hanya JPG/PNG/WebP; MIME asli diperiksa (`finfo` + `getimagesize`); ekstensi
  ditentukan dari MIME (bukan nama klien) sehingga `.php`/executable ditolak.
- Nama file acak 32 hex; maksimal 5 MB per berkas; disimpan di
  `storage/uploads/laporan/` (di luar document root).
- Disajikan melalui `GET /laporan/foto/{id}` dengan pemeriksaan hak akses:
  pelanggan pemilik tiket, teknisi pembuat laporan, atau admin/CS.

### Hasil pengujian (Tahap 7)

- [x] Lint PHP seluruh berkas: 0 error.
- [x] Teknisi dapat login; dashboard menampilkan tugas miliknya dari MySQL.
- [x] Teknisi tidak dapat melihat/membuka tugas teknisi lain (403).
- [x] Detail tugas dapat dibuka; foto gangguan dapat dilihat teknisi berwenang
  (200) dan ditolak untuk bukan pemilik (403).
- [x] Tombol WhatsApp memakai nomor valid (`https://wa.me/6281200000002`).
- [x] Mulai penanganan berhasil; status menjadi `Dalam Penanganan`; `riwayat_status` tercatat.
- [x] Laporan tanpa penyebab / tanpa tindakan / tanpa foto → ditolak, status tidak berubah.
- [x] Upload PNG berhasil; laporan + referensi foto tersimpan (JPG/PNG/WebP didukung).
- [x] File berbahaya (`.php`) ditolak; file terlalu besar ditolak.
- [x] Laporan lengkap berhasil disimpan; status otomatis menjadi `Selesai`.
- [x] Laporan ganda dicegah (tetap satu laporan; status tidak berubah).
- [x] Kegagalan simpan tidak mengubah status menjadi `Selesai`.
- [x] CS melihat hasil perbaikan; pelanggan melihat status & hasil terbaru; dashboard admin diperbarui.
- [x] Tiket milik teknisi lain / dibatalkan tidak dapat diproses (403).
- [x] Audit log tercatat (`penanganan_dimulai`, `laporan_perbaikan_dikirim`).
- [x] RBAC & CSRF: pelanggan ke area teknisi → 403; teknisi ke area CS → 403;
  tanpa login → 302; POST tanpa CSRF → 419.
- [x] Total pengujian otomatis: **31/31 PASS**.
- [ ] Responsif smartphone: diverifikasi lewat CSS (grid, filter bar, tabel, galeri)
  — disarankan cek visual di perangkat.

### Belum dikerjakan (sesuai batas Tahap 7)

- Rating pelanggan, manajemen akun Admin, notifikasi otomatis, AI Assistant,
  ekspor PDF/Excel, dan fitur lain di luar modul Teknisi.

## Tahap 8 — Manajemen Admin, Notifikasi, Rating & Audit (✅ selesai)

**Batasan yang dipatuhi:** tidak mengerjakan ekspor PDF/Excel, AI Assistant,
integrasi WhatsApp API, atau email otomatis. Tidak ada data dummy — seluruh
angka berasal dari MySQL. Rating bersifat **sekali per tiket** (kebijakan PRD
bisu) dan didokumentasikan di sini.

### Fitur yang dibangun

- **Manajemen pengguna (Admin):** daftar akun + pencarian + filter peran/status +
  pagination; tambah akun (admin/CS/teknisi), ubah, aktif/nonaktif, dan reset
  kata sandi (di-hash). Akun pelanggan dibuat melalui Data Pelanggan (bukan form
  ini) agar `users` + `pelanggan` konsisten.
- **Manajemen pelanggan (Admin):** tambah pelanggan + akun login (Username PPPoE),
  ubah profil (nama/PPPoE/WhatsApp/alamat), aktif/nonaktif, dan halaman detail
  berisi riwayat tiket pelanggan. Password jaringan PPPoE tidak pernah disimpan.
- **Manajemen kategori (Admin):** tambah/ubah (nama + deskripsi), aktif/nonaktif
  (soft), daftar + pencarian + pagination. Tidak ada penghapusan destruktif.
- **Monitoring seluruh tiket (Admin):** nomor, pelanggan, PPPoE, kategori, status,
  teknisi, tanggal dibuat/selesai, info batal/arsip, tombol detail; pencarian +
  filter status/kategori/teknisi/tanggal + pagination.
- **Pembatalan tiket (Admin + CS):** wajib alasan; mencatat pelaku & waktu,
  `audit_log`, memicu notifikasi; tiket batal tidak dapat diproses melalui alur
  normal. Pembatalan tetap **bukan** status ketujuh.
- **Pengarsipan (khusus Admin):** penanda `archived_at`/`archived_by` terpisah,
  mencatat pelaku & waktu + audit; hanya tiket non-aktif (selesai/ditolak/dibatalkan)
  yang dapat diarsipkan; riwayat tetap tersimpan.
- **Monitoring kinerja teknisi (Admin):** tugas ditugaskan/aktif/selesai,
  rata-rata waktu penanganan, riwayat pekerjaan, dan rating — semuanya query MySQL.
- **Pengaturan aplikasi (Admin):** nama aplikasi, nama ISP, email/telepon/WhatsApp
  kontak, batas ukuran upload (validasi 1–50 MB); perubahan penting dicatat ke
  audit. Kredensial/API key **tidak pernah** disimpan atau ditampilkan.
- **Notifikasi in-app:** tabel `notifikasi` + lonceng header (jumlah belum dibaca,
  daftar terbaru, tandai dibaca, tautan ke tiket sesuai peran) dan halaman daftar
  ber-pagination. Dipicu konsisten dalam transaksi perubahan tiket: tiket baru →
  CS + Admin; diverifikasi/ditolak → Pelanggan; penugasan → Teknisi + Pelanggan;
  mulai penanganan/selesai → CS + Pelanggan; dibatalkan → pelanggan + teknisi aktif
  + CS. Tidak ada pengiriman WhatsApp/email otomatis.
- **Rating layanan:** bintang 1–5 + ulasan opsional; hanya pemilik tiket berstatus
  `selesai`; tiket batal tidak dapat dinilai; maksimum satu rating per tiket
  (`UNIQUE pengaduan_id`); backend memvalidasi 1–5 dan `pelanggan_id` selalu dari
  session. Ringkasan rating tampil pada laporan Admin dan halaman kinerja teknisi.
- **Audit log (Admin):** mencatat aktor, aksi, objek + ID, waktu, dan perubahan
  relevan (login sukses/gagal, pembuatan/perubahan/penonaktifan akun, reset
  password, perubahan pelanggan/kategori, verifikasi/penolakan/penugasan/mulai/
  selesai, pembatalan, pengarsipan, perubahan pengaturan). Halaman audit
  ber-pencarian/filter/pagination, **tidak** dapat diubah/dihapus dari UI, dan
  tidak pernah mencatat password/token.

### Perubahan skema database

| Objek | Perubahan |
|---|---|
| `kategori_gangguan.deskripsi` | kolom baru `varchar(255)` nullable (migrasi `005_tahap8.sql`) |
| `idx_pengaduan_archived` | indeks baru pada `pengaduan.archived_at` |
| `pengaturan` seed | diubah `INSERT IGNORE` agar perubahan Admin tidak tertimpa saat migrasi ulang; tambah kunci `isp_name`, `contact_email`, `contact_phone` |

### Berkas baru / diubah

| Berkas | Status | Fungsi |
|---|---|---|
| `database/migrations/005_tahap8.sql` | baru | Kolom `deskripsi` kategori + indeks arsip |
| `database/seeds/pengaturan.sql` | diubah | `INSERT IGNORE` + kunci pengaturan baru |
| `app/Models/{Notifikasi,Rating,Pengaturan}.php` | baru | Notifikasi, rating, konfigurasi |
| `app/Models/{Kategori,User,Pelanggan,Pengaduan,Penugasan,AuditLog}.php` | diubah | Metode admin (list/filter/pagination/performa) |
| `app/Services/{NotificationService,UserService,PelangganService,KategoriService,PengaturanService,ArsipService,RatingService}.php` | baru | Logika tulis + validasi |
| `app/Services/{PengaduanService,PengaduanFlowService,DashboardService}.php` | diubah | Pemanggilan notifikasi dalam transaksi + ringkasan admin |
| `app/Controllers/AdminBaseController.php` | baru | Basis admin (CSRF/validasi/flash) |
| `app/Controllers/Admin{User,Pelanggan,Kategori,Pengaturan,Audit}Controller.php` | baru | Modul admin terpisah |
| `app/Controllers/{AdminController,NotifikasiController,PelangganController,CsController}.php` | diubah | Monitoring/arsip, notifikasi, rating, redirect per peran |
| `app/Views/admin/{pengguna,pelanggan,pelanggan_detail,kategori,pengaduan,pengaduan_detail,teknisi,teknisi_detail,laporan,audit,pengaturan}.php` | baru/ubah | Halaman admin |
| `app/Views/notifikasi/index.php` | baru | Daftar notifikasi |
| `app/Views/pelanggan/{rating,tiket}.php` | baru/ubah | Rating pelanggan |
| `app/Views/components/header.php`, `layouts/app.php` | diubah | Lonceng notifikasi data-driven + meta CSRF |
| `routes/web.php`, `app/bootstrap.php` | diubah | Rute & pemuat model/service baru |
| `public/assets/css/style.css`, `js/main.js` | diubah | Gaya chip/form-grid/notifikasi/rating/audit + submit modal |

### Hasil pengujian (Tahap 8)

Pengujian otomatis melalui HTTP nyata + verifikasi database (67 skenario):

- [x] Login keempat peran; RBAC: pelanggan→`/admin/*` 403, teknisi→`/cs/*` 403,
  CS→`/teknisi/*` 403; tamu diarahkan ke login; POST tanpa CSRF → 419.
- [x] Admin membuat akun CS; akun baru dapat login; username duplikat ditolak;
  ubah akun; reset password (password baru bekerja, lama ditolak).
- [x] Aktif/nonaktif akun bekerja; akun nonaktif tidak dapat login.
- [x] Peran admin aktif terakhir tidak dapat diturunkan; admin tidak dapat
  menonaktifkan dirinya; tidak ada rute penghapusan akun.
- [x] Admin membuat pelanggan + akun (login via PPPoE) dan memperbaruinya; detail 200.
- [x] Kategori: tambah/ubah/nonaktif/aktif; kategori nonaktif tidak tampil pada
  form pengaduan, kategori aktif tampil.
- [x] Monitoring pengaduan: daftar/filter/pagination/detail (200).
- [x] Pengarsipan: tiket selesai terarsip + audit; tiket aktif ditolak arsip.
- [x] Pembatalan CS/Admin: alasan + pelaku + audit tersimpan; tiket batal tidak
  diproses lanjut; pembatalan tanpa alasan ditolak.
- [x] Notifikasi: tiket baru → CS + Admin; lonceng menampilkan jumlah; tandai
  dibaca & tandai semua; CS tidak dapat menandai notifikasi Admin (IDOR).
- [x] Rating: tiket selesai dapat dinilai (audit tercatat); rating kedua ditolak;
  di luar 1–5 ditolak; tiket belum selesai ditolak; pelanggan lain ditolak.
- [x] Admin: monitoring teknisi + detail kinerja 200; laporan menampilkan ringkasan rating.
- [x] Pengaturan tersimpan + audit; `upload_max_mb` tidak valid ditolak.
- [x] Halaman audit 200 + filter aksi; halaman notifikasi 200.
- [x] Total pengujian otomatis: **67/67 PASS**.
- [ ] Responsif smartphone: diverifikasi lewat CSS — disarankan cek visual di perangkat.

### Belum dikerjakan (sesuai batas Tahap 8)

- Ekspor laporan PDF/Excel, AI Assistant, integrasi WhatsApp API, dan email otomatis.
- Tahap 9 dan seterusnya (belum dikerjakan atas instruksi).

## Tahap 9 — Laporan (PDF/Excel), Analitik & AI Assistant (✅ selesai)

### Fitur yang dibangun

**A. Laporan & Analitik (9 jenis laporan, menyesuaikan PRD §10).**

| `jenis` | Laporan | Isi |
|---|---|---|
| `semua` | Seluruh Pengaduan | Daftar tiket + status, teknisi, rating; pagination server-side |
| `periode` | Pengaduan per Periode | Rekap harian/mingguan/bulanan + grafik tren |
| `status` | Pengaduan per Status | Distribusi 6 status resmi + persentase + donat |
| `teknisi` | Kinerja Teknisi | Beban tugas, aktif, selesai, rata-rata durasi, rata-rata rating |
| `kategori` | Kategori Gangguan | Sebaran per kategori + grafik batang |
| `pelanggan` | Riwayat Pelanggan | Rekap per pelanggan (total/aktif/selesai/ditolak/batal/rating) |
| `durasi` | Waktu Penyelesaian | Durasi tiap tiket selesai + tercepat/terlama/median |
| `rating` | Rating & Ulasan | Daftar ulasan + distribusi bintang + tren rating |
| `analitik` | Grafik & Ringkasan | Ringkasan metrik + seluruh grafik analitik |

Filter (server-side, tervalidasi): rentang tanggal (`date_from`/`date_to`), status,
kategori, teknisi, pelanggan, status pembatalan, status arsip, dan kelompok periode.
Tanggal awal > tanggal akhir ditolak dengan pesan; tanggal tidak valid diabaikan.

Grafik memakai **Chart.js** dengan **data nyata** dari MySQL (tanpa data dummy).

**B. AI Assistant (menyesuaikan PRD §8).**
- Antarmuka chat untuk semua peran: area percakapan, input, tombol kirim,
  indikator memuat, pesan error, pertanyaan cepat, auto-scroll, responsif,
  pencegahan input kosong.
- Alur: frontend → endpoint PHP (`POST /ai/chat`) → service AI → API provider →
  JSON → frontend. **Kunci API hanya di server** (`.env`), tidak pernah di JS.
- Kemampuan: panduan penggunaan aplikasi, troubleshooting WiFi dasar, FAQ,
  panduan pembuatan pengaduan, pemeriksaan status tiket (dengan RBAC), dan
  pengarahan ke CS.
- Tool calling dengan daftar putih: `get_ticket_status`, `get_app_usage_guide`,
  `get_wifi_troubleshooting_guide`, `get_faq`. Argumen divalidasi; tool di luar
  daftar ditolak.
- Perlindungan: timeout, penanganan error, batas panjang pesan, rate limit per
  sesi, CSRF, escape output, dan perlindungan prompt injection. Riwayat chat
  **tidak** disimpan.
- Bila kunci API belum diisi, halaman menampilkan "Layanan AI belum tersedia".

### Definisi metrik & rumus (satu sumber: `LaporanService::metrik`)

| Metrik | Rumus |
|---|---|
| Total | `COUNT(*)` semua tiket dalam lingkup filter (termasuk dibatalkan) |
| Aktif | empat status aktif (`menunggu_verifikasi`, `diproses_cs`, `menunggu_teknisi`, `dalam_penanganan`) **dan** `is_cancelled = 0` |
| Selesai / Ditolak | tiket berstatus `selesai` / `ditolak` |
| Dibatalkan | `is_cancelled = 1` (penanda, **bukan** status ketujuh) |
| Persentase selesai | `selesai / (total − dibatalkan) × 100` |
| Rata-rata penyelesaian | `AVG(TIMESTAMPDIFF(MINUTE, created_at, COALESCE(laporan_teknisi.completed_at, penyelesaian_cs.completed_at)))` **hanya** tiket selesai; tiket belum selesai/NULL tidak dihitung 0 |
| Rata-rata rating | `AVG(rating_layanan.rating)` |

Anti-double-count: semua agregat memakai `COUNT(DISTINCT p.id)` dengan klausa JOIN
bersama, sehingga tidak ada penggandaan dari relasi penugasan/laporan/rating.

### Perpustakaan & kompatibilitas

Aplikasi tetap **PHP Native tanpa Composer/framework**. Karena ekspor harus berupa
PDF asli dan `.xlsx` asli tanpa ketergantungan eksternal, dibuat penulis berkas
native di dalam proyek:

- `app/Services/PdfReport.php` — penulis PDF minimal (font inti Helvetica,
  `/Encoding /WinAnsiEncoding`, tabel dengan pembungkusan teks, ringkasan,
  filter, periode, waktu pembuatan, "Halaman X dari Y").
- `app/Services/XlsxWriter.php` — penulis OOXML `.xlsx` asli via `ZipArchive`
  (`inlineStr`, lebar kolom, format tanggal `yyyy-mm-dd hh:mm`, angka `#,##0`,
  persen, gaya header). Teks pengguna selalu `inlineStr` → **tidak** dieksekusi
  sebagai formula (pencegahan formula injection).

### Berkas baru / diubah

| Berkas | Status | Fungsi |
|---|---|---|
| `config/app.php`, `.env`, `.env.example` | diubah | Bagian konfigurasi `ai` (`AI_API_KEY`,`AI_MODEL`,`AI_API_BASE_URL`,`AI_TIMEOUT`,`AI_MAX_MESSAGE`,`AI_RATE_LIMIT`,`AI_RATE_WINDOW`,`AI_MAX_TOOL_ROUNDS`) |
| `app/Models/Laporan.php` | baru | Repositori analitik (prepared statement, `COUNT(DISTINCT)`, filter bersama) |
| `app/Services/LaporanService.php` | baru | Validasi filter, metrik, dataset 9 laporan, tabel ekspor |
| `app/Services/PdfReport.php`, `XlsxWriter.php` | baru | Penulis PDF & XLSX native |
| `app/Services/{AiKnowledge,AiToolService,AiRateLimiter,AiAssistantService}.php` | baru | Basis pengetahuan, tool terkontrol, rate limit, pemanggilan provider |
| `app/Controllers/AdminLaporanController.php` | baru | Halaman laporan + `pdf` + `excel` |
| `app/Controllers/AiController.php` | baru | Halaman chat + endpoint `POST /ai/chat` |
| `app/Models/Pengaduan.php` | diubah | `findByNumber`, `assignedToAny` (dukungan AI) |
| `app/Controllers/PelangganController.php` | diubah | `/pelanggan/ai` dialihkan ke `/ai` |
| `app/Helpers/menu.php` | diubah | Menu AI Assistant untuk semua peran |
| `app/Views/admin/laporan.php` | ditulis ulang | Tab 9 laporan, filter, kartu metrik, grafik, tabel, tombol ekspor |
| `app/Views/ai/index.php` | baru | Antarmuka chat AI |
| `app/Views/layouts/app.php` | diubah | Memuat `ai.js` bila `$includeAi` |
| `public/assets/js/{dashboard,ai}.js`, `css/style.css` | diubah | Grafik batang/tren/grid + chat; gaya Tahap 9 |
| `routes/web.php`, `app/bootstrap.php` | diubah | Rute laporan/AI + pemuat kelas baru |

### Perubahan skema database

Tidak ada migrasi baru. Rate limit AI memakai session; riwayat chat tidak disimpan.

### Cara ekspor

- **PDF**: buka `/admin/laporan`, pilih jenis + filter, klik **Ekspor PDF**
  (atau akses `/admin/laporan/pdf?jenis=...&<filter>`). Diperuntukkan **Admin**
  saja; berkas dibuat langsung (tanpa penyimpanan publik permanen) dan memuat
  judul, nama ISP, periode, waktu dibuat, filter, ringkasan, tabel, dan nomor halaman.
- **Excel**: klik **Ekspor Excel** (`/admin/laporan/excel?...`). Menghasilkan
  `.xlsx` asli dengan header jelas, format tanggal, lebar kolom, angka sebagai
  angka, dan format persen.

### Arsitektur & konfigurasi AI

- Isi di `.env` (jangan di `.env.example`/frontend):
  `AI_API_KEY`, `AI_MODEL`, `AI_API_BASE_URL` (default `https://api.openai.com/v1`),
  `AI_TIMEOUT` (detik), `AI_MAX_MESSAGE`, `AI_RATE_LIMIT`, `AI_RATE_WINDOW`,
  `AI_MAX_TOOL_ROUNDS`.
- Kosongkan `AI_API_KEY`/`AI_MODEL` untuk menonaktifkan AI (UI menampilkan
  "belum tersedia").
- Butuh koneksi internet **walau berjalan di localhost**, karena permintaan
  diteruskan ke API provider dari sisi server.

### Cara menguji AI

1. Isi `AI_API_KEY`/`AI_MODEL` di `.env`, lalu buka `/ai` dan kirim pertanyaan.
2. Pemeriksaan status tiket: kirim nomor tiket berformat `WFC-YYYYMMDD-XXXXXX`.
   Otorisasi backend menegakkan pelanggan (hanya tiket sendiri), teknisi (hanya
   yang ditugaskan), CS/Admin sesuai PRD; bila tidak berhak, balasan tidak
   membocorkan keberadaan tiket.
3. Untuk pengujian tanpa kunci asli, dipakai **mock provider** lokal (`php -S`)
   sebagai test double; `.env` diarahkan sementara ke mock lalu dikembalikan.

### Hasil pengujian (Tahap 9)

Semua pengujian dijalankan nyata (HTTP + verifikasi database + CLI); tidak ada
klaim tanpa bukti.

- [x] 9 jenis laporan tampil (HTTP 200) dan **semuanya** diekspor ke PDF asli
  (`%PDF-`) dan `.xlsx` asli (tanda `PK`): **33/33 PASS** (skrip `t9-report-http`).
- [x] Akurasi metrik vs SQL langsung, anti-double-count, filter status/pembatalan,
  validasi tanggal, XLSX anti-formula-injection, PDF asli: **47/47 PASS**
  (skrip `t9-report-cli`).
- [x] AI via mock provider: chat, kunci API tidak bocor, output aman (`textContent`),
  error API, timeout, tool calling, rate limit (429), CSRF (419), RBAC tiket
  (pelanggan & teknisi), AI tidak dapat mengubah status, prompt injection tidak
  membocorkan/mengubah data, responsif: **20/20 PASS** (skrip `t9-ai-test`).
- [x] RBAC tool AI & allowlist: **15/15 PASS** (skrip `t9-ai-cli`).
- [x] Regresi seluruh modul sebelumnya (admin/cs/teknisi/pelanggan + aset + alih
  rute lama): **48/48 PASS** (skrip `t9-regression`).
- [x] Lint seluruh berkas PHP: **123 berkas, 0 error**; `node --check` JS OK.
- [ ] Responsif smartphone: diverifikasi lewat CSS — disarankan cek visual di perangkat.

### Belum dikerjakan (sesuai batas Tahap 9)

- Integrasi WhatsApp API dan email otomatis (di luar cakupan PRD tahap ini).
- Penyimpanan riwayat chat AI (sengaja tidak dibuat pada v1.0).

## Tahap 10 — Audit, Perbaikan Bug, Pengerasan Keamanan & Finalisasi (✅ selesai)

Audit menyeluruh seluruh Tahap 1–9, perbaikan bug, pengerasan keamanan, dan
finalisasi dokumentasi. Laporan lengkap: `AUDIT_REPORT.md` dan `TEST_REPORT.md`.

**Batasan yang dipatuhi:** tidak menambah fitur di luar PRD, tidak membangun
ulang, tidak menghapus fitur yang berjalan, tetap PHP Native (tanpa framework),
tidak menghapus data, tidak melemahkan RBAC/validasi.

### Ringkasan temuan & perbaikan

| ID | Prioritas | Temuan | Perbaikan |
|---|---|---|---|
| A4 | P1 | View `app/Views/admin/pelanggan_detail.php` hilang → halaman "Riwayat Pelanggan" admin (`/admin/pelanggan/{id}`) rusak | Membuat view `admin/pelanggan_detail.php` (tautan detail ke `/admin/pengaduan/{id}`) + pemindaian memastikan 0 view hilang |
| A1 | P2 | Pengguna yang dinonaktifkan saat sesi berjalan menerima HTTP 403, bukan dialihkan ke login | `AuthMiddleware::auth()` kini memvalidasi `Auth::user()`; sesi tidak valid → `redirect('/login')` |
| A2 | P2 | `bin/seed-sample.php --reset` gagal FK (`penyelesaian_cs` tidak dihapus) | Tambah `DELETE FROM penyelesaian_cs` sebelum `pengaduan` |
| A3 | P3 | Komentar `Session` menjanjikan batas **idle**, implementasi batas absolut | Tambah `_last_activity` yang diperbarui tiap request; batas idle sesungguhnya |
| A5 | P3 | Input `username[]` (array) pada `POST /login` memicu PHP warning | Hanya menerima nilai bertipe string pada `AuthController::authenticate()` |

Tidak ditemukan temuan P0.

### Hasil pengujian (258 assertion lulus, 0 gagal)

- [x] E2E Skenario A–E + RBAC + CSRF + alur tiket + rating + laporan + AI:
  **79/79** (`tests/tahap10/e2e.php`).
- [x] Keamanan/tepi (XSS, unggahan, transisi ilegal, IDOR, 404/500, regresi
  halaman detail admin):
  **34/34** (`tests/tahap10/security.php`).
- [x] Autentikasi (throttle, akun nonaktif, invalidasi sesi, anti-fixation):
  **15/15** (`tests/tahap10/auth.php`).
- [x] Regresi Tahap 9 — laporan CLI **47/47**, regresi HTTP **48/48**,
  AI CLI **15/15**, AI HTTP via mock **20/20**.
- [x] DB uji: 0 orphan, 0 duplikat nomor tiket, 0 penugasan/laporan/penyelesaian/
  rating ganda, 0 status di luar 6 status resmi; 22 foreign key lengkap.
- [x] Lint 128 berkas PHP 0 error; `node --check` 3 berkas JS OK.

### Integrasi AI — BLOCKED

`AI_API_KEY`/`AI_MODEL` di `.env` kosong dan provider eksternal tidak tersedia,
sehingga **integrasi AI nyata ditandai BLOCKED (bukan PASSED)**. Pipeline
endpoint, keamanan kunci, otorisasi tool, penanganan error/timeout, rate limit,
dan ketahanan prompt injection tetap diuji via mock provider (20/20).

### Perubahan berkas (Tahap 10)

| Berkas | Perubahan |
|---|---|
| `app/Middleware/AuthMiddleware.php` | Validasi akun aktif pada sesi berjalan (A1) |
| `app/Services/Session.php` | Batas idle sesungguhnya (A3) |
| `app/Views/admin/pelanggan_detail.php` | **View baru** — memperbaiki halaman Riwayat Pelanggan admin (A4) |
| `app/Controllers/AuthController.php` | Tolak input non-string pada login (A5) |
| `bin/seed-sample.php` | Perbaikan urutan `DELETE` mode `--reset` (A2) |
| `tests/tahap10/*`, `tests/README.md` | Harness pengujian Tahap 10 |
| `AUDIT_REPORT.md`, `TEST_REPORT.md` | Laporan audit & pengujian |
| `README.md`, `PROGRESS.md` | Diperbarui (status, konfigurasi produksi, pengujian) |

Berkas unggahan yatim hasil pengujian (tidak dirujuk DB mana pun) dibersihkan;
0 referensi aktif kehilangan berkas. Database utama tidak diubah (hanya
`audit_log` bertambah karena login pengujian — sifatnya append-only).

## Pembaruan UI — Floating AI Assistant (✅ selesai)

Mengubah posisi AI Assistant dari menu sidebar menjadi **floating chat widget**
di pojok kanan bawah pada semua halaman yang memerlukan login (Admin, Customer
Service, Teknisi, Pelanggan). Tidak ada controller, service, endpoint API, atau
kemampuan AI yang dihapus.

**Batasan yang dipatuhi:** tidak mengubah modul lain yang tidak berkaitan, tidak
membuat endpoint duplikat, tidak ada jawaban AI palsu, tetap PHP Native.

### Perubahan

- **Menu sidebar**: empat item `AI Assistant` (admin, cs, teknisi, pelanggan)
  dihapus dari `app/Helpers/menu.php`. Navigasi lain tidak terpengaruh.
- **Komponen baru** `app/Views/components/ai-widget.php`: tombol bulat (FAB) +
  panel chat. Dipasang **sekali** melalui `app/Views/layouts/app.php` (shared
  layout) untuk semua peran yang sudah masuk.
- **Aset baru** `public/assets/js/ai-widget.js` dan gaya `.aiw-*` pada
  `public/assets/css/style.css`.
- **Layout** memuat widget + skrip hanya bila `$user !== null`.

### Posisi floating button

- `position: fixed; right: 24px; bottom: 24px;` (+ `env(safe-area-inset-*)`),
  `z-index: 1060` (di atas sidebar/scrim 1025 & dropdown 1040, di bawah modal
  1080 & toast 1100 sehingga tidak terpotong dan tidak menutupi dialog penting).
- Tombol bulat 56px, warna `#2563EB`, ikon bot putih, hover halus, tooltip
  "Tanya WiFiCare AI".
- Panel chat: lebar 380px / tinggi 520px (menyesuaikan `min(…, 100vw/100vh)`),
  background `#172238`, header biru `#2563EB`, radius & shadow modern, area
  pesan dapat di-scroll, input selalu terlihat. Header menampilkan ikon AI,
  "WiFiCare AI", subtitle "Asisten Virtual", tombol minimize & close.

### Perilaku

- Klik FAB → buka; minimize → mengecil (hanya header); klik FAB lagi → buka
  kembali; close → tutup; Escape → tutup.
- Enter mengirim; Shift+Enter membuat baris baru. Indikator memuat (typing dots)
  saat menunggu AI. Tidak ada request saat widget hanya dibuka. Isi percakapan
  dipertahankan selama halaman aktif. Tidak menyimpan riwayat permanen.
- Aksesibilitas: `aria-label`, `aria-expanded`, `aria-haspopup`, `role="dialog"`,
  `role="log"` + `aria-live`, dan pengelolaan fokus (fokus ke input saat buka,
  kembali ke tombol saat tutup).
- Responsif: FAB tetap di pojok kanan bawah, popup menyesuaikan lebar layar,
  tidak keluar viewport, tidak menyebabkan horizontal overflow.

### Integrasi API

Alur dipertahankan: Floating widget → JavaScript → `POST /ai/chat` (endpoint
sama) → `AiAssistantService` → AI provider → respons di widget. CSRF, RBAC,
session, timeout (klien 30s + server `AI_TIMEOUT`), rate limit, dan pemasangan
kunci API di sisi server tetap berlaku. Bila `AI_API_KEY`/`AI_MODEL` kosong,
widget tetap dapat dibuka tetapi menampilkan pesan
**"Layanan AI belum dikonfigurasi. Silakan hubungi administrator."** dan input
dinonaktifkan — tidak ada respons palsu.

### Hasil pengujian

- **Floating widget: 80/80** (`tests/tahap10/ai-widget.php`) — menu AI hilang,
  widget hadir pada keempat dashboard + halaman lain, markup/tooltip/header,
  pesan konfigurasi, aset CSS/JS, CSRF 419, validasi 422, API belum dikonfigurasi
  → `ok=false` (bukan sukses palsu), tamu tidak mendapat widget, tidak ada
  kunci API di HTML/JS.
- **AI live via mock provider: 12/12** (`tests/tahap10/ai-live.php`) — balasan AI
  nyata, tool calling backend, error provider, timeout, XSS dikembalikan sebagai
  teks, kunci API tidak bocor.
- **Regresi**: E2E **79/79**, keamanan **34/34**, autentikasi **15/15** — semua
  tetap lulus (navigasi & dashboard sebelumnya tidak rusak).
- Lint PHP & `node --check` berkas baru: OK.

### Perubahan berkas (Pembaruan UI)

| Berkas | Perubahan |
|---|---|
| `app/Helpers/menu.php` | Hapus 4 item menu `AI Assistant` dari sidebar |
| `app/Views/components/ai-widget.php` | **Komponen baru** — FAB + panel chat |
| `app/Views/layouts/app.php` | Muat widget + `ai-widget.js` (bila sudah masuk) |
| `public/assets/js/ai-widget.js` | **JS baru** — perilaku widget + pemanggilan endpoint |
| `public/assets/css/style.css` | Gaya `.aiw-*` (FAB, panel, header, pesan, form, responsif) |
| `tests/tahap10/ai-widget.php`, `tests/tahap10/ai-live.php` | Harness pengujian widget |
| `AUDIT_REPORT.md`, `PROGRESS.md` | Diperbarui |

Halaman `/ai` lama tetap ada dan berfungsi (fitur tidak dihapus), meskipun tidak
lagi memiliki menu sidebar.

## Pembaruan UI — Login Full-Screen (✅ selesai)

Halaman login sebelumnya memakai layout publik (`layouts/base.php`) yang memuat
sidebar (Beranda/Tentang/Masuk), header, dan footer dashboard. Halaman login kini
memakai **layout autentikasi khusus** yang terpisah dan tampil full-screen tanpa
sidebar/navbar. Layout dashboard (Admin, CS, Teknisi, Pelanggan) tidak diubah.

**Batasan yang dipatuhi:** sistem autentikasi tidak diubah; tidak ada login palsu
atau data dummy; tidak menghapus sidebar/navbar dashboard.

### Pemisahan layout

- **Auth layout** (`app/Views/layouts/auth.php`) — dipakai login: hanya
  stylesheet + skrip dasar, tanpa sidebar, header, footer dashboard, dan tanpa
  floating AI widget.
- **Dashboard layout** (`app/Views/layouts/app.php`) — tetap untuk halaman
  setelah login (sidebar + header + widget AI).
- `Controller` mendapat metode `renderAuth()`; `AuthController::login()` kini
  memakai layout autentikasi.

### Desain

- Full-screen (`min-height: 100vh`/`100dvh`), form tepat di tengah layar.
- Background `#0B1120` (dengan aksen gradien biru halus), card `#172238`,
  tombol utama `#2563EB`, teks `#F8FAFC`, teks sekunder `#94A3B8`.
- Card lebar maksimum 420px, padding nyaman, radius & shadow modern, border tipis.
- Konten: logo/brand WiFiCare CDN, judul **"Selamat Datang"**, subtitle **"Masuk
  ke akun WiFiCare CDN Anda"**, field **Username / PPPoE** dan **Password
  WiFiCare**, tombol show/hide password, tombol **Masuk** dengan ikon, loading
  state (spinner), pesan validasi/error, serta info bahwa akun dibuat
  Administrator (tanpa tautan registrasi publik).
- Responsif: di layar kecil card menyesuaikan lebar, padding aman
  (`env(safe-area-inset-*)`), tanpa horizontal overflow.

### Autentikasi (tetap berfungsi)

CSRF, validasi server, hashing, rate limiting, akun nonaktif, dan redirect per
peran (admin/cs/teknisi/pelanggan) tidak berubah. Pelanggan tetap memakai
Username PPPoE + password WiFiCare (terpisah dari password jaringan PPPoE).

### Hasil pengujian

- **Login full-screen: 43/43** (`tests/tahap10/login-layout.php`) — tanpa
  sidebar/navbar/footer/AI widget, isi form lengkap, CSRF ditolak tanpa token,
  kredensial salah ditolak, redirect per peran benar, dashboard tetap utuh,
  aset CSS/JS & palet benar.
- **Regresi**: E2E **79/79**, keamanan **34/34**, autentikasi **15/15**,
  floating AI widget **80/80**.
- Verifikasi visual: card login ter-center, palet gelap sesuai, toggle password
  dan loading state bekerja, tanpa error JavaScript.

### Perubahan berkas (Pembaruan UI Login)

| Berkas | Perubahan |
|---|---|
| `app/Views/layouts/auth.php` | **Layout baru** — autentikasi full-screen tanpa sidebar/navbar/footer |
| `app/Views/login.php` | **Desain ulang** — card modern + show/hide password + loading + info akun |
| `app/Controllers/Controller.php` | Metode `renderAuth()` |
| `app/Controllers/AuthController.php` | `login()` memakai layout autentikasi |
| `public/assets/css/style.css` | Gaya `.auth-*` (shell, panel, card, input, spinner, responsif) |
| `public/assets/js/main.js` | `initPasswordToggle()` + `initLoadingForms()` |
| `tests/tahap10/login-layout.php` | Harness pengujian login |
| `AUDIT_REPORT.md`, `TEST_REPORT.md`, `README.md`, `tests/README.md` | Diperbarui |

## Integrasi Google Gemini ke Floating AI (✅ selesai)

Floating AI Assistant (pojok kanan bawah) kini terhubung ke **Google Gemini**
melalui backend PHP cURL. Tidak ada menu AI yang ditambahkan ke sidebar; widget
tetap tidak muncul di halaman login.

### Konfigurasi

- `.env`: `AI_PROVIDER=gemini`, `GEMINI_API_KEY` (terisi), `GEMINI_MODEL`,
  `GEMINI_API_BASE_URL`, `GEMINI_THINKING_BUDGET=0`, `AI_TIMEOUT=60`.
  Kunci **tidak dicetak/ditulis ke log**.
- Provider dipilih otomatis (Gemini bila `GEMINI_API_KEY` terisi); OpenAI-compatible
  tetap didukung sebagai provider alternatif.

### Alur & format

- Endpoint: `POST {GEMINI_API_BASE_URL}/models/{model}:generateContent` dengan
  header `x-goog-api-key`.
- Format request/response **Gemini** (`systemInstruction` + `contents`,
  `candidates[].content.parts[].text`), bukan format OpenAI. Bagian "proses
  berpikir" model tidak ditampilkan.
- Alur utuh dipertahankan: Widget → JS → PHP (`/ai/chat`) → `AiAssistantService`
  → Gemini → respons ditampilkan sebagai teks (`textContent`).
- Peran/RBAC, CSRF, session, dan rate limiting tetap berlaku. AI tidak memiliki
  akses database; status tiket hanya dibaca lewat backend berotorisasi.

### Penanganan error (tanpa membocorkan rahasia)

| Kode | Perilaku |
|---|---|
| 400 | Pesan "permintaan tidak valid" (khusus `API_KEY_INVALID` → "kunci API tidak valid"); **permanen** (tidak diulang) |
| 403 | "Kunci API tidak valid / tidak memiliki izin"; **permanen** (tidak diulang) |
| 404 | "Model AI tidak ditemukan"; diulang lalu beralih ke model cadangan |
| 429 | "Layanan AI sibuk atau kuota habis"; diulang lalu beralih ke model cadangan |
| 5xx | "Layanan AI sedang sibuk" (mis. 503 high demand); diulang lalu beralih ke model cadangan |
| Safety block | Pesan kebijakan keamanan (bukan jawaban palsu) |

### Ketahanan (retry + fallback model)

Provider Gemini sempat membalas **503 UNAVAILABLE** ("model is currently
experiencing high demand") untuk `gemini-3.8-flash`, sehingga widget menampilkan
"Layanan AI sedang bermasalah". Perbaikannya:

- **Model utama diganti** ke `gemini-3.5-flash` (tersedia dan cepat).
- **Model cadangan** (`GEMINI_MODEL_FALLBACK`, dipisah koma) dicoba bila model
  utama membalas 503/429/404 — default: `gemini-3.8-flash,gemini-flash-latest`.
- **Retry terbatas** (`AI_MAX_RETRIES`, default 1) dengan jeda singkat untuk
  error sementara, sehingga total waktu tetap di bawah batas widget.
- Error **permanen** (400/403 kunci/argumen) tidak diulang dan tidak memakai
  cadangan — langsung memberi pesan aman.


### Catatan penting saat integrasi

- Model `gemini-2.5-flash` ternyata **tidak lagi tersedia untuk pengguna baru**;
  Google menyarankan `gemini-3.8-flash` (`GEMINI_MODEL` di `.env` diset ke model
  tersebut).
- Mode "thinking" bawaan menghabiskan jatah token dan memperlambat respons →
  diset `GEMINI_THINKING_BUDGET=0` (respons ~5–10 detik, jauh di bawah batas
  widget 30 detik).

### Hasil pengujian

- **Live Gemini via Floating AI** (`tests/tahap10/ai-gemini.php`): **19/19** —
  tiga pertanyaan uji dijawab oleh Gemini (jawaban asli), endpoint terlindungi
  (401 tanpa login, 419 tanpa CSRF), kunci API tidak bocor.
- **Ketahanan Gemini** (`tests/tahap10/ai-gemini-resilience.php`): **23/23** —
  memakai mock lokal: model sehat dipakai sekali, 503/404 diulang lalu beralih
  ke cadangan, 400/403 tidak diulang, jawaban SAFETY ditangani aman, kunci tidak
  bocor ke log.
- **Error handling Gemini**: 400/403/404/429/5xx + safety → pesan aman, tanpa
  membocorkan kunci.
- **Live ulang (CLI)**: `gemini-3.5-flash` menjawab ketiga pertanyaan uji
  (~2–3 detik/pertanyaan).
- **Regresi**: E2E **79/79**, keamanan **34/34**, autentikasi **15/15**,
  floating AI widget **81/81** (+1 skip), login full-screen **43/43**.
- Lint **134 berkas PHP** 0 error; `node --check` JS OK.

### Perubahan berkas

| Berkas | Perubahan |
|---|---|
| `config/app.php` | Bagian `ai`: `provider`, kunci/model/base Gemini, `gemini_model_fallback`, `max_retries`, `gemini_thinking_budget` |
| `app/Services/AiAssistantService.php` | `provider()`, `credentials()`, `geminiChat()`, `geminiModels()`, `geminiRequestModel()`, `geminiPost()`, `geminiEndpoint()`, `geminiError()` (dengan penanda `retry`); OpenAI dipindah ke `openAiChat()` |
| `.env` | `AI_PROVIDER=gemini`, `GEMINI_MODEL=gemini-3.5-flash`, `GEMINI_MODEL_FALLBACK`, `AI_MAX_RETRIES=1`, `AI_TIMEOUT=60`, `GEMINI_THINKING_BUDGET=0` (nilai kunci tidak diubah) |
| `.env.example` | Dokumentasi kunci Gemini + `AI_PROVIDER` + fallback/retry |
| `tests/tahap10/ai-gemini.php` | Harness live Gemini via Floating AI |
| `tests/tahap10/mock-gemini.php` | **Baru** — mock Gemini untuk uji ketahanan |
| `tests/tahap10/ai-gemini-resilience.php` | **Baru** — uji retry + fallback model (23) |
| `tests/tahap10/ai-widget.php`, `tests/tahap10/e2e.php` | Menyesuaikan diri: uji "belum dikonfigurasi" jadi kondisional (skip saat provider aktif) |
| `README.md`, `TEST_REPORT.md`, `AUDIT_REPORT.md`, `tests/README.md` | Diperbarui |

## WiFiCare AI — Asisten Operasional Database Read-Only (✅ selesai)

Peran AI diubah menjadi **asisten operasional** aplikasi (bukan asisten coding).
AI hanya boleh **membaca** data aplikasi lewat fungsi PHP terkontrol; model
Gemini tidak pernah menerima kredensial MySQL dan tidak pernah menjalankan SQL.

### Aturan utama AI

1. **Tidak melayani coding.** AI tidak membuat kode/script/program/`.py`, tidak
   memberikan perintah yang menjalankan program, dan tidak menghasilkan SQL untuk
   dieksekusi. Bila diminta, AI menjawab **persis**:

   > Maaf, saya adalah WiFiCare AI yang khusus membantu layanan internet,
   > pengaduan pelanggan, dan informasi WiFiCare CDN. Saya tidak menyediakan
   > bantuan pemrograman.

   Penolakan ini dijaga **dua lapis**: deteksi kata kunci di backend (menolak
   tanpa memanggil provider) + aturan pada prompt sistem.
2. **Tidak menulis/mengubah data.** AI tidak menambahkan komentar pengaduan,
   catatan internal CS, ulasan/rating, atau menulis komentar ke database. Tidak
   ada tool `INSERT/UPDATE/DELETE/DROP/ALTER` maupun eksekusi perintah.
3. **Hanya membaca data asli.** AI menjawab dengan data MySQL sebenarnya melalui
   tool read-only; dilarang mengarang angka/status. Bila tool gagal, AI
   menyatakan data tidak dapat diambil.

### Arsitektur

```
Floating AI Chat → AiController (/ai/chat)
                 → AiAssistantService (Gemini + function calling)
                 → AiToolService  (Authorization & Tool Service; allowlist + RBAC)
                 → AiReadService  (fungsi baca terkontrol, SELECT prepared)
                 → Database::readPdo()  (opsional akun MySQL SELECT-only)
                 → data terfilter → Gemini → jawaban
```

`AiToolService::execute()` adalah satu-satunya pintu eksekusi: nama tool di luar
allowlist peran ditolak (`tool_not_allowed`). Argumen selalu berupa nilai sederhana
(tidak ada SQL/identifier dari pemanggil).

### Tool read-only & pembatasan peran

| Tool | Pelanggan | Teknisi | CS | Admin |
|---|---|---|---|---|
| `get_ticket_status` | tiketnya | tugasnya | semua | semua |
| `get_my_tickets` | tiketnya | tugasnya | terbaru | terbaru |
| `get_my_active_ticket_count` | tiketnya | tugasnya | semua | semua |
| `get_ticket_statistics` | tiketnya | tugasnya | semua | semua |
| `get_complaint_category_statistics` | tiketnya | tugasnya | semua | semua |
| `get_technician_assignments` | — | tugasnya | — | kinerja teknisi |
| `get_customer_count` | — | — | — | ✓ |
| `get_app_usage_guide` / `get_wifi_troubleshooting_guide` / `get_faq` | ✓ | ✓ | ✓ | ✓ |

Deklarasi tool ke Gemini **disaring per peran** — pelanggan tidak pernah
"melihat" tool admin. Bila pelanggan memaksa, backend tetap menolak.

### Function calling Gemini

- Request menyertakan `tools: [{ functionDeclarations: [...] }]`.
- Model membalas `functionCall`; backend mengeksekusi tool dengan otorisasi,
  lalu mengirim kembali `functionResponse` (role `user`).
- **Penting:** part model dikembalikan **apa adanya termasuk `thoughtSignature`**
  (wajib untuk Gemini 3.x), dan argumen kosong dikodekan `{}` (bukan `[]`).
- Maksimum putaran diatur `AI_MAX_TOOL_ROUNDS` (default 3). Kegagalan tool
  ditangani (tidak membuat aplikasi error); hasil `null` disanitasi menjadi `""`
  agar valid sebagai protobuf Struct.

### Database read-only

- Semua pembacaan melalui `Database::readPdo()`. Bila `DB_RO_USER`/`DB_RO_PASSWORD`
  diisi, dipakai koneksi **akun MySQL khusus** berizin `SELECT` saja; bila kosong,
  jatuh ke koneksi utama (query tetap `SELECT` prepared).
- Otorisasi kepemilikan: pelanggan hanya tiketnya (`pelanggan_id` dari session),
  teknisi hanya tugasnya; CS/Admin sesuai kewenangan PRD.

### Hasil pengujian

- **WiFiCare AI read-only** (`tests/tahap10/ai-read-tools.php`): **26/26** —
  memakai mock lokal: penolakan coding (tanpa panggilan API), tool loop
  (functionCall → tool → jawaban data asli), argumen kosong `{}`, RBAC tool,
  allowlist tanpa tool tulis, dan pembacaan ter-scope.
- **Verifikasi wire-format live**: satu putaran `functionCall` → `functionResponse`
  ke API Gemini nyata berhasil (HTTP 200) sebelum kuota harian kunci uji habis.
- **Penolakan coding E2E via HTTP**: POST `/ai/chat` (login pelanggan) →
  `ok=true` + kalimat penolakan tetap.
- **Regresi**: E2E **79/79**, keamanan **34/34**, autentikasi **15/15**,
  floating AI widget **81/81** (+1 skip), login full-screen **43/43**,
  ketahanan Gemini **23/23**.
- Lint **138 berkas PHP** 0 error.

> Catatan: uji live *tool calling* end-to-end sempat terhalang batas laju/kuota
> harian kunci Gemini uji (HTTP 429). Format wire-format sudah diverifikasi live
> dan seluruh logika tool diuji deterministik via mock.

### Perubahan berkas

| Berkas | Perubahan |
|---|---|
| `app/Services/AiReadService.php` | **Baru** — fungsi baca terkontrol, SELECT prepared, ter-scope per peran |
| `app/Services/AiToolService.php` | Ditulis ulang: spesifikasi tool tunggal (OpenAI + Gemini), penyaringan peran, dispatcher + RBAC |
| `app/Services/AiAssistantService.php` | Prompt sistem operasional; deteksi permintaan coding; tool loop Gemini (`handleGeminiParts`, `sanitizeForStruct`); tool error aman |
| `app/Services/AiKnowledge.php` | Identitas "WiFiCare AI", `codingRefusal()`, pertanyaan cepat berbasis data |
| `app/Services/Database.php` | `readPdo()` (akun read-only opsional) |
| `config/database.php` | `read_username` / `read_password` |
| `app/bootstrap.php` | Memuat `AiReadService` |
| `.env.example` | `DB_RO_USER`/`DB_RO_PASSWORD` + catatan WiFiCare AI read-only |
| `tests/tahap10/ai-read-tools.php` | **Baru** — uji read-only + tool calling (26) |
| `tests/tahap10/mock-gemini.php` | Menambah skenario `functionCall`/`functionResponse` |
| `tests/README.md`, `README.md`, `TEST_REPORT.md` | Diperbarui |

## Tahap berikutnya (opsional)

- Isi `AI_API_KEY`/`AI_MODEL` bila AI Assistant ingin diaktifkan.
- Pengerasan produksi: `APP_ENV=production`, `APP_DEBUG=false`,
  `SESSION_COOKIE_SECURE=true` (HTTPS).
- Integrasi WhatsApp/email (di luar cakupan v1.0).

---

## Tahap 11 — Pengaturan AI Chatbot melalui Dashboard Admin

**Status: SELESAI** (implementasi + pengujian nyata).

Tujuan: Admin dapat mengganti Gemini API key dan mengatur chatbot langsung dari
dashboard tanpa mengedit `.env`, tanpa membangun ulang Floating AI Assistant.

### Ringkasan
- Submenu ADMIN baru: **Pengaturan → Pengaturan AI Chatbot** (`/admin/pengaturan/ai`),
  admin-only (CS/teknisi/pelanggan → 403). Menu AI lama tetap tidak dikembalikan ke
  sidebar pengguna biasa; floating AI tetap di pojok kanan bawah.
- Halaman menyediakan: provider (Google Gemini), API key (input password + tombol
  tampil/sembunyikan, status "API Key Tersimpan"/"Belum Dikonfigurasi"), model
  (dengan saran datalist), toggle Aktif/Nonaktif, batas output token, timeout,
  batas request + jendela waktu, batas panjang pesan, dan system instruction opsional.
- **Enkripsi API key**: libsodium `crypto_secretbox` (XSalsa20-Poly1305), nonce acak
  per enkripsi, format tersimpan `v1:` + base64(nonce||ciphertext). Master key dari
  environment `AI_SETTINGS_KEY` (bukan DB, bukan repositori). Bila libsodium/master
  key tidak tersedia, Admin diberi informasi aman dan key TIDAK disimpan.
- **Tes Koneksi Gemini**: tombol memanggil API sungguhan (minimal `generateContent`).
  Hasil dipetakan ke pesan aman ("Koneksi Gemini berhasil.", "API Key tidak valid.",
  "Model tidak tersedia.", "Kuota API telah mencapai batas.", "Gagal terhubung..."),
  tanpa menampilkan key/respons mentah. Dapat memakai kuota API.
- **Integrasi Floating AI**: `AiAssistantService`/`AiRateLimiter`/`AiController` kini
  membaca konfigurasi runtime dari `AiSettingsService` (key tersimpan terenkripsi →
  didekripsi di backend), dengan fallback `.env` yang jelas. Status Nonaktif
  menghentikan layanan tanpa menghapus key.
- **Audit log**: mencatat admin, waktu, dan field yang berubah; untuk key hanya
  dicatat "diganti". Tidak pernah mencatat key/ciphertext/master key.
- Aturan WiFiCare AI (operasional, read-only, tolak coding, RBAC) tetap berlaku;
  system instruction Admin tidak dapat menonaktifkan kontrol backend.

### Prioritas konfigurasi (didokumentasikan)
1. Baris `ai_settings` ADA → status dari `is_enabled` (Nonaktif mengabaikan `.env`);
   key tersimpan bila ada, jika tidak fallback ke `GEMINI_API_KEY`; model/limit dari
   tabel bila terisi.
2. Belum ada baris → perilaku lama (`.env`) dipertahankan sampai Admin menyimpan.

### Penyiapan master key
```
php bin/ai-keygen.php        # hasilkan kunci 32 byte (base64)
# salin ke .env server:
AI_SETTINGS_KEY=<kunci>
```

### Berkas baru/berubah
| Berkas | Perubahan |
|---|---|
| `database/migrations/006_ai_settings.sql` | **Baru** — tabel `ai_settings` (tanpa key plaintext) |
| `app/Services/Crypto.php` | **Baru** — enkripsi libsodium secretbox |
| `app/Models/AiSetting.php` | **Baru** — akses baris konfigurasi tunggal |
| `app/Services/AiSettingsService.php` | **Baru** — runtime, validasi, simpan, tes koneksi, view data aman |
| `app/Controllers/AdminAiSettingsController.php` | **Baru** — index/save/test (admin-only) |
| `app/Views/admin/pengaturan_ai.php` | **Baru** — halaman Pengaturan AI Chatbot |
| `public/assets/js/ai-settings.js` | **Baru** — toggle key, label status, tes koneksi |
| `bin/ai-keygen.php` | **Baru** — pembuat master key |
| `tests/tahap11/crypto.php` | **Baru** — uji enkripsi + read-only (19) |
| `tests/tahap11/config-priority.php` | **Baru** — uji prioritas konfigurasi (17) |
| `tests/tahap11/ai-settings.php` | **Baru** — uji HTTP 19 poin (67) |
| `app/Services/AiAssistantService.php` | Kredensial/provider dari runtime; batas dari DB; prompt + system instruction Admin; `testGeminiConnection()` |
| `app/Services/AiRateLimiter.php` | Rate limit dari runtime |
| `app/Controllers/AiController.php` | Batas pesan runtime + status widget |
| `app/Views/layouts/app.php`, `app/Views/components/ai-widget.php` | Status active/disabled/unconfigured |
| `app/Helpers/menu.php`, `app/Helpers/functions.php` | Submenu pengaturan AI + ikon `cpu` |
| `routes/web.php`, `app/bootstrap.php`, `config/app.php` | Rute, muat service, blok `ai_settings` |
| `.env`, `.env.example` | `AI_SETTINGS_KEY` + catatan prioritas |
| `app/Views/admin/pengaturan.php` | Status Modul AI dari `AiSettingsService` |
| `README.md`, `TEST_REPORT.md`, `AUDIT_REPORT.md`, `tests/README.md` | Diperbarui |

### Catatan lingkungan
- Ekstensi `sodium` diaktifkan pada `php.ini` XAMPP (`extension=sodium`), dan
  `libsodium.dll` disalin ke `C:\xampp\apache\bin` agar dimuat proses Apache
  (dependensi DLL yang tidak ada di direktori kerja Apache).

## Tahap 12 — Revisi Final (7 revisi) (✓ selesai)

Tujuh revisi final diterapkan tanpa membangun ulang dan tanpa merusak fitur
Tahap 1–11.

1. **Tata letak teks jawaban AI** — bubble AI rata kiri konsisten
   (`text-align:left`), `line-height:1.6`, jarak paragraf, `overflow-wrap:anywhere`
   + `word-break:break-word` (tanpa overflow horizontal). Sapaan awal:
   "Halo! Saya WiFiCare AI." + deskripsi.
2. **Tanpa Markdown mentah + kartu tiket terstruktur** — perender DOM aman
   (`public/assets/js/ai-render.js`) mengubah **tebal**/judul/daftar menjadi
   elemen tanpa simbol mentah, tanpa `innerHTML` dari respons AI. Data tiket
   dikirim backend sebagai JSON terkontrol (`tickets`) dari `AiReadService`
   (SELECT prepared, RBAC), dirender sebagai kartu (nomor tiket, nama pelanggan
   bila berhak, kategori, status + badge, tanggal Indonesia panjang, teknisi
   bila relevan), dapat digulir, multi-kartu. FAQ/troubleshooting tetap
   paragraf biasa.
3. **Jawaban ringkas** — system instruction diperbarui: jawab langsung, tanpa
   komentar/saran/menu tambahan, tanpa kesimpulan penutup, tanpa menyalin detail
   tiket bila sudah tampil sebagai kartu. Otorisasi tetap di backend.
4. **Kebersihan input chat** — spinner input dihilangkan (tidak ada
   `input[type=number]`), teks peringatan kata sandi dihapus, placeholder
   "Tulis pertanyaan Anda...", tombol kirim biru dengan ikon + state loading
   (`is-loading`), Enter kirim / Shift+Enter baris baru, auto-grow, tanpa resize,
   scrollbar textarea disembunyikan. Validasi/keamanan backend tetap utuh.
5. **Tema global Light + Dark** — design token CSS (`data-theme` pada `<html>`),
   Light default (`#F8FAFC`/`#FFFFFF`/`#0F172A`), Dark opsional
   (`#0b1120`/`#172238`/`#F8FAFC`); remap variabel Bootstrap mengikuti token.
   Tombol sun/moon di header; skrip anti-flash di semua layout; preferensi
   disimpan di `localStorage`; grafik Chart.js mengikuti tema via
   `wificare:themechange`; floating AI mengikuti tema aktif tanpa toggle
   terpisah.
6. **Routing root** — `/` → Login (belum masuk) atau Dashboard per peran (sudah
   masuk). Halaman publik Beranda/Tentang dihapus; `layouts/base` kini tanpa
   sidebar pengunjung; halaman 403/404 memakai `layouts/error` (ringkas,
   terpusat, bertema). Login memakai `layouts/auth` (tanpa sidebar/navbar).
   Logout → login. Floating AI hanya saat masuk.
7. **Scrollbar disembunyikan visual** — `scrollbar-width:none` (Firefox) +
   `::-webkit-scrollbar{display:none}` (WebKit) pada `html`/`body`, sidebar, dan
   area chat; gulir (roda/touch/keyboard) tetap berfungsi, tanpa `overflow:hidden`.

### Berkas baru/berubah (Tahap 12)
| Berkas | Perubahan |
|---|---|
| `app/Views/components/theme-toggle.php` | **Baru** — tombol tema (header/melayang) |
| `app/Views/layouts/error.php` | **Baru** — layout error ringkas bertema |
| `public/assets/js/ai-render.js` | **Baru** — perender respons AI aman + kartu tiket |
| `tests/tahap12/revisi-final.php` | **Baru** — uji 7 revisi (86) |
| `public/assets/css/style.css` | Token Light/Dark, bubble AI, kartu tiket, tombol tema, scrollbar |
| `public/assets/js/main.js` | `initTheme()` + persist + event tema |
| `public/assets/js/ai-widget.js`, `ai.js` | Perender aman, kartu, state loading |
| `public/assets/js/dashboard.js` | Grafik ikut tema |
| `app/Views/layouts/app.php`, `auth.php`, `base.php` | Anti-flash + toggle tema |
| `app/Views/components/header.php` | Toggle tema di header |
| `app/Views/components/ai-widget.php`, `app/Views/ai/index.php` | Sapaan, hapus peringatan, spinner kirim |
| `app/Helpers/functions.php` | Ikon sun/moon, `bulan_panjang()`, `format_waktu_panjang()` |
| `app/Controllers/HomeController.php` | Root → login/dashboard |
| `app/Controllers/AuthController.php` | 403 via layout error |
| `app/Helpers/auth.php`, `functions.php` | `render_forbidden`/`render_error` layout error |
| `app/Helpers/menu.php` | Menu publik dikosongkan |
| `routes/web.php` | Hapus `/about` |
| `app/Views/error.php`, `akses-ditolak.php` | Tombol kembali sesuai status login |
| `app/Services/AiAssistantService.php` | Kartu tiket + system instruction ringkas |
| `app/Services/AiReadService.php`, `AiToolService.php` | Field kartu (status_key, tanggal panjang, nama/teknisi ber-RBAC) |
| `app/Models/Pengaduan.php` | `nama_pelanggan_snapshot` pada `findByNumber` |
| `app/Controllers/AiController.php` | Kirim `tickets` pada JSON |
| `tests/tahap10/ai-read-tools.php`, `ai-gemini-resilience.php`, `tests/tahap11/config-priority.php` | Netralkan `ai_settings` non-destruktif (pulihkan baris asli) |
| `README.md`, `AUDIT_REPORT.md`, `TEST_REPORT.md`, `PROGRESS.md` | Diperbarui |

### Catatan
- Baris `ai_settings` di DB utama dipertahankan oleh pengujian offline
  (dinonaktifkan sementara lalu dipulihkan), bukan dihapus.
- Pengujian kartu tiket memakai data DB nyata lewat mock Gemini lokal (tanpa jaringan).

## Bugfix — Sidebar Role Tertukar & Error 403 (18 Oktober 2026) (✓ selesai)

**Gejala:** saat login sebagai Administrator dan membuka Manajemen Pengguna,
sidebar/header menampilkan peran Pelanggan (atau peran lain yang salah), namun
chip profil menampilkan Administrator; sebagian navigasi menghasilkan 403.

**Akar masalah:** `render_view()` (di `app/Helpers/functions.php`) melakukan
`include` view dan layout dalam **satu lingkup PHP** yang sama. View
`app/Views/admin/pengguna.php` menetapkan `$role` di dalam loop daftar pengguna
(`$role = (string) $item['role'];`). Setelah loop, `$role` menyimpan peran baris
**terakhir** (mis. `pelanggan`), lalu dipakai layout untuk `role_menu($role)` dan
header. Chip profil memakai `$user['role']` (dari autentikasi) sehingga tetap
benar — itulah sebabnya tampilan tidak sinkron. Menu sidebar yang salah (mis.
`/pelanggan/...`) kemudian memicu 403 saat Admin mengkliknya.

**Perbaikan (akar masalah):**
1. `render_view()` merender isi view dalam **lingkup terisolasi** (closure), lalu
   layout menerima **data asli** dari controller — variabel view tidak bisa lagi
   bocor ke layout. Ini menghentikan seluruh kelas bug variabel-bocor.
2. `Auth` menjadi **satu sumber kebenaran peran**: konstanta `Auth::ROLES`
   (admin/cs/teknisi/pelanggan) + `Auth::isValidRole()`; `Auth::role()` membaca
   dari database via session `user_id` dan mengembalikan `null` bila peran tidak
   dikenali (tanpa fallback ke Pelanggan). Kunci session bayangan `user_role`
   dihapus.
3. `layouts/app.php` menurunkan `$role` dari `auth_user()` (database), bukan dari
   variabel `$role`/URL; tanpa sesi → redirect login; peran tak dikenal →
   HTTP 403 (gagal aman).
4. `app/Views/admin/pengguna.php` mengganti variabel loop `$role` → `$itemRole`
   agar tidak bertabrakan dengan layout.

RBAC **tidak** dilonggarkan: CS/Teknisi/Pelanggan tetap ditolak HTTP 403 pada
Manajemen Pengguna dan tidak menerima data manajemen.

### Berkas diperbaiki (bugfix)
| Berkas | Perubahan |
|---|---|
| `app/Helpers/functions.php` | `render_view()` isolasi lingkup view vs layout |
| `app/Services/Auth.php` | `ROLES`/`isValidRole()`, `role()` tervalidasi, hapus session bayangan |
| `app/Views/layouts/app.php` | Peran dari `auth_user()`, tanpa fallback Pelanggan |
| `app/Views/admin/pengguna.php` | Loop var `$role` → `$itemRole` |
| `tests/tahap12/role-sidebar.php` | **Baru** — uji role/sidebar & RBAC (28) |
| `PROGRESS.md`, `AUDIT_REPORT.md`, `TEST_REPORT.md` | Diperbarui |

---

## Tahap 13 — Perbaikan 7 Bug & Revisi Final (WiFiCare CDN)

### Ringkasan
Tujuh pekerjaan diselesaikan berurutan dengan pengujian nyata: (1) HY093 pada
pencarian, (2) identitas pelanggan, (3) unggah foto, (4) pemisahan Data
Pelanggan vs Data Karyawan, (5) penyederhanaan filter Semua Pengaduan,
(6) penyederhanaan filter Laporan, (7) kerapian Laporan & Analitik.
Tidak ada framework/RBAC/aturan status yang diubah; enam status resmi tetap.

### Akar masalah & perbaikan
1. **BUG 1 — `SQLSTATE[HY093]: Invalid parameter number`.** `Database` memakai
   PDO native prepare (`PDO::ATTR_EMULATE_PREPARES => false`), sehingga satu
   nama placeholder tidak boleh dipakai berulang. Beberapa query pencarian
   memakai `:q` dua–tiga kali. Diperbaiki menjadi placeholder unik
   (`:q1`,`:q2`,`:q3`) pada: `User::paginateForAdmin`,
   `Pelanggan::paginateForAdmin`, `Pelanggan::paginateForCs`,
   `Pengaduan::buildAdminFilters`, `Pengaduan::buildCsFilters`,
   `AuditLog`, `Kategori`, `LaporanTeknisi`, `Penugasan`.
2. **BUG 2 — pelanggan melihat identitas orang lain (Dewi).** Halaman
   `pelanggan/profil.php` berisi data contoh hardcoded (`Dewi Lestari`,
   `dewi.pppoe`, nomor contoh) dan controller tidak mengirim data. Kini data
   diambil dari session -> `users` -> `pelanggan` (`Pelanggan::findByUserId`);
   pengubahan profil (nama + WhatsApp) memakai ID dari session (tanpa IDOR).
   Ditambahkan `POST /pelanggan/profil` dan `POST /pelanggan/profil/password`.
3. **BUG 3 — "Foto bukti gangguan wajib diunggah" walau foto sudah dipilih.**
   Akar: `component_html()` memakai parameter bernama `$name`, sedangkan
   `extract($data, EXTR_SKIP)` melewati data komponen kunci `name`; akibatnya
   input berkas dirender `name="upload-photo"`, bukan `foto[]`, sehingga
   `$_FILES['foto']` selalu kosong. Parameter komponen diganti `$__component`/
   `$__data`. Ditambah deteksi `post_max_size` terlampaui, pratinjau nama+ukuran
   berkas, dan penjagaan anti kirim-ganda.
4. **REVISI 4 — Data Karyawan.** `User::paginateForAdmin` dibatasi ke
   admin/cs/teknisi; menu "Manajemen Pengguna" menjadi "Data Karyawan" (isi
   admin/cs/teknisi), tetap berdampingan dengan "Data Pelanggan" (pelanggan).
   Tidak ada peran pelanggan pada Data Karyawan; RBAC tidak dilonggarkan.
5. **REVISI 5 — filter Semua Pengaduan** disederhanakan menjadi Cari
   (placeholder `Cari nomor tiket, pelanggan, atau PPPoE...`), Status (6 + semua),
   Periode (Semua Waktu/Hari Ini/7 Hari/Bulan Ini) + Cari/Terapkan & Reset.
   Data kategori/teknisi/arsip/pembatalan tetap pada detail tiket.
6. **REVISI 6 — filter Laporan** disederhanakan menjadi Jenis Laporan (9 jenis)
   + Periode (Hari Ini, 7 Hari, Bulan Ini [default], Bulan Lalu, Tahun Ini,
   Semua Waktu, Pilih Tanggal Sendiri) dengan validasi tanggal backend; ekspor
   PDF/Excel memakai filter aktif.
7. **REVISI 7 — layout Laporan & Analitik** dirapikan: header, kartu filter
   ringkas, 4 kartu statistik (Total, Selesai, Dalam Proses, Rata-rata Rating),
   grafik theme-aware dengan empty state, tabel detail (tanggal Indonesia,
   paginasi, empty state, responsif), tombol ekspor di atas kartu detail.

### Berkas dibuat/diubah (Tahap 13)
| Berkas | Perubahan |
|---|---|
| `app/Helpers/functions.php` | `component_html()` parameter anti-bentrok; helper `periode_range()` |
| `app/Models/User.php` | `:q` unik; batasi Data Karyawan; `updateName()` |
| `app/Models/Pelanggan.php` | `:q` unik (admin & CS) |
| `app/Models/Pengaduan.php`, `AuditLog.php`, `Kategori.php`, `LaporanTeknisi.php`, `Penugasan.php` | `:q` unik |
| `app/Controllers/PelangganController.php` | Profil dari session; `profilSimpan`; `passwordSimpan`; deteksi `post_max_size` |
| `app/Views/pelanggan/profil.php` | Ditulis ulang (tanpa data contoh) |
| `app/Views/pelanggan/buat.php` | `data-loading` |
| `app/Controllers/AdminController.php` | Filter pengaduan (Cari+Status+Periode) |
| `app/Views/admin/pengaduan.php` | Bar filter ringkas |
| `app/Controllers/AdminUserController.php`, `app/Views/admin/pengguna.php` | Data Karyawan |
| `app/Helpers/menu.php` | Menu: Data Pelanggan, Data Karyawan, Laporan & Analitik |
| `app/Controllers/AdminLaporanController.php` | `buildQuery` filter baru |
| `app/Services/LaporanService.php` | `rentang` + `rentangLabels()`; 4 kartu ringkas |
| `app/Views/admin/laporan.php` | Ditulis ulang (Revisi 6 & 7) |
| `public/assets/js/main.js` | Pratinjau unggah nama+ukuran, anti kirim-ganda, toggle periode |
| `public/assets/css/style.css` | `.is-hidden`, `.upload-preview-meta`, `.table-toolbar-actions` |
| `routes/web.php` | Route POST profil & password |
| `tests/tahap12/prompt7.php` | **Baru** — uji perbaikan 7 bug & revisi (75) |

### Hasil uji
- `tests/tahap12/prompt7.php` — **75 / 75**.
- Regresi penuh (lihat `TEST_REPORT.md` §15) tetap hijau; lint 0 error.

---

## Tahap 14 - Security Fix: Autentikasi, Proteksi Dashboard & Folder (20 Oktober 2026) (selesai)

### Ringkasan
Audit keamanan menemukan folder internal proyek dapat diakses langsung melalui
URL karena DocumentRoot Apache masih `C:/xampp/htdocs` (proyek di subfolder).
`.env` (berisi API key & master key AI), `storage/logs/app.log`, `composer.json`,
`tests/`, dan directory listing terbuka untuk publik. Middleware autentikasi
sudah ada, tetapi endpoint API/AJAX tanpa sesi masih di-redirect (bukan 401),
dan alur akun nonaktif pada halaman login berpotensi redirect berulang.

### Perbaikan
1. **Proteksi folder (Apache)**
   - `.htaccess` root proyek: tolak `app/`, `bin/`, `config/`, `database/`,
     `routes/`, `storage/`, `tests/`, `vendor/`, dotfiles (`.env`, `.git`),
     `composer.json`, `*.sql`, `*.log`, `*.bak`, `*.md`; `Options -Indexes`;
     akar proyek diarahkan ke `public/`.
   - `.htaccess` `Require all denied` di setiap folder internal (lapisan kedua).
   - `public/.htaccess`: front controller, tolak dotfiles, header keamanan.
   - Contoh VirtualHost `DocumentRoot -> public/`:
     `deploy/apache/wificare-cdn-vhost.conf.example` + panduan rollback di README.
2. **Autentikasi & RBAC terpusat** (`AuthMiddleware`)
   - Navigasi tanpa sesi -> redirect `/login`; API/AJAX tanpa sesi -> **401 JSON**.
   - Login tetapi salah peran -> **403** (JSON untuk API/AJAX).
   - Peran/akun selalu dibaca ulang dari database; akun nonaktif langsung
     kehilangan akses; peran tidak dikenal tidak pernah fallback ke Pelanggan.
3. **Session hardening** (`Session`, `Auth`)
   - HttpOnly, SameSite=Lax (dapat dikonfigurasi), Secure otomatis saat HTTPS.
   - `use_strict_mode`, regenerasi ID setelah login + rotasi berkala 30 menit.
   - Timeout idle tetap berlaku (diuji dengan `SESSION_LIFETIME=2`).
   - Logout menghapus session + cookie; respons privat `Cache-Control: no-store`
     sehingga tombol Back tidak menampilkan data setelah logout.
4. **Error handling**
   - `display_errors=0` permanen; stack trace/path/SQL tidak pernah dikirim ke
     browser. Detail hanya ditulis ke `storage/logs/app.log` (di luar public).
   - `APP_DEBUG=false` pada `.env` dan `.env.example`.
5. **Path traversal & foto**
   - `Upload::absolutePath()` menolak `..`, null byte, path absolut, dan path di
     luar `storage/uploads/`; `Upload::resolve()` memverifikasi `realpath()`.
   - Foto tetap hanya lewat endpoint ber-ID dengan cek kepemilikan tiket.

### Berkas dibuat/diubah (Tahap 14)
| Berkas | Perubahan |
|---|---|
| `.htaccess` | **Baru** - proteksi folder/file internal + redirect ke `public/` |
| `app/.htaccess`, `bin/.htaccess`, `config/.htaccess`, `database/.htaccess`, `deploy/.htaccess`, `routes/.htaccess`, `storage/.htaccess`, `tests/.htaccess` | **Baru** - `Require all denied` |
| `public/.htaccess` | Tolak dotfiles + header keamanan `always` |
| `public/index.php` | Header keamanan + `no-store`; error generik tanpa stack trace |
| `app/Middleware/AuthMiddleware.php` | 401 JSON untuk API/AJAX; validasi peran; anti redirect-loop |
| `app/Helpers/auth.php` | `request_wants_json()`, `json_response()`, `respond_unauthorized()`, `respond_forbidden()` |
| `app/Services/Session.php` | Secure otomatis HTTPS, SameSite config, rotasi ID berkala |
| `app/Services/Auth.php` | Tolak login peran tidak valid; set waktu rotasi |
| `app/Controllers/HomeController.php` | Validasi akun sebelum redirect dashboard |
| `app/Services/Upload.php` | Hardening path traversal + `resolve()` realpath |
| `app/Controllers/PengaduanFotoController.php`, `LaporanFotoController.php` | Pakai `Upload::resolve()` |
| `config/app.php` | Opsi `session.samesite`, `session.regenerate_every` |
| `.env`, `.env.example` | `APP_DEBUG=false` |
| `deploy/apache/wificare-cdn-vhost.conf.example` | **Baru** - contoh VirtualHost + rollback |
| `tests/tahap13/keamanan.php` | **Baru** - 101 uji keamanan HTTP nyata |
| `README.md` | Panduan proteksi folder, VirtualHost, rollback, path traversal |

### Hasil uji
- `tests/tahap13/keamanan.php` - **102 / 102 PASS, 1 SKIP** (cookie Secure
  menunggu HTTPS).
- Semua regresi sebelumnya tetap hijau (lihat `TEST_REPORT.md` A16).
- Lint PHP: seluruh berkas 0 error.

## Tahap 15 - Revisi Final AI Assistant (20 Oktober 2026) (selesai)

Lingkup: 10 revisi final AI Assistant pada basis WiFiCare CDN yang sudah ada.
Tidak ada pembangunan ulang, perubahan stack PHP Native, penghapusan data
MySQL, perubahan alur bisnis pengaduan, atau pelemahan autentikasi/RBAC.

### Arsitektur akhir

```
Widget floating (kanan bawah)
  -> POST /ai/chat (session + CSRF + rate limit + batas harian)
  -> AiController (identitas HANYA dari session server)
  -> AiAssistantService (orkestrasi + adapter provider)
  -> AiToolService (allowlist tool + RBAC per peran)
  -> AiReadService (SELECT prepared, scope per peran)
  -> Database::readPdo() (opsional DB_RO_USER read-only)
  -> hasil tervalidasi -> provider AI -> jawaban -> renderer aman
```

- Identitas/role tidak pernah diambil dari body, URL, atau localStorage.
- Tidak ada SQL bebas dari model dan tidak ada tool INSERT/UPDATE/DELETE.
- Kartu tiket dibuat dari hasil tool terstruktur, bukan parsing teks AI.
- FAQ Admin diperlakukan sebagai DATA referensi, bukan instruksi sistem.

### Migrasi database

`database/migrations/007_ai_platform.sql` (sudah dijalankan pada
`wificare_cdn_v1` dan `wificare_cdn_test`):

| Objek | Fungsi |
|---|---|
| `ai_settings.daily_limit` | batas request harian per pengguna (default 100, 1-10000) |
| `ai_provider_keys` | satu baris per provider, ciphertext `v1:` (libsodium) |
| `ai_usage_log` | metadata monitoring; tanpa isi pesan/jawaban/API key |
| `ai_faq` | FAQ resmi yang dikelola Admin + 5 FAQ awal |

Template akun MySQL read-only: `database/readonly_ai_user.example.sql`.
Verifikasi nyata dengan akun sementara: `SELECT` berhasil, `UPDATE` dan
`DELETE` ditolak `SQLSTATE 42000`.

### 10 revisi yang diimplementasikan

1. **Identitas sesi & RBAC server-side** - `/ai/chat` memakai `auth_user()`;
   role tidak dapat dipalsukan dari browser.
2. **Akses MySQL read-only** - semua data lewat `AiReadService`; tidak ada SQL
   bebas, tidak ada kredensial DB ke model/browser; dukungan `DB_RO_USER`.
3. **Cakupan per peran** - Admin (operasional + pelanggan + kinerja teknisi),
   CS (tiket/operasional), Teknisi (tugas/riwayat sendiri), Pelanggan
   (profil/tiket sendiri). Tool yang dideklarasikan juga disaring per peran.
4. **Statistik tanggal akurat** - periode `today`/`this_month`/`all`,
   timezone aplikasi, jumlah tiket selesai dihitung dari `riwayat_status`
   (`status_baru='selesai'`), bukan `created_at`; data kosong dan kegagalan DB
   dilaporkan jujur, tanpa angka karangan.
5. **Multi-provider** - adapter terpisah `GeminiProvider`, `DeepSeekProvider`,
   `OpenAiProvider`, registry allowlist, tanpa fallback provider otomatis.
6. **Admin provider/model/limits/monitoring/FAQ** - ganti provider & model,
   tes koneksi nyata, aktif/nonaktif, batas request per pengguna, monitoring
   metadata, CRUD FAQ dengan pencarian/kategori.
7. **Enam status resmi dipertahankan** - `menunggu_verifikasi`,
   `diproses_cs`, `menunggu_teknisi`, `dalam_penanganan`, `selesai`, `ditolak`.
8. **UI chat** - render aman (textContent/node, bukan HTML AI), kartu tiket,
   salin jawaban, bersihkan percakapan, indikator mengetik, Enter kirim,
   Shift+Enter baris baru, auto-scroll, konteks hanya sesi, tema Light/Dark.
9. **Penanganan kegagalan** - key hilang/tidak valid, model tidak tersedia,
   kuota/rate limit, timeout, jaringan/provider, DB, dan chatbot nonaktif
   menghasilkan pesan aman dan kode error; tidak ada jawaban palsu.
10. **Pengujian nyata & dokumentasi** - PASS/FAIL/BLOCKED berbasis eksekusi;
    dokumen diperbarui tanpa menghapus riwayat sebelumnya.

### Berkas utama dibuat/diubah

| Berkas | Perubahan |
|---|---|
| `database/migrations/007_ai_platform.sql` | **Baru** - tabel/key/limit/FAQ |
| `database/readonly_ai_user.example.sql` | **Baru** - contoh akun MySQL SELECT-only |
| `app/Services/Ai/AiProviderInterface.php` | **Baru** - kontrak adapter |
| `app/Services/Ai/AbstractOpenAiCompatibleProvider.php` | **Baru** - transport OpenAI/DeepSeek |
| `app/Services/Ai/GeminiProvider.php` | **Baru** - metadata/tes Gemini |
| `app/Services/Ai/DeepSeekProvider.php` | **Baru** - adapter DeepSeek |
| `app/Services/Ai/OpenAiProvider.php` | **Baru** - adapter OpenAI |
| `app/Services/Ai/AiProviderRegistry.php` | **Baru** - allowlist provider |
| `app/Models/AiProviderKey.php` | **Baru** - key terenkripsi per provider |
| `app/Models/AiFaq.php` | **Baru** - CRUD/validasi/pencarian FAQ |
| `app/Services/AiUsage.php` | **Baru** - log metadata, statistik, batas harian |
| `app/Services/AiConversation.php` | **Baru** - konteks percakapan session-only |
| `app/Services/AiSettingsService.php` | Multi-provider, key per provider, tes, limit |
| `app/Services/AiReadService.php` | Tool profil/tiket aktif/riwayat/rating, statistik periode |
| `app/Services/AiToolService.php` | Spesifikasi/eksekusi tool baru + FAQ DB |
| `app/Services/AiAssistantService.php` | Provider, usage, kode error, prompt tanggal/FAQ |
| `app/Services/AiKnowledge.php` | Konteks FAQ dinamis |
| `app/Controllers/AiController.php` | Batas harian, monitoring, endpoint `clear` |
| `app/Controllers/AdminAiSettingsController.php` | Provider/test/monitoring/FAQ |
| `app/Views/admin/pengaturan_ai.php` | UI provider, monitoring, FAQ, limit |
| `app/Views/components/ai-widget.php` | Tombol bersihkan + endpoint |
| `public/assets/js/ai-widget.js` | Salin, bersihkan, konteks, keyboard |
| `public/assets/js/ai-settings.js` | Provider switching, key reveal, tes nyata |
| `public/assets/css/style.css` | Gaya tombol salin/bersihkan, tema |
| `app/Models/AiSetting.php`, `config/app.php`, `app/bootstrap.php`, `routes/web.php` | Dukungan platform AI |
| `tests/tahap15/ai-platform.php` | **Baru** - 66 uji HTTP platform AI |
| `tests/tahap15/ai-tools-revisi.php` | **Baru** - 29 uji tool/cakupan peran |
| `tests/tahap15/ai-live-roles.php` | **Baru** - uji live Gemini per peran |

### Hasil pengujian ringkas

| Suite | Hasil |
|---|---|
| `tests/tahap15/ai-platform.php` | **66 PASS, 0 FAIL, 2 BLOCKED** |
| `tests/tahap15/ai-tools-revisi.php` | **29 PASS, 0 FAIL** |
| `tests/tahap15/ai-live-roles.php` | **4 PASS (login/endpoint), 4 BLOCKED** (kuota Gemini) |
| `tests/tahap10/ai-read-tools.php` | **26 PASS** |
| `tests/tahap10/ai-widget.php` | **81 PASS, 1 SKIP** |
| `tests/tahap10/ai-gemini-resilience.php` | **23 PASS** |
| `tests/tahap11/ai-settings.php` | **67 PASS** |
| `tests/tahap11/crypto.php` | **19 PASS** |
| `tests/tahap11/config-priority.php` | **17 PASS** |
| Regresi Tahap 10/12/13 | **semua lulus** (79+34+15+43+75+86+28+102, 1 SKIP) |
| Lint PHP seluruh proyek | **166 berkas, 0 error** |
| `node --check` JS AI | **3/3 OK** |

BLOCKED yang jujur dicatat:

- Verifikasi **live DeepSeek** dan **live OpenAI** belum bisa dinyatakan PASS
  karena kredensial nyata tidak tersedia; tes dengan key uji berjalan dan
  menghasilkan `invalid_key` yang aman.
- Verifikasi **live Gemini per peran** sempat berhasil untuk percakapan umum
  (Q1/Q2), lalu provider membalas `server`/`quota` pada percobaan berikutnya
  (batas free tier). Ini bukan klaim PASS.
- Cookie `Secure` penuh masih SKIP tanpa TLS lokal.

### Catatan operasional

- Kunci API yang pernah terekspos sebelum pengerasan **perlu dirotasi** oleh
  pemilik layanan. Kunci tidak dicetak/diubah otomatis oleh aplikasi.
- Tanpa `DB_RO_USER`, AI memakai koneksi utama tetapi tetap hanya `SELECT`
  prepared. Untuk produksi, aktifkan akun read-only.
- Monitoring menyimpan metadata (user, role, provider, model, status, kode
  error, durasi, token) - bukan isi percakapan.
- Konteks percakapan hanya di session PHP; tidak ada tabel riwayat chat.

---

## Penyempurnaan UI - Modal Konfirmasi, Toast, dan Laporan (20 Oktober 2026) (selesai)

### Ringkasan

- Modal konfirmasi global reusable (`app/Views/components/modal-confirm.php`)
  dipakai oleh seluruh peran. Mendukung varian `primary`, `danger`, `success`,
  `warning`, `info`, ikon, judul/pesan dinamis, tombol batal/konfirmasi,
  indikator loading, `role="dialog"`, `aria-modal`, `aria-labelledby`,
  `aria-describedby`, focus trap, Escape/backdrop, pemulihan fokus, dan
  `prefers-reduced-motion`.
- Form/aksi memakai atribut `data-confirm`; submit pertama hanya membuka modal,
  lalu submit asli dilakukan melalui `requestSubmit()` dengan penanda bypass.
  Validasi required, CSRF, FormData, dan unggahan tetap berjalan seperti biasa.
  Tombol konfirmasi dinonaktifkan selama proses untuk mencegah kirim ganda.
- Toast global mendukung tipe `success`, `danger`, `warning`, `info`, judul,
  tombol tutup, progress bar, batas tumpukan 4, serta durasi berbeda (error
  lebih lama). Flash session dipancarkan sebagai JSON dan diubah menjadi toast.
- Toast ditempatkan di atas tombol AI mengambang, termasuk pada viewport kecil;
  override CSS lama yang menempelkan toast ke dasar layar diperbaiki.
- Konfirmasi diterapkan pada aksi teknisi (mulai, kirim laporan), CS
  (terima/tolak/selesaikan/tugaskan/batalkan), admin (pembatalan), pelanggan
  (kirim pengaduan, kirim rating), serta modal khusus simpan pengaturan AI dan
  tandai semua notifikasi. Aksi sederhana tanpa risiko tidak diberi konfirmasi.
- Halaman Laporan & Analitik memakai filter kompak, empat kartu statistik,
  grid grafik responsif dengan legenda yang seimbang, dan tabel yang menggulir
  di dalam kartu tanpa overflow halaman.

### Berkas utama

| Berkas | Perubahan |
|---|---|
| `public/assets/js/main.js` | Toast bertipe, `WifiCare.confirm()`, focus trap, bypass konfirmasi, flash toast, loading, tema |
| `app/Views/components/modal-confirm.php` | Markup modal reusable + aksesibilitas + footer kustom |
| `app/Views/layouts/app.php`, `auth.php` | Modal global, container toast, flash JSON |
| `public/assets/css/style.css` | Gaya modal/toast Light-Dark, responsif, reduced motion, laporan, offset AI mobile |
| `app/Views/teknisi/laporan.php`, `detail.php` | Konfirmasi laporan dan mulai penanganan |
| `app/Views/cs/detail.php` | Konfirmasi lima aksi CS |
| `app/Views/admin/pengaduan_detail.php` | Konfirmasi pembatalan |
| `app/Views/pelanggan/buat.php`, `rating.php` | Konfirmasi kirim pengaduan dan rating |
| `app/Views/admin/pengaturan_ai.php`, `app/Views/notifikasi/index.php` | Modal halaman memakai komponen reusable |
| `app/Views/admin/laporan.php`, `public/assets/js/dashboard.js` | Redesain filter/statistik/grafik/tabel dan legenda |

### Hasil verifikasi

| Pemeriksaan | Hasil |
|---|---|
| Lint PHP seluruh `app/**/*.php` | **0 error** |
| `node --check public/assets/js/main.js` | **OK** |
| `tests/tahap10/e2e.php` | **79 PASS, 0 FAIL** |
| `tests/tahap10/security.php` | **34 PASS, 0 FAIL** |
| `tests/tahap10/login-layout.php` | **43 PASS, 0 FAIL** |
| `tests/tahap10/ai-widget.php` | **81 PASS, 1 SKIP** |
| `tests/tahap12/role-sidebar.php` | **28 PASS, 0 FAIL** |
| `tests/tahap12/revisi-final.php` | **86 PASS, 0 FAIL** |
| `tests/tahap13/keamanan.php` | **102 PASS, 1 SKIP** |
| `tests/tahap11/ai-settings.php` | **67 PASS, 0 FAIL** |
| Browser desktop 912px dan iframe mobile 390px | Modal/toast tidak overflow; halaman laporan tanpa scroll horizontal; toast tidak menutupi FAB AI |
| Tema Light/Dark | Warna modal, toast, kartu laporan, dan tabel mengikuti token |
| Ekspor laporan | PDF `application/pdf` dan XLSX `application/vnd...sheet` status 200; pagination server-side berfungsi |

Catatan: pengujian HTTP di atas dijalankan pada server uji port `8100` dengan
`DB_NAME=wificare_cdn_test`. Verifikasi live provider AI dan cookie `Secure`
penuh tetap terhalang kredensial/kuota/TLS seperti pada catatan Tahap 15.

---

## Tahap 16 - WiFiCare AI Mengenali Identitas Pengguna yang Login (24 Oktober 2026) (selesai)

### Penyebab masalah

- `AiAssistantService` hanya mengirim **role** ke provider AI. Nama dan
  username pengguna tidak pernah masuk ke prompt, sehingga model tidak punya
  sumber kebenaran untuk pertanyaan "Siapa nama saya?" dan dapat menebak,
  memakai contoh, atau mengulang nama dari percakapan.
- Konteks percakapan disimpan per `user_id`, tetapi belum ada pembersihan
  eksplisit saat login/logout. Bila browser berganti akun, state lama masih
  berada pada session sampai ditimpa.
- Belum ada jalur jawaban identitas langsung dari backend, sehingga pertanyaan
  identitas sederhana ikut memanggil provider dan berpotensi tidak konsisten.

### Perbaikan

- Service baru `app/Services/AiIdentity.php` menjadi **satu-satunya** sumber
  identitas: `$_SESSION['user_id']` → `users` terbaru (prepared statement).
  Nama, username, dan role harus valid; akun nonaktif/tidak ada/role invalid
  menghasilkan `null` (request ditolak, bukan ditebak).
- `AiController::chat()` memakai `AiIdentity::fromSession()`; parameter
  `name`, `nama`, `role`, `username`, atau `user_id` dari browser tidak pernah
  dibaca. Bila identitas tidak valid → HTTP 401 JSON `identity_invalid`.
- `AiAssistantService` menyisipkan konteks identitas minimum ke system prompt
  yang sama untuk **Gemini, DeepSeek, dan OpenAI** (via `buildMessages`).
  Prompt melarang memakai nama dari riwayat/klaim pengguna dan melarang
  menaikkan hak akses berdasarkan klaim "saya Admin".
- Pertanyaan identitas sederhana (nama, username, role, "siapa saya", "saya
  login sebagai apa") dijawab langsung oleh PHP dengan
  `request_type=identity` dan `provider=local` — tanpa panggilan provider,
  tanpa kuota AI. Pertanyaan campuran/data operasional tetap diproses AI.
- `AiConversation::clearAll()` dipanggil pada login dan logout, sehingga akun
  berikutnya pada browser yang sama tidak mewarisi konteks akun sebelumnya.
  Riwayat tetap terikat pada `user_id` dan TTL 30 menit.
- Tidak ada perubahan pada autentikasi, RBAC, CSRF, struktur database, tool
  read-only, atau alur status tiket.

### Berkas

| Berkas | Perubahan |
|---|---|
| `app/Services/AiIdentity.php` | **Baru** — identitas session/DB, konteks prompt, jawaban identitas langsung |
| `app/Controllers/AiController.php` | Identitas dari session, penolakan 401, pencatatan request `identity` |
| `app/Services/AiAssistantService.php` | Validasi identitas, jawaban langsung, konteks identitas untuk semua provider |
| `app/Services/Auth.php` | Bersihkan konteks AI saat login dan logout |
| `app/Services/AiConversation.php` | `clearAll()` untuk pemisahan konteks antar-akun |
| `app/bootstrap.php` | Memuat `AiIdentity` |
| `tests/tahap16/identity.php` | **Baru** — 61 pemeriksaan unit + HTTP lintas peran |
| `PROGRESS.md`, `AUDIT_REPORT.md` | Dokumentasi hasil |

### Hasil verifikasi

| Pemeriksaan | Hasil |
|---|---|
| `tests/tahap16/identity.php` | **61 PASS, 0 FAIL** |
| `tests/tahap10/ai-read-tools.php` | **26 PASS, 0 FAIL** |
| `tests/tahap10/ai-gemini-resilience.php` | **23 PASS, 0 FAIL** |
| `tests/tahap10/ai-gemini.php` (live Gemini) | **19 PASS, 0 FAIL** |
| `tests/tahap10/ai-widget.php` | **81 PASS, 1 SKIP** |
| Lint seluruh `app/**/*.php` | **0 error** |
| Lint `tests/tahap16/identity.php` | **OK** |

Identitas langsung (tanpa provider):

| Akun uji | Pertanyaan | Jawaban |
|---|---|---|
| Admin (`admin.budi`) | Siapa nama saya? | Nama Anda Budi Santoso Herman. |
| CS (`cs.rizki`) | Apa role saya? | Role Anda Customer Service. |
| Teknisi (`cdn.rehan`) | Apa role saya? | Role Anda Teknisi. |
| Pelanggan (`Heri`) | Siapa nama saya? | Nama Anda Heri. |

Sapaan melalui provider Gemini nyata (konteks identitas sampai ke model):

| Peran | Jawaban |
|---|---|
| Admin | Halo Pak Budi Santoso, ada yang bisa saya bantu... |
| CS | Halo Dewi Lestari! Ada yang bisa saya bantu... |
| Teknisi | Halo Andi Pratama! Ada yang bisa saya bantu... |
| Pelanggan | Halo Kak Rina Marlina! Ada yang bisa saya bantu... |

Uji manipulasi (login Admin, body berisi `name=Heri`, `role=pelanggan`,
`user_id=14`) tetap menghasilkan **Budi Santoso**. Uji pelanggan yang mengaku
administrator ditolak oleh RBAC; AI menjawab bahwa ia Pelanggan dan tidak
memberi data jumlah pelanggan.

### Catatan yang masih tersisa

- Kuota/free-tier Gemini dan `max_execution_time` PHP dapat menghasilkan
  timeout pada percakapan yang membutuhkan beberapa putaran tool. Bukan
  masalah identitas; jawaban identitas langsung tidak terpengaruh provider.
- DeepSeek/OpenAI belum diuji live karena tidak ada kredensial; jalur prompt
  dan validasi identitas sama karena memakai `buildMessages`/`AiIdentity`.
- Verifikasi cookie `Secure` penuh tetap SKIP tanpa TLS lokal.

---

## Tahap 17 — Pusat Konfigurasi API & Monitoring (26 Oktober 2026)

**Status: selesai untuk konfigurasi, antrean, dan monitoring.** Pengujian
koneksi/pengiriman ke provider nyata tetap **BLOCKED** sampai kredensial
DeepSeek/OpenAI dan Meta WhatsApp Cloud API yang valid tersedia.

### Cakupan

- Halaman Admin baru: **Pengaturan WhatsApp Gateway** dan
  **Monitoring Penggunaan API**. Menu lama tidak diduplikasi.
- Konfigurasi terpusat untuk WhatsApp Gateway, OTP, notifikasi tiket, dan
  rencana laporan; AI tetap memakai halaman Pengaturan AI Chatbot yang sudah
  ada dan tetap multi-provider (Gemini/DeepSeek/OpenAI).
- Satu halaman WhatsApp dengan tab **Gateway**, **OTP**, **Notifikasi**,
  **Laporan**, dan **Audit**; setiap form disimpan lewat endpoint sendiri.
- Fitur dapat diaktifkan/nonaktifkan secara independen. Kegagalan satu fitur
  tidak menghentikan autentikasi, tiket, laporan, atau fitur lain.

### Arsitektur

```
AI Chatbot ──► AiAssistantService / adapter provider AI yang sudah ada
OTP ─────────┐
Notifikasi ──┼─► WhatsAppMessagingService ─► WhatsAppProviderRegistry
Laporan* ────┘                                  └─► WhatsAppCloudProvider

Tiket ──► WhatsAppNotificationService ──► whatsapp_outbox ──► worker
```

`*` Laporan hanya berupa konfigurasi/rencana; belum ada scheduler atau
pengiriman otomatis.

- `WhatsAppMessagingService` adalah satu pintu pengiriman. Tidak ada kode HTTP
  WhatsApp yang diduplikasi per fitur.
- Adapter WhatsApp memakai interface `WhatsAppProviderInterface`; implementasi
  saat ini `WhatsAppCloudProvider` (Meta Cloud API) dengan base URL yang dapat
  dikonfigurasi.
- Notifikasi tiket hanya menulis ke outbox di dalam transaksi tiket. Worker
  `bin/whatsapp-worker.php` memproses antrean di luar transaksi tiket.

### Database (migrasi tambahan)

Migrasi: `database/migrations/008_api_center.sql` (sudah diterapkan pada DB
utama dan DB uji; tidak menghapus data lama).

| Tabel | Fungsi |
|---|---|
| `api_credentials` | Kredensial WhatsApp; token/webhook secret terenkripsi `v1:` |
| `api_integration_settings` | Satu baris per fitur + mapping koneksi + status aktif |
| `api_usage_log` | Metadata penggunaan non-AI; tanpa isi pesan/OTP/token |
| `whatsapp_outbox` | Antrean idempoten; payload & penerima terenkripsi |
| `whatsapp_otp` | Hash OTP + fingerprint HMAC nomor; bukan plaintext |
| `api_config_audit` | Audit perubahan konfigurasi per fitur; tanpa secret |

`expires_at` OTP memakai `DATETIME`, bukan `TIMESTAMP`, agar tidak ikut berubah
saat status pengiriman diperbarui (MariaDB memberi implicit
`ON UPDATE CURRENT_TIMESTAMP` pada `TIMESTAMP`).

### Alur kredensial & konfigurasi

1. Admin membuka **Pengaturan WhatsApp Gateway** dan menambahkan koneksi:
   provider, nama, access token, Phone Number ID, nomor pengirim, Business
   Account ID (opsional), base URL, webhook verification token, dan webhook
   secret.
2. Secret dienkripsi dengan libsodium sebelum disimpan. Master key hanya dari
   environment (`AI_SETTINGS_KEY`, atau alias `API_CREDENTIALS_KEY`). Bila
   master key tidak ada, penyimpanan secret ditolak.
3. Edit dengan field secret kosong mempertahankan secret lama. Mengganti token
   membuat ciphertext baru. Pencabutan bersifat lunak: secret dihapus,
   kredensial ditandai `revoked_at`, dan tidak dapat dipakai lagi.
4. `connection_id = NULL` berarti fitur memakai koneksi WhatsApp aktif. Admin
   dapat memilih koneksi khusus per fitur.
5. Token tidak pernah dikirim ke view/HTML/JSON/log/audit. Halaman hanya
   menampilkan status “secret tersimpan”.
6. Base URL wajib HTTPS; HTTP hanya diizinkan untuk `localhost`, `127.0.0.1`,
   atau `::1` pada pengujian.

### OTP

- Dua use case: `verifikasi_nomor` dan `reset_password`.
- Konfigurasi: status, koneksi, template, masa berlaku, maksimum percobaan,
  cooldown kirim ulang, batas per akun/hari, dan batas per nomor/hari.
- OTP disimpan sebagai `password_hash`, nomor sebagai HMAC fingerprint.
- OTP sekali pakai dan terikat pada akun, purpose, serta nomor tujuan.
- Status monitoring: requested, sent, failed, verified, expired, rate-limited.
  Kode OTP plaintext tidak pernah disimpan atau ditampilkan.

### Notifikasi tiket

Event yang dapat diaktifkan sendiri-sendiri: pengaduan baru, konfirmasi tiket
pelanggan, diverifikasi, ditolak, penugasan teknisi, penanganan dimulai,
selesai, dan dibatalkan.

- Setiap event memiliki template, penerima (`pelanggan`, `admin`, atau nomor
  tertentu), mode teks/template, batas harian, dan retry.
- `event_key` unik mencegah event ganda. Payload dan penerima di outbox
  disimpan terenkripsi.
- Status `accepted` berarti provider menerima request, **bukan** delivered.
  `delivered`/`read` hanya diisi oleh webhook dengan signature
  `X-Hub-Signature-256` yang valid.
- Webhook GET memverifikasi `hub.verify_token`; webhook POST wajib signature.
- Worker: `php bin/whatsapp-worker.php [--limit=10] [--feature=...] [--loop=30]`.

### Laporan

- Halaman menyediakan status, koneksi, penerima berwenang, jenis laporan,
  format PDF/XLSX, jadwal, dan batas harian.
- Fitur laporan **belum mengirim otomatis**. Aktivasi mensyaratkan centang
  persetujuan; scheduler tidak dibuat pada tahap ini. Tidak ada URL publik
  permanen atau penyimpanan isi laporan di log.

### Monitoring Penggunaan API

Sumber data nyata:

- AI: `ai_usage_log` (request, sukses/gagal, token, model, tes terakhir).
- WhatsApp: `api_usage_log` + `whatsapp_outbox` (request, accepted, pending,
  delivered/read, failed, error provider).

Halaman `/admin/monitoring-api` menyediakan filter fitur, provider, dan periode
(hari ini, 7 hari, bulan ini, bulan lalu, semua). Ringkasan dibuat sederhana;
angka biaya/kuota tidak dikarang. Kolom estimasi biaya menampilkan
“Belum tersedia” karena tarif resmi provider tidak disimpan.

### Perbaikan lintas tahap yang diperlukan

- `app/bootstrap.php` sekarang membaca `config('app.timezone')` dari struktur
  yang benar (`app.timezone`). Sebelumnya nilai `APP_TIMEZONE` tidak pernah
  diterapkan; PHP dan MySQL bisa berbeda zona waktu sehingga batas harian AI
  dan monitoring tidak konsisten.
- `Pengaduan::lockRow()` menyertakan `nama_pelanggan_snapshot` agar hook
  notifikasi transisi tiket tidak memicu PHP warning.
- Batas waktu OTP dan retry outbox memakai jam database agar konsisten dengan
  `CURRENT_TIMESTAMP`.

### Pengujian

| Harness | Hasil |
|---|---|
| `tests/tahap17/api-center.php` | **94 PASS, 0 FAIL** |
| `tests/tahap10/e2e.php` | 79 PASS |
| `tests/tahap15/ai-platform.php` | 66 PASS, 2 BLOCKED (live provider) |
| `tests/tahap11/ai-settings.php` | 67 PASS |
| `tests/tahap11/crypto.php` | 19 PASS |
| `tests/tahap11/config-priority.php` (DB uji) | 17 PASS |
| `tests/tahap12/revisi-final.php` | 86 PASS |
| `tests/tahap12/role-sidebar.php` | 28 PASS |
| `tests/tahap10/ai-widget.php` | 81 PASS, 1 SKIP |
| `tests/tahap10/ai-gemini-resilience.php` | 23 PASS |
| `tests/tahap15/ai-tools-revisi.php` | 29 PASS |
| `tests/tahap10/security.php` | 34 PASS |
| `tests/tahap10/auth.php` | 15 PASS |

Regresi identitas HTTP per peran tetap lulus. Tiga assertion unit lama pada
`tests/tahap16/identity.php` gagal karena data akun Admin di DB utama saat ini
`admin.cdn`/`Nur Muhammad`, bukan nama yang di-hardcode test
(`Budi Santoso Herman`). Ini adalah perbedaan dataset, bukan perubahan kode
identitas.

### BLOCKED / sisa risiko

- Live DeepSeek/OpenAI belum dapat diverifikasi tanpa kredensial nyata.
- Live WhatsApp Cloud API (tes koneksi, kirim, webhook Meta nyata) belum dapat
  diverifikasi tanpa access token, Phone Number ID, pengirim/template approved.
  Pengujian saat ini memakai mock lokal dan signature HMAC nyata.
- Pengiriman laporan otomatis belum diimplementasikan (sesuai keputusan).
- Verifikasi cookie `Secure` penuh tetap SKIP tanpa TLS lokal.

### Berkas utama

| Berkas | Perubahan |
|---|---|
| `database/migrations/008_api_center.sql` | **Baru** — enam tabel + seed status fitur |
| `app/Controllers/AdminWhatsAppController.php` | **Baru** — halaman & aksi WhatsApp |
| `app/Controllers/AdminApiMonitoringController.php` | **Baru** — halaman monitoring |
| `app/Controllers/WhatsAppWebhookController.php` | **Baru** — webhook GET/POST |
| `app/Services/ApiCredentialService.php` | **Baru** — siklus kredensial terenkripsi |
| `app/Services/WhatsAppMessagingService.php` | **Baru** — pengiriman/antrean bersama |
| `app/Services/WhatsAppOtpService.php` | **Baru** — OTP + batas + monitoring |
| `app/Services/WhatsAppNotificationService.php` | **Baru** — event tiket + monitoring |
| `app/Services/WhatsAppSettingsService.php` | **Baru** — validasi/simpan per fitur |
| `app/Services/WhatsAppWebhookService.php` | **Baru** — verifikasi & status delivery |
| `app/Services/ApiMonitoringService.php` | **Baru** — agregasi AI + WhatsApp |
| `app/Services/WhatsApp/WhatsAppCloudProvider.php` | **Baru** — adapter Meta Cloud API |
| `app/Views/admin/whatsapp/*.php` | UI konfigurasi WhatsApp (dipecah per submenu) |
| `app/Views/admin/monitoring_api.php` | **Baru** — UI monitoring |
| `bin/whatsapp-worker.php` | **Baru** — worker outbox |
| `routes/web.php`, `app/Helpers/menu.php` | Route & menu Admin baru |
| `public/assets/css/style.css` | Gaya komponen pusat API |
| `tests/tahap17/api-center.php` | **Baru** — 94 pemeriksaan |
| `tests/tahap17/mock-whatsapp.php` | **Baru** — mock provider lokal |

---

## Tahap 18 - Hybrid AI, Database Lokal & Ollama Opsional (26 Oktober 2026) (selesai)

### Ringkasan

WiFiCare AI sekarang dapat menjawab pertanyaan operasional Admin langsung dari
database melalui **Local Database Assistant** deterministik, tanpa mengirim SQL
atau kredensial MySQL ke model AI. Cloud AI tetap dipakai untuk troubleshooting,
percakapan umum, dan pertanyaan yang membutuhkan penalaran. Ollama bersifat
**opsional dan lokal**; tidak ada unduhan model otomatis.

Urutan hybrid yang diterapkan:

1. Intent database lokal yang didukung (jika lokal diaktifkan dan diprioritaskan).
2. Cloud provider aktif untuk troubleshooting/percakapan umum.
3. Ollama lokal (hanya jika Admin mengaktifkan dan mengonfigurasinya).
4. FAQ/database lokal sebagai fallback bila cloud gagal.
5. Pesan batasan yang jujur bila intent tidak didukung atau data tidak tersedia.

Tidak ada perpindahan otomatis ke provider berbayar lain dan tidak ada retry
tanpa batas. Master `is_enabled` tetap mengontrol layanan AI secara keseluruhan;
jawaban database lokal tetap dapat dipakai saat master aktif tetapi cloud belum
dikonfigurasi.

### Cakupan data & RBAC

- Admin dapat membaca data **karyawan** (`admin`, `cs`, `teknisi` saja),
  pelanggan, pengaduan, teknisi, rating, kategori, tren, dan ringkasan
  operasional. Akun `pelanggan` tidak pernah dihitung sebagai karyawan.
- CS tetap dapat membaca data pelanggan/pengaduan sesuai kewenangannya, dengan
  nomor kontak disamarkan. Teknisi dan pelanggan tetap terbatas pada data
  miliknya.
- Pemeriksaan peran dilakukan di `AiOperationsReadService` dan `AiToolService`,
  sehingga jalur lokal dan cloud memakai aturan akses yang sama.
- Follow-up seperti “Apa PPPoE-nya?” dan “Berapa yang selesai?” memakai konteks
  sesi terbatas (`AiContext`); konteks lama dibersihkan saat pengguna berpindah
  dataset agar tidak mencampur entitas.

### Keamanan

- Tidak ada tool SQL bebas, DDL, atau operasi tulis. Semua query memakai PDO
  prepared statement, filter allowlist, batas hasil, dan `SELECT`.
- Password hash, password PPPoE/plaintext, API key, token, session ID, master key,
  dan kredensial MySQL tidak pernah masuk prompt, jawaban, atau tool result.
- Audit akses data AI disimpan di `ai_data_access_log` dengan metadata saja:
  user, role, source, intent, dataset, jumlah hasil, dan status sukses. Isi
  pesan, kata pencarian, nomor telepon, dan alamat tidak disimpan.
- Endpoint Admin tetap dilindungi session + RBAC + CSRF; respons tes provider
  dan MySQL tidak memuat secret.
- Status “Online” tidak pernah ditampilkan tanpa tes nyata. Halaman menampilkan
  status “belum diuji” atau hasil tes terakhir secara akurat.

### Pengaturan Admin

Halaman `/admin/pengaturan/ai` sekarang mencakup:

- mode hybrid, cloud provider, toggle database lokal, prioritas lokal, dan
  fallback otomatis;
- timeout cloud;
- konfigurasi Ollama opsional (aktif/nonaktif, model, base URL loopback,
  timeout);
- tombol tes provider cloud, tes MySQL read-only, dan status hasil tes terakhir.

Form lama/parsial yang tidak mengirim penanda `hybrid_settings_present` tidak
mereset pengaturan hybrid/Ollama yang sudah ada. Konfigurasi model/base Ollama
disimpan di `ai_provider_keys` tanpa menambah kolom duplikat dan tanpa
menghapus key Ollama lama saat form disimpan.

### Migrasi

`database/migrations/009_ai_hybrid.sql` (aditif, sudah diterapkan pada
`wificare_cdn_v1` dan `wificare_cdn_test`):

- kolom `ai_settings`: `hybrid_mode`, `local_db_enabled`, `prefer_local_db`,
  `fallback_enabled`, `ollama_enabled`;
- tabel `ai_data_access_log` beserta indeks dan foreign key `user_id`.

Tidak ada tabel/kolom lama yang dihapus dan tidak ada data yang diubah.

### Berkas utama

| Berkas | Perubahan |
|---|---|
| `database/migrations/009_ai_hybrid.sql` | **Baru** — skema hybrid + audit akses |
| `app/Services/AiOperationsReadService.php` | **Baru** — query baca luas + RBAC |
| `app/Services/LocalDatabaseAssistant.php` | **Baru** — intent deterministik & fallback |
| `app/Services/AiContext.php` | **Baru** — konteks follow-up terbatas |
| `app/Models/AiDataAccessLog.php` | **Baru** — audit metadata-only |
| `app/Services/Ai/OllamaProvider.php` | **Baru** — adapter OpenAI-compatible lokal |
| `app/Services/AiAssistantService.php` | Routing hybrid, konteks, timeout Ollama |
| `app/Services/AiToolService.php` | Tool baca luas + audit akses |
| `app/Services/AiSettingsService.php` | Pengaturan hybrid/Ollama, status tes, tes MySQL |
| `app/Controllers/AdminAiSettingsController.php` | Simpan hybrid + endpoint tes MySQL |
| `app/Controllers/AiController.php` | Reset konteks + pencatatan pemakaian lokal |
| `app/Views/admin/pengaturan_ai.php` | Form hybrid/Ollama + status tes |
| `public/assets/js/ai-settings.js` | Tes provider/MySQL |
| `public/assets/js/ai-render.js` | Tabel aman + badge |
| `public/assets/css/style.css` | Tabel/badge responsif Light/Dark |
| `tests/tahap18/local-ai.php` | **Baru** — 135 pemeriksaan + 1 SKIP |

### Hasil pengujian

| Harness | Hasil |
|---|---|
| `tests/tahap18/local-ai.php` | **135 PASS, 0 FAIL, 1 SKIP** |
| `tests/tahap17/api-center.php` | 94 PASS |
| `tests/tahap10/e2e.php` | 79 PASS |
| `tests/tahap15/ai-platform.php` | 66 PASS, 2 BLOCKED (live provider) |
| `tests/tahap11/ai-settings.php` | 67 PASS |
| `tests/tahap11/crypto.php` | 19 PASS |
| `tests/tahap11/config-priority.php` (DB uji) | 17 PASS |
| `tests/tahap12/revisi-final.php` | 86 PASS |
| `tests/tahap12/role-sidebar.php` | 28 PASS |
| `tests/tahap10/ai-widget.php` | 81 PASS, 1 SKIP |
| `tests/tahap10/ai-gemini-resilience.php` | 23 PASS |
| `tests/tahap15/ai-tools-revisi.php` | 29 PASS |
| `tests/tahap10/security.php` | 34 PASS |
| `tests/tahap10/auth.php` | 15 PASS |

Lint PHP untuk seluruh `app/`, `config/`, `routes/`, dan `tests/` tanpa error;
`node --check` untuk seluruh `public/assets/js/*.js` tanpa error.

### BLOCKED / sisa risiko

- **Live Ollama BLOCKED** — Ollama tidak terpasang/berjalan di lingkungan ini
  (`network`). Tidak ada model yang diunduh. Adapter dan validasi loopback sudah
  diuji secara terbatas, tetapi jawaban model Ollama nyata belum diverifikasi.
- **Live Gemini/DeepSeek/OpenAI BLOCKED** tanpa kredensial/kuota nyata.
- **Live WhatsApp Cloud API BLOCKED** tanpa kredensial Meta/template approved.
- Pengiriman laporan otomatis tetap belum diimplementasikan.
- Verifikasi cookie `Secure` penuh tetap SKIP tanpa TLS lokal.
- Perbedaan dataset membuat beberapa assertion unit lama di
  `tests/tahap16/identity.php` gagal karena nama/ID akun uji tidak lagi sama
  dengan hardcode lama; pengujian identitas HTTP per peran tetap lulus.
  `tests/tahap12/revisi-final.php` dan `tests/tahap17/api-center.php` juga
  diperbaiki agar tidak bergantung pada nomor tiket/ID pelanggan hardcode yang
  dapat bergeser setelah data uji bertambah.

---

## Tahap 19 - Local-First AI, Intent Akurat & Monitoring Sumber (26 Oktober 2026) (selesai)

### Ringkasan

Enam koreksi final diterapkan tanpa membangun ulang aplikasi dan tanpa
mengubah alur bisnis pengaduan:

1. **Local-first nyata** - semua intent data yang dikenali dijawab dari
   MySQL/PHP lebih dulu; cloud tidak dipanggil setelah lokal berhasil.
2. **Intent akurat** - jumlah, daftar, dan pencarian pelanggan dipisahkan;
   peringkat teknisi membedakan akun, penugasan, tugas aktif, tiket selesai,
   tie, dan teknisi tanpa penugasan.
3. **Jawaban ringkas** - pertanyaan fakta dijawab 1-2 kalimat tanpa langkah
   troubleshooting yang tidak diminta.
4. **Konteks aman** - sesi terbatas, dibersihkan saat logout/ganti akun/dataset,
   dan identitas divalidasi ulang dari session/DB pada setiap permintaan.
5. **RBAC read-only** - prepared statement, allowlist, batas hasil, CSRF,
   escaping, dan audit metadata-only; cloud tidak menerima SQL/kredensial.
6. **Fallback jujur** - timeout cloud, MySQL gagal, intent tak dikenal, dan
   akses ditolak memakai pesan berbeda; tidak ada angka/identitas karangan.

### Perubahan penting

- `LocalDatabaseAssistant`: `customers.count`/`list`/`search` dipisahkan;
  `technicians.most_assignments`/`least_assignments` ditambahkan; frasa
  "pelanggan siapa aja" tidak lagi dianggap pencarian nama; "banyak" pada
  "kerjaannya banyak" diperlakukan sebagai peringkat.
- `AiAssistantService`: local-first untuk seluruh intent yang dikenali, cloud
  hanya untuk pertanyaan non-database; prompt ringkas; tanpa SQL bebas;
  timeout cloud terpisah.
- `AiOperationsReadService`: perhitungan penugasan aktif, tiket selesai, dan
  tie konsisten dengan MySQL; `total_tugas` (aktif) dan `total_penugasan`
  (historis non-batal) dibedakan.
- `ai_data_access_log`: metadata-only, agregat 30 hari; `AiUsage::stats()`
  menambahkan `local_database_month`, `local_faq_month`, `cloud_ai_month`,
  dan `timeout_month`.
- Halaman Admin AI menampilkan akses lokal, database/FAQ lokal, request cloud,
  timeout cloud, dan intent lokal tak dikenal.
- `tests/tahap19/local-first.php` (25 pemeriksaan wajib) dan
  `tests/tahap19/mock-timeout.php` (timeout deterministik) ditambahkan.

### Hasil pengujian

| Harness | Hasil |
|---|---|
| `tests/tahap19/local-first.php` | **25 PASS, 0 FAIL, 0 BLOCKED** |
| `tests/tahap18/local-ai.php` | 135 PASS, 0 FAIL, 1 SKIP |
| `tests/tahap17/api-center.php` | 94 PASS, 0 FAIL |
| `tests/tahap10/e2e.php` | 79 PASS, 0 FAIL |
| `tests/tahap10/ai-widget.php` | 81 PASS, 0 FAIL, 1 SKIP |
| `tests/tahap10/security.php` | 34 PASS, 0 FAIL |
| `tests/tahap10/auth.php` | 15 PASS, 0 FAIL |
| `tests/tahap10/login-layout.php` | 43 PASS, 0 FAIL |
| `tests/tahap10/ai-gemini-resilience.php` | 23 PASS, 0 FAIL |
| `tests/tahap11/ai-settings.php` | 67 PASS, 0 FAIL |
| `tests/tahap11/crypto.php` | 19 PASS, 0 FAIL |
| `tests/tahap11/config-priority.php` | 17 PASS, 0 FAIL |
| `tests/tahap12/revisi-final.php` | 86 PASS, 0 FAIL |
| `tests/tahap12/role-sidebar.php` | 28 PASS, 0 FAIL |
| `tests/tahap12/prompt7.php` | 75 PASS, 0 FAIL |
| `tests/tahap15/ai-platform.php` | 66 PASS, 0 FAIL, 2 BLOCKED |
| `tests/tahap15/ai-tools-revisi.php` | 29 PASS, 0 FAIL |

Dua harness regresi diperbaiki agar terisolasi dari data mutable:
`config-priority.php` menyimpan/mengembalikan `ai_provider_keys` dan menghapus
baris provider key saat menguji key legacy; `revisi-final.php` memeriksa field
`dibuat_panjang` untuk format tanggal Indonesia. Ini bukan pelonggaran
assertion keamanan.

### FAIL / BLOCKED yang dicatat terbuka

- `tests/tahap10/ai-read-tools.php` **FAIL** (16 PASS, 10 FAIL): harness lama
  menghardcode ID pengguna dan mengharapkan tool-loop cloud untuk pertanyaan
  data yang kini dijawab lokal. Perilaku ini disengaja oleh local-first.
- `tests/tahap13/keamanan.php` **FAIL** pada Apache/DB utama (akun dan foto
  hardcode tidak cocok dengan dataset mutable). Alur setara di DB uji tetap
  diuji suite lain.
- `tests/tahap16/identity.php` **FAIL** (44 PASS, 17 FAIL) karena assertion unit
  hardcode ID/nama akun lama; seluruh alur identitas HTTP per peran PASS.
- Live Ollama/Gemini/DeepSeek/OpenAI dan WhatsApp Cloud API **BLOCKED** tanpa
  runtime/kredensial nyata. Tidak ada klaim PASS untuk live provider.

Rincian lengkap ada di `AI_LOCAL_FIRST_REPORT.md`.

## Tahap 20 - Pencarian Nama Pelanggan Lokal & Monitoring Provider (27 Oktober 2026) (selesai)

### Ringkasan

Bug nyata yang dilaporkan: pertanyaan
`apakah ada pelanggan atas nama agus` dijawab
`Pelanggan dengan nama "atas nama agus" tidak ditemukan.` Local Database
Assistant sudah merespons cepat, tetapi nilai yang diekstrak salah. Perbaikan
dilakukan pada ekstraksi entitas, routing intent, query baca pelanggan, dan
pesan hasil; bukan dengan menghardcode nama Agus.

Tahap ini juga menambahkan pencatatan event provider AI dan notifikasi Admin
untuk kuota/kredit habis. Pemantauan membedakan kuota habis, rate limit
sementara, autentikasi, izin, timeout, jaringan, model tidak tersedia, dan
kesalahan server.

### Akar masalah

- Pola lama `pelanggan\s+(.+)$` menangkap seluruh frasa setelah kata
  `pelanggan`, termasuk `atas nama`.
- `cleanName()` belum membuang frasa pengantar `atas nama`, `bernama`,
  `yang bernama`, `yang namanya`, dan `dengan nama`.
- Pertanyaan `apakah agus terdaftar sebagai pelanggan?` tidak memiliki pola
  nama terbalik sehingga jatuh ke `customers.list`.
- `siapa pelanggan terbaru?` belum memiliki intent `customers.latest` dan
  jatuh ke daftar pelanggan.
- Kata `nya` pada `namanya` ikut terbaca sebagai referensi pronomina sehingga
  `pelanggan yang namanya agus` bisa salah menjadi `customers.detail`.

Nilai ekstraksi sebelum perbaikan:

| Pertanyaan | Nilai lama | Akibat |
|---|---|---|
| `apakah ada pelanggan atas nama agus` | `atas nama agus` | tidak ditemukan |
| `pelanggan atas nama agus setiawan` | `atas nama agus setiawan` | tidak ditemukan |
| `apakah agus terdaftar sebagai pelanggan?` | kosong | `customers.list` |
| `siapa pelanggan terbaru` | kosong | `customers.list` |
| `pelanggan yang namanya agus` | `agus` tetapi konteks `nya` menang | `customers.detail` |

Nilai ekstraksi setelah perbaikan:

| Pertanyaan | Nilai baru | Intent |
|---|---|---|
| `apakah ada pelanggan atas nama agus` | `agus` | `customers.search` |
| `pelanggan atas nama agus setiawan` | `agus setiawan` | `customers.search` |
| `apakah agus terdaftar sebagai pelanggan?` | `agus` | `customers.search` |
| `siapa pelanggan terbaru` | kosong | `customers.latest` |
| `pelanggan yang namanya agus` | `agus` | `customers.search` |

### Perubahan kode

- `LocalDatabaseAssistant`: pola ekstraksi nama diperluas untuk frasa pengantar,
  pola terbalik `terdaftar sebagai pelanggan`, nama lengkap, kapitalisasi, dan
  spasi; frasa pengantar dibuang sebelum pembersihan kata intent; intent
  `customers.latest` ditambahkan; `lookupOnly` menampilkan tabel ringkas
  (nama, PPPoE, status) untuk pertanyaan keberadaan.
- `AiOperationsReadService`: `customerSearch()` tetap membaca
  `pelanggan INNER JOIN users`, bukan snapshot pengaduan, dengan LIKE parameter
  ter-escape dan batas hasil; `latestCustomer()` ditambahkan dengan urutan
  `p.created_at DESC, p.id DESC`.
- Pesan tidak ditemukan menjadi
  `Pelanggan bernama <Nama> tidak ditemukan.` (tanpa mengulang frasa
  pengantar).
- `AiProviderErrorClassifier`, `AiProviderMonitor`, migrasi
  `010_ai_provider_events.sql`, halaman Admin AI, serta sumber
  `local_database`/`local_faq`/`cloud_ai` pada `ai_usage_log` dipertahankan dari
  pekerjaan Tahap 20 sebelumnya.
- `tests/tahap20/customer-name-search.php` ditambahkan: 20 skenario wajib
  dengan fixture DB uji yang dibersihkan kembali.
- `tests/tahap20/provider-monitoring.php` ditambah bukti pencarian nama tetap
  lokal saat provider cloud gagal kuota.
- `tests/tahap16/identity.php` diubah agar membaca akun Admin/CS/teknisi/
  pelanggan dari fixture DB, bukan ID/nama hardcode.

### Query dan tabel

- Pencarian: `pelanggan p INNER JOIN users u ON u.id = p.user_id`,
  `WHERE u.nama LIKE :q_nama OR u.username LIKE :q_username OR p.username_pppoe LIKE :q_pppoe`.
- Pelanggan terbaru: tabel `pelanggan` + `users`, urutan
  `p.created_at DESC, p.id DESC`, `LIMIT 1`.
- Hasil pencarian dibatasi 10 (maksimum 25) dan tidak menyertakan pengaduan
  sebagai syarat.
- Semua nilai memakai PDO prepared statement; tidak ada SQL dari isi chat.
- Tidak ada kredensial yang ditulis ke laporan atau respons.

### Bukti pengujian

| Harness | Hasil |
|---|---|
| `tests/tahap20/customer-name-search.php` | **20 PASS, 0 FAIL** |
| `tests/tahap20/provider-monitoring.php` | **24 PASS, 0 FAIL, 0 BLOCKED** |
| `tests/tahap20/local-latest.php` | **14 PASS, 0 FAIL** |
| `tests/tahap19/local-first.php` | **25 PASS, 0 FAIL, 0 BLOCKED** |
| `tests/tahap18/local-ai.php` | 103 PASS, 0 FAIL, 2 SKIP (Ollama live dan server uji HTTP tidak aktif) |
| `tests/tahap16/identity.php` | 27 PASS, 0 FAIL, 1 SKIP (server uji HTTP tidak aktif) |
| `tests/tahap10/ai-read-tools.php` | 18 PASS, 8 FAIL (harness lama mengharapkan tool-loop cloud; kini local-first) |
| `tests/tahap13/keamanan.php` | 77 PASS, 25 FAIL, 1 SKIP (akun/fixture hardcode pada DB utama) |

Bukti utama:

- `apakah ada pelanggan atas nama agus` ->
  `Pelanggan bernama Agus Setiawan ditemukan.` pada DB uji.
- `apakah ada pelanggan atas nama zzz tidak ada` ->
  `Pelanggan bernama Zzz Tidak Ada tidak ditemukan.`
- `apakah agus terdaftar sebagai pelanggan?` -> `customers.search`, bukan
  daftar.
- `siapa pelanggan terbaru` -> `customers.latest`.
- Pelanggan dengan role `pelanggan` mendapat `access.denied`; Admin dan CS
  dapat mencari.
- Tabel keberadaan tidak memuat WhatsApp/alamat.
- Follow-up `berapa pengaduannya` setelah pencarian memakai pelanggan yang
  benar.

### FAIL / BLOCKED yang masih terbuka

- `tests/tahap10/ai-read-tools.php` masih menguji function-calling cloud untuk
  pertanyaan yang sekarang dijawab lokal. Ini konsekuensi desain local-first;
  pengujian keamanan tool dan RBAC di harness tersebut tetap lulus.
- `tests/tahap13/keamanan.php` memakai akun `admin.budi`, `pppoe.rina`, dan
  `pppoe.agus` yang tidak ada pada DB utama saat ini. Tidak ada fixture
  produksi yang dibuat.
- Live Ollama/Gemini/DeepSeek/OpenAI dan WhatsApp Cloud API tetap BLOCKED tanpa
  runtime/kredensial/kuota nyata. Mock provider dipakai untuk menguji kuota,
  rate limit, timeout, dan deduplikasi notifikasi.

### Berkas utama

- `app/Services/LocalDatabaseAssistant.php`
- `app/Services/AiOperationsReadService.php`
- `tests/tahap20/customer-name-search.php`
- `tests/tahap20/provider-monitoring.php`
- `tests/tahap16/identity.php`

Semua perubahan tetap belum di-commit.