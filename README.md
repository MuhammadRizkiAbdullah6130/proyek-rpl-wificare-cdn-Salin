# IDENTITAS MAHASISWA
NAMA : M. RIZKI ABDULLAH
NIM : 41257203
PRODI : TEKNIK INFORMATIKA
KELAS : TI-1F-2025

LINK PENGUMPULAN VIDEO PRESENTASI YOUTUBE :
TAHAP 1: https://youtu.be/59rLj4kuZHs?si=43oHUVAe6TmB_gwW
TAHAP 2: https://youtu.be/-r_-xRkXjj4?si=jx1tzWJfszO7Hv9w
FINAL VIDEO DEMO : https://youtu.be/ITtc1w0I5U4?si=IYrsQSY22kHlHsxe

Sistem manajemen pengaduan pelanggan untuk penyedia layanan WiFi (versi 1.0).

WiFiCare CDN memusatkan pengaduan pelanggan menjadi satu tiket yang
terdokumentasi, terpantau, dan mudah diikuti — mulai dari pengajuan,
verifikasi Customer Service, penugasan teknisi, hingga laporan
penyelesaian.

> **Status saat ini (v1.0 — Tahap 1–19 selesai):** fondasi & UI/UX, database
> MySQL + autentikasi + RBAC 4 peran, dashboard dinamis, sistem pengaduan
> pelanggan (nomor tiket otomatis + foto), modul Customer Service, modul
> Teknisi, modul Admin (pengguna, pelanggan, kategori, pengaturan, audit log,
> arsip, notifikasi, rating), **Laporan & Analitik** (9 jenis + ekspor PDF/Excel
> native), **AI Assistant multi-provider**, serta **Pusat API & WhatsApp**
> (kredensial terenkripsi, OTP, notifikasi tiket, monitoring penggunaan API).
> Tahap 10 menutup audit menyeluruh, perbaikan bug, dan pengerasan keamanan —
> lihat [AUDIT_REPORT.md](AUDIT_REPORT.md) dan
> [TEST_REPORT.md](TEST_REPORT.md).

## Persyaratan

- PHP 8.2+ (diuji juga pada PHP 8.0 untuk lint)
- MySQL 5.7+ / MariaDB 10.4+ (diuji pada MariaDB 10.4 XAMPP)
- XAMPP / Laragon (Apache + MySQL)
- **Tidak memerlukan Composer.** PDF & Excel dibuat dengan penulis native
  bebas-dependensi (`PdfReport`, `XlsxWriter`); `vendor/` tidak diperlukan.
- Ekstensi PHP: `pdo_mysql`, `mbstring`, `fileinfo`, `openssl`, `curl`,
  `zip`, `xml`/`xmlwriter`/`dom`, **`sodium`** (enkripsi kredensial).
  **GD tidak diperlukan.**
- AI Assistant (opsional) memerlukan koneksi internet + `GEMINI_API_KEY`/`GEMINI_MODEL`.

## Menjalankan

### Opsi 1 — Server bawaan PHP (paling cepat)

```bash
php -S localhost:8000 -t public public/index.php
```

Lalu buka <http://localhost:8000>.

### Opsi 2 — XAMPP / Laragon

1. Letakkan proyek pada dokumen web server:

   ```
   C:\xampp\htdocs\wificare-cdn
   ```

   atau

   ```
   laragon\www\wificare-cdn
   ```

2. Salin `.env.example` menjadi `.env` dan sesuaikan nilainya:

   ```
   copy .env.example .env
   ```

3. Arahkan **document root** ke folder `public/` (sangat disarankan), atau
   akses melalui `http://localhost/wificare-cdn/public/`.

   Bila dipasang pada subfolder tanpa mengubah document root, atur:

   ```
   APP_BASE_PATH=/wificare-cdn/public
   ```

4. Pastikan `mod_rewrite` Apache aktif (aktif secara bawaan pada XAMPP) agar
   `public/.htaccess` meneruskan URL bersih ke `public/index.php`.

5. Buka browser:

   ```
   http://localhost/
   ```

## Database & akun

### Konfigurasi

Salin `.env.example` menjadi `.env` lalu sesuaikan nilainya (jangan commit `.env`).

| Variabel | Keterangan |
|---|---|
| `DB_HOST`, `DB_PORT` | Host & port MySQL. |
| `DB_NAME` | Nama database (contoh: `wificare_cdn_v1`). |
| `DB_USER`, `DB_PASSWORD` | Kredensial MySQL. |
| `APP_ENV`, `APP_DEBUG` | Lingkungan & mode debug (matikan di produksi). |
| `APP_URL`, `APP_BASE_PATH` | URL aplikasi & base path (bila di subfolder). |

### Impor / migrasi database

Cara termudah melalui CLI:

```bash
php bin/migrate.php
```

Perintah di atas membuat database bila belum ada, menjalankan seluruh migrasi di
`database/migrations` (dicatat pada tabel `migrations`, tidak diulang), lalu
menjalankan seeder di `database/seeds`.

Alternatif manual melalui phpMyAdmin / MySQL CLI:

1. Buat database (mis. `wificare_cdn_v1`) dengan charset `utf8mb4`.
2. Impor berurutan: `database/migrations/001_core_auth.sql`,
   `002_pengaduan.sql`, `003_operasional.sql`, `004_penyelesaian_cs.sql`.
3. Impor seeder: `database/seeds/kategori_gangguan.sql`, `database/seeds/pengaturan.sql`.

### Membuat Admin pertama

Skrip hanya dapat dijalankan dari CLI dan berada di luar web root (`bin/`):

```bash
php bin/create-user.php --role=admin --username=admin.budi --nama="Budi Santoso"
```

- Bila `--password` tidak diberikan, password kuat dibuat otomatis dan
  ditampilkan **sekali** — simpan segera.
- Admin kedua ditolak kecuali memakai `--force`.

Akun peran lain:

```bash
php bin/create-user.php --role=cs --username=cs.dewi --nama="Dewi Lestari"
php bin/create-user.php --role=teknisi --username=tek.andi --nama="Andi Pratama"
php bin/create-user.php --role=pelanggan --username=rina --nama="Rina" --pppoe=rina --whatsapp=081234567890
```

### Login

- **Admin / CS / Teknisi:** username + password akun.
- **Pelanggan:** Username PPPoE + password akun WiFiCare
  (password WiFiCare berbeda dari password jaringan PPPoE).

Setelah login, pengguna diarahkan ke dashboard sesuai peran. Akun nonaktif
ditolak. Tidak ada registrasi publik — semua akun dibuat oleh Admin.

### Data contoh untuk pengujian (opsional)

Dashboard admin, CS, teknisi, dan pelanggan menampilkan statistik serta grafik
nyata dari database. Bila database masih kosong, dashboard tetap berjalan
(statistik 0 dan empty state). Untuk pengujian dengan data, tersedia skrip data
contoh (dijalankan manual — aplikasi tidak pernah membuat tiket dummy otomatis):

```bash
php bin/seed-sample.php          # mengisi tiket contoh (menolak bila sudah ada data)
php bin/seed-sample.php --force  # tetap menambahkan walaupun sudah ada data
php bin/seed-sample.php --reset  # bersihkan data pengaduan lalu isi ulang
```

Skrip membuat tiket lintas status (termasuk 1 tiket dibatalkan) beserta
penugasan dan laporan, serta akun uji tambahan `tek.bayu` (teknisi) dan
`pppoe.agus` (pelanggan) untuk menguji isolasi data antar pengguna.

## Daftar halaman

| Area | URL | Halaman |
|---|---|---|
| Publik | `/` | Beranda |
| Publik | `/about` | Tentang |
| Publik | `/login` | Login (form nyata + CSRF) |
| Publik | `/logout` | Keluar (POST + CSRF) |
| Publik | `/akses-ditolak` | Akses ditolak (403) |
| Admin | `/admin/dashboard` | Dashboard Admin |
| Admin | `/admin/pengguna` | Manajemen Pengguna |
| Admin | `/admin/pelanggan` | Data Pelanggan |
| Admin | `/admin/pengaduan` | Semua Pengaduan |
| Admin | `/admin/teknisi` | Monitoring Teknisi |
| Admin | `/admin/laporan` | Laporan & Analitik (9 jenis) |
| Admin | `/admin/laporan/pdf` | Ekspor PDF (filter sama dengan tampilan) |
| Admin | `/admin/laporan/excel` | Ekspor Excel `.xlsx` (anti formula-injection) |
| Admin | `/admin/kategori` | Kategori Gangguan |
| Admin | `/admin/audit` | Audit Log |
| Admin | `/admin/pengaturan` | Pengaturan |
| CS | `/cs/dashboard` | Dashboard Customer Service |
| CS | `/cs/pengaduan` | Pengaduan Masuk (cari/filter/pagination) |
| CS | `/cs/pengaduan/{id}` | Detail Pengaduan + tindakan (verifikasi/tolak/selesai/tugaskan/batal/catatan) |
| CS | `/cs/verifikasi` | Antrian Verifikasi Pengaduan |
| CS | `/cs/penugasan` | Antrian Penugasan Teknisi |
| CS | `/cs/monitoring` | Monitoring Pengaduan (ringkasan + filter) |
| CS | `/cs/pelanggan` | Daftar Riwayat Pelanggan |
| CS | `/cs/pelanggan/{id}` | Riwayat Pengaduan Pelanggan |
| CS | `/cs/catatan` | Catatan Internal (terbaru lintas tiket) |
| Teknisi | `/teknisi/dashboard` | Dashboard Teknisi (statistik tugas milik sendiri) |
| Teknisi | `/teknisi/tugas` | Tugas Saya (cari/filter/pagination) |
| Teknisi | `/teknisi/tugas/{id}` | Detail Tugas + tindakan (mulai penanganan) |
| Teknisi | `/teknisi/tugas/{id}/laporan` | Form Laporan Perbaikan (GET), kirim laporan (POST) |
| Teknisi | `/teknisi/riwayat` | Riwayat Pekerjaan (cari/filter/pagination) |
| Pelanggan | `/pelanggan/dashboard` | Dashboard Pelanggan |
| Pelanggan | `/pelanggan/pengaduan/baru` | Buat Pengaduan (form + upload foto) |
| Pelanggan | `/pelanggan/pengaduan` | Riwayat Pengaduan (cari/filter/pagination) |
| Pelanggan | `/pelanggan/pengaduan/{id}` | Detail Tiket (timeline + foto) |
| Pelanggan | `/pelanggan/profil` | Profil |
| Pelanggan | `/pelanggan/rating` | Rating Layanan |
| Pelanggan | `/pelanggan/ai` | AI Assistant (dialihkan ke `/ai`) |
| Umum (auth) | `/ai` | Halaman AI Assistant lama (semua peran). Akses utama kini melalui **floating widget** di pojok kanan bawah setiap halaman. |
| Umum (auth) | `/ai/chat` | Endpoint chat AI (dipakai halaman `/ai` dan floating widget) |
| Umum (auth) | `/notifikasi` | Notifikasi in-app (terisolasi per pengguna) |
| Umum (auth) | `/pengaduan/foto/{id}` | Foto bukti (diperiksa hak akses) |
| Umum (auth) | `/laporan/foto/{id}` | Foto hasil perbaikan (diperiksa hak akses) |

## Struktur proyek

```
wificare-cdn/
├── app/
│   ├── Controllers/      # Controller per area (Home, Auth, Admin, Cs, Teknisi, Pelanggan)
│   ├── Helpers/          # functions.php, menu.php, env.php, auth.php
│   ├── Middleware/       # AuthMiddleware.php (auth, guest, role/RBAC)
│   ├── Models/           # User, Pelanggan, Kategori, Pengaduan, Penugasan,
│   │                     #   LaporanTeknisi, PenyelesaianCs, CatatanCs,
│   │                     #   Rating, Notifikasi, Pengaturan, AuditLog,
│   │                     #   LoginAttempt, Laporan
│   ├── Services/         # Database, Session, Csrf, Auth, Dashboard,
│   │                     #   Pengaduan, PengaduanFlowService, Upload, Rating,
│   │                     #   User, Pelanggan, Kategori, Arsip, Notifikasi,
│   │                     #   Pengaturan, Laporan, PdfReport, XlsxWriter,
│   │                     #   AiAssistantService, AiToolService, AiKnowledge,
│   │                     #   AiRateLimiter, ValidationException
│   ├── Views/
│   │   ├── layouts/      # base.php (publik), app.php (per peran), auth.php (login)
│   │   ├── components/   # komponen reusable
│   │   ├── admin/ cs/ teknisi/ pelanggan/
│   │   └── home.php, about.php, login.php, error.php, akses-ditolak.php
│   └── bootstrap.php     # memuat env, config, helper, service, middleware, model
├── bin/                  # migrate.php, create-user.php, seed-sample.php (hanya CLI)
├── config/               # app.php, database.php
├── database/             # migrations/*.sql, seeds/*.sql
├── public/               # document root: index.php, .htaccess, assets/
├── routes/               # web.php
├── storage/              # logs/, uploads/pengaduan/, uploads/laporan/ (di luar web root)
├── tests/                # harness pengujian (lihat tests/README.md)
├── AUDIT_REPORT.md       # hasil audit Tahap 10
├── TEST_REPORT.md        # hasil pengujian Tahap 10
└── PROGRESS.md
```

## Catatan keamanan (sudah aktif)

- Password disimpan sebagai hash bcrypt (`password_hash()`); tidak ada plaintext.
- Verifikasi password dengan `password_verify()`; pesan error login aman.
- Session aman: cookie HttpOnly + SameSite=Lax, `use_strict_mode`, ID
  diregenerasi setelah login (mencegah session fixation), batas waktu idle.
- CSRF token pada setiap form (login, logout) dengan perbandingan `hash_equals()`.
- RBAC diterapkan pada setiap route per peran; akses salah peran → HTTP 403.
- Pembatasan percobaan login (brute force) berdasarkan username dan IP.
- PDO prepared statement tanpa emulasi; detail error database tidak ditampilkan
  ke pengguna (dicatat ke `storage/logs/app.log`).
- Escape output melalui helper `e()` pada seluruh view.
- Password jaringan PPPoE tidak pernah disimpan.
- Upload foto: MIME asli diperiksa (`finfo` + `getimagesize`), ekstensi dari MIME,
  nama file acak, maksimal 5 MB, disimpan di luar document root
  (`storage/uploads/pengaduan/`); file PHP/executable ditolak.
- Foto hanya dapat diakses melalui `/pengaduan/foto/{id}` dengan pemeriksaan
  autentikasi & kepemilikan tiket (pelanggan hanya fotonya sendiri).
- Modul CS: setiap route dilindungi autentikasi + RBAC (halaman `cs`; operasi
  tulis juga untuk `admin`). Identitas petugas selalu dari session.
- Transisi status divalidasi terpusat (`PengaduanFlowService`) dan dijalankan
  dalam transaksi + `SELECT ... FOR UPDATE`, sehingga klik ganda / permintaan
  bersamaan tidak menghasilkan perubahan status ganda; penugasan teknisi aktif
  dibatasi satu per tiket (histori penugasan tetap disimpan).
- Pembatalan adalah atribut terpisah (`is_cancelled`), bukan status ketujuh,
  dan tercatat pada `audit_log` tanpa mengubah status utama.
- Catatan internal CS hanya dapat dibaca petugas; tidak pernah dimuat pada
  halaman pelanggan maupun respons untuk pelanggan.
- Modul Teknisi: setiap route dilindungi autentikasi + RBAC (`teknisi`).
  Otorisasi kepemilikan tugas dilakukan di level query (`Pengaduan::findForTeknisi`
  + `Penugasan::activeForTeknisi`); teknisi hanya dapat melihat/menangani tiket
  dengan penugasan aktif miliknya, identitas selalu dari session.
- Foto hasil perbaikan disimpan di luar document root (`storage/uploads/laporan/`),
  MIME asli diperiksa (`finfo` + `getimagesize`), nama file acak, maksimal 5 MB;
  akses melalui `/laporan/foto/{id}` dengan pemeriksaan hak akses (pemilik tiket,
  teknisi terkait, admin/CS).
- Penyelesaian oleh teknisi bersifat atomik: laporan & referensi foto disimpan,
  status `selesai` baru diubah setelah semuanya tersimpan; bila gagal, transaksi
  di-rollback dan berkas yatim dibersihkan. Laporan ganda dicegah
  (UNIQUE `pengaduan_id` + kunci baris + pra-validasi).
- `.env` diabaikan git; `storage/` dan `bin/` berada di luar web root.
- Seluruh respons dinamis mengirim `Cache-Control: no-store`, sehingga data
  privat tidak ditampilkan kembali dari cache setelah logout / tombol Back.
- Endpoint API/AJAX tanpa sesi mengembalikan **HTTP 401 JSON** (bukan 403);
  pengguna yang sudah login tetapi salah peran mendapat **HTTP 403**.
- Identitas & peran selalu dibaca ulang dari database pada setiap request.
  Akun yang dinonaktifkan langsung kehilangan akses; peran tidak dikenal
  ditolak dan tidak pernah fallback ke Pelanggan.

### Proteksi folder internal (deployment XAMPP)

Aplikasi hanya boleh mengekspos folder `public/`. Pada deployment subfolder
(`http://localhost/aduan-pelanggan/public/`), folder internal dilindungi oleh:

| Berkas | Fungsi |
|---|---|
| `.htaccess` (root proyek) | Menolak `app/`, `bin/`, `config/`, `database/`, `deploy/`, `routes/`, `storage/`, `tests/`, `vendor/`, dotfiles (`.env`, `.git`), `composer.json`, `*.sql`, `*.log`, `*.bak`, `*.zip`, `*.dump`, dan sejenisnya; menonaktifkan directory listing. Akar proyek diarahkan ke `public/`. |
| `app/`, `bin/`, `config/`, `database/`, `deploy/`, `routes/`, `storage/`, `tests/` + `.htaccess` | `Require all denied` — lapisan kedua bila .htaccess root diubah. |
| `storage/uploads/` | Berada di luar `public/` dan ditolak dari URL. Foto hanya dilayani endpoint PHP ber-autentikasi (`/pengaduan/foto/{id}`, `/laporan/foto/{id}`). |
| `public/.htaccess` | Front controller, `Options -Indexes`, tolak dotfiles, header keamanan. |

Hasil yang diharapkan (tanpa login):

```
GET /aduan-pelanggan/                 -> 302 ke /aduan-pelanggan/public/
GET /aduan-pelanggan/.env            -> 403 Forbidden
GET /aduan-pelanggan/app/            -> 403 Forbidden
GET /aduan-pelanggan/config/         -> 403 Forbidden
GET /aduan-pelanggan/database/       -> 403 Forbidden
GET /aduan-pelanggan/storage/...     -> 403 Forbidden
GET /aduan-pelanggan/tests/          -> 403 Forbidden
GET /aduan-pelanggan/composer.json   -> 403 Forbidden
```

> Catatan: `RewriteBase /aduan-pelanggan/` pada `.htaccess` root mengikuti nama
> folder proyek. Bila folder diganti, sesuaikan baris tersebut atau gunakan
> VirtualHost di bawah.

#### Opsi utama (disarankan): VirtualHost ke `public/`

Gunakan contoh siap pakai `deploy/apache/wificare-cdn-vhost.conf.example`.
Ringkasnya:

1. Salin ke `C:\xampp\apache\conf\extra\wificare-cdn.conf`, sesuaikan path.
2. Aktifkan `Include conf/extra/wificare-cdn.conf` di `httpd.conf`.
3. Tambahkan `127.0.0.1 wificare.local` ke `C:\Windows\System32\drivers\etc\hosts`.
4. Setel `.env`: `APP_URL=http://wificare.local` dan `APP_BASE_PATH=` (kosong).
5. Restart Apache dan buka `http://wificare.local`.

**Rollback:** hapus baris `Include`, hapus berkas vhost, hapus entri hosts, lalu
kembalikan `APP_BASE_PATH=/aduan-pelanggan/public`.

#### Path traversal & foto

- Foto tidak pernah dibaca dari path mentah query string. Endpoint hanya
  menerima **ID numerik**, lalu memetakan path dari database.
- `Upload::resolve()` memverifikasi `realpath()` agar berkas tetap berada di
  dalam `storage/uploads/` (menolak `../`, null byte, path absolut, symlink).
- Nama file acak 32 heksadesimal; MIME asli diperiksa; akses dicek per tiket.

## Konfigurasi produksi

Sebelum dipakai di produksi (HTTPS), setel pada `.env`:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://domain-anda
APP_BASE_PATH=            # kosongkan bila document root = folder public/
SESSION_COOKIE_SECURE=true
```

- `APP_DEBUG=false` memastikan error rinci **tidak** ditampilkan ke pengguna
  (tetap dicatat ke `storage/logs/app.log`).
- Pastikan document root web server mengarah ke `public/` atau atur
  `APP_BASE_PATH` bila dipasang di subfolder.
- Jangan commit `.env`.

## AI Assistant (Gemini, DeepSeek, OpenAI, Ollama opsional)

AI Assistant berupa **floating chat widget** di pojok kanan bawah setiap halaman
yang memerlukan login (Admin, CS, Teknisi, Pelanggan). Widget dipasang sekali
melalui layout bersama (`app/Views/layouts/app.php`), bukan menu sidebar, dan
**tidak** muncul di halaman login. Halaman `/ai` lama tetap tersedia.

Provider cloud yang didukung: **Google Gemini** (`generateContent`), **DeepSeek**,
dan **OpenAI** (dua terakhir melalui API kompatibel OpenAI `chat/completions`).
**Ollama** dapat diaktifkan sebagai provider lokal opsional (loopback/localhost);
Ollama tidak wajib, tidak mengunduh model otomatis, dan tidak pernah menerima
akses SQL. Provider aktif dipilih Admin lewat dashboard; `.env` hanya menjadi
fallback saat database belum dikonfigurasi. Bila cloud belum dikonfigurasi tetapi
asisten lokal diaktifkan, pertanyaan database yang didukung tetap dijawab dari
database. Bila tidak ada jalur yang tersedia, widget menampilkan pesan batasan
yang jujur, bukan jawaban palsu. Contoh `.env`:

```
AI_PROVIDER=gemini
GEMINI_API_KEY=...
GEMINI_MODEL=gemini-3.5-flash
GEMINI_MODEL_FALLBACK=gemini-3.8-flash,gemini-flash-latest
GEMINI_API_BASE_URL=https://generativelanguage.googleapis.com/v1beta
GEMINI_THINKING_BUDGET=0

DEEPSEEK_API_KEY=...
DEEPSEEK_MODEL=deepseek-chat
DEEPSEEK_API_BASE_URL=https://api.deepseek.com/v1

AI_API_KEY=...                  # OpenAI-compatible (opsional)
AI_MODEL=gpt-4o-mini
AI_API_BASE_URL=https://api.openai.com/v1

AI_TIMEOUT=60
AI_MAX_RETRIES=1
AI_MAX_MESSAGE=2000
AI_RATE_LIMIT=20
AI_RATE_WINDOW=60
```

Provider dipilih dari konfigurasi aktif (database Admin) atau fallback `.env`.
Endpoint dipanggil dari backend via cURL: Gemini memakai
`POST {base}/models/{model}:generateContent` dengan header `x-goog-api-key`;
DeepSeek/OpenAI memakai `POST {base}/chat/completions` dengan
`Authorization: Bearer`. Format request/response ditangani adapter terpisah
(`app/Services/Ai/`), bukan dicampur dalam satu format.

Bila model utama membalas **503/429/404** (sempat terjadi saat `gemini-3.8-flash`
"high demand"), sistem mencoba ulang sebentar (`AI_MAX_RETRIES`) lalu beralih ke
`GEMINI_MODEL_FALLBACK`. Error permanen (400/403) tidak diulang.

Kunci API **hanya** dibaca di sisi server dan tidak pernah dikirim ke browser,
HTML, atau log. Endpoint `/ai/chat` dilindungi session + CSRF + rate limiting.

### WiFiCare AI — asisten operasional (database read-only)

AI berperan sebagai **asisten operasional** aplikasi (memandu troubleshooting,
FAQ layanan ISP, dan membantu membaca data pengaduan) — **bukan** asisten coding.
Aturan & keamanan:

- **Tidak melayani coding.** Permintaan kode/script/program/SQL ditolak dengan
  kalimat tetap, dijaga dua lapis (deteksi backend + aturan prompt).
- **Read-only.** AI tidak pernah menambah/mengubah/menghapus data (tidak menulis
  komentar, catatan CS, rating, dll). Tidak ada tool tulis.
- **Membaca database lewat fungsi PHP terkontrol.** Model Gemini tidak pernah
  menerima kredensial MySQL dan tidak pernah menjalankan SQL. Alur:
  `Widget → AiController → AiToolService (RBAC) → AiReadService → PDO SELECT →
  data terfilter → Gemini → jawaban`. AI dilarang mengarang angka/status.
- **Pembatasan per peran (RBAC)**: pelanggan (tiket miliknya), teknisi (tugasnya),
  CS (seluruh tiket + data pelanggan sesuai kewenangan), Admin (seluruh tiket,
  karyawan `admin`/`cs`/`teknisi`, pelanggan, teknisi, rating, kategori, tren,
  dan ringkasan operasional). Akun `pelanggan` tidak pernah dihitung sebagai
  karyawan. Deklarasi tool disaring per peran dan jalur lokal/cloud memakai
  pemeriksaan yang sama.
- **Function calling Gemini** dipakai agar model meminta data via tool; argumen
  kosong dikodekan `{}` dan part model dikembalikan termasuk `thoughtSignature`.

Opsional (disarankan di produksi): buat akun MySQL berizin `SELECT` saja untuk
pembacaan AI, lalu isi `.env`:

```
DB_RO_USER=wificare_ai_ro
DB_RO_PASSWORD=...
```

Bila `DB_RO_USER` kosong, dipakai koneksi utama (query tetap `SELECT` prepared).

## Pengaturan AI Chatbot (Admin)

Admin dapat mengelola layanan AI tanpa mengedit `.env` melalui
**Pengaturan → Pengaturan AI Chatbot** (`/admin/pengaturan/ai`, admin-only):

- Pilih **provider** (Gemini/DeepSeek/OpenAI), **model**, dan base URL.
- Simpan **API key terpisah per provider** (diinput sebagai password; key
  tersimpan tidak pernah ditampilkan ulang; field kosong = jangan ubah).
- Atur **status Aktif/Nonaktif**, batas output token, timeout, rate limit,
  **batas request harian per pengguna**, batas panjang pesan, dan
  **system instruction** opsional.
- **Tes Koneksi** memakai API sungguhan untuk provider yang dipilih (dapat
  memakai kuota API) dan menampilkan kode error aman.
- Lihat **monitoring** request/error/token hari ini dan penggunaan per pengguna
  (metadata saja).
- Kelola **FAQ** resmi: tambah, edit, cari, aktif/nonaktif, hapus.

**Penyimpanan API key yang aman.** Setiap key provider dienkripsi dengan
libsodium (`crypto_secretbox`, nonce acak per enkripsi) dan disimpan di
`ai_provider_keys` sebagai `v1:` + base64(nonce||ciphertext). Key Gemini lama
di `ai_settings` tetap dibaca sebagai fallback. Master key enkripsi hanya
dibaca dari environment `AI_SETTINGS_KEY` (tidak di database/repositori). Buat
kunci dengan:

```bash
php bin/ai-keygen.php
# salin baris AI_SETTINGS_KEY ke .env server
```

Bila libsodium/master key belum tersedia, halaman menampilkan informasi
konfigurasi yang aman dan API key **tidak disimpan** (fail-safe).

**Prioritas konfigurasi.** Bila baris `ai_settings` ada, provider aktif,
status Aktif/Nonaktif, model, dan limit dari tabel dipakai (Nonaktif
mengabaikan `.env`). Key provider yang tersimpan dipakai lebih dulu; bila key
provider tersebut belum tersimpan, key `.env` yang sesuai
(`GEMINI_API_KEY`, `DEEPSEEK_API_KEY`, atau `AI_API_KEY`) menjadi fallback.
Bila belum ada baris, perilaku `.env` lama dipertahankan sampai Admin
menyimpan pengaturan. Status Nonaktif menghentikan layanan tanpa menghapus
API key. Tidak ada perpindahan provider otomatis saat provider aktif gagal.

Catatan lingkungan XAMPP: pastikan ekstensi `sodium` aktif
(`extension=sodium` pada `php.ini`) dan `libsodium.dll` tersedia bagi proses
Apache.

### Tahap 15 - platform AI multi-provider

- **Provider terpisah:** `GeminiProvider`, `DeepSeekProvider`, `OpenAiProvider`
  di `app/Services/Ai/`, dengan registry allowlist. Tidak ada fallback provider
  otomatis.
- **Key per provider:** tabel `ai_provider_keys`; ciphertext `v1:`; key lama
  Gemini tetap kompatibel. Key tidak pernah dikirim ke browser/log.
- **Batas & monitoring:** `ai_settings.daily_limit` (per pengguna) dan
  `ai_usage_log` (metadata: provider, model, status, kode error, durasi, token;
  tanpa isi pesan/jawaban).
- **FAQ Admin:** tabel `ai_faq`; rute `POST /admin/pengaturan/ai/faq`,
  `/faq/status`, `/faq/hapus`; FAQ aktif menjadi konteks referensi.
- **Tool baca-only baru:** tiket aktif, profil sendiri, riwayat teknisi,
  ringkasan rating, statistik periode. Semua memakai SELECT prepared dan
  cakupan per peran.
- **UI:** salin jawaban, bersihkan percakapan (`POST /ai/clear`), indikator
  mengetik, Enter kirim, Shift+Enter baris baru, auto-scroll, tema Light/Dark.
- **Migrasi:** `database/migrations/007_ai_platform.sql` (sudah diterapkan pada
  DB utama dan DB uji; tidak menghapus data).
- **Read-only MySQL:** contoh `database/readonly_ai_user.example.sql`; isi
  `DB_RO_USER`/`DB_RO_PASSWORD` di `.env` agar AI memakai akun SELECT-only.

Langkah uji browser manual:

1. Login Admin -> **Pengaturan -> Pengaturan AI Chatbot**. Ganti provider,
   isi model/key, klik **Tes Koneksi**, simpan, lihat monitoring dan FAQ.
2. Login CS/Teknisi/Pelanggan -> pastikan tidak ada menu AI di sidebar dan
   halaman `/admin/pengaturan/ai` menghasilkan 403.
3. Buka widget di setiap peran. Tekan Enter untuk kirim, Shift+Enter untuk
   baris baru, tombol **Salin**, **Bersihkan**, minimize, dan ganti tema
   Light/Dark.
4. Tanyakan data sesuai peran (pelanggan: username/status sendiri; teknisi:
   tugas sendiri; CS/Admin: rekap tiket). Pastikan angka cocok dengan DB.
5. Nonaktifkan chatbot dari Admin, lalu kirim pesan: harus muncul pesan
   "dinonaktifkan", bukan jawaban palsu.

### Tahap 17 - Pusat API & WhatsApp Gateway

- **Halaman Admin:** submenu `WhatsApp Gateway`
  (`/admin/whatsapp/gateway`, `/admin/whatsapp/otp`,
  `/admin/whatsapp/notifikasi`, `/admin/whatsapp/laporan`,
  `/admin/whatsapp/audit`) dan `Monitoring Penggunaan API`
  (`/admin/monitoring-api`). URL lama `/admin/pengaturan/whatsapp` tetap
  diarahkan ke submenu yang sesuai. Tidak ada menu duplikat.
- **Kredensial terenkripsi:** token, webhook verification token, dan webhook
  secret disimpan sebagai ciphertext libsodium `v1:`. Master key hanya dari
  environment (`AI_SETTINGS_KEY`; alias opsional `API_CREDENTIALS_KEY`).
  Penyimpanan secret ditolak bila master key tidak tersedia.
- **Edit aman:** field secret yang dikosongkan mempertahankan secret lama;
  isi token baru untuk mengganti. Pencabutan menghapus secret dan menandai
  koneksi `revoked_at` (tidak menghapus riwayat).
- **Koneksi bersama:** `connection_id = NULL` berarti fitur memakai koneksi
  WhatsApp aktif. Admin juga dapat memetakan koneksi khusus per fitur.
- **Fitur independen:** Gateway, OTP, Notifikasi, dan Laporan punya status,
  batas harian, monitoring, dan audit sendiri. Kegagalan satu fitur tidak
  menghentikan fitur lain.
- **OTP:** `verifikasi_nomor` dan `reset_password`; kode disimpan sebagai
  password hash, nomor sebagai HMAC fingerprint; sekali pakai; terikat akun,
  purpose, dan nomor; TTL, max attempts, cooldown, batas akun/nomor.
- **Notifikasi tiket:** event pengaduan baru, konfirmasi pelanggan, verifikasi,
  penolakan, penugasan teknisi, penanganan dimulai, selesai, dan pembatalan.
  Event masuk `whatsapp_outbox` secara idempoten. `accepted` bukan `delivered`;
  delivery hanya dari webhook bertanda tangan valid.
- **Laporan:** halaman menyediakan konfigurasi penerima/jenis/format/jadwal,
  tetapi **belum mengirim otomatis**. Aktivasi mensyaratkan persetujuan dan
  scheduler belum diimplementasikan.
- **Monitoring:** data nyata dari `ai_usage_log`, `api_usage_log`, dan
  `whatsapp_outbox`; filter fitur/provider/periode. Biaya/kuota tidak dikarang.

#### Langkah konfigurasi

1. Pastikan migrasi `database/migrations/008_api_center.sql` sudah diterapkan.
2. Isi master key di `.env` (buat dengan `php bin/ai-keygen.php` bila belum).
3. Login Admin -> **Pengaturan WhatsApp Gateway** -> tambahkan kredensial Meta:
   provider `meta_cloud`, access token, Phone Number ID, nomor pengirim, base
   URL (HTTPS), webhook verification token, dan webhook secret. Klik
   **Tes Koneksi**.
4. Daftarkan webhook Meta ke URL yang tampil di halaman:
   `https://domain-anda/webhook/whatsapp/{id}`. Isi verification token yang
   sama; event status delivery memakai signature `X-Hub-Signature-256`.
5. Aktifkan Gateway, lalu atur OTP/Notifikasi. Pilih koneksi “Otomatis” untuk
   berbagi kredensial aktif.
6. Jalankan worker antrean notifikasi dari scheduler/cron produksi:

   ```bash
   php bin/whatsapp-worker.php --limit=20
   ```

   Untuk loop sederhana: `php bin/whatsapp-worker.php --loop=30`. Jangan
   mengandalkan tombol proses antrean di UI untuk volume besar.
7. Pantau pemakaian di **Monitoring Penggunaan API**. Tidak ada angka contoh;
   data baru muncul setelah request nyata.

#### Catatan keamanan & batasan

- Jangan menaruh access token/webhook secret di `.env` aplikasi; simpan lewat
  halaman Admin agar terenkripsi di database.
- Base URL HTTP hanya diizinkan untuk localhost pada pengujian. Produksi wajib
  HTTPS.
- Webhook POST tanpa signature valid ditolak; isi pesan masuk tidak disimpan.
- Live WhatsApp/DeepSeek/OpenAI **BLOCKED** tanpa kredensial nyata. Pengujian
  otomatis memakai mock lokal dan signature HMAC nyata.
- Laporan WhatsApp belum mengirim file; konfigurasi ini adalah rencana sampai
  ada persetujuan implementasi.

### Tahap 18 - Hybrid AI, Database Lokal & Ollama Opsional

- **Local Database Assistant** (`app/Services/LocalDatabaseAssistant.php`)
  menjawab intent operasional deterministik tanpa SQL dari model: jumlah/daftar
  karyawan, pelanggan, detail/follow-up pelanggan, pengaduan, teknisi, rating,
  kategori, tren, dan ringkasan operasional.
- **Urutan hybrid:** database lokal → cloud provider → Ollama (jika aktif) →
  FAQ/fallback lokal → pesan batasan jujur. Tidak ada perpindahan otomatis ke
  provider berbayar lain dan tidak ada retry tanpa batas.
- **Fallback wajib:** saat cloud tidak dikonfigurasi, dinonaktifkan, gagal, atau
  terkena kuota, intent database yang didukung tetap dijawab lokal. Bila DB
  gagal, pengguna mendapat pesan jujur—bukan angka karangan.
- **Akses data:** query read-only prepared di
  `app/Services/AiOperationsReadService.php`, dengan RBAC internal. Karyawan
  hanya `admin`/`cs`/`teknisi`; kontak pelanggan untuk CS disamarkan.
- **Audit:** `ai_data_access_log` menyimpan metadata (user, role, source, intent,
  dataset, jumlah hasil, sukses) tanpa isi pesan, kata pencarian, nomor telepon,
  atau alamat.
- **Pengaturan Admin:** mode hybrid, cloud provider, toggle database lokal,
  prioritas lokal, fallback otomatis, timeout cloud, serta Ollama opsional
  (aktif, model, base URL loopback, timeout). Tombol **Tes Koneksi** provider
  dan **Tes MySQL read-only** memakai tes nyata; status “belum diuji”/hasil tes
  ditampilkan apa adanya, bukan klaim “Online” palsu.
- **Migrasi:** `database/migrations/009_ai_hybrid.sql` (aditif). Konfigurasi
  model/base Ollama disimpan di `ai_provider_keys`, bukan kolom duplikat.
- **Ollama:** hanya loopback/localhost untuk HTTP; API key boleh kosong; tidak
  ada unduhan model otomatis. Bila runtime tidak ada, fitur tetap nonaktif dan
  status tes dilaporkan **BLOCKED**, bukan PASS.

#### Langkah konfigurasi

1. Terapkan migrasi `009_ai_hybrid.sql` (otomatis via `php bin/migrate.php`).
2. Login Admin → **Pengaturan → Pengaturan AI Chatbot**.
3. Aktifkan **database lokal**, **prioritaskan lokal**, dan **fallback otomatis**
   sesuai kebutuhan. Atur **timeout cloud**.
4. Untuk Ollama: aktifkan, isi model (mis. model yang sudah terpasang) dan base
   URL loopback (mis. `http://127.0.0.1:11434`), lalu simpan. Jangan mengunduh
   model tanpa persetujuan.
5. Klik **Tes MySQL read-only** untuk memverifikasi koneksi baca. Klik **Tes
   Koneksi** provider cloud bila kredensial tersedia.
6. Uji dari widget Admin: “Berapa jumlah karyawan?”, “Cari pelanggan Rina”,
   “Apa PPPoE-nya?”, “Berapa tiket yang selesai bulan ini?”.

### Tahap 19 - Local-first, intent akurat & monitoring sumber

- **Local-first penuh:** semua intent data yang dikenali dijawab dari
  MySQL/PHP lebih dulu. Bila lokal berhasil, provider cloud **tidak dipanggil**.
  Cloud hanya menerima pertanyaan yang tidak dikenali asisten lokal.
- **Intent pelanggan dipisah:** `customers.count`, `customers.list`, dan
  `customers.search`. “Pelanggan siapa aja” menampilkan daftar, bukan mencari
  nama “siapa aja”.
- **Metrik teknisi:** `technicians.most_assignments`, `least_assignments`,
  `most_completed`, `most_active`, dan `never_assigned`. Akun teknisi, total
  penugasan, tugas aktif, tiket selesai, tie, dan teknisi 0 penugasan
  dibedakan. “Kerjaannya banyak” diperlakukan sebagai peringkat, bukan jumlah.
- **Jawaban ringkas:** pertanyaan fakta dijawab 1-2 kalimat; daftar/peringkat
  memakai tabel/kartu baris terbatas tanpa langkah troubleshooting tambahan.
- **Konteks aman:** terbatas per sesi/pengguna, divalidasi ulang RBAC, dan
  dibersihkan saat logout, ganti akun, atau ganti dataset.
- **Fallback jujur:** timeout cloud, MySQL gagal, intent tak dikenal, dan akses
  ditolak punya pesan berbeda. MySQL gagal:
  “Data belum dapat diakses saat ini. Data aplikasi tidak dapat dibaca tanpa
  koneksi database.”
- **Monitoring:** `local_database_month`, `local_faq_month`, `cloud_ai_month`,
  `timeout_month`, serta jumlah akses lokal/intent tak dikenal 30 hari di
  halaman Admin AI. Audit tetap metadata-only.
- **Keamanan:** prepared statement, filter allowlist, batas hasil, identitas
  dari session/DB, CSRF, escaping, tanpa SQL bebas, tanpa tool tulis, dan tanpa
  perpindahan provider berbayar otomatis.

Rincian lengkap dan matriks pengujian ada di
[AI_LOCAL_FIRST_REPORT.md](AI_LOCAL_FIRST_REPORT.md).

## Pengujian

Pengujian Tahap 10 (Skenario A–E, keamanan, autentikasi, floating AI widget)
dapat dijalankan terhadap database uji terpisah — lihat
[tests/README.md](tests/README.md).

```bash
php tests/tahap10/e2e.php
php tests/tahap10/security.php
php tests/tahap10/auth.php
php tests/tahap10/ai-widget.php
php tests/tahap10/login-layout.php
php tests/tahap10/ai-gemini-resilience.php  # retry + fallback (mock, tanpa internet)
# Catatan: ai-read-tools.php adalah harness lama yang menghardcode ID pengguna
# dan mengharapkan tool-loop cloud. Sejak local-first, intent data dijawab
# lokal; harness ini dilaporkan FAIL terbuka dan tidak dipakai sebagai gerbang.
php tests/tahap10/ai-read-tools.php
php tests/tahap10/ai-gemini.php    # live Gemini (butuh internet + kunci)

# Tahap 11 — Pengaturan AI Chatbot (dashboard Admin)
php tests/tahap11/crypto.php       # enkripsi API key (libsodium) + read-only (CLI)
php tests/tahap11/config-priority.php  # prioritas konfigurasi DB vs .env (CLI)
php tests/tahap11/ai-settings.php  # halaman, RBAC, enkripsi, tes koneksi (HTTP, DB uji)

# Tahap 15 — platform AI multi-provider (DB uji + server :8099)
php tests/tahap15/ai-platform.php     # 66 uji HTTP: provider, monitoring, limit, FAQ, clear
php tests/tahap15/ai-tools-revisi.php # 29 uji tool baca-only & cakupan per peran
php tests/tahap15/ai-live-roles.php   # live Gemini per peran (butuh kredensial/kuota)

# Tahap 17 — pusat API & WhatsApp (butuh mock WhatsApp + server :8099)
# Terminal 1: php -S 127.0.0.1:8098 tests/tahap17/mock-whatsapp.php
# Terminal 2: server aplikasi DB uji di :8099
# Terminal 3: $env:WA_MOCK_LOG='...'; php tests/tahap17/api-center.php
php tests/tahap17/api-center.php      # 94 uji: enkripsi, OTP, outbox, webhook, RBAC

# Tahap 18 - hybrid AI + database lokal (server DB uji :8099)
$env:DB_NAME='wificare_cdn_test'; $env:APP_ENV='testing'
php tests/tahap18/local-ai.php       # 135 uji + 1 SKIP (Ollama live tidak tersedia)

# Tahap 19 - local-first, intent, RBAC, monitoring (server DB uji :8099)
php tests/tahap19/local-first.php    # 25 uji wajib
php tests/tahap19/mock-timeout.php   # mock timeout lokal (bukan live provider)
```

Mock WhatsApp Cloud API lokal ada di `tests/tahap17/mock-whatsapp.php`. Mock
ini **bukan** pengganti verifikasi live; ia menguji kontrak HTTP, signature,
status, dan antrean. Live provider tetap BLOCKED tanpa kredensial nyata.

Hasil terbaru (26 Oktober 2026): Tahap 19 **25 PASS, 0 FAIL, 0 BLOCKED**;
Tahap 18 **135 PASS, 0 FAIL, 1 SKIP**; Tahap 17 **94 PASS, 0 FAIL**; Tahap 15
**66 PASS + 2 BLOCKED** (live DeepSeek/OpenAI); tool baca-only **29 PASS**;
regresi Tahap 10/11/12/15 lulus (E2E 79, keamanan 34, auth 15, AI widget
81+1 SKIP, AI settings 67, crypto 19, config-priority 17, revisi final 86,
role-sidebar 28, prompt7 75, login-layout 43, resilience 23). Lint PHP dan
`node --check` tanpa error.

Tiga harness lama dicatat **FAIL** terbuka karena fixture hardcode/dataset
mutable: `ai-read-tools.php` (local-first kini menjawab data sebelum tool-loop
cloud), `tahap13/keamanan.php` (akun/foto hardcode), dan
`tahap16/identity.php` bagian assertion unit (alur identitas HTTP per peran
PASS). Live Ollama **BLOCKED** karena runtime tidak tersedia; live WhatsApp
Cloud API dan live Gemini/DeepSeek/OpenAI juga **BLOCKED** tanpa kredensial
nyata. Rincian lengkap ada di [AI_LOCAL_FIRST_REPORT.md](AI_LOCAL_FIRST_REPORT.md),
[TEST_REPORT.md](TEST_REPORT.md), [PROGRESS.md](PROGRESS.md), dan
[AUDIT_REPORT.md](AUDIT_REPORT.md).

## Dokumentasi

- [PROGRESS.md](PROGRESS.md) — catatan perkembangan per tahap dan hasil uji.
- [AUDIT_REPORT.md](AUDIT_REPORT.md) — audit Tahap 10 + addendum Tahap 15/17/18/19.
- [TEST_REPORT.md](TEST_REPORT.md) — laporan pengujian Tahap 10 + Tahap 15/17/18/19.
- [AI_LOCAL_FIRST_REPORT.md](AI_LOCAL_FIRST_REPORT.md) — laporan khusus
  local-first, intent, konteks, RBAC, fallback, dan monitoring.
- [tests/README.md](tests/README.md) — cara menjalankan harness pengujian.
- Kebutuhan produk mengacu pada PRD WiFiCare CDN v1.0.
