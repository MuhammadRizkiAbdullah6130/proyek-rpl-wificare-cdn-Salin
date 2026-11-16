# AUDIT_REPORT — WiFiCare CDN (Tahap 10)

Audit menyeluruh, perbaikan bug, pengerasan keamanan, dan finalisasi untuk
**WiFiCare CDN v1.0** (PHP Native 8.2+, MySQL, tanpa framework).

Tanggal audit: 17 Oktober 2026
Lingkup: seluruh Tahap 1–9 (struktur, database, autentikasi/RBAC, modul
pelanggan/CS/teknisi/admin, alur status tiket, unggahan berkas, notifikasi,
rating, audit log, laporan/analitik, ekspor PDF/Excel, AI Assistant, UI/UX,
kinerja, dan integrasi).

Metode: pembacaan kode menyeluruh + **pengujian otomatis nyata** terhadap
instance berjalan. Pengujian destruktif dijalankan pada **database uji
terpisah** (`wificare_cdn_test`), bukan database utama. Rincian hasil ada di
`TEST_REPORT.md`.

---

## 1. Ringkasan Eksekutif

Aplikasi berada pada kondisi **sehat dan layak produksi (dengan catatan)**.
Tidak ditemukan kerentanan kritis (P0) maupun celah keamanan tinggi (P1) yang
terbuka. Seluruh kontrol keamanan wajib (hash password, RBAC di setiap route,
CSRF, validasi server, unggahan terverifikasi, output ter-escape, tanpa SQL
mentah) **terverifikasi berfungsi** melalui pengujian.

Ditemukan dan **sudah diperbaiki** 5 cacat:

  ID   Prioritas   Ringkasan   Status
 ---- ----------- ----------- --------
  A4   P1   View `app/Views/admin/pelanggan_detail.php` **hilang**, padahal route `/admin/pelanggan/{id}` (modul Admin) memanggilnya, sehingga halaman "Riwayat Pelanggan" admin rusak   ✅ Diperbaiki
  A1   P2   Sesi pengguna yang **dinonaktifkan saat sudah login** menerima HTTP 403, bukan diarahkan ke halaman login   ✅ Diperbaiki
  A2   P2   `bin/seed-sample.php --reset` gagal dengan pelanggaran foreign key (`penyelesaian_cs` tidak dihapus)   ✅ Diperbaiki
  A3   P3   Komentar `Session` menjanjikan batas **idle**, tetapi implementasi memakai batas absolut (komentar ≠ perilaku)   ✅ Diperbaiki
  A5   P3   Input `username[]` (array) pada `POST /login` memicu PHP warning *Array to string conversion*   ✅ Diperbaiki

Tidak ada temuan **P0**. Satu temuan **P1** (A4 — halaman admin rusak) dan dua
P2 (A1, A2) serta dua P3 (A3, A5) — semuanya telah diperbaiki. Sisanya adalah
catatan operasional (mis. `APP_DEBUG` lokal, ketergantungan CDN, integrasi AI
eksternal yang belum dikonfigurasi).

**Hasil pengujian:** 258 assertion otomatis **semuanya lulus** (79 E2E, 34
keamanan/tepi, 15 auth, 47 laporan CLI, 48 regresi HTTP, 15 AI CLI, 20 AI
HTTP via mock). Lint 128 berkas PHP bersih, 3 berkas JS lolos `node --check`.

---

## 2. Struktur Proyek

```
aduan-pelanggan/
├── app/
│   ├── bootstrap.php          # pemuat env, config, helper, service, model
│   ├── Controllers/ (18)      # 1 controller per area + base controller
│   ├── Models/ (14)           # repository; SELURUH SQL ada di sini
│   ├── Services/ (23)         # logika tulis/transaksi/keamanan/ekspor/AI
│   ├── Middleware/ (1)        # AuthMiddleware (auth + RBAC)
│   ├── Helpers/ (4)           # functions, auth, env, menu
│   └── Views/ (56)            # layout + komponen + view per peran
├── bin/ (3)                   # migrate.php, create-user.php, seed-sample.php (CLI saja)
├── config/ (2)                # app.php, database.php (tanpa kredensial)
├── database/
│   ├── migrations/ (5)        # 001..005, idempotent
│   └── seeds/ (2)             # kategori, pengaturan
├── public/                    # DOCUMENT ROOT — hanya ini yang publik
│   ├── index.php              # front controller
│   ├── .htaccess              # rewrite + header keamanan + Options -Indexes
│   └── assets/                # css/js (tanpa berkas rahasia)
├── routes/web.php             # 73 route
├── storage/                   # DI LUAR document root
│   ├── logs/                  # app.log
│   └── uploads/{pengaduan,laporan}/
├── .env / .env.example / .gitignore
├── composer.json
├── README.md / PROGRESS.md
└── AUDIT_REPORT.md / TEST_REPORT.md
```

**Arsitektur (sesuai PRD, tanpa framework):**
- MVC sederhana; `public/index.php` sebagai satu-satunya pintu masuk HTTP.
- Routing deklaratif di `routes/web.php` dengan middleware `guest`/`auth`/`role`
  (string **atau** array).
- Aliran data: tulis = Controller → Service → Model (dalam transaksi);
  baca = Controller → Model.
- Berkas sensitif (`app/`, `config/`, `database/`, `routes/`, `storage/`,
  `.env`) berada **di luar document root** — tidak dapat diakses langsung.
- `public/` hanya berisi front controller, `.htaccess`, dan aset statis.

Skala: 128 berkas PHP (termasuk 4 harness pengujian), 73 route, 5 migrasi,
17 tabel.

---

## 3. Modul & Status Fitur

  Modul   Status   Catatan verifikasi
 ------- -------- --------------------
  Autentikasi (login/logout)   ✅ Lengkap   Session regenerate, CSRF rotate, throttle 5 percobaan/15 mnt + 10/IP, pesan seragam, akun nonaktif ditolak
  RBAC 4 peran   ✅ Lengkap   Silang peran ditolak 403; `role` string/array; ditegakkan middleware sebelum controller
  Pelanggan   ✅ Lengkap   Buat tiket + wajib foto, nomor unik, riwayat, detail, rating sekali per tiket, profil
  Customer Service   ✅ Lengkap   Verifikasi, tolak (wajib alasan), selesaikan langsung, tugaskan teknisi, monitoring, catatan internal, batalkan
  Teknisi   ✅ Lengkap   Hanya tugas miliknya, mulai, laporan perbaikan + wajib foto, riwayat, WA
  Admin   ✅ Lengkap   Pengguna, pelanggan (termasuk riwayat per pelanggan), kategori, pengaturan, audit log, monitoring, arsip
  Alur status tiket   ✅ Lengkap   Tepat 6 status; transisi ilegal ditolak; atomik (transaksi + `FOR UPDATE`); batal & arsip sebagai atribut terpisah
  Notifikasi in-app   ✅ Lengkap   Terisolasi per `user_id`; tidak menyentuh milik pengguna lain
  Rating   ✅ Lengkap   Hanya pemilik, hanya tiket `selesai`, tidak dibatalkan, sekali saja
  Laporan & Analitik   ✅ Lengkap   9 jenis; satu dataset dipakai layar + PDF + Excel
  Ekspor PDF / Excel   ✅ Lengkap   `PdfReport`/`XlsxWriter` native (tanpa Composer); anti formula-injection (`inlineStr`)
  AI Assistant   ⚠️ Kode lengkap; integrasi eksternal BLOCKED   Kunci & model kosong di `.env` → layanan menampilkan pesan "belum dikonfigurasi". Pipeline diuji via mock 20/20
  UI/UX responsif   ✅ Lengkap   Palet PRD tepat; 12 media query; viewport; tanpa horizontal overflow

Tidak ada fitur yang hilang dari PRD. Tidak ada fitur di luar PRD yang ditambahkan.

---

## 4. Temuan per Kategori

### 4.1 Database (MySQL/PDO)
- Semua query memakai **prepared statement** (`ATTR_EMULATE_PREPARES=false`);
  tidak ada input pengguna disisipkan ke SQL. **Terverifikasi** (tidak ada
  superglobal di `app/Models`).
- **Foreign key** lengkap (22 relasi) dengan aturan RESTRICT pada data riwayat;
  hanya `notifikasi` yang CASCADE. **Terverifikasi** via `information_schema`.
- **Unique key** menjamin: `uq_users_username`, `uq_pelanggan_pppoe`,
  `uq_pengaduan_tiket`, `uq_laporan_pengaduan`, `uq_penyelesaian_pengaduan`,
  `uq_rating_pengaduan`.
- **Indeks** memadai untuk filter/join laporan (`idx_pengaduan_status`,
  `idx_pengaduan_created`, `idx_pengaduan_kategori`, dst.).
- **Integritas** (DB uji): 0 orphan, 0 nomor tiket duplikat, 0 penugasan aktif
  ganda, 0 laporan/penyelesaian/rating ganda, 0 status di luar 6 status resmi.
- Migrasi `001..005` **idempotent** dan berhasil dijalankan pada database baru
  (`bin/migrate.php` mencatat ke tabel `migrations`).
- **Temuan A2 (P2, diperbaiki):** `bin/seed-sample.php --reset` menghapus
  `pengaduan` tanpa lebih dulu menghapus `penyelesaian_cs` → gagal
  `SQLSTATE[23000] 1451` pada database yang memiliki tiket "selesai langsung
  oleh CS". Diperbaiki dengan menambahkan `DELETE FROM penyelesaian_cs`.

### 4.2 Autentikasi & RBAC
- Password `password_hash()` (bcrypt); tidak ada plaintext; tidak ada kolom
  password jaringan PPPoE (terverifikasi pada skema `pelanggan`).
- Session: `HttpOnly`, `SameSite=Lax`, `use_strict_mode`, `use_only_cookies`.
- **Anti session fixation**: ID berubah setelah login (**terverifikasi**).
- Registrasi publik **tidak ada** (`/register`, `/daftar`, `/signup` → 404).
- Throttle brute-force **terverifikasi**: percobaan ke-6 terkunci walau password
  benar; akun nonaktif tidak dapat login.
- Setiap POST terverifikasi CSRF (missing/invalid → 419).
- **Temuan A1 (P2, diperbaiki):** `AuthMiddleware::auth()` hanya memeriksa
  `Auth::check()` (ada `user_id` di session). Bila akun dinonaktifkan **saat
  sesi berjalan**, `Auth::user()` memang mengakhiri sesi, tetapi `role()`
  sudah terlanjur menilai peran sebagai kosong → **HTTP 403**, bukan pengalihan
  ke login. Perbaikan: `auth()` kini juga memanggil `Auth::user()` dan
  mengarahkan ke `/login` bila sesi tidak valid.
- **Temuan A3 (P3, diperbaiki):** `Session::start()` hanya menyetel
  `_started_at` sekali (batas absolut), padahal komentar menyebut batas
  **idle**. Perbaikan: menambah `_last_activity` yang diperbarui setiap request
  dan mengakhiri sesi setelah `SESSION_LIFETIME` detik tanpa aktivitas.

### 4.3 Keamanan Aplikasi
- **SQL Injection**: tidak ada; semua parameter ter-bind.
- **XSS**: output ter-escape (`e()`). **Terverifikasi**: deskripsi tiket berisi
  `<script>alert("xss")</script>` tampil ter-escape di halaman pelanggan, CS,
  admin, dan laporan; respons AI dirender dengan `textContent`.
- **CSRF**: diterapkan pada semua aksi tulis. **Terverifikasi** (POST tanpa
  token → 419).
- **IDOR**: **terverifikasi** — pelanggan lain → 403 pada detail & foto tiket;
  teknisi lain → 403; notifikasi terisolasi; AI hanya boleh tiket yang berhak.
- **Broken access control**: RBAC silang peran → 403; route admin oleh peran
  lain → 403.
- **Upload**: MIME asli (`finfo` + `getimagesize`), ekstensi ditentukan server,
  nama acak 32-hex, disimpan **di luar document root**, disajikan lewat endpoint
  ber-otorisasi. **Terverifikasi**: berkas `.php` berkedok `.png` ditolak; tiket
  tidak dibuat; foto hanya dapat diakses pemilik/berwenang.
- **Info disclosure**: `render_error` tidak membocorkan detail exception; mode
  debug mati secara default (`env_bool('APP_DEBUG', false)`). Tidak ada API key
  atau hash password di HTML/JS/audit log (**terverifikasi**).
- **Formula injection (Excel)**: semua teks ditulis sebagai `inlineStr` →
  tidak ditafsirkan sebagai formula (**terverifikasi** pada berkas `.xlsx`).
- **Brute force / direct file access / session hijacking**: ditangani
  (throttle, `.htaccess` + `Options -Indexes` + berkas sensitif di luar docroot,
  `HttpOnly` + regenerasi ID).

### 4.4 Unggahan & Berkas
- Lokasi penyimpanan, penamaan, dan validasi sesuai PRD.
- **Orphan bersih**: setelah pengujian, 8 berkas yatim (artefak uji, **tidak
  dirujuk DB mana pun**) dihapus; 0 berkas hilang untuk 10 referensi aktif.
- Tidak ada berkas eksekutabel yang dapat tersimpan.

### 4.5 Notifikasi, Rating, Audit Log
- Notifikasi terisolasi per pengguna (**terverifikasi**). "Baca semua" tidak
  menyentuh milik pengguna lain.
- Rating sekali per tiket, hanya tiket `selesai`, bukan yang dibatalkan
  (**terverifikasi**).
- `audit_log` **append-only**, tidak menyimpan password/API key
  (**terverifikasi**).

### 4.6 Laporan, Analitik, PDF/Excel
- 9 jenis laporan (`semua`, `periode`, `status`, `teknisi`, `kategori`,
  `pelanggan`, `durasi`, `rating`, `analitik`) semuanya HTTP 200.
- Layar, PDF, dan Excel berasal dari **dataset yang sama** → filter konsisten.
- PDF: magic `%PDF-`, memiliki `xref`/EOF/halaman. Excel: `.xlsx` asli (ZIP,
  dapat dibuka `ZipArchive`, punya `sheetData`, tipe `inlineStr`).
- Filter tanggal terbalik / `jenis` tak dikenal / `page` negatif tidak memicu
  500 (fallback aman).
- Ekspor hanya untuk Admin (**terverifikasi** teknisi/pelanggan → 403).

### 4.7 AI Assistant
- Kunci API hanya dibaca sisi server; **tidak** muncul di HTML/JS
  (**terverifikasi**).
- Model tidak mengakses DB langsung; hanya lewat allowlist tool
  (`get_ticket_status`, `get_app_usage_guide`, `get_wifi_troubleshooting_guide`,
  `get_faq`) dengan otorisasi backend (**terverifikasi**).
- Otorisasi tiket per peran benar: pelanggan hanya tiketnya, teknisi hanya yang
  ditugaskan, CS/Admin semua.
- **AI tidak dapat mengubah status** dan **prompt injection tidak dapat
  melewati RBAC** (**terverifikasi** via mock).
- Timeout, error provider (401/403/429/5xx), respons tak terduga, dan fallback
  tanpa function-calling ditangani.
- Rate limit berbasis session: permintaan ke-3 saat limit=2 → 429
  (**terverifikasi**).
- **Integrasi AI eksternal = BLOCKED**: `.env` tidak berisi kunci/model nyata,
  sehingga komunikasi ke provider sungguhan tidak dapat diuji. Pipeline diuji
  dengan provider tiruan (mock) 20/20.

### 4.8 UI/UX & Frontend
- Palet Dark Mode tepat sesuai PRD (`#0B1120`, `#172238`, `#2563EB`, `#60A5FA`,
  `#F8FAFC`, `#94A3B8`) — terverifikasi di CSS.
- Responsif: 12 media query, `name="viewport"`, sidebar + hamburger, tabel
  responsif, modal/toast, grafik.
- JS lolos `node --check`; tidak ada error sintaks.

### 4.9 Kinerja
- Query laporan memakai `COUNT(DISTINCT p.id)` dan JOIN 1:0..1 (tidak menghitung
  ganda). Pagination pada semua daftar.
- Indeks tersedia untuk kolom filter/join utama.
- Aset: `style.css` ~60 KB, `main.js` ~21 KB, `ai.js` ~6 KB,
  `dashboard.js` ~6 KB (tanpa build step).
- Tidak ada caching bersama yang berisiko (tidak ada cache lintas-pengguna).

### 4.10 Integrasi & Ketergantungan
- Bootstrap 5 & Chart.js dimuat dari CDN (jsdelivr). Secara offline, tampilan
  dan grafik tidak termuat. **Bukan bug**, tetapi catatan deployment
  (dapat di-vendor-kan bila perlu).
- `composer.json` menyatakan `require-dev: phpunit/phpunit` dan autoload
  `Tests\\` → `tests/`, tetapi Composer/vendor belum dipasang dan direktori
  `tests/` belum ada. Pengujian Tahap 10 memakai harness native (lihat
  `TEST_REPORT.md`).
- Tanpa `GD`; PDF/Excel dibuat dengan penulis native bebas-dependensi.

### 4.11 Konsistensi View & Route
- Seluruh 34 nama view yang direferensikan controller **ada** (dipindai
  otomatis, 0 hilang) — setelah perbaikan A4. Seluruh 12 komponen
  (`table`, `modal-confirm`, dst.) juga ada.
- Setiap route GET yang terdaftar di `routes/web.php` mengarah ke controller
  dan aksi yang benar-benar ada (diverifikasi lewat smoke test seluruh halaman
  pada `t9-regression.ps1` + suite Tahap 10).
- Menemukan bug A4: aplikasi dapat "terlihat lulus" pada uji status HTTP 200
  semata; karena itu audit menambahkan pemeriksaan keberadaan view dan
  pemeriksaan tak adanya `Warning:`/`Fatal error` pada body.

---

## 5. Prioritas

**P0 (kritis):** tidak ada.
**P1 (tinggi):** A4 (halaman admin rusak) — sudah diperbaiki dan diuji ulang.
**P2 (sedang):** A1, A2 — sudah diperbaiki dan diuji ulang.
**P3 (rendah):** A3, A5 diperbaiki; sisanya rekomendasi operasional:

  ID   Rekomendasi   Dampak bila diabaikan
 ---- ------------- ------------------------
  R1   Setel `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_COOKIE_SECURE=true` di server HTTPS produksi   Error rinci/detail teknis dapat tampil bila terjadi kesalahan
  R2   Isi `AI_API_KEY`/`AI_MODEL` bila AI Assistant ingin diaktifkan   Fitur AI menampilkan pesan "belum dikonfigurasi"
  R3   (Opsional) Vendor-kan Bootstrap/Chart.js agar berjalan offline   Tampilan/grafik hilang tanpa internet
  R4   (Opsional) Hapus dead code `AdminController::laporan()` (route sudah diarahkan ke `AdminLaporanController`)   Kebingungan pemeliharaan saja
  R5   Sediakan `tests/` bila ingin memakai PHPUnit (atau hapus `require-dev`/`autoload-dev`)   Ketidaksesuaian `composer.json`

---

## 6. Detail Perbaikan (root cause → dampak → perbaikan → uji)

### A4 — View `admin/pelanggan_detail.php` hilang (halaman admin rusak)
- **Gejala:** membuka `/admin/pelanggan/{id}` (tombol **Detail** pada Data
  Pelanggan) menghasilkan halaman rusak; log server mencatat
  `include(.../Views/admin/pelanggan_detail.php): Failed to open stream`.
- **Akar masalah:** `AdminPelangganController::pelangganDetail()` memanggil
  `renderRole('admin/pelanggan_detail', ...)`, tetapi berkas view tersebut tidak
  pernah dibuat (versi CS-nya ada: `cs/pelanggan_detail.php`).
- **Dampak:** fitur "Riwayat Pelanggan" pada modul Admin (fitur inti) tidak
  dapat digunakan (isi halaman kosong + peringatan PHP saat debug aktif).
- **Perbaikan minimal:** membuat `app/Views/admin/pelanggan_detail.php` yang
  menampilkan identitas pelanggan (termasuk status akun) dan seluruh riwayat
  tiketnya, dengan tautan detail ke `/admin/pengaduan/{id}` (jalur admin), bukan
  jalur CS. Menambahkan pemindaian otomatis memastikan **semua** view yang
  dipanggil controller kini ada (0 yang hilang).
- **Uji:** `security.php` — "admin detail pelanggan 200", "tanpa warning/fatal",
  "memuat nama pelanggan", "tak ada → 404", plus smoke `/admin/pengaduan/{id}`
  dan `/admin/teknisi/{id}` **lulus**.

### A5 — Input array pada `POST /login` memicu PHP warning
- **Gejala:** mengirim `username[]=a` (array) menghasilkan
  `PHP Warning: Array to string conversion in AuthController.php on line 37`.
- **Akar masalah:** `(string) ($_POST['username'] ?? '')` memaksa konversi array.
- **Dampak:** peringatan PHP pada log (dan berpotensi tampil bila debug aktif);
  bukan eskalasi hak karena validasi tetap menolak.
- **Perbaikan minimal:** hanya menerima nilai bertipe string
  (`is_string($_POST['username'])`), selain itu dianggap kosong.
- **Uji:** `security.php` — "login array injection tidak 500" **lulus**; tidak
  ada lagi peringatan pada log server.

### A1 — Sesi akun nonaktif → 403 (bukan login)
- **Gejala:** pengguna yang dinonaktifkan Administrator saat sesi aktif
  menerima halaman **403 Akses Ditolak** pada request berikutnya.
- **Akar masalah:** `AuthMiddleware::auth()` hanya `Auth::check()` (melihat
  `user_id` di session). Penonaktifan baru terdeteksi di `Auth::user()` yang
  mengakhiri sesi; namun `role()` sudah menghitung peran kosong → 403.
- **Dampak:** pengalaman menyesatkan; tidak konsisten dengan kebijakan
  "akun nonaktif tidak boleh berlanjut". Bukan eskalasi hak (akses tetap
  ditolak), tetapi UX/kebijakan salah.
- **Perbaikan minimal:** di `auth()`, panggil `Auth::user()`; bila `null`
  (sesi diakhiri), flash + `redirect('/login')`. Peran tetap tegas.
- **Uji:** `t10-auth.php` — "setelah dinonaktifkan, sesi dipaksa keluar
  (302 login)" **lulus**.

### A2 — `seed-sample.php --reset` gagal FK
- **Gejala:** `php bin/seed-sample.php --reset` → `SQLSTATE[23000] 1451
  Cannot delete or update a parent row` (`penyelesaian_cs`).
- **Akar masalah:** urutan `DELETE` tidak menyertakan `penyelesaian_cs`
  (tabel baru pada migrasi 004) sebelum menghapus `pengaduan`.
- **Dampak:** skrip penyiapan data contoh gagal pada database yang memiliki
  tiket "diselesaikan langsung oleh CS".
- **Perbaikan minimal:** tambah `DELETE FROM penyelesaian_cs;` sebelum
  `DELETE FROM pengaduan;`.
- **Uji:** `seed-sample.php --reset` berjalan **OK** pada `wificare_cdn_test`
  (11 tiket contoh dibuat).

### A3 — Batas idle sesi (komentar ≠ perilaku)
- **Gejala:** sesi berakhir tepat setelah `SESSION_LIFETIME` **sejak login**,
  walaupun pengguna aktif — bukan berdasarkan **idle**.
- **Akar masalah:** hanya `_started_at` yang disetel sekali dan tidak
  diperbarui.
- **Perbaikan minimal:** tambah `_last_activity`, diperbarui tiap request;
  akhiri sesi bila `now - _last_activity > lifetime`.
- **Uji:** seluruh suite auth/E2E tetap lulus (tidak ada regresi).

---

## 7. Batasan & Hal yang Memblokir

- **AI integrasi nyata: BLOCKED** — tidak ada `AI_API_KEY`/`AI_MODEL` valid dan
  koneksi ke provider eksternal tidak dapat diandalkan; pengujian integrasi
  ditandai **BLOCKED**, bukan PASSED. Yang diuji dan lulus adalah pipeline
  endpoint (mock), keamanan kunci, otorisasi tool, penanganan error/timeout,
  rate limit, dan ketahanan prompt injection.
- Tidak ada git repository; seluruh perubahan adalah berkas working tree.

---

## 8. Kesimpulan

WiFiCare CDN memenuhi persyaratan fungsional dan keamanan PRD. Lima cacat yang
ditemukan — satu P1 (A4, halaman admin rusak), dua P2 (A1, A2), dan dua P3
(A3, A5) — telah diperbaiki dengan perubahan minimal dan diverifikasi ulang.
Tidak ada temuan P0. Rekomendasi operasional (R1–R5) bersifat
pengerasan/penyempurnaan, bukan penghalang rilis.

Bukti pengujian lengkap: lihat `TEST_REPORT.md`.

---

## 9. Addendum — Pembaruan UI: Floating AI Assistant (Tahap 10 lanjutan)

Perubahan atas permintaan pengguna: memindahkan AI Assistant dari menu sidebar
menjadi **floating chat widget** pojok kanan bawah pada semua halaman yang
memerlukan login. Tidak ada controller/service/endpoint AI yang dihapus dan
tidak ada endpoint duplikat dibuat.

### 9.1 Ruang lingkup audit perubahan

- **Menu sidebar** — empat entri `AI Assistant` (`app/Helpers/menu.php`) dihapus.
  Diverifikasi: menu tiap peran tetap valid dan semua rute lain tetap berfungsi
  (E2E/keamanan tetap 200 seperti sebelumnya).
- **Pemasangan tunggal (DRY)** — widget dirender satu kali dari
  `app/Views/layouts/app.php`; tidak ada salinan kode per halaman.
- **Halaman `/ai` lama** — tetap ada dan berfungsi (fitur tidak dihapus), walau
  tanpa menu. Rute tetap terdaftar.
- **Z-index** — widget `1060`: di atas sidebar/scrim (`1025`) dan dropdown
  (`1040`), di bawah modal (`1080`) dan toast (`1100`). Tidak ada elemen yang
  saling menutupi secara permanen.
- **Aksesibilitas** — `aria-label`, `aria-expanded`, `aria-haspopup`,
  `role="dialog"`, `role="log"` + `aria-live`, pengelolaan fokus, dan dukungan
  `Escape`/`prefers-reduced-motion`.
- **Keamanan** — token CSRF dari server; kunci API tetap hanya di sisi server;
  output AI dimasukkan sebagai `textContent` (bukan HTML); tidak ada respons AI
  palsu; tanpa konfigurasi API, input dinonaktifkan + pesan jelas.

### 9.2 Tidak ada temuan baru (P0–P3)

Audit perubahan tidak menemukan cacat baru. Tidak ada perubahan skema database,
RBAC, validasi, atau rute selain penghapusan entri menu.

### 9.3 Pengujian

- `tests/tahap10/ai-widget.php` — **80/80** lulus (menu hilang, widget di semua
  peran, markup/tooltip/header, pesan konfigurasi, CSRF 419, validasi 422,
  `ok=false` tanpa API, tamu tanpa widget, tidak ada kunci bocor).
- `tests/tahap10/ai-live.php` — **12/12** lulus via mock provider (balasan AI
  nyata, tool calling, error, timeout, XSS sebagai teks, kunci tidak bocor).
- Regresi: E2E **79/79**, keamanan **34/34**, autentikasi **15/15**.

### 9.4 Berkas yang diubah

  Berkas   Perubahan
 --- ---
  `app/Helpers/menu.php`   Hapus 4 entri menu AI
  `app/Views/components/ai-widget.php`   Komponen widget baru (FAB + panel)
  `app/Views/layouts/app.php`   Muat widget + skrip
  `public/assets/js/ai-widget.js`   JS widget baru
  `public/assets/css/style.css`   Gaya `.aiw-*`
  `tests/tahap10/ai-widget.php`, `tests/tahap10/ai-live.php`   Harness baru

Integrasi AI nyata (provider eksternal) tetap **BLOCKED** (lihat §7); alur
endpoint divalidasi penuh via mock.

---

## 10. Addendum — Pembaruan UI: Login Full-Screen

Halaman login sebelumnya memakai layout publik yang memuat sidebar
(Beranda/Tentang/Masuk), header, dan footer dashboard. Login dipindahkan ke
**layout autentikasi khusus** (`layouts/auth.php`), terpisah dari layout
dashboard (`layouts/app.php`).

### 10.1 Ruang lingkup audit perubahan

- **Pemisahan layout** — `AuthController::login()` memakai `renderAuth()`
  (layout `auth`). Layout dashboard **tidak diubah**; sidebar/header tetap ada
  pada halaman Admin/CS/Teknisi/Pelanggan (diverifikasi ulang).
- **Tanpa sidebar/navbar/footer/AI widget** pada halaman login; floating AI
  widget hanya untuk pengguna yang sudah masuk.
- **Desain** — full-screen ter-center, palet `#0B1120`/`#172238`/`#2563EB`/
  `#F8FAFC`/`#94A3B8`, card ≤420px, radius/shadow modern, responsif + safe-area.
- **Autentikasi** — tidak diubah: CSRF, validasi server, hashing, rate limiting,
  akun nonaktif, redirect per peran. Pelanggan memakai Username PPPoE.
- **JavaScript** — `initPasswordToggle()` & `initLoadingForms()` bersifat generik
  (`data-password-toggle`, `form[data-loading]`), tidak mengganggu halaman lain.

### 10.2 Tidak ada temuan baru (P0–P3)

Tidak ada perubahan skema database, RBAC, validasi, atau rute. Tidak ditemukan
cacat baru.

### 10.3 Pengujian

- `tests/tahap10/login-layout.php` — **43/43** lulus.
- Regresi: E2E **79/79**, keamanan **34/34**, autentikasi **15/15**, AI widget
  **80/80**.
- Verifikasi visual (browser): card ter-center, styling & palet benar, toggle
  password + loading bekerja, 0 error konsol.

### 10.4 Berkas yang diubah

  Berkas   Perubahan
 --- ---
  `app/Views/layouts/auth.php`   Layout autentikasi baru
  `app/Views/login.php`   Desain ulang halaman login
  `app/Controllers/Controller.php`   Metode `renderAuth()`
  `app/Controllers/AuthController.php`   Login memakai layout auth
  `public/assets/css/style.css`   Gaya `.auth-*`
  `public/assets/js/main.js`   Toggle password + loading form
  `tests/tahap10/login-layout.php`   Harness baru

---

## 11. Addendum — Integrasi Google Gemini ke Floating AI

Floating AI Assistant kini terhubung ke **Google Gemini** (`generateContent`)
melalui backend PHP cURL. Tidak ada menu AI di sidebar; widget tidak muncul di
halaman login.

### 11.1 Ruang lingkup audit perubahan

- **Provider** — `AiAssistantService` memilih provider (`gemini`/`openai`),
  memanggil `POST {base}/models/{model}:generateContent` dengan header
  `x-goog-api-key`, memakai format JSON Gemini (bukan OpenAI). Provider
  OpenAI-compatible dipertahankan sebagai alternatif (`openAiChat()`).
- **Keamanan kunci** — `GEMINI_API_KEY` hanya dibaca di sisi server lewat
  `config('ai.gemini_api_key')`. Tidak pernah dikirim ke browser/HTML/JS, tidak
  ditulis ke log. Uji memverifikasi tidak ada `AIza...`/`api_key` pada respons.
- **Kontrol akses** — endpoint `/ai/chat` tetap dilindungi session (auth),
  CSRF, dan `AiRateLimiter`. Tanpa login → 401/302; tanpa CSRF → 419.
- **Tanpa akses DB** — model tidak diberi akses database; status tiket hanya
  dibaca lewat backend berotorisasi (RBAC) via `AiToolService`/pre-deteksi.
- **Penanganan error** — 400/403/404/429/5xx + safety block dipetakan ke pesan
  aman; tidak ada detail rahasia yang bocor.
- **Fitur dipertahankan** — panduan aplikasi sesuai peran, troubleshooting WiFi,
  dan FAQ disertakan sebagai `systemInstruction`, sehingga kemampuan AI tetap
  lengkap tanpa memberi model akses data langsung.

### 11.2 Temuan operasional (bukan cacat keamanan)

1. `GEMINI_MODEL=gemini-2.5-flash` (nilai awal) **tidak lagi tersedia untuk
   pengguna baru** (HTTP 404 `NOT_FOUND`). Model kemudian diset ke
   `gemini-3.5-flash`.
2. Mode "thinking" bawaan menghabiskan jatah token sehingga jawaban bisa
   terpotong/timeout. Ditambahkan `GEMINI_THINKING_BUDGET` (default `0`) dan
   `AI_TIMEOUT` dinaikkan ke 60 detik; ada fallback otomatis bila model menolak
   `thinkingConfig`.
3. `gemini-3.8-flash` **intermiten membalas 503 UNAVAILABLE** ("high demand"),
   sehingga widget sempat menampilkan "Layanan AI sedang bermasalah".
   Perbaikannya bersifat operasional: model utama → `gemini-3.5-flash`, ditambah
   retry terbatas (`AI_MAX_RETRIES`) dan daftar model cadangan
   (`GEMINI_MODEL_FALLBACK`). Error permanen (400/403) tidak diulang.

### 11.3 Tidak ada temuan baru (P0–P3)

Tidak ada perubahan skema database, RBAC, validasi, atau rute.

### 11.4 Pengujian

- `tests/tahap10/ai-gemini.php` — **19/19** lulus (jawaban asli Gemini untuk tiga
  pertanyaan uji; endpoint terlindungi; kunci tidak bocor).
- `tests/tahap10/ai-gemini-resilience.php` — **23/23** lulus (retry + fallback
  model via mock lokal; kunci tidak bocor ke log).
- Pemetaan error Gemini 400/403/404/429/5xx + safety — semua pesan aman.
- Regresi: E2E **79/79**, keamanan **34/34**, autentikasi **15/15**, AI widget
  **81/81** (+1 skip), login full-screen **43/43**.

### 11.5 Berkas yang diubah

  Berkas   Perubahan
 --- ---
  `config/app.php`   Konfigurasi provider + Gemini (+ `gemini_model_fallback`, `max_retries`)
  `app/Services/AiAssistantService.php`   Dukungan Gemini + pemisahan jalur OpenAI + retry/fallback
  `.env`   `AI_PROVIDER`, `GEMINI_MODEL`, `GEMINI_MODEL_FALLBACK`, `AI_MAX_RETRIES`, `AI_TIMEOUT`, `GEMINI_THINKING_BUDGET`
  `.env.example`   Dokumentasi kunci Gemini + fallback/retry
  `tests/tahap10/ai-gemini.php`   Harness live Gemini
  `tests/tahap10/mock-gemini.php`   Mock Gemini untuk uji ketahanan
  `tests/tahap10/ai-gemini-resilience.php`   Harness retry + fallback
  `tests/tahap10/ai-widget.php`, `tests/tahap10/e2e.php`   Uji konfigurasi jadi kondisional

## 12. Addendum — WiFiCare AI Read-Only (pengubahan fungsi AI)

### 12.1 Ruang lingkup audit perubahan

AI Assistant diubah menjadi **asisten operasional read-only**:

- **Tidak melayani coding** (kode/script/program/SQL) — ditolak dengan kalimat
  tetap, dijaga deteksi backend + aturan prompt.
- **Tidak menulis data** — tidak ada tool `INSERT/UPDATE/DELETE/DROP/ALTER`,
  tidak menambah komentar/catatan/rating.
- **Membaca database via fungsi PHP terkontrol** — model Gemini tidak pernah
  menerima kredensial MySQL maupun menjalankan SQL.
- **RBAC per peran** pada tool baca; deklarasi tool disaring per peran.

Arsitektur: `Widget → AiController → AiToolService (allowlist+RBAC) →
AiReadService (SELECT prepared) → Database::readPdo() → Gemini`.

### 12.2 Analisis keamanan

  Aspek   Penilaian
 --- ---
  Kredensial DB ke model AI   **Tidak ada** — hanya dipakai di proses PHP
  Eksekusi SQL oleh model   **Tidak mungkin** — hanya fungsi baca tetap, prepared statement
  Operasi tulis dari AI   **Tidak ada tool tulis**; dispatcher menolak nama di luar allowlist
  IDOR pada data tiket   **Dicegah** — `pelanggan_id` diambil dari session, bukan input
  Kebocoran antar peran   **Dicegah** — deklarasi + eksekusi tool disaring per peran
  Instruksi jahat (prompt injection)   Aturan prompt no.7 + tool allowlist; tanpa tool tulis
  Kunci API bocor   Tidak pernah dikirim ke browser/HTML/log
  Kegagalan DB saat tool   Ditangkap (try/catch) → pesan aman, aplikasi tidak error

Akun MySQL read-only opsional (`DB_RO_USER`/`DB_RO_PASSWORD`) tersedia untuk
isolasi izin `SELECT`; bila kosong, fallback ke koneksi utama.

### 12.3 Temuan baru

Tidak ada temuan P0–P3 baru. Beberapa hal yang diperhatikan dan sudah ditangani:

- **Argumen tool kosong** dikodekan `{}` (bukan `[]`) — mencegah HTTP 400 dari
  Gemini ("Proto field is not repeating").
- **`thoughtSignature`** pada part model wajib dikembalikan apa adanya (Gemini
  3.x) — ditangani di `handleGeminiParts()`.
- **Nilai `null`** pada hasil tool disanitasi menjadi `""` agar valid sebagai
  protobuf Struct.
- **Kegagalan koneksi DB** saat tool tidak lagi mematikan request (try/catch).

### 12.4 Pengujian

- `tests/tahap10/ai-read-tools.php` — **26/26 lulus** (tolak coding, tool loop,
  argumen kosong, RBAC, allowlist tanpa tool tulis, baca ter-scope).
- Verifikasi wire-format live `functionCall → functionResponse` (HTTP 200).
- Penolakan coding via HTTP `POST /ai/chat` — `ok=true` + kalimat tetap.
- Regresi: E2E 79/79, keamanan 34/34, autentikasi 15/15, widget AI 81/81 (+1),
  login 43/43, ketahanan Gemini 23/23. Lint 138 berkas PHP 0 error.

### 12.5 Berkas yang diubah

  Berkas   Perubahan
 --- ---
  `app/Services/AiReadService.php`   **Baru** — fungsi baca ter-scope, SELECT prepared
  `app/Services/AiToolService.php`   Ditulis ulang: allowlist+RBAC, format OpenAI & Gemini
  `app/Services/AiAssistantService.php`   Prompt operasional, tolak coding, tool loop Gemini, sanitasi
  `app/Services/AiKnowledge.php`   Identitas "WiFiCare AI" + `codingRefusal()`
  `app/Services/Database.php`   `readPdo()` (akun read-only opsional)
  `config/database.php`   `read_username` / `read_password`
  `app/bootstrap.php`   Memuat `AiReadService`
  `.env`, `.env.example`   `DB_RO_USER`/`DB_RO_PASSWORD` + catatan read-only
  `tests/tahap10/ai-read-tools.php`   **Baru** — harness read-only + tool calling (26)
  `tests/tahap10/mock-gemini.php`   Skenario `functionCall`/`functionResponse`
  `tests/README.md`, `README.md`, `PROGRESS.md`, `TEST_REPORT.md`   Diperbarui

---

## 13. Audit Tahap 11 — Pengaturan AI Chatbot (Admin)

Ruang lingkup: pengelolaan provider/API key/model/status/batas AI melalui
dashboard Admin, dengan enkripsi API key dan integrasi ke Floating AI Assistant.

### 13.1 Kontrol keamanan yang diverifikasi
- **RBAC**: hanya Admin (`role => admin`) dapat membuka halaman & endpoint
  (`/admin/pengaturan/ai`, `.../ai/test`); CS/Teknisi/Pelanggan → 403; anon → login.
- **CSRF**: simpan dan tes koneksi menolak tanpa token yang sah (HTTP 419).
- **Enkripsi**: API key dienkripsi libsodium `secretbox` dengan **nonce acak**
  per enkripsi; tersimpan sebagai `v1:` + base64(nonce  ciphertext); tidak ada
  kolom plaintext. Master key hanya di environment (`AI_SETTINGS_KEY`).
- **Tanpa enkripsi = tanpa simpan**: bila libsodium/master key tidak tersedia,
  halaman menampilkan informasi aman dan API key TIDAK disimpan.
- **Tidak ada eksposur rahasia**: key/ciphertext tidak muncul di HTML, respons
  JSON, maupun audit log; master key tidak di DB/repositori.
- **Simpan aman**: kunci lama dipertahankan bila field key dikosongkan; key baru
  baru menggantikan setelah tersimpan berhasil; status Nonaktif tidak menghapus key.
- **Validasi server-side + PDO prepared**: model (regex), limit rentang angka,
  panjang system instruction; semua tulis via prepared statement/`AiSetting::save`.
- **Integritas AI read-only**: system instruction Admin tidak dapat menonaktifkan
  RBAC/prompt wajib/mode read-only; penolakan coding & allowlist tool tetap di
  backend (`AiAssistantService`, `AiToolService`).

### 13.2 Temuan & perbaikan
- **T-11-01 (lingkungan)** — libsodium belum aktif: `extension=sodium` dikomentari
  di `php.ini` dan DLL dependen `libsodium.dll` tidak berada di direktori kerja
  Apache. **Perbaikan**: aktifkan `extension=sodium` dan salin `libsodium.dll` ke
  `C:\xampp\apache\bin`. Tanpa ini, halaman menampilkan informasi aman dan tidak
  menyimpan key (fail-safe, bukan kegagalan data).
- **T-11-02 (integritas organisasi)** — daftar saran model Gemini dapat berubah
  antar akun; divalidasi hanya format, ketersediaan sebenarnya diverifikasi lewat
  tombol Tes Koneksi. Tidak ada temuan kebocoran.
- Tidak ditemukan temuan kritis/tinggi.

### 13.3 Bukti pengujian
- `tests/tahap11/crypto.php` **19/19**; `tests/tahap11/config-priority.php`
  **17/17**; `tests/tahap11/ai-settings.php` **67/67**.
- Regresi: E2E 79/79, keamanan 34/34, autentikasi 15/15, widget AI 81/81 (+1),
  login 43/43, WiFiCare AI read-only 26/26, ketahanan Gemini 23/23.
- Lint **147 berkas PHP** 0 error.

### 13.4 Berkas baru/berubah
  Berkas   Perubahan
 --- ---
  `database/migrations/006_ai_settings.sql`   **Baru** — tabel `ai_settings`
  `app/Services/Crypto.php`   **Baru** — enkripsi libsodium
  `app/Models/AiSetting.php`   **Baru** — model konfigurasi tunggal
  `app/Services/AiSettingsService.php`   **Baru** — runtime/validasi/simpan/tes/view aman
  `app/Controllers/AdminAiSettingsController.php`   **Baru** — admin-only index/save/test
  `app/Views/admin/pengaturan_ai.php`   **Baru** — halaman pengaturan
  `public/assets/js/ai-settings.js`   **Baru** — perilaku UI
  `bin/ai-keygen.php`   **Baru** — pembuat master key
  `tests/tahap11/crypto.php`, `tests/tahap11/config-priority.php`, `tests/tahap11/ai-settings.php`   **Baru** — harness (19, 17 & 67)
  `app/Services/AiAssistantService.php`   Runtime kredensial/batas + `testGeminiConnection()`
  `app/Services/AiRateLimiter.php`   Rate limit runtime
  `app/Controllers/AiController.php`, `app/Views/layouts/app.php`, `app/Views/components/ai-widget.php`   Status & batas dari konfigurasi
  `app/Helpers/menu.php`, `app/Helpers/functions.php`, `routes/web.php`, `app/bootstrap.php`, `config/app.php`   Menu, ikon, rute, muat service, blok `ai_settings`
  `.env`, `.env.example`   `AI_SETTINGS_KEY` + catatan prioritas
  `PROGRESS.md`, `TEST_REPORT.md`, `README.md`, `tests/README.md`   Diperbarui

## 14. Revisi Final — 7 Perbaikan (18 Oktober 2026)

Audit lanjutan atas tujuh permintaan revisi final. Tidak ada modul yang dibangun
ulang atau diganti; seluruh perubahan bersifat penyesuaian.

### 14.1 Temuan audit
1. **Tata letak teks AI** — bubble jawaban sebelumnya memakai `white-space`
   bawaan sehingga paragraf panjang dan daftar berpotensi melebar/terpotong;
   tidak ada jaminan pembungkusan kata. Tidak ada sapaan baku.
2. **Markdown mentah** — jawaban Gemini sering memuat `**tebal**`, `###`, dan
   daftar; sebelumnya dirender sebagai `textContent` sehingga simbol tampil
   mentah. Data tiket tidak disajikan sebagai struktur terpisah.
3. **Kelengkapan jawaban** — prompt lama tidak melarang komentar/saran/menu
   tambahan, sehingga jawaban bisa bertele-tele atau menambah kesimpulan.
4. **Input chat** — terdapat teks peringatan kata sandi di widget dan halaman
   `/ai`; indikator kirim tidak memiliki state loading; tinggi textarea tidak
   tumbuh otomatis.
5. **Tema** — hanya token Dark; belum ada Light default, belum ada toggle,
   belum ada persistensi, grafik Chart.js berpalet gelap tetap.
6. **Routing** — `/` menampilkan beranda publik, `/about` ada, layout dasar
   menampilkan sidebar pengunjung.
7. **Scrollbar** — scrollbar terlihat pada sidebar dan konten utama.

### 14.2 Tindakan perbaikan
- **Revisi 1:** `.chat-bubble-body` → `text-align:left`, `line-height:1.6`,
  `overflow-wrap:anywhere`, `word-break:break-word`; paragraf `--plain` =
  `white-space:pre-wrap`. Sapaan: "Halo! Saya WiFiCare AI." + deskripsi.
- **Revisi 2:** perender DOM aman `public/assets/js/ai-render.js`
  (`window.WifiCareAiRender`) mengubah teks kaya menjadi elemen nyata tanpa
  `innerHTML` dari respons AI; kartu tiket dibangun dari JSON backend
  (`tickets`) — tidak pernah di-parse dari teks AI. Field kartu dibatasi per
  peran.
- **Revisi 3:** blok "GAYA JAWABAN (WAJIB)" pada system prompt; otorisasi dan
  pembatasan tetap di backend (bukan mengandalkan prompt).
- **Revisi 4:** elemen peringatan dihapus dari `ai-widget.php` dan
  `ai/index.php`; placeholder "Tulis pertanyaan Anda..."; tombol kirim dengan
  ikon + state `is-loading`; Enter kirim / Shift+Enter baris baru; auto-grow;
  scrollbar textarea disembunyikan. Validasi & keamanan backend tidak diubah.
- **Revisi 5:** token Light di `:root` (default) + override Dark di
  `:root[data-theme="dark"]`; remap variabel Bootstrap; komponen
  `theme-toggle`; skrip anti-flash di `app.php`/`auth.php`/`base.php`/`error.php`;
  `main.js` `initTheme()` + `localStorage` + event `wificare:themechange`;
  `dashboard.js` membaca token CSS dan merender ulang saat tema berganti.
- **Revisi 6:** `HomeController` hanya mengarahkan (login/dashboard per peran);
  rute `/about` dihapus; menu publik dikosongkan; `layouts/error.php` baru untuk
  403/404; `layouts/base.php` disederhanakan; tombol kembali pada halaman error
  sadar-peran.
- **Revisi 7:** `scrollbar-width:none` + `::-webkit-scrollbar{display:none}`
  pada `html`/`body`, `.app-sidebar`, area chat, dan textarea; gulir tetap aktif.

### 14.3 Pengerasan keamanan (dikonfirmasi ulang)
- Tidak ada jawaban AI yang dieksekusi sebagai HTML (tanpa `innerHTML` dinamis
  pada `ai-render.js`).
- Data tiket hanya melalui `AiReadService` (SELECT prepared) dengan pembatasan
  peran; kunci API & kredensial DB tidak pernah dikirim ke model.
- Validasi/CSRF/RBAC input chat tetap utuh meski peringatan UI dihapus.
- Baris `ai_settings` di DB utama **tidak** dihapus oleh pengujian; harness
  offline (`ai-read-tools`, `ai-gemini-resilience`, `config-priority`)
  menetralkan field/baris secara sementara lalu memulihkannya.

### 14.4 Berkas baru/berubah (Revisi Final)
  Berkas   Perubahan
 --- ---
  `app/Views/components/theme-toggle.php`   **Baru** — tombol tema
  `app/Views/layouts/error.php`   **Baru** — layout error ringkas bertema
  `public/assets/js/ai-render.js`   **Baru** — perender AI aman + kartu tiket
  `tests/tahap12/revisi-final.php`   **Baru** — uji 7 revisi (86)
  `public/assets/css/style.css`   Token Light/Dark, bubble AI, kartu tiket, toggle, scrollbar
  `public/assets/js/main.js`, `dashboard.js`   Tema + grafik ikut tema
  `public/assets/js/ai-widget.js`, `ai.js`   Perender aman, kartu tiket, state loading
  `app/Views/components/header.php`, `ai-widget.php`, `app/Views/ai/index.php`   Toggle, sapaan, hapus peringatan, spinner kirim
  `app/Views/layouts/app.php`, `auth.php`, `base.php`   Anti-flash + `data-theme`
  `app/Controllers/HomeController.php`, `AuthController.php`, `AiController.php`   Routing root, 403, `tickets`
  `app/Helpers/auth.php`, `functions.php`, `menu.php`   `render_error`/`render_forbidden`, ikon, tanggal panjang, menu publik
  `app/Services/AiAssistantService.php`, `AiReadService.php`, `AiToolService.php`, `app/Models/Pengaduan.php`   Kartu tiket + prompt ringkas
  `tests/tahap10/ai-read-tools.php`, `ai-gemini-resilience.php`   Netralisasi `ai_settings` non-destruktif

## 15. Bugfix — Sidebar Role Tertukar & Error 403 (18 Oktober 2026)

### 15.1 Ringkasan
Ditemukan dan diperbaiki bug kritis: saat Admin membuka **Manajemen Pengguna**,
sidebar dan header menampilkan peran **Pelanggan** (atau peran lain yang salah),
sedangkan chip profil benar menampilkan Administrator; sebagian navigasi
menghasilkan **HTTP 403**. RBAC tidak dilemahkan.

### 15.2 Analisis akar masalah
- `render_view()` meng-`include` view lalu layout dalam **satu lingkup fungsi**.
- `app/Views/admin/pengguna.php` menetapkan `$role` di dalam `foreach` daftar
  pengguna. Setelah iterasi terakhir, `$role` berisi peran **baris terakhir**
  (mis. `pelanggan`) dan "membocor" ke layout.
- Layout memakai `$role` untuk `role_menu()` dan eyebrow header; chip profil
  memakai `$user['role']` dari autentikasi → tampilan tidak sinkron.
- Karena menu sidebar berisi tautan peran yang salah (mis. `/pelanggan/...`),
  Admin yang mengkliknya melewati middleware RBAC → **403**. Jadi 403 adalah
  akibat dari akar yang sama, bukan kesalahan otorisasi.

Bukti reproduksi (sebelum perbaikan): chip profil `Administrator`, eyebrow/sidebar
`Pelanggan`; dengan `?role=teknisi`, sidebar ikut berubah menjadi `Teknisi`
(membuktikan `$role` berasal dari daftar pengguna, bukan dari identitas login).

### 15.3 Perbaikan (root cause)
1. **Isolasi lingkup render** — `render_view()` merender isi view dalam closure
   terpisah; layout menerima data asli controller. Variabel view tidak lagi bisa
   bocor ke layout (menutup seluruh kelas bug ini).
2. **Satu sumber peran** — `Auth::ROLES`, `Auth::isValidRole()`, `Auth::role()`
   tervalidasi (dari DB via session `user_id`, `null` bila tidak dikenali).
   Tanpa fallback ke Pelanggan. Session bayangan `user_role` dihapus.
3. **Layout aman** — `layouts/app.php` menurunkan `$role` dari `auth_user()`;
   tanpa sesi → login; peran tak dikenal → 403.
4. **Anti-tabrakan** — variabel loop `$role` di `admin/pengguna.php` diganti
   `$itemRole`.

### 15.4 Pemeriksaan keamanan
- Peran **tidak pernah** diambil dari URL/input/`?role=`; dibuktikan uji.
- CS/Teknisi/Pelanggan tetap 403 pada Manajemen Pengguna dan tidak menerima data.
- Controller mengambil data sensitif hanya setelah middleware RBAC lolos; view
  admin tidak pernah dirender lewat layout/tampilan peran lain.
- Tidak ada IDOR baru; pemeriksaan peran server-side dipertahankan.

### 15.5 Berkas diperbaiki
  Berkas   Perubahan
 --- ---
  `app/Helpers/functions.php`   `render_view()` — isolasi lingkup view vs layout (fix akar)
  `app/Services/Auth.php`   `ROLES` + `isValidRole()`; `role()` tervalidasi; hapus session bayangan
  `app/Views/layouts/app.php`   Peran dari `auth_user()`; tanpa fallback Pelanggan; tolak peran tak dikenal
  `app/Views/admin/pengguna.php`   Loop var `$role` → `$itemRole`
  `tests/tahap12/role-sidebar.php`   **Baru** — uji regresi role/sidebar & RBAC (28 assertion)

---

## 16. Audit Perbaikan 7 Bug & Revisi Final (Tahap 13)

### 16.1 Akar masalah (root cause)
  Bug   Akar masalah   Bukti
 --- --- ---
  BUG 1 — HY093   `Database` memakai native prepare (`ATTR_EMULATE_PREPARES=false`); nama placeholder `:q` dipakai berulang pada query pencarian   Reproduksi langsung: `User::paginateForAdmin(['q'=>'budi'])` melempar `SQLSTATE[HY093]`
  BUG 2 — identitas   `app/Views/pelanggan/profil.php` berisi data contoh hardcoded (`Dewi Lestari`, `dewi.pppoe`) + `'demo' => true`; controller tidak mengirim data   Halaman profil menampilkan Dewi untuk semua pelanggan
  BUG 3 — unggah foto   `component_html()` memakai parameter `$name`; `extract($data, EXTR_SKIP)` melewati kunci data `name`, sehingga input dirender `name="upload-photo"` bukan `foto[]` dan `$_FILES['foto']` kosong   HTML ter-render: `<input ... name="upload-photo">`

### 16.2 Dampak & cakupan
- BUG 1 berdampak pada **semua** daftar berpencarian (pengguna, pelanggan,
  pengaduan admin/CS, kategori, audit, teknisi). Seluruhnya diperbaiki.
- BUG 2/3 diperbaiki pada akarnya (sumber identitas tunggal dari session;
  nama field komponen benar), bukan gejalanya.

### 16.3 Keamanan
- Pengubahan profil hanya memakai ID dari session (tanpa IDOR); password lama
  diverifikasi `password_verify`, password baru di-hash `password_hash`.
- Foto tetap disimpan di luar document root dan disajikan lewat endpoint
  ber-RBAC; pelanggan lain ditolak HTTP 403 (diuji).
- Tidak ada kredensial/kunci API yang bocor; tidak ada stack trace ke pengguna.
- RBAC tidak dilonggarkan: Data Karyawan tetap Admin-only; peran pelanggan
  dipisahkan ke Data Pelanggan.

### 16.4 Berkas diperbaiki
Lihat `PROGRESS.md` §"Tahap 13 — Berkas dibuat/diubah" untuk daftar lengkap.

### 16.5 Catatan non-destruktif
- Tidak ada tabel/kolom baru yang diminta (revisi murni UI + koreksi query).
- Skrip contoh (`bin/seed-sample.php`) tidak diubah; tidak ada data dummy yang
  ditambahkan untuk menutupi bug.

---

## 17. Audit Keamanan Autentikasi, Proteksi Dashboard & Folder (Tahap 14)

Tanggal: 20 Oktober 2026. Lingkungan: XAMPP Windows, Apache :80, proyek pada
subfolder `/aduan-pelanggan` (DocumentRoot `C:/xampp/htdocs`).

### 17.1 Celah yang ditemukan (reproduksi nyata)
  #   Temuan   Dampak   Status
 --- --- --- ---
  S1   `GET /aduan-pelanggan/.env` -> 200   API key Gemini, master key `AI_SETTINGS_KEY`, kredensial DB terbaca   **Diperbaiki (403)**
  S2   Directory listing `/aduan-pelanggan/`, `/app/`, `/config/`, `/database/`, `/routes/`, `/tests/` -> 200   Struktur & nama berkas internal terbuka   **Diperbaiki (403)**
  S3   `GET /storage/logs/app.log` -> 200   Log berisi path server, pesan error, metadata operasional   **Diperbaiki (403)**
  S4   `GET /composer.json` -> 200   Metadata proyek/versi   **Diperbaiki (403)**
  S5   Folder internal (`app/`, `tests/`, dst.) dapat diakses   Permukaan serangan; skrip CLI dapat dieksekusi via HTTP   **Diperbaiki (403)**
  S6   API/AJAX tanpa sesi di-redirect HTML   Klien fetch menerima HTML, bukan 401   **Diperbaiki (401 JSON)**
  S7   `Auth::check()` saja pada `/`/guest   Session akun nonaktif/peran rusak berpotensi redirect berulang   **Diperbaiki**
  S8   Peran tidak valid bisa lolos sampai login   Risiko bypass RBAC   **Diperbaiki**
  S9   `APP_DEBUG=true`   Stack trace + path server berpotensi tampil   **Diperbaiki (false + display_errors=0)**
  S10   Path foto dari DB tidak diverifikasi `realpath`   Risiko traversal bila data DB tercela   **Diperbaiki**

### 17.2 Akar penyebab
- **S1-S5:** DocumentRoot Apache menunjuk ke `C:/xampp/htdocs`, bukan ke
  `public/`. Folder proyek berada di dalam document root dan hanya
  `public/.htaccess` yang ada; tidak ada aturan penolakan di level proyek.
- **S6:** `AuthMiddleware::auth()` selalu memanggil `flash_set()` + `redirect()`
  tanpa membedakan navigasi browser dan API/AJAX.
- **S7-S8:** `guest()` dan `HomeController` memakai `Auth::check()` (keberadaan
  `user_id`) sebelum `Auth::user()` memvalidasi akun/peran; `Auth::attempt()`
  tidak menolak peran di luar `Auth::ROLES`.
- **S9:** `APP_DEBUG=true` dan cabang error menampilkan `(string)$exception`.
- **S10:** `Upload::absolutePath()` hanya melakukan `ltrim()` path dari DB.

### 17.3 Perbaikan & middleware
- **Middleware autentikasi:** `AuthMiddleware::auth()` - wajib sesi valid;
  redirect login untuk browser, 401 JSON untuk API/AJAX.
- **Middleware RBAC:** `AuthMiddleware::role()` - peran dari database; salah
  peran 403 (JSON untuk API/AJAX); peran tidak dikenal 403.
- **Middleware guest:** `AuthMiddleware::guest()` - hanya pengunjung; sesi
  akun nonaktif/peran rusak dibersihkan, bukan diarahkan ke dashboard.
- **Sumber identitas:** `Auth::user()`/`Auth::role()` (user_id session ->
  tabel `users`); tidak ada role dari URL/input/session terpisah.
- **Session:** HttpOnly, SameSite=Lax, Secure otomatis HTTPS, strict mode,
  regenerasi setelah login, rotasi 30 menit, timeout idle, logout destruktif,
  `Cache-Control: no-store` pada semua respons dinamis.
- **Apache:** `.htaccess` root + per-folder `Require all denied`; contoh
  VirtualHost `DocumentRoot` ke `public/` beserta rollback.
- **Foto:** ID numerik -> path dari DB -> `Upload::resolve()` (`realpath` harus
  di dalam `storage/uploads/`) -> cek kepemilikan tiket.
- **Error:** `display_errors=0`, halaman 401/403/404/500 generik; detail ke log.

### 17.4 Folder yang berhasil dilindungi
`.env`, `.env.example`, `.git/`, `app/`, `bin/`, `config/`, `database/`,
`deploy/`, `routes/`, `storage/` (termasuk `logs/` dan `uploads/`), `tests/`, `vendor/`,
`composer.json`, `composer.lock`, `README.md`, `*.md`, `*.sql`, `*.log`,
`*.bak`, `*.ini`, `*.sh`, `*.zip`, `*.rar`, `*.7z`, `*.tar`, `*.gz`, `*.dump`,
`*.sqlite`, dan dotfiles lain. Directory listing nonaktif.

### 17.5 Catatan & sisa risiko
- Kunci API yang sempat terekspos melalui HTTP lokal sebaiknya **di-rotasi**
  bila mesin pernah dapat diakses pihak lain. Kunci tidak diubah otomatis
  sesuai instruksi (jangan mengganti API key).
- Uji cookie `Secure` penuh memerlukan HTTPS; implementasi otomatis aktif saat
  `HTTPS=on`/port 443, tetapi belum diuji pada TLS lokal (SKIP).
- VirtualHost produksi disediakan sebagai contoh (tidak diaktifkan global agar
  konfigurasi XAMPP pengguna tidak berubah tanpa persetujuan); proteksi
  `.htaccess` sudah aktif dan diuji pada URL sebenarnya.
- Tidak ada perubahan skema database dan tidak ada data pengguna yang dihapus.


## 18. Audit Tahap 15 - Revisi Final AI Assistant (20 Oktober 2026)

### 18.1 Lingkup dan batasan

Audit ini memeriksa 10 revisi final AI Assistant pada aplikasi yang sudah
berjalan. Tidak ada pembangunan ulang, perubahan stack PHP Native, penghapusan
data MySQL, perubahan alur bisnis pengaduan, atau pelemahan autentikasi/RBAC.
AI tetap berupa widget floating di kanan bawah; tidak ada menu AI di sidebar.
Pengaturan AI tetap khusus Admin.

### 18.2 Temuan audit

| ID | Temuan | Dampak | Perbaikan |
|---|---|---|---|
| T15-01 | Konfigurasi AI masih terikat satu key Gemini dan satu model | Admin tidak dapat berganti provider/model dengan aman | Adapter provider + `ai_provider_keys` per provider |
| T15-02 | Belum ada batas request harian per pengguna | Penyalahgunaan kuota provider | `ai_settings.daily_limit` + pengecekan server-side dari `ai_usage_log` |
| T15-03 | Belum ada monitoring penggunaan | Admin tidak dapat melihat error/kuota/pemakaian | `ai_usage_log` metadata + ringkasan di halaman Admin |
| T15-04 | FAQ hanya statis di kode | Admin tidak dapat mengelola pengetahuan resmi | Tabel `ai_faq`, CRUD, pencarian, konteks dinamis |
| T15-05 | Statistik "selesai" berisiko memakai tanggal tiket dibuat | Jawaban periode bisa salah | Hitung dari `riwayat_status.status_baru='selesai'` |
| T15-06 | Tool belum mencakup profil sendiri, tiket aktif, riwayat teknisi, rating | Pertanyaan wajar tidak terjawab dari DB | Tool baru + cakupan per peran |
| T15-07 | UI belum punya salin/bersihkan percakapan | Kebutuhan operasional pengguna | Tombol salin, endpoint `/ai/clear`, reset UI |
| T15-08 | Kode error provider tidak diteruskan ke klien | UI sulit membedakan kuota/timeout/kunci | Kode aman `quota`, `timeout`, `invalid_key`, dll. |
| T15-09 | Pencarian FAQ memakai named parameter berulang | `SQLSTATE HY093` pada pencarian | Placeholder `:q1/:q2/:q3` |
| T15-10 | `daily_limit` kosong dari form lama dianggap tidak valid | Menyimpan pengaturan gagal untuk payload lama | Nilai kosong memakai default/baris lama |

### 18.3 Mekanisme read-only

- Model **tidak pernah** menerima kredensial MySQL dan tidak pernah mengirim SQL.
- Alur: `AiToolService` (allowlist + RBAC) -> `AiReadService` (prepared SELECT)
  -> `Database::readPdo()`.
- `Database::readPdo()` memakai `DB_RO_USER`/`DB_RO_PASSWORD` bila diisi;
  jika kosong memakai koneksi utama, tetapi query tetap `SELECT` prepared.
- Tidak ada tool tulis pada allowlist. Permintaan kode/SQL ditolak dua lapis
  (deteksi backend + aturan prompt).
- Template `database/readonly_ai_user.example.sql` memberi `SELECT` hanya pada
  tujuh tabel yang dibaca AI (`users`, `pelanggan`, `pengaduan`,
  `penugasan_teknisi`, `riwayat_status`, `kategori_gangguan`, `rating_layanan`).
- Verifikasi nyata akun read-only: `SELECT` berhasil; `UPDATE` dan `DELETE`
  ditolak `SQLSTATE 42000` (permission denied).

### 18.4 Cakupan per peran

| Peran | Data yang boleh dibaca AI |
|---|---|
| Admin | statistik operasional, seluruh tiket, pelanggan, kinerja/penugasan teknisi, rating agregat |
| CS | tiket, status, kategori, statistik operasional; **bukan** kinerja pribadi teknisi |
| Teknisi | hanya penugasan dan riwayat miliknya; statistik dihitung dari penugasan aktif |
| Pelanggan | hanya profil sendiri dan tiket miliknya; nomor WhatsApp disamarkan |

Identitas selalu berasal dari session (`auth_user()`), bukan body/URL. Kartu
tiket berasal dari hasil tool tervalidasi, bukan parsing teks model.

### 18.5 Provider dan keamanan kunci

- Provider: `gemini`, `deepseek`, `openai`; allowlist di `AiProviderRegistry`.
- Gemini memakai format `generateContent`; DeepSeek/OpenAI memakai
  `chat/completions` (kompatibel OpenAI) melalui adapter terpisah.
- Satu key terenkripsi per provider di `ai_provider_keys`; key Gemini lama di
  `ai_settings` tetap dibaca sebagai fallback.
- Master key hanya dari environment `AI_SETTINGS_KEY`; tidak ada key/ciphertext
  di HTML, respons tes, audit log, atau log aplikasi.
- Tidak ada fallback provider otomatis. Jika provider gagal, pengguna mendapat
  pesan aman; Admin harus memilih provider lain secara sadar.
- Field key kosong saat edit berarti "jangan ubah key"; key tersimpan tidak
  pernah ditampilkan ulang.

### 18.6 Alur Admin

1. Buka **Pengaturan -> Pengaturan AI Chatbot** (Admin-only).
2. Pilih provider, isi model/base URL, dan key bila perlu.
3. Atur Aktif/Nonaktif, batas output, timeout, rate limit, batas harian,
   panjang pesan, dan system instruction.
4. Klik **Tes Koneksi** (panggilan nyata, hasil aman).
5. Pantau request/error/token hari ini pada bagian monitoring.
6. Kelola FAQ: tambah/edit, aktif/nonaktif, hapus, cari berdasarkan
   pertanyaan/jawaban/kategori.

### 18.7 Monitoring dan FAQ

- `ai_usage_log` menyimpan: user, role, provider, model, jenis request,
  sukses/gagal, kode error, durasi, token. **Tidak** menyimpan pesan, jawaban,
  API key, atau data pribadi.
- Halaman Admin menampilkan ringkasan hari ini/bulan serta penggunaan per
  pengguna (nama, peran, jumlah request, gagal, waktu terakhir).
- Batas harian dihitung dari log nyata; request yang ditolak tidak menambah log.
- FAQ aktif dimasukkan sebagai konteks referensi dan juga tersedia lewat tool
  `get_faq`. FAQ bukan instruksi yang dapat melewati RBAC.

### 18.8 UI chat

- Floating widget di semua dashboard; tanpa menu sidebar.
- Render jawaban aman (textContent/node), kartu tiket, salin jawaban,
  bersihkan percakapan, indikator mengetik, Enter kirim, Shift+Enter baris baru,
  auto-scroll.
- Tema Light/Dark memakai variabel CSS; tombol salin/bersihkan aman di kedua tema.
- Konteks percakapan hanya di session PHP; endpoint `/ai/clear` mengosongkannya.

### 18.9 Bukti pengujian

| Suite | Hasil |
|---|---|
| `tests/tahap15/ai-platform.php` | 66 PASS, 0 FAIL, 2 BLOCKED |
| `tests/tahap15/ai-tools-revisi.php` | 29 PASS, 0 FAIL |
| `tests/tahap15/ai-live-roles.php` | 4 PASS (login/endpoint), 4 BLOCKED (kuota) |
| `tests/tahap10/ai-read-tools.php` | 26 PASS |
| `tests/tahap10/ai-widget.php` | 81 PASS, 1 SKIP |
| `tests/tahap10/ai-gemini-resilience.php` | 23 PASS |
| `tests/tahap11/ai-settings.php` | 67 PASS |
| `tests/tahap11/crypto.php` | 19 PASS |
| `tests/tahap11/config-priority.php` | 17 PASS |
| Regresi Tahap 10/12/13 | lulus (79+34+15+43+75+86+28+102; 1 SKIP) |
| Lint PHP | 166 berkas, 0 error |

### 18.10 Sisa risiko / BLOCKED

- Live DeepSeek dan live OpenAI belum PASS karena kredensial nyata tidak
  tersedia; tes dengan key uji hanya membuktikan penanganan `invalid_key` aman.
- Live Gemini free tier membalas `server`/`quota` pada sebagian percobaan.
  Jawaban yang sempat berhasil tidak diklaim sebagai bukti seluruh peran.
- Uji cookie `Secure` penuh tetap SKIP tanpa TLS lokal.
- Kunci API yang pernah terekspos sebelum pengerasan perlu dirotasi oleh
  pemilik layanan (tindakan operasional, bukan cacat kode).

---

## 19. Perbaikan Identitas Pengguna WiFiCare AI (24 Oktober 2026)

### 19.1 Temuan

- **Root cause identitas salah:** system prompt hanya menyertakan role. Nama
  dan username pengguna yang login tidak pernah dikirim ke provider, sehingga
  model menjawab pertanyaan identitas dengan menebak atau memakai nama dari
  contoh/riwayat percakapan.
- **Risiko sisa konteks antar-akun:** konteks chat tersimpan per `user_id` di
  session, tetapi belum dibersihkan secara eksplisit saat login/logout.
- **Tidak ada jalur jawaban lokal:** pertanyaan identitas sederhana tetap
  memanggil provider, sehingga hasilnya bergantung pada model dan kuota.
- **Permukaan serangan:** field seperti `name`, `role`, `user_id`, atau
  `username` yang dikirim bersama request chat tidak boleh memengaruhi
  identitas. Klaim "saya Admin" di dalam pesan juga tidak boleh menaikkan hak
  akses.

### 19.2 Perbaikan

| Area | Perbaikan |
|---|---|
| Sumber identitas | `AiIdentity::fromSession()` membaca `$_SESSION['user_id']` lalu baris `users` terbaru dengan prepared statement; nama/username/role invalid atau akun nonaktif → `null` |
| Endpoint chat | `AiController::chat()` memakai identitas session, bukan input browser; identitas invalid → HTTP 401 `identity_invalid` |
| Semua provider | `AiAssistantService::buildMessages()` menyisipkan konteks identitas minimum ke system prompt yang sama untuk Gemini, DeepSeek, dan OpenAI |
| Pertanyaan identitas | Nama/username/role/"siapa saya"/"saya login sebagai apa" dijawab langsung PHP (`provider=local`, `request_type=identity`) tanpa memanggil provider |
| Prompt injection | Prompt melarang memakai nama dari riwayat/klaim pengguna dan melarang menaikkan hak akses berdasarkan klaim role |
| Konteks antar-akun | `AiConversation::clearAll()` dipanggil saat login dan logout; riwayat tetap terikat `user_id` dan TTL 30 menit |
| Data minimum | Hanya id, nama, username, dan role yang dikirim; tidak ada password, hash, API key, atau data pengguna lain |

### 19.3 Bukti pengujian

| Skenario | Hasil |
|---|---|
| Unit + HTTP `tests/tahap16/identity.php` | **61 PASS, 0 FAIL** |
| Admin (`admin.budi`) → "Siapa nama saya?" | `Nama Anda Budi Santoso Herman.` |
| CS (`cs.rizki`) → "Apa role saya?" | `Role Anda Customer Service.` |
| Teknisi (`cdn.rehan`) → "Apa role saya?" | `Role Anda Teknisi.` |
| Pelanggan Heri → "Siapa nama saya?" | `Nama Anda Heri.` (bukan Dewi/Budi) |
| Manipulasi body `name=Heri`, `role=pelanggan`, `user_id=14` saat login Admin | Tetap `Budi Santoso Herman` |
| Pelanggan mengaku administrator | Ditolak RBAC; AI menyebut role Pelanggan, tanpa data admin |
| Ganti akun Rina → logout → Agus | Agus melihat Agus, bukan Rina |
| Session tanpa login / user id tidak ada | HTTP 401 / identitas `null`, tidak menebak |
| Sapaan live Gemini per peran | Admin/CS/Teknisi/Pelanggan disebut dengan nama masing-masing |
| `tests/tahap10/ai-read-tools.php` | 26 PASS |
| `tests/tahap10/ai-gemini-resilience.php` | 23 PASS |
| `tests/tahap10/ai-gemini.php` | 19 PASS |
| `tests/tahap10/ai-widget.php` | 81 PASS, 1 SKIP |
| Lint seluruh `app/**/*.php` | 0 error |

### 19.4 Sisa risiko / BLOCKED

- DeepSeek dan OpenAI belum diuji live karena kredensial nyata tidak tersedia.
  Jalur prompt/validasi identitas sama karena memakai `AiIdentity` dan
  `buildMessages` yang sama.
- Kuota/free-tier Gemini dan `max_execution_time` PHP masih dapat menyebabkan
  timeout pada percakapan multi-tool. Jawaban identitas langsung tidak
  terpengaruh karena tidak memanggil provider.
- Verifikasi cookie `Secure` penuh tetap SKIP tanpa TLS lokal.
- Riwayat chat masih hanya di session PHP (bukan database); pembersihan saat
  login/logout sudah diuji, tetapi tidak ada audit trail isi percakapan.

---

## 20. Audit Pusat Konfigurasi API & WhatsApp (26 Oktober 2026)

### 20.1 Lingkup

Audit dan implementasi pusat konfigurasi API untuk WhatsApp Gateway, OTP,
notifikasi tiket, rencana laporan, serta monitoring penggunaan API. AI tetap
memakai arsitektur multi-provider yang sudah ada (`ai_settings`,
`ai_provider_keys`, `ai_usage_log`); tidak ada migrasi/penulisan ulang key AI.

Prinsip yang diuji:

- secret tidak pernah disimpan plaintext, tidak muncul di HTML/JSON/audit;
- field secret kosong saat edit mempertahankan secret lama;
- kredensial dapat diganti, diaktifkan, dites, dan dicabut;
- fitur dapat dinonaktifkan secara independen;
- OTP sekali pakai dan terikat akun/purpose/nomor;
- notifikasi idempoten dan `accepted` tidak pernah diklaim sebagai delivered;
- delivery hanya dari webhook bertanda tangan valid;
- monitoring memakai data nyata dan tidak mengarang biaya/kuota;
- RBAC/CSRF tidak berubah dan floating AI tetap berfungsi.

### 20.2 Temuan & perbaikan

| ID | Prioritas | Temuan | Status |
|---|---|---|---|
| T17-01 | P1 | `expires_at` OTP memakai `TIMESTAMP`; MariaDB memberi implicit `ON UPDATE CURRENT_TIMESTAMP`, sehingga expiry berubah menjadi waktu update status kirim | ✅ Diperbaiki: `DATETIME` + migrasi/ALTER |
| T17-02 | P1 | `app/bootstrap.php` membaca `$appConfig['timezone']` padahal konfigurasi berada di `$appConfig['app']['timezone']`; PHP dan MySQL dapat berbeda zona waktu sehingga batas harian AI/monitoring salah | ✅ Diperbaiki: membaca `app.timezone` |
| T17-03 | P2 | Hook notifikasi membaca `nama_pelanggan_snapshot` dari `Pengaduan::lockRow()`, tetapi kolom itu tidak dipilih → PHP warning pada transisi tiket | ✅ Diperbaiki: kolom ditambahkan ke SELECT |
| T17-04 | P2 | Retry outbox dan expiry OTP memakai jam PHP, sedangkan status antrean memakai jam DB | ✅ Diperbaiki: ekspresi `CURRENT_TIMESTAMP`/`DATE_ADD` di SQL |
| T17-05 | P2 | Tidak ada halaman monitoring untuk data gabungan AI + WhatsApp | ✅ Dibuat: `/admin/monitoring-api` |
| T17-06 | P3 | Tidak ada worker antrean; pemrosesan hanya lewat aksi manual | ✅ Dibuat: `bin/whatsapp-worker.php` |

Tidak ada temuan P0 baru. Tidak ada data lama yang dihapus. Migrasi bersifat
aditif.

### 20.3 Kontrol keamanan yang terverifikasi

- **Enkripsi:** token, webhook verify token, dan webhook secret disimpan sebagai
  ciphertext `v1:` libsodium. Master key hanya di environment. Penyimpanan
  ditolak saat master key tidak tersedia.
- **Anti-kebocoran:** halaman Admin, JSON, audit konfigurasi, audit umum, usage
  log, dan outbox tidak memuat token/secret/ciphertext. Form edit hanya
  menampilkan “secret tersimpan”.
- **Edit aman:** token/webhook kosong mempertahankan ciphertext lama; token baru
  mengganti ciphertext dan dicatat sebagai `access_token_diganti` (tanpa nilai).
- **Revoke lunak:** secret dihapus, `revoked_at` diisi, koneksi tidak dapat
  dites/dipakai; baris audit tetap ada.
- **OTP:** password hash + HMAC fingerprint; TTL, max attempts, cooldown, batas
  akun/nomor, purpose, dan single-use diuji.
- **Notifikasi:** `event_key` unik; payload/penerima terenkripsi; status hanya
  `pending → processing → accepted → delivered/read` (delivery via webhook).
- **Webhook:** GET memeriksa mode/token/challenge; POST memeriksa
  `X-Hub-Signature-256` dengan `hash_equals`; signature salah tidak mengubah
  status; webhook duplikat idempoten.
- **Rate limit:** batas gateway, fitur, per akun, dan per nomor diuji; satu
  kegagalan provider tidak menghentikan fitur lain.
- **RBAC/CSRF:** hanya Admin; CS/Teknisi 403; POST tanpa CSRF 419; route webhook
  publik hanya memproses signature valid.
- **Audit:** `api_config_audit` + `audit_log` mencatat aksi/field yang berubah
  tanpa secret.

### 20.4 Bukti pengujian

| Harness | Hasil |
|---|---|
| `tests/tahap17/api-center.php` | **94 PASS, 0 FAIL, 0 SKIP** |
| `tests/tahap10/e2e.php` | 79 PASS |
| `tests/tahap15/ai-platform.php` | 66 PASS, 2 BLOCKED |
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

`tests/tahap17/api-center.php` mencakup enkripsi, master key hilang,
blank-preserve, replace, revoke, provider selection, OTP (single-use, purpose,
nomor, expiry, attempts, rate limit), hook tiket, deduplikasi, accepted vs
delivered, signature webhook, kegagalan provider, isolasi fitur, RBAC/CSRF,
audit tanpa secret, monitoring nyata, filter, menu unik, tema, dan floating AI.

Catatan regresi lama: `tests/tahap16/identity.php` memiliki tiga assertion unit
yang menghardcode nama Admin `Budi Santoso Herman`, sedangkan DB utama saat ini
memakai `admin.cdn`/`Nur Muhammad`. Seluruh pengujian identitas via HTTP per
peran tetap lulus; kegagalan tersebut adalah drift dataset, bukan regresi kode.
`tests/tahap13/keamanan.php` juga memakai akun `admin.budi` pada Apache/DB
utama yang sudah tidak ada; pengujian setara pada DB uji lulus.

### 20.5 BLOCKED / sisa risiko

- **Live DeepSeek/OpenAI BLOCKED** — tidak ada kredensial nyata. Tes dengan key
  uji hanya membuktikan penanganan `invalid_key` aman.
- **Live WhatsApp Cloud API BLOCKED** — tidak ada access token, Phone Number ID,
  nomor pengirim, dan template approved. Tes memakai mock lokal, tetapi
  signature, status, dan antrean diuji nyata.
- **Laporan otomatis belum diimplementasikan** — hanya konfigurasi/rencana;
  aktivasi mensyaratkan persetujuan. Tidak ada scheduler, URL publik, atau
  penyimpanan isi laporan.
- **Cookie Secure penuh** tetap SKIP tanpa TLS lokal.
- Kredensial provider yang pernah terekspos sebelum pengerasan harus dirotasi
  secara operasional.

---

## 21. Audit Hybrid AI, Database Lokal & Ollama Opsional (Tahap 18, 26 Oktober 2026)

### 21.1 Lingkup

Audit dan implementasi asisten AI hybrid: jawaban operasional deterministik
dari database lokal, cloud AI untuk troubleshooting/percakapan umum, dan Ollama
lokal opsional. Fokus audit:

- Admin dapat membaca data karyawan, pelanggan, pengaduan, teknisi, rating,
  kategori, tren, dan ringkasan operasional melalui tool PHP read-only.
- CS/teknisi/pelanggan tetap terkena RBAC; `pelanggan` tidak pernah dihitung
  sebagai karyawan.
- Tidak ada SQL bebas, DDL, atau tool tulis; model tidak menerima kredensial
  MySQL/API key/session.
- Fallback lokal tetap menjawab intent yang didukung saat cloud tidak
  dikonfigurasi, dinonaktifkan, gagal, atau terkena kuota.
- Tidak ada klaim “Online” tanpa tes nyata dan tidak ada angka/identitas yang
  dikarang.
- UI floating AI, jawaban rata kiri, tabel/kartu aman, badge, Light/Dark, dan
  layout mobile tetap dipertahankan.

### 21.2 Temuan & perbaikan

| ID | Prioritas | Temuan | Status |
|---|---|---|---|
| T18-01 | P1 | Jawaban database lokal tidak dihitung pada batas harian asisten sehingga monitoring/limit tidak konsisten | Diperbaiki: jawaban lokal dicatat sebagai pemakaian chat; detail sumber tetap di `ai_data_access_log` |
| T18-02 | P1 | Placeholder PDO bernama dipakai berulang pada pencarian karyawan/pelanggan/pengaduan; native prepare MySQL menolak | Diperbaiki: placeholder unik per kemunculan |
| T18-03 | P1 | Statistik “selesai” mengabaikan filter pelanggan/kategori/teknisi/pencarian/arsip/batal dan memakai tanggal yang salah | Diperbaiki: filter diterapkan dan timestamp penyelesaian dipakai untuk periode |
| T18-04 | P2 | Konteks follow-up lama dapat menimpa intent eksplisit (mis. “tiket aktif”) atau mencampur entitas setelah pindah dataset | Diperbaiki: intent eksplisit diutamakan; konteks dibersihkan saat dataset berubah |
| T18-05 | P2 | Form/API pengaturan lama yang parsial dapat mereset toggle hybrid/Ollama | Diperbaiki: penanda `hybrid_settings_present`; data lama dipertahankan bila penanda tidak ada |
| T18-06 | P2 | Status provider berisiko menampilkan “Online” tanpa bukti | Diperbaiki: status “belum diuji”/hasil tes nyata; tidak ada klaim tanpa tes |
| T18-07 | P2 | Belum ada audit akses data AI yang aman | Dibuat: `ai_data_access_log` metadata-only |
| T18-08 | P3 | Jawaban pelanggan mencampur total non-batal dan jumlah batal | Diperbaiki: dipisah dan baris ditolak/dibatalkan dijelaskan |
| T18-09 | P3 | Timeout Ollama belum ditangani khusus | Diperbaiki: jalur timeout terpisah untuk provider lokal |

Tidak ada temuan P0 baru. Migrasi `009_ai_hybrid.sql` bersifat aditif; tidak
ada data lama yang dihapus atau diubah.

### 21.3 Kontrol keamanan yang terverifikasi

- **Read-only:** seluruh query memakai `SELECT` + PDO prepared statement;
  filter, urutan, dan batas hasil memakai allowlist. Tidak ada tool tulis/DDL.
- **RBAC berlapis:** `AiOperationsReadService` dan `AiToolService` memeriksa
  peran; jalur lokal dan cloud tidak memiliki aturan akses yang berbeda.
- **Minimisasi data:** password hash, password PPPoE/plaintext, API key, token,
  session ID, master key, dan kredensial MySQL tidak pernah diseleksi/dikirim.
  Nomor kontak CS disamarkan; Admin melihat data sesuai kewenangan.
- **Anti-fabrikasi:** intent yang tidak didukung, data kosong, atau database
  gagal menghasilkan pesan batasan yang jujur—bukan angka/identitas karangan.
- **Audit metadata-only:** `ai_data_access_log` hanya menyimpan user, role,
  source, intent, dataset, jumlah hasil, status sukses, dan waktu. Tidak ada isi
  pesan, kata pencarian, nomor telepon, atau alamat.
- **CSRF/RBAC Admin:** tes provider dan tes MySQL read-only hanya untuk Admin,
  memakai token CSRF; respons tidak memuat secret/ciphertext.
- **Ollama lokal:** base URL HTTP hanya diizinkan untuk loopback/localhost; API
  key boleh kosong; tidak ada unduhan model otomatis dan tidak ada akses SQL.
- **UI aman:** tabel/badge dirender sebagai DOM teks, bukan HTML mentah; tema
  Light/Dark dan layout mobile dipertahankan.

### 21.4 Bukti pengujian

| Harness | Hasil |
|---|---|
| `tests/tahap18/local-ai.php` | **135 PASS, 0 FAIL, 1 SKIP** |
| `tests/tahap17/api-center.php` | 94 PASS |
| `tests/tahap10/e2e.php` | 79 PASS |
| `tests/tahap15/ai-platform.php` | 66 PASS, 2 BLOCKED |
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

Tahap 18 mencakup akurasi jumlah karyawan, pelanggan tidak dihitung karyawan,
privasi kontak CS, detail/follow-up pelanggan, akurasi pengaduan dan filter
periode, tanpa duplikasi, fallback lokal tanpa API key, kejujuran saat DB gagal,
kejujuran intent tak didukung, RBAC/prompt injection, tidak ada SQL bebas/tool
tulis, tidak ada secret, perilaku multi-provider, serta Ollama bila tersedia.
SKIP hanya untuk tes live Ollama karena runtime tidak tersedia.

### 21.5 BLOCKED / sisa risiko

- **Ollama live BLOCKED** — Ollama tidak terpasang/berjalan; probe terakhir
  `network`. Tidak ada model yang diunduh tanpa persetujuan pengguna.
- **Gemini/DeepSeek/OpenAI live BLOCKED** tanpa kredensial/kuota nyata.
- **WhatsApp Cloud API live BLOCKED** tanpa kredensial Meta/template approved.
- **Laporan otomatis** tetap belum diimplementasikan (konfigurasi/rencana).
- **Cookie `Secure`** tetap SKIP tanpa TLS lokal.
- `tests/tahap16/identity.php` masih memiliki assertion unit yang hardcode nama
  akun lama; kegagalan tersebut adalah drift dataset. Pengujian identitas HTTP
  per peran tetap lulus. Dua harness lain diperbaiki agar tidak bergantung pada
  ID pelanggan/nomor tiket hardcode yang mudah bergeser.

---

## 22. Audit Local-First AI, Intent, dan Monitoring Sumber (Tahap 19, 26 Oktober 2026)

### 22.1 Lingkup

Audit final enam koreksi perilaku AI:

- local-first untuk seluruh intent data yang didukung;
- pemisahan jumlah/daftar/pencarian pelanggan dan metrik teknisi;
- jawaban ringkas untuk pertanyaan sederhana;
- konteks sesi yang terbatas dan aman;
- RBAC read-only pada jalur lokal dan cloud;
- fallback jujur serta monitoring sumber jawaban.

Tidak ada perubahan pada alur pengaduan, autentikasi, RBAC aplikasi, tema,
floating widget, API key, atau data produksi.

### 22.2 Temuan & perbaikan

| ID | Prioritas | Temuan | Status |
|---|---|---|---|
| T19-01 | P1 | "Pelanggan siapa aja" bisa salah dianggap pencarian nama | Diperbaiki: `customers.list` membedakan daftar dari `customers.search` |
| T19-02 | P1 | Pertanyaan peringkat teknisi informal ("kerjaannya banyak") bisa jatuh ke jumlah akun | Diperbaiki: regex peringkat memprioritaskan `kerjaannya`/`pekerjaannya` sebelum `banyak` |
| T19-03 | P1 | Intent personal/lama masih melalui cloud meski data sudah dapat dijawab lokal | Diperbaiki: semua intent yang dikenali masuk local-first; cloud tidak dipanggil setelah lokal berhasil |
| T19-04 | P1 | Pesan kegagalan MySQL, timeout cloud, intent tak dikenal, dan akses ditolak berisiko tercampur | Diperbaiki: pesan dan jalur error dipisahkan; MySQL memakai pesan khusus yang jujur |
| T19-05 | P2 | Monitoring belum membedakan database lokal, FAQ lokal, cloud AI, dan timeout | Diperbaiki: agregat `local_database_month`, `local_faq_month`, `cloud_ai_month`, `timeout_month`, plus kartu Admin |
| T19-06 | P2 | Risiko konteks pencarian pelanggan terbawa saat pengguna meminta daftar baru | Diperbaiki: konteks dibersihkan saat dataset berubah; daftar tidak memakai konteks pencarian lama |
| T19-07 | P2 | Harness config-priority tidak mengisolasi provider key lama sehingga key legacy tidak benar-benar diuji | Diperbaiki pada harness: backup/restore `ai_provider_keys`, hapus saat pengujian |
| T19-08 | P3 | Assertion tanggal kartu tiket memakai regex yang tidak cocok dengan format tampilan pendek | Diperbaiki: assertion memakai field `dibuat_panjang` yang memang berformat Indonesia |

Tidak ada temuan P0 baru. Tidak ada migrasi baru; skema `009_ai_hybrid.sql`
tetap aditif dan sudah diterapkan.

### 22.3 Kontrol yang terverifikasi

- **Local-first:** T15/T16/T19 membuktikan jawaban DB tetap ada tanpa cloud dan
  provider cloud tidak dipanggil untuk data lokal.
- **Intent:** T01-T14 membandingkan hasil asisten dengan query MySQL nyata
  untuk jumlah, daftar, pencarian, peringkat, tie, tugas aktif, tiket selesai,
  dan teknisi tanpa penugasan.
- **Jawaban jujur:** T17 (intent tak dikenal), T18 (timeout cloud), dan T20
  (MySQL gagal) tidak menghasilkan angka karangan.
- **Keamanan:** T21 menolak permintaan kredensial; T22 menolak SQL bebas dan
  memastikan data tidak berubah; T23/T24 memverifikasi RBAC CS/teknisi.
- **Monitoring/audit:** T25 memeriksa pencatatan sumber dan memastikan audit
  tidak memuat data sensitif.
- **Konteks:** T02/T03 memeriksa pembersihan konteks daftar dan penggunaan
  konteks pencarian yang sah.
- **Read-only:** query memakai PDO prepared statement, filter allowlist, dan
  batas hasil; cloud tidak menerima SQL atau kredensial MySQL.

### 22.4 Bukti pengujian

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

### 22.5 FAIL / BLOCKED yang dicatat

- `tests/tahap10/ai-read-tools.php` **FAIL** (16 PASS, 10 FAIL): harness lama
  menghardcode ID dan mengharapkan tool-loop cloud untuk intent data yang kini
  dijawab lokal. Ini konsekuensi local-first, bukan kegagalan jalur produksi.
- `tests/tahap13/keamanan.php` **FAIL** pada Apache/DB utama karena akun/foto
  hardcode tidak cocok dengan dataset mutable. Pengujian HTTP setara di DB uji
  tetap dijalankan suite lain.
- `tests/tahap16/identity.php` **FAIL** (44 PASS, 17 FAIL) karena assertion unit
  hardcode ID/nama lama; alur identitas HTTP per peran PASS.
- Live Ollama/Gemini/DeepSeek/OpenAI dan WhatsApp Cloud API **BLOCKED** tanpa
  runtime/kredensial nyata. SKIP bukan PASS.
- Laporan WhatsApp otomatis belum diimplementasikan; cookie `Secure` penuh
  belum diverifikasi tanpa TLS lokal.

### 22.6 Sisa risiko

- Harness lama yang menghardcode dataset perlu dimigrasikan ke fixture dinamis
  sebelum dapat dijadikan gerbang rilis.
- Provider live harus diuji terpisah dengan kredensial/kuota terkontrol dan
  persetujuan biaya.
- Audit metadata tidak menyimpan isi pesan; investigasi insiden memerlukan
  telemetry tambahan yang tetap menghormati privasi.

---

## 23. Audit Perbaikan Pencarian Nama Pelanggan & Monitoring Provider (Tahap 20, 27 Oktober 2026)

### 23.1 Lingkup

Audit terhadap bug nyata pada Floating WiFiCare AI:

> `apakah ada pelanggan atas nama agus`
> dijawab `Pelanggan dengan nama "atas nama agus" tidak ditemukan.`

Audit mencakup ekstraksi entitas, intent router, query pelanggan, pesan hasil,
RBAC, konteks follow-up, local-first, serta pemantauan kegagalan provider.
Tidak ada perubahan pada alur pengaduan, autentikasi aplikasi, tema, floating
widget, atau API key. Tidak ada data pelanggan fixture yang dibuat di database
utama.

### 23.2 Temuan & perbaikan

| ID | Prioritas | Temuan | Status |
|---|---|---|---|
| T20-01 | P1 | Frasa `atas nama` ikut diekstrak sebagai nama pelanggan | Diperbaiki: frasa pengantar dikonsumsi sebelum pembersihan nama |
| T20-02 | P1 | `bernama`, `yang bernama`, `yang namanya`, `dengan nama` belum ditangani seragam | Diperbaiki: satu daftar frasa pengantar, bukan `if` per kalimat |
| T20-03 | P1 | `apakah agus terdaftar sebagai pelanggan?` jatuh ke `customers.list` | Diperbaiki: pola nama terbalik -> `customers.search` |
| T20-04 | P2 | `siapa pelanggan terbaru` jatuh ke daftar pelanggan | Diperbaiki: intent `customers.latest` + query `created_at DESC, id DESC` |
| T20-05 | P2 | Substring `nya` pada `namanya` memicu intent detail dari konteks lama | Diperbaiki: referensi pronomina hanya dipakai bila tidak ada nama eksplisit |
| T20-06 | P2 | Pesan tidak ditemukan mengulang frasa pengantar | Diperbaiki: `Pelanggan bernama <Nama> tidak ditemukan.` |
| T20-07 | P2 | Pertanyaan keberadaan berpotensi menampilkan WhatsApp/alamat | Diperbaiki: tabel ringkas nama, PPPoE, status |
| T20-08 | P2 | LIKE belum meng-escape `%`/`_` dari input pengguna | Diperbaiki: parameter LIKE di-escape, tetap prepared statement |
| T20-09 | P2 | Harness identitas memakai ID/nama Admin hardcode | Diperbaiki: akun dibaca dari fixture DB; T16 kini 27 PASS, 0 FAIL, 1 SKIP |
| T20-10 | P3 | Bukti no-cloud untuk pencarian pelanggan belum ada | Diperbaiki: provider mock memverifikasi tidak ada request cloud |

Tidak ada temuan P0 baru. Tidak ada perubahan skema pada audit ini; migrasi
`010_ai_provider_events.sql` dari pekerjaan Tahap 20 tetap aditif dan sudah
diterapkan pada DB uji dan DB utama.

### 23.3 Kontrol yang terverifikasi

- **Ekstraksi:** 20 skenario `customer-name-search.php` memeriksa frasa
  pengantar, nama lengkap, kapitalisasi/spasi, nama tidak ada, nama mirip,
  dan pelanggan tanpa riwayat.
- **Query:** hasil AI dibandingkan langsung dengan
  `AiOperationsReadService::customerSearch()` dan tabel `pelanggan/users`.
  Tidak ada `JOIN pengaduan` sebagai syarat, sehingga pelanggan tanpa tiket
  tetap ditemukan.
- **Intent:** daftar, jumlah, pencarian, pelanggan terbaru, dan pembuat
  pengaduan terbaru dipisahkan.
- **RBAC:** Admin dan CS dapat mencari; role pelanggan mendapat
  `access.denied` dan tidak menerima baris data.
- **Minimalisasi:** tabel keberadaan hanya memuat nama, PPPoE, dan status;
  tidak ada WhatsApp/alamat. Respons tidak memuat password/hash/API key.
- **Local-first:** provider mock berstatus kuota habis; pertanyaan pelanggan
  tetap dijawab lokal dan jumlah request ke mock tidak bertambah.
- **Konteks:** follow-up `berapa pengaduannya` dan `pelanggan itu` memakai
  pelanggan yang baru ditemukan, bukan pelanggan lama.
- **Monitoring:** kuota habis, rate limit sementara, auth, permission, timeout,
  jaringan, model, dan server dibedakan; notifikasi Admin dideduplikasi;
  informasi yang tidak tersedia ditampilkan `Tidak tersedia`.

### 23.4 Bukti pengujian

| Harness | Hasil |
|---|---|
| `tests/tahap20/customer-name-search.php` | **20 PASS, 0 FAIL** |
| `tests/tahap20/provider-monitoring.php` | **24 PASS, 0 FAIL, 0 BLOCKED** |
| `tests/tahap20/local-latest.php` | **14 PASS, 0 FAIL** |
| `tests/tahap19/local-first.php` | **25 PASS, 0 FAIL, 0 BLOCKED** |
| `tests/tahap18/local-ai.php` | 103 PASS, 0 FAIL, 2 SKIP |
| `tests/tahap16/identity.php` | 27 PASS, 0 FAIL, 1 SKIP |
| `tests/tahap10/ai-read-tools.php` | 18 PASS, 8 FAIL (utang harness local-first) |
| `tests/tahap13/keamanan.php` | 77 PASS, 25 FAIL, 1 SKIP (fixture DB utama) |

Bukti langsung:

- Sebelum: `apakah ada pelanggan atas nama agus` ->
  `Pelanggan dengan nama "atas nama agus" tidak ditemukan.`
- Sesudah: `apakah ada pelanggan atas nama agus` ->
  `Pelanggan bernama Agus Setiawan ditemukan.` (DB uji).
- Tidak ditemukan: `Pelanggan bernama Zzz Tidak Ada ... tidak ditemukan.`
- `apakah agus terdaftar sebagai pelanggan?` -> `customers.search`.
- `siapa pelanggan terbaru` -> `customers.latest`.
- Pelanggan biasa ditolak; Admin/CS diizinkan.

### 23.5 FAIL / BLOCKED yang dicatat

- `tests/tahap10/ai-read-tools.php` masih mengharapkan tool-loop cloud untuk
  intent yang sekarang dijawab lokal. Delapan assertion gagal karena perubahan
  desain local-first; assertion keamanan tool/RBAC tetap lulus.
- `tests/tahap13/keamanan.php` masih memakai akun dan foto hardcode
  (`admin.budi`, `pppoe.rina`, `pppoe.agus`) yang tidak cocok dengan DB utama
  saat audit. Tidak ada kredensial/fixture produksi yang dibuat untuk memaksa
  PASS.
- Live Ollama/Gemini/DeepSeek/OpenAI dan WhatsApp Cloud API **BLOCKED** tanpa
  runtime/kredensial/kuota nyata. Mock provider hanya membuktikan jalur
  deterministik, bukan kualitas model live.

### 23.6 Sisa risiko

- Harness Tahap 10 dan Tahap 13 perlu migrasi ke fixture dinamis atau
  lingkungan uji terisolasi sebelum dijadikan gerbang rilis.
- Pencarian memakai `LIKE` sehingga nama yang sangat mirip tetap memerlukan
  klarifikasi; ini disengaja agar tidak memilih pelanggan yang salah.
- `displayName()` hanya untuk tampilan pesan tidak ditemukan; nama yang
  ditemukan selalu diambil dari kolom database, bukan dari input.
- Semua perubahan tetap belum di-commit.