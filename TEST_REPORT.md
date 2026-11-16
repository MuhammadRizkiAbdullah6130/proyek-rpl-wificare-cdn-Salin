# TEST_REPORT — WiFiCare CDN (Tahap 10)

Laporan pengujian otomatis yang **benar-benar dijalankan**. Tidak ada hasil yang
diklaim lulus tanpa eksekusi nyata.

Tanggal: 17 Oktober 2026 (diperbarui 6 November 2026)
Aplikasi: WiFiCare CDN v1.0 (PHP Native 8.2+, MySQL/MariaDB)
Hasil ringkas terbaru: **1.120 assertion lulus, 0 gagal, 1 skip**
(lihat Bagian 27; integrasi WhatsApp/AI live dan viewport perangkat fisik
tetap BLOCKED)

---

## 1. Lingkungan Pengujian

| Item | Nilai |
|------|-------|
| PHP CLI | 8.0.30 (kode tetap kompatibel 8.2+) |
| Web server uji | PHP built-in server (`php -S`) |
| Database uji | `wificare_cdn_test` (terpisah dari utama `wificare_cdn_v1`) |
| DB utama | Hanya dibaca (kecuali append `audit_log` dari login uji) |
| Ekstensi | pdo_mysql, fileinfo, mbstring, openssl, curl, zip, xml, xmlwriter, dom (**tanpa GD**) |
| Port uji | 8096 (app utama/regresi), 8097 (mock AI), 8099 (app DB uji) |

**Prinsip tidak destruktif:** semua pembuatan/transisi tiket, pembatalan,
rating, unggahan, dan transisi status dijalankan pada **database uji**.
Database utama hanya dibaca.

### Cara menjalankan
```powershell
# 1) Database uji
$env:DB_NAME='wificare_cdn_test'; $env:APP_BASE_PATH=''
C:\xampp\php\php.exe bin\migrate.php
C:\xampp\php\php.exe bin\create-user.php --role=admin --username=admin.budi --nama="Budi Santoso" --password="<PASSWORD_UJI_KUAT>"
$env:T10_PW_CS='<PASSWORD_CS_UJI>'; $env:T10_PW_TEK='<PASSWORD_TEK_UJI>'; $env:T10_PW_PEL='<PASSWORD_PEL_UJI>'
C:\xampp\php\php.exe bin\seed-sample.php

# 2) Server uji
C:\xampp\php\php.exe -S localhost:8099 -t public public/index.php   # (APP_BASE_PATH='', DB_NAME=wificare_cdn_test)

# 3) Suite Tahap 10 (harness CLI)
C:\xampp\php\php.exe tests\tahap10\e2e.php
C:\xampp\php\php.exe tests\tahap10\security.php
C:\xampp\php\php.exe tests\tahap10\auth.php
```

> Catatan: berkas harness Tahap 10 disertakan di `tests/tahap10/` untuk
> reproduksibilitas. Harness hanya untuk pengujian lokal.

---

## 2. Lint & Sintaks

| Pemeriksaan | Hasil |
|-------------|-------|
| `php -l` semua berkas PHP | **128 berkas, 0 error** |
| `node --check` `main.js`, `dashboard.js`, `ai.js` | **3/3 OK** |

---

## 3. Matriks Hasil Pengujian

### 3.1 E2E (Skenario A–E) — `e2e.php` — **79/79 lulus**

Mencakup login semua peran, RBAC silang, CSRF, pembuatan tiket + foto, nomor
unik, verifikasi CS, penugasan teknisi, mulai penanganan, laporan + foto,
penyelesaian, rating, laporan & ekspor admin, notifikasi, dan endpoint AI.

**Skenario A — Teknisi menyelesaikan:** pelanggan buat tiket (+foto) →
status `menunggu_verifikasi` → CS terima → `diproses_cs` → CS tugaskan →
`menunggu_teknisi` → teknisi mulai → `dalam_penanganan` → laporan (+foto,
disimulasikan) → `selesai` → pelanggan beri rating. **Lulus** (termasuk
penolakan penugasan ganda, laporan tanpa foto, dan rating ganda).

**Skenario B — CS menyelesaikan langsung:** buat → CS terima → CS selesaikan
langsung → `selesai`. **Lulus.**

**Skenario C — Ditolak:** buat → CS tolak tanpa alasan **ditolak** → CS tolak
dengan alasan → `ditolak` + alasan tampil ke pelanggan. **Lulus.**

**Skenario D — Dibatalkan:** buat → CS batal tanpa alasan **ditolak** → CS
batal dengan alasan → `is_cancelled=1` **tanpa** menambah status ke-7 (status
utama tetap), dan tiket batal tidak dapat diproses lagi. **Lulus.**

**Skenario E — Akses tidak sah ditolak:** pelanggan → `/admin/*` = 403;
teknisi → `/cs/*` = 403; CS → `/teknisi/*` = 403; pelanggan → `/admin/laporan`
= 403; teknisi → excel ekspor = 403; pelanggan lain → detail & foto tiket = 403;
IDOR notifikasi & AI tidak membocorkan data. **Lulus.**

### 3.2 Keamanan & Tepi — `security.php` — **34/34 lulus**

XSS (detail pelanggan/CS/admin + laporan), unggah berkas non-gambar ditolak,
tiket tanpa foto ditolak, transisi status ilegal ditolak (selesaikan/tugaskan
dari `menunggu_verifikasi`), teknisi akses tiket bukan miliknya, 404 tanpa 500,
ID validasi aneh, jenis laporan tak dikenal, `page` negatif, injeksi array pada
login, rating tiket dibatalkan ditolak, audit log tidak membocorkan hash,
"baca semua" terisolasi, ekspor dengan filter tanggal tidak valid, dan
**regresi halaman detail admin** (pelanggan/pengaduan/teknisi) yang memastikan
halaman ter-render 200 tanpa `Warning:`/`Fatal error` serta 404 yang benar untuk
ID tidak ada (menangkap bug A4).

### 3.3 Autentikasi — `auth.php` — **15/15 lulus**

Throttle brute-force (percobaan ke-6 terkunci walau password benar), akun
nonaktif tidak dapat login, **sesi dipaksa keluar (302 login) setelah akun
dinonaktifkan** (regresi A1), logout menghapus sesi, ID session berubah setelah
login (anti fixation), tidak ada route registrasi publik.

### 3.4 Regresi Tahap 9

| Suite | Berkas | Hasil |
|-------|--------|-------|
| Laporan & analitik + PDF/XLSX (CLI, tanpa jaringan) | `t9-report-cli.php` | **47/47 lulus** |
| Regresi HTTP semua halaman 4 peran + aset | `t9-regression.ps1` | **48/48 lulus** |
| AI logika/tool/FK (CLI) | `t9-ai-cli.php` | **15/15 lulus** |
| AI endpoint (HTTP via **mock** provider) | `t9-ai-test.ps1` | **20/20 lulus** |

Suite AI HTTP memverifikasi: balasan chat, kunci API tidak bocor ke HTML/JS,
respons dirender sebagai teks, error & timeout ditangani, tool-calling
dijalankan backend, RBAC tiket (pelanggan/teknisi), AI **tidak** mengubah
status, prompt injection **tidak** menembus otorisasi, CSRF 419, rate limit 429.

### 3.5 Rekap

| Kelompok | Jumlah | Lulus | Gagal |
|----------|-------:|------:|------:|
| E2E Tahap 10 | 79 | 79 | 0 |
| Keamanan/tepi Tahap 10 | 34 | 34 | 0 |
| Autentikasi Tahap 10 | 15 | 15 | 0 |
| Laporan CLI Tahap 9 | 47 | 47 | 0 |
| Regresi HTTP Tahap 9 | 48 | 48 | 0 |
| AI CLI Tahap 9 | 15 | 15 | 0 |
| AI HTTP (mock) Tahap 9 | 20 | 20 | 0 |
| **Total** | **258** | **258** | **0** |

---

## 4. Pemeriksaan Database (DB uji)

| Pemeriksaan | Hasil |
|-------------|-------|
| Orphan `pengaduan`→`pelanggan` / `kategori` | 0 / 0 |
| Nomor tiket duplikat | 0 |
| Penugasan aktif ganda per tiket | 0 |
| Laporan / penyelesaian / rating ganda | 0 / 0 / 0 |
| Riwayat yatim | 0 |
| Status di luar 6 status resmi | 0 |
| Foreign key | 22 relasi, lengkap |
| Unique key penting | `username`, `username_pppoe`, `nomor_tiket`, `laporan`, `penyelesaian`, `rating` |
| Migrasi | 001–005 tercatat; `migrate.php` berhasil pada DB baru |

---

## 5. Pengujian yang Diblokir

| Item | Alasan | Status |
|------|--------|--------|
| Integrasi AI ke provider **nyata** (OpenAI-kompatibel) | `AI_API_KEY` dan `AI_MODEL` kosong di `.env`; provider eksternal tidak tersedia | **BLOCKED** (bukan PASSED) |

Pipeline AI tetap diuji menyeluruh dengan provider tiruan (mock) — lihat §3.4.

---

## 6. Pemetaan Uji Wajib Tahap 9

| # | Uji wajib | Bukti | Hasil |
|---|-----------|-------|-------|
| 1 | Format & keunikan nomor tiket | E2E: format `WFC-YYYYMMDD-XXXXXX`; DB: 0 duplikat | ✅ |
| 2 | Login/logout, session, regenerate | auth.php | ✅ |
| 3 | Hash password (tanpa plaintext) | Skema + audit | ✅ |
| 4 | Akun nonaktif tidak dapat login | auth.php | ✅ |
| 5 | Throttle login | auth.php | ✅ |
| 6 | Redirect dashboard per peran | E2E | ✅ |
| 7 | Proteksi route & RBAC 4 peran | E2E | ✅ |
| 8 | Tanpa registrasi publik | auth.php | ✅ |
| 9 | Password WiFiCare ≠ password PPPoE | Skema `pelanggan` (tanpa kolom password jaringan) | ✅ |
| 10 | Admin terakhir tidak dapat dinonaktifkan | E2E | ✅ |
| 11 | Arsip menyimpan riwayat | audit/arsip (route & service) | ✅ |
| 12 | Batal ≠ status ke-7 | E2E skenario D | ✅ |
| 13 | Tepat 6 status; transisi ilegal ditolak | security.php + E2E | ✅ |
| 14 | Transaksi atomik status+riwayat | E2E + kode (`FOR UPDATE`) | ✅ |
| 15 | CSRF semua aksi tulis | E2E + security.php | ✅ |
| 16 | Escaping output (XSS) | security.php | ✅ |
| 17 | Mis. MIME unggah, nama acak, luar docroot | security.php + struktur | ✅ |
| 18 | Otorisasi unduh foto | E2E + security.php | ✅ |
| 19 | Isolasi notifikasi & rating sekali | E2E + security.php | ✅ |
| 20 | 9 jenis laporan & filter | CLI + HTTP | ✅ |
| 21 | Ekspor PDF & XLSX konsisten | CLI (magic xref/EOF, ZIP/sheetData/inlineStr) | ✅ |
| 22 | Anti formula injection Excel | CLI + security.php | ✅ |
| 23 | AI: kunci di server, otorisasi tool | AI CLI + AI HTTP mock | ✅ |
| 24 | AI: tanpa kunci → pesan jelas | E2E | ✅ |
| 25 | AI: rate limit & error/timeout | AI HTTP mock | ✅ |

---

## 7. Kesimpulan

Semua pengujian yang dapat dijalankan **lulus tanpa kegagalan**. Perbaikan
Tahap 10 (A1, A2, A3, A4, A5) diverifikasi ulang tanpa regresi. Satu-satunya item yang
tidak dapat diverifikasi adalah integrasi AI ke provider eksternal nyata, yang
ditandai **BLOCKED** secara jujur karena tidak tersedianya kredensial/koneksi.

---

## 8. Addendum — Floating AI Assistant (Pembaruan UI)

Pengujian atas perubahan AI Assistant dari menu sidebar menjadi floating widget.

| # | Uji | Bukti | Hasil |
|---|-----|-------|-------|
| 1 | Menu AI hilang dari sidebar (4 peran) | `ai-widget.php` | ✅ |
| 2 | Floating button muncul pada semua dashboard peran | `ai-widget.php` | ✅ |
| 3 | Popup terbuka saat tombol diklik | JS + markup (`ai-widget.php`) | ✅ |
| 4 | Popup dapat ditutup | `data-aiw-close` | ✅ |
| 5 | Minimize berfungsi | `data-aiw-minimize` | ✅ |
| 6 | Layout smartphone responsif | CSS `.aiw` media queries + `ai-widget.php` | ✅ |
| 7 | Chat dapat mengirim pesan | `ai-live.php` (200, `ok=true`) | ✅ |
| 8 | Respons AI muncul bila API terhubung | `ai-live.php` (mock provider 12/12) | ✅ |
| 9 | Pesan konfigurasi bila API belum tersedia | `ai-widget.php` | ✅ |
| 10 | Kunci API tidak terekspos | `ai-widget.php` + `ai-live.php` | ✅ |
| 11 | Tidak ada error JavaScript | `node --check` + markup valid | ✅ |
| 12 | Tidak ada konflik CSS/z-index (1060) | audit + CSS | ✅ |
| 13 | Dashboard & navigasi sebelumnya tetap berfungsi | regresi E2E 79/79, secur. 34/34, auth 15/15 | ✅ |

Total tambahan: **92 assertion** (80 widget + 12 AI live) lulus, 0 gagal.

---

## 9. Addendum — Login Full-Screen

| # | Uji | Bukti | Hasil |
|---|-----|-------|-------|
| 1 | Login memakai layout autentikasi (bukan layout dashboard) | `login-layout.php` | ✅ |
| 2 | Tanpa sidebar dashboard | `login-layout.php` | ✅ |
| 3 | Tanpa navbar/header dashboard | `login-layout.php` | ✅ |
| 4 | Tanpa footer dashboard | `login-layout.php` | ✅ |
| 5 | Tanpa menu Beranda/Tentang/Masuk sidebar | `login-layout.php` | ✅ |
| 6 | Tanpa floating AI widget di login | `login-layout.php` | ✅ |
| 7 | Judul "Selamat Datang" + subtitle | `login-layout.php` | ✅ |
| 8 | Field Username / PPPoE & Password WiFiCare | `login-layout.php` | ✅ |
| 9 | Tombol show/hide password | `login-layout.php` + verifikasi browser | ✅ |
| 10 | Loading state saat login | `login-layout.php` + verifikasi browser | ✅ |
| 11 | Info akun dibuat Administrator, tanpa registrasi | `login-layout.php` | ✅ |
| 12 | Palet warna & full-screen (`100dvh`), card ≤420px, responsif | `login-layout.php` (CSS) | ✅ |
| 13 | CSRF: POST tanpa token ditolak | `login-layout.php` | ✅ |
| 14 | Kredensial salah ditolak + pesan error | `login-layout.php` | ✅ |
| 15 | Redirect per peran (admin/cs/teknisi/pelanggan) | `login-layout.php` | ✅ |
| 16 | Dashboard tetap punya sidebar+header+AI widget | `login-layout.php` | ✅ |
| 17 | Pelanggan login dengan Username PPPoE | `login-layout.php` | ✅ |
| 18 | Tanpa error JavaScript | verifikasi browser (0 error) | ✅ |

Total tambahan: **43 assertion** lulus, 0 gagal. Regresi E2E 79/79, keamanan
34/34, autentikasi 15/15, AI widget 80/80 tetap lulus.

---

## 10. Addendum — Integrasi Live Google Gemini

Diuji terhadap API Gemini nyata (kunci dibaca server; tidak dicetak).

| # | Uji | Bukti | Hasil |
|---|-----|-------|-------|
| 1 | Login pelanggan + widget termuat | `ai-gemini.php` | ✅ |
| 2 | Widget melaporkan layanan terkonfigurasi | `ai-gemini.php` | ✅ |
| 3 | Endpoint + token CSRF dari widget | `ai-gemini.php` | ✅ |
| 4 | POST /ai/chat tanpa login → 401/302 | `ai-gemini.php` | ✅ |
| 5 | POST /ai/chat tanpa CSRF → 419 | `ai-gemini.php` | ✅ |
| 6 | Q1 "Halo, kamu siapa?" → jawaban Gemini | `ai-gemini.php` | ✅ |
| 7 | Q2 "WiFi terhubung tetapi tidak ada internet" → jawaban Gemini | `ai-gemini.php` | ✅ |
| 8 | Q3 "cara membuat pengaduan" → jawaban Gemini | `ai-gemini.php` | ✅ |
| 9 | Kunci API tidak bocor di respons | `ai-gemini.php` | ✅ |
| 10 | Error 400 dipetakan aman | mock Gemini | ✅ |
| 11 | Error 403 dipetakan aman | mock Gemini | ✅ |
| 12 | Error 404 (model) dipetakan aman | mock Gemini | ✅ |
| 13 | Error 429 (kuota) dipetakan aman | mock Gemini | ✅ |
| 14 | Error 5xx dipetakan aman | mock Gemini | ✅ |
| 15 | Safety block → pesan aman | mock Gemini | ✅ |
| 16 | Jalur OpenAI-compatible (mock) tetap berfungsi | mock OpenAI | ✅ |

**Total live Gemini: 19/19 lulus** (`tests/tahap10/ai-gemini.php`), ditambah
16 pengujian error/provider. Regresi: E2E 79/79, keamanan 34/34, autentikasi
15/15, AI widget 81/81 (+1 skip), login full-screen 43/43.

Contoh jawaban asli Gemini (ringkas):

- **Q1** → "Halo! Saya **WiFiCare Assistant**, asisten virtual untuk aplikasi
  layanan WiFi **WiFiCare CDN**…"
- **Q2** → langkah restart router/ONT, cek lampu indikator (LOS merah = gangguan
  jalur), cek kabel, lupakan & sambungkan ulang, uji di perangkat lain, lalu
  arahkan membuat pengaduan bila belum selesai.
- **Q3** → buka menu **Pengaduan Baru**, pilih kategori, isi deskripsi, unggah
  foto, kirim; pantau status via menu Pengaduan/Beranda.

Model aktif: `gemini-3.5-flash` (via `GEMINI_MODEL`), dengan cadangan
`gemini-3.8-flash,gemini-flash-latest` (`GEMINI_MODEL_FALLBACK`).

### 10.1 Ketahanan model (retry + fallback)

`gemini-3.8-flash` sempat membalas **503 UNAVAILABLE** ("high demand") sehingga
widget menampilkan pesan gagal. Perbaikan: model utama → `gemini-3.5-flash`,
retry terbatas (`AI_MAX_RETRIES=1`) untuk error sementara, dan fallback model.
Diuji tanpa jaringan lewat mock lokal (`mock-gemini.php`).

| # | Uji | Berkas | Hasil |
|---|-----|--------|-------|
| 1 | Model sehat dipakai tepat sekali | `ai-gemini-resilience.php` | ✅ |
| 2 | 503 → diulang lalu beralih ke cadangan | `ai-gemini-resilience.php` | ✅ |
| 3 | 404 model → beralih ke cadangan | `ai-gemini-resilience.php` | ✅ |
| 4 | 503 tanpa cadangan → gagal dengan pesan "sibuk" | `ai-gemini-resilience.php` | ✅ |
| 5 | 400 permanen → tanpa retry/cadangan | `ai-gemini-resilience.php` | ✅ |
| 6 | 403 permanen → tanpa retry/cadangan | `ai-gemini-resilience.php` | ✅ |
| 7 | Jawaban SAFETY → pesan aman, tanpa cadangan | `ai-gemini-resilience.php` | ✅ |
| 8 | Kunci API tidak bocor ke log permintaan | `ai-gemini-resilience.php` | ✅ |
| 9 | Cadangan duplikat tidak menghasilkan panggilan ganda | `ai-gemini-resilience.php` | ✅ |

**Total ketahanan: 23/23 lulus** (`tests/tahap10/ai-gemini-resilience.php`).
Verifikasi live ulang: `gemini-3.5-flash` menjawab ketiga pertanyaan uji dalam
~2–3 detik (CLI).

## 11. Addendum — WiFiCare AI Read-Only + Tool Calling

AI diubah menjadi asisten operasional yang dapat **membaca database lewat fungsi
PHP terkontrol**. Model Gemini tidak pernah menerima kredensial MySQL dan tidak
pernah menjalankan SQL. Diuji tanpa jaringan lewat mock lokal (`mock-gemini.php`
dengan skenario `functionCall`/`functionResponse`).

### 11.1 Aturan & keamanan

| # | Uji | Berkas | Hasil |
|---|-----|--------|-------|
| 1 | Permintaan coding (Python) ditolak dengan kalimat tetap | `ai-read-tools.php` | ✅ |
| 2 | Permintaan coding (SQL) ditolak dengan kalimat tetap | `ai-read-tools.php` | ✅ |
| 3 | Deteksi coding: "buatkan script" → true | `ai-read-tools.php` | ✅ |
| 4 | Deteksi coding: pertanyaan biasa → false | `ai-read-tools.php` | ✅ |
| 5 | Allowlist tanpa tool tulis (INSERT/UPDATE/DELETE/DROP/…) | `ai-read-tools.php` | ✅ |
| 6 | Tool tak dikenal ditolak (`tool_not_allowed`) | `ai-read-tools.php` | ✅ |

### 11.2 Function calling & RBAC tool

| # | Uji | Berkas | Hasil |
|---|-----|--------|-------|
| 7 | Admin punya `get_customer_count` | `ai-read-tools.php` | ✅ |
| 8 | Pelanggan TIDAK punya `get_customer_count` | `ai-read-tools.php` | ✅ |
| 9 | Pelanggan TIDAK punya `get_technician_assignments` | `ai-read-tools.php` | ✅ |
| 10 | Teknisi punya `get_technician_assignments` | `ai-read-tools.php` | ✅ |
| 11 | Tool loop status: functionCall → tool → jawaban | `ai-read-tools.php` | ✅ |
| 12 | Tool loop status: memuat data asli tiket (`found=true`) | `ai-read-tools.php` | ✅ |
| 13 | Argumen kosong dikodekan `{}` (tanpa error 400) | `ai-read-tools.php` | ✅ |
| 14 | Pelanggan memanggil tool admin → ditolak | `ai-read-tools.php` | ✅ |
| 15 | Admin memanggil tool admin → data asli | `ai-read-tools.php` | ✅ |
| 16 | Statistik status via tool | `ai-read-tools.php` | ✅ |
| 17 | Balasan teks biasa tetap jalan meski tools dikirim | `ai-read-tools.php` | ✅ |
| 18 | Tiket pelanggan ter-scope (hanya miliknya) | `ai-read-tools.php` | ✅ |
| 19 | Tugas teknisi terisi | `ai-read-tools.php` | ✅ |
| 20 | CS tidak melihat kinerja teknisi | `ai-read-tools.php` | ✅ |
| 21 | Kunci API tidak bocor ke log mock | `ai-read-tools.php` | ✅ |

**Total WiFiCare AI read-only: 26/26 lulus** (`tests/tahap10/ai-read-tools.php`;
beberapa baris di atas merinci beberapa assertion).

### 11.3 Verifikasi live & HTTP

- **Wire-format live**: satu putaran `functionCall` → `functionResponse` ke API
  Gemini nyata berhasil (HTTP 200); part model dikembalikan termasuk
  `thoughtSignature`; argumen kosong `{}` diterima.
- **Penolakan coding via HTTP**: `POST /ai/chat` (login pelanggan) →
  `ok=true` + kalimat penolakan tetap, tanpa memanggil provider.
- **Batas laju**: uji live tool-calling end-to-end sempat terhalang HTTP 429
  (kuota/rate-limit kunci uji); seluruh logika tool terverifikasi deterministik
  lewat mock.

### 11.4 Regresi

E2E **79/79**, keamanan **34/34**, autentikasi **15/15**, floating AI widget
**81/81** (+1 skip), login full-screen **43/43**, ketahanan Gemini **23/23**.
Lint **138 berkas PHP** 0 error.

---

## 12. Tahap 11 — Pengaturan AI Chatbot (Dashboard Admin)

Tanggal pengujian: 18 Oktober 2026. Lingkungan: XAMPP (Apache port 80, MariaDB
10.4, PHP 8.0.30 + libsodium), DB uji `wificare_cdn_test`, server uji port 8099.

### 12.1 Uji unit enkripsi & read-only (`tests/tahap11/crypto.php`) — 19/19 lulus
### 12.1b Uji prioritas konfigurasi (`tests/tahap11/config-priority.php`) — 17/17 lulus
- Tanpa baris `ai_settings`: perilaku `.env` lama dipertahankan, termasuk pemilihan
  provider `AI_PROVIDER=openai` (kompatibilitas) dan `GEMINI_API_KEY`.
- Baris ada + Nonaktif: mengabaikan `.env`; state `disabled`.
- Baris ada + Aktif + key tersimpan: `key_source=database`, key tersimpan menang
  atas `.env`; model & batas dari tabel dipakai.
- libsodium tersedia; master key (`AI_SETTINGS_KEY`) terbaca; fasilitas siap.
- Enkripsi `v1:` + nonce acak (dua ciphertext berbeda untuk plaintext sama).
- Dekripsi mengembalikan plaintext; ciphertext tidak memuat plaintext.
- Input rusak/prefix salah/master key salah → `null` (gagal aman).
- Allowlist tool AI tidak memuat tool tulis; tool tak dikenal/ditulis ditolak.

### 12.2 Uji HTTP 19 poin (`tests/tahap11/ai-settings.php`) — 67/67 lulus

| No | Skenario | Hasil |
|---|---|---|
| 1 | Admin membuka Pengaturan AI (200, judul, form, viewport, grid) | OK |
| 2–4 | CS / Teknisi / Pelanggan → 403 (tanpa form) | OK |
| 5 | Admin menyimpan API key baru | OK |
| 6 | API key tersimpan terenkripsi (`v1:`, bukan plaintext) | OK |
| 7 | API key tidak muncul di HTML (juga tanpa ciphertext/nama kolom) | OK |
| 8 | API key tidak muncul di respons JSON (tes koneksi) | OK |
| 9 | Simpan tanpa key baru mempertahankan ciphertext lama | OK |
| 10 | Tes Koneksi memakai API sungguhan (key `.env`) | OK |
| 11 | Key tidak valid → pesan aman (`ok=false`, tanpa key) | OK |
| 12–13 | AI dapat dinonaktifkan → widget "dinonaktifkan" & `/ai/chat` menolak; diaktifkan kembali | OK |
| 14 | Model AI dapat diganti | OK |
| 15 | Floating AI memakai key terbaru (sumber "Tersimpan (terenkripsi)") | OK |
| 16 | Penggantian key tidak merusak chatbot (aturan WiFiCare AI tetap) | OK |
| 17 | Audit log tidak membocorkan key/ciphertext; menandai "diganti" & perubahan status | OK |
| 18 | Endpoint tidak mengeksekusi SQL pengguna (read-only) | OK |
| 19 | Aset JS/CSS pengaturan responsif tersedia; CSRF 419; RBAC 403 | OK |

### 12.3 Live Gemini (`tests/tahap10/ai-gemini.php`)
- Q1 & Q3 dijawab nyata (`ok=true`, jawaban asli Gemini).
- Q2 terhalang HTTP 429 (kuota kunci uji) → pesan aman "kuota habis". Bukan cacat
  kode; jalur kegagalan terverifikasi aman.

### 12.4 Regresi (setelah Tahap 11)
E2E **79/79**, keamanan **34/34**, autentikasi **15/15**, floating AI widget
**81/81** (+1 skip), login full-screen **43/43**, WiFiCare AI read-only **26/26**,
ketahanan Gemini **23/23**. Lint **147 berkas PHP** 0 error.

---

## 13. Revisi Final — 7 Revisi (18 Oktober 2026)

Tanggal: 18 Oktober 2026
Hasil: **semua suite LULUS** (86 assertion revisi + 359 regresi, 0 gagal).

### 13.1 Suite baru `tests/tahap12/revisi-final.php` (86/86)
Memadukan pemeriksaan aset statis (CSS/JS/PHP), HTTP (DB uji `wificare_cdn_test`),
dan backend (mock Gemini lokal tanpa jaringan + DB aplikasi).

| Area | Bukti yang diverifikasi |
|---|---|
| Revisi 1 | `text-align:left`, `line-height:1.6`, `overflow-wrap:anywhere`; sapaan + deskripsi |
| Revisi 2 | `ai-render.js` ada, `renderRich`/`renderCards`, `textContent`, **tanpa** `innerHTML`; kartu tiket dari DB nyata (nomor tiket WFC-, status 6 resmi, kategori, tanggal Indonesia panjang); pelanggan tidak menerima nama orang lain; admin menerima kartu |
| Revisi 3 | system instruction memuat "GAYA JAWABAN" + larangan komentar tambahan (via reflection) |
| Revisi 4 | placeholder "Tulis pertanyaan Anda"; peringatan kata sandi absen di widget & `/ai`; state `is-loading`; auto-grow |
| Revisi 5 | `:root[data-theme="dark"]`, token Light `#f8fafc`, Dark `#0b1120`/`#172238`; komponen toggle; `wificare-theme` + anti-flash di layout app/auth/error; `dashboard.js` ikut `wificare:themechange` |
| Revisi 6 | root anon → 302 `/login`; root pelanggan → 302 `/pelanggan/dashboard`; root admin → 302 `/admin/dashboard`; `/about` 404; `/akses-ditolak` 403 tanpa sidebar; RBAC 403 |
| Revisi 7 | `scrollbar-width:none`, `::-webkit-scrollbar`, `body::-webkit-scrollbar`; tidak ada `overflow:hidden` pada sidebar |

### 13.2 Regresi lengkap (dijalankan ulang)
| Suite | Hasil |
|---|---|
| `tests/tahap10/e2e.php` | **79 / 79** |
| `tests/tahap10/security.php` | **34 / 34** |
| `tests/tahap10/auth.php` | **15 / 15** |
| `tests/tahap10/ai-widget.php` | **81 / 81** (+1 skip) |
| `tests/tahap10/login-layout.php` | **43 / 43** |
| `tests/tahap10/ai-read-tools.php` | **26 / 26** |
| `tests/tahap10/ai-gemini-resilience.php` | **23 / 23** |
| `tests/tahap11/crypto.php` | **19 / 19** |
| `tests/tahap11/config-priority.php` | **17 / 17** |
| `tests/tahap11/ai-settings.php` | **67 / 67** |
| `tests/tahap12/revisi-final.php` | **86 / 86** |
| Lint PHP (seluruh projek) | **151 berkas, 0 error** |

### 13.3 Catatan
- Baris `ai_settings` pada DB utama **dinetralkan sementara (non-destruktif)**
  lalu dipulihkan oleh harness offline (`ai-read-tools`, `ai-gemini-resilience`,
  dan `config-priority`); bukan dihapus. Terverifikasi utuh setelah seluruh
  pengujian (provider `gemini`, model `gemini-3.5-flash`, `is_enabled=1`).
- Live Gemini tetap dapat terkena HTTP 429 (kuota kunci uji) — keterbatasan
  lingkungan, bukan cacat kode.
- Tidak ada kegagalan tersisa pada lingkungan uji.

---

## 14. Bugfix — Sidebar Role Tertukar & Error 403 (18 Oktober 2026)

**Hasil:** semua suite LULUS, termasuk suite baru role/sidebar **28/28**.
Lint **152 berkas PHP, 0 error**.

### 14.1 Reproduksi (sebelum perbaikan)
Login Admin → `/admin/pengguna`:
- chip profil `Administrator` (dari autentikasi) **benar**;
- eyebrow header & role sidebar `Pelanggan` **salah**; menu sidebar berisi
  tautan Pelanggan; `?role=teknisi` mengubah sidebar menjadi `Teknisi`.
Ini membuktikan `$role` layout berasal dari daftar pengguna (sisa loop view),
bukan dari identitas login.

### 14.2 Suite baru `tests/tahap12/role-sidebar.php` (28/28)
| Area | Bukti |
|---|---|
| Admin konsisten | 10 halaman admin (+ kembali ke dashboard): HTTP 200, eyebrow/sidebar/chip = `Administrator`, menu admin ada, tautan Pelanggan tidak ada |
| Query param | `?role=pelanggan|teknisi|cs|admin` tidak mengubah sidebar |
| Identitas | Nama header = akun login (Budi Santoso); refresh & navigasi berulang tetap `Administrator` |
| RBAC | CS/Teknisi/Pelanggan → `/admin/pengguna` **403**, tanpa data manajemen; sidebar sesuai perannya |
| Logout/login ulang | Logout → 302 login; akses admin pasca-logout → 302 login; login ulang sebagai CS tidak memakai peran lama; `/admin/pengguna` → 403 |
| Sesi paralel | Dua sesi (Admin & Pelanggan) tidak saling mengubah peran |

### 14.3 Regresi lengkap (dijalankan ulang)
| Suite | Hasil |
|---|---|
| `tests/tahap12/role-sidebar.php` | **28 / 28** |
| `tests/tahap12/revisi-final.php` | **86 / 86** |
| `tests/tahap10/e2e.php` | **79 / 79** |
| `tests/tahap10/security.php` | **34 / 34** |
| `tests/tahap10/auth.php` | **15 / 15** |
| `tests/tahap10/ai-widget.php` | **81 / 81** (+1 skip) |
| `tests/tahap10/login-layout.php` | **43 / 43** |
| `tests/tahap10/ai-read-tools.php` | **26 / 26** |
| `tests/tahap10/ai-gemini-resilience.php` | **23 / 23** |
| `tests/tahap11/crypto.php` | **19 / 19** |
| `tests/tahap11/config-priority.php` | **17 / 17** |
| `tests/tahap11/ai-settings.php` | **67 / 67** |
| Lint PHP (seluruh projek) | **152 berkas, 0 error** |

### 14.4 Catatan
- Tidak ada kegagalan tersisa. Baris `ai_settings` DB utama tetap utuh.
- Tidak ada penonaktifan RBAC: seluruh penolakan akses tetap HTTP 403 server-side.

---

## 15. Laporan Pengujian — Perbaikan 7 Bug & Revisi Final (Tahap 13)

Dijalankan pada 19 Oktober 2026. Server uji `http://localhost:8099`
(DB `wificare_cdn_test`); sebagian uji model read-only pada DB aplikasi.

### 15.1 Uji khusus perbaikan
| Berkas uji | Hasil |
|---|---|
| `tests/tahap12/prompt7.php` | **75 / 75** |

Cakupan: BUG 1 (HY093 di model + seluruh daftar admin), BUG 2 (profil
Rina/Agus, perubahan hanya data sendiri, tanpa demo), BUG 3 (`foto[]`,
tanpa foto/invalid ditolak, PNG valid tersimpan, tampil di riwayat, akses foto
pemilik vs pelanggan lain 403), REVISI 4 (Data Karyawan vs Data Pelanggan),
REVISI 5 (filter Cari+Status+Periode), REVISI 6 (Jenis + Periode, tanggal
kustom, tanggal terbalik, ekspor PDF/Excel), REVISI 7 (kartu & layout).

### 15.2 Regresi penuh (tidak ada yang berubah menjadi gagal)
| Berkas uji | Hasil |
|---|---|
| `tests/tahap10/e2e.php` | **79 / 79** |
| `tests/tahap10/security.php` | **34 / 34** |
| `tests/tahap10/auth.php` | **15 / 15** |
| `tests/tahap12/role-sidebar.php` | **28 / 28** |
| `tests/tahap12/revisi-final.php` | **86 / 86** |
| `tests/tahap11/crypto.php` | **19 / 19** |
| `tests/tahap10/login-layout.php` | **43 / 43** |
| `tests/tahap10/ai-widget.php` | **81 / 81** (+1 skip) |
| `tests/tahap10/ai-read-tools.php` | **26 / 26** |
| `tests/tahap10/ai-gemini-resilience.php` | **23 / 23** |
| `tests/tahap11/ai-settings.php` | **67 / 67** |
| `tests/tahap11/config-priority.php` | **17 / 17** |
| Lint PHP (seluruh projek) | **151 berkas, 0 error** |

### 15.3 Catatan pengujian
- `revisi-final.php` bagian backend dijalankan **tanpa** override `DB_NAME`
  (memakai DB aplikasi) — sesuai desain uji; server HTTP 8099 tetap memakai
  DB `wificare_cdn_test`. Kesalahan setelan environment sempat menghasilkan 2
  FAIL palsu; setelah dijalankan sesuai desain, **86/86**.
- Baris `ai_settings` DB utama tetap utuh (dipulihkan oleh shutdown handler uji).
- Tidak ada stack trace, password, kunci API, atau kredensial DB yang bocor.
- Tidak ada kegagalan tersisa.

---

## 16. Pengujian Keamanan Autentikasi & Proteksi Folder (Tahap 14)

Tanggal: 20 Oktober 2026. Pengujian **nyata via HTTP** terhadap Apache :80
(`http://localhost/aduan-pelanggan/public`) dan database utama (hanya SELECT).
Suite: `tests/tahap13/keamanan.php`.

### 16.1 Hasil suite keamanan
| Kelompok | Jumlah | Hasil |
|---|---|---|
| A. Proteksi folder/file sensitif (`.env`, `app/`, `config/`, `database/`, `routes/`, `storage/`, `tests/`, `vendor/`, `.git/`, `composer.*`, log, SQL, docs) | 22 | **22 PASS** |
| A. Redirect akar proyek + aset publik | 4 | **4 PASS** |
| A. Path traversal (`../`, `%2e%2e`, encoded) | 5 | **5 PASS** |
| B. Halaman privat tanpa login (12 URL) | 12 | **12 PASS** |
| B. API/foto tanpa login -> 401/redirect | 5 | **5 PASS** |
| B. Login 4 peran + dashboard + cache | 17 | **17 PASS** |
| B. Matriks RBAC lintas peran | 14 | **14 PASS** |
| B. Identitas pelanggan tidak tertukar + anti parameter peran palsu | 4 | **4 PASS** |
| C. Cookie HttpOnly/SameSite/Secure + rotasi ID + logout + CSRF | 9 | **9 PASS** |
| D. Otorisasi foto (pemilik, pelanggan lain, admin, CS, teknisi, 404) | 7 | **7 PASS** |
| E. Error handling & header cache privat | 4 | **4 PASS** |
| **Total** | **103** | **102 PASS, 0 FAIL, 1 SKIP** |

SKIP: cookie `Secure` saat HTTPS (memerlukan TLS lokal; kode mengaktifkan
otomatis berdasarkan `Session::isHttps()`).

### 16.2 Bukti URL langsung (tanpa login)
| URL | Sebelum | Sesudah |
|---|---|---|
| `/aduan-pelanggan/` | 200 directory listing | 302 -> `/aduan-pelanggan/public/` -> login |
| `/aduan-pelanggan/.env` | 200 isi kredensial | **403** |
| `/aduan-pelanggan/app/` | 200 directory listing | **403** |
| `/aduan-pelanggan/config/` | 200 directory listing | **403** |
| `/aduan-pelanggan/database/` | 200 directory listing | **403** |
| `/aduan-pelanggan/routes/` | 200 directory listing | **403** |
| `/aduan-pelanggan/storage/logs/app.log` | 200 isi log | **403** |
| `/aduan-pelanggan/tests/` | 200 directory listing | **403** |
| `/aduan-pelanggan/composer.json` | 200 | **403** |
| `/aduan-pelanggan/.git/` | 403/404 | **403** |
| `/aduan-pelanggan/README.md` | 200 | **403** |
| `/aduan-pelanggan/public/assets/css/style.css` | 200 | **200** (tetap) |

### 16.3 Pengujian dashboard & role
| Skenario | Hasil |
|---|---|
| Tanpa login: `/admin/dashboard`, `/admin/pengguna`, `/admin/laporan`, `/cs/dashboard`, `/teknisi/dashboard`, `/pelanggan/dashboard`, `/pelanggan/profil`, `/notifikasi`, `/ai` | 302 -> login (PASS) |
| API `POST /ai/chat` tanpa sesi (`X-Requested-With`) | 401 JSON (PASS) |
| Foto `/pengaduan/foto/1` tanpa sesi | 302 (browser) / 401 (AJAX) (PASS) |
| Admin -> dashboard Admin | 200 (PASS) |
| CS -> dashboard CS | 200 (PASS) |
| Teknisi -> dashboard Teknisi | 200 (PASS) |
| Pelanggan -> dashboard Pelanggan | 200 (PASS) |
| Peran salah (Admin->Pelanggan, Pelanggan->Admin/CS, CS->Admin, Teknisi->CS) | 403 (PASS) |
| Profil Rina vs Agus | identitas masing-masing, tanpa kebocoran (PASS) |
| `?role=admin&user_id=1` + header `X-User-Id/X-Role` | diabaikan (PASS) |
| Session ID berubah setelah login | PASS |
| Logout tanpa CSRF -> 403, session tetap; logout valid -> session berakhir | PASS |
| Halaman privat `Cache-Control: no-store`, `Pragma: no-cache`, `Expires` | PASS |
| Idle timeout (`SESSION_LIFETIME=2`, server uji :8098) | 200 -> 4 detik -> 302 login (PASS) |

### 16.4 Regresi setelah perubahan keamanan
| Suite | Hasil |
|---|---|
| `tests/tahap13/keamanan.php` | **102 / 102** (+1 skip) |
| `tests/tahap12/prompt7.php` | **75 / 75** |
| `tests/tahap10/e2e.php` | **79 / 79** |
| `tests/tahap10/security.php` | **34 / 34** |
| `tests/tahap10/auth.php` | **15 / 15** |
| `tests/tahap12/role-sidebar.php` | **28 / 28** |
| `tests/tahap12/revisi-final.php` | **86 / 86** |
| `tests/tahap11/crypto.php` | **19 / 19** |
| `tests/tahap10/login-layout.php` | **43 / 43** |
| `tests/tahap10/ai-widget.php` | **81 / 81** (+1 skip) |
| `tests/tahap10/ai-read-tools.php` | **26 / 26** |
| `tests/tahap10/ai-gemini-resilience.php` | **23 / 23** |
| `tests/tahap11/ai-settings.php` | **67 / 67** |
| `tests/tahap11/config-priority.php` | **17 / 17** |
| Lint PHP (seluruh proyek) | **0 error** |

### 16.5 Catatan
- Pengujian tidak menulis/menghapus data pada database utama; sesi uji memakai
  akun yang ada. Database uji `wificare_cdn_test` dipakai suite regresi pada
  server :8099.
- Tidak ada stack trace, path `C:\xampp`, password, API key, atau kredensial DB
  yang muncul pada respons yang diuji.
- Sisa: pengujian cookie `Secure` pada HTTPS (SKIP) dan rotasi API key yang
  pernah terekspos (tindakan operasional, bukan cacat kode).


## 17. Tahap 15 - Pengujian Revisi Final AI Assistant (20 Oktober 2026)

Semua hasil di bawah berasal dari eksekusi nyata pada 20 Oktober 2026.
Status hanya **PASS**, **FAIL**, **BLOCKED**, atau **SKIP**. Tidak ada PASS
tanpa bukti eksekusi.

### 17.1 Lingkungan

| Item | Nilai |
|---|---|
| PHP CLI | 8.0.30 (kode kompatibel 8.2+) |
| Web server uji | PHP built-in `http://localhost:8099` |
| DB uji | `wificare_cdn_test` |
| DB utama | `wificare_cdn_v1` (migrasi 007 diterapkan; data dipertahankan) |
| Migrasi | `007_ai_platform.sql` diterapkan pada kedua DB |
| Node.js | v24.19.0 (untuk `node --check`) |
| Provider | Gemini (key `.env`); DeepSeek/OpenAI tanpa kredensial nyata |

Perintah server uji:

```powershell
$env:DB_NAME='wificare_cdn_test'; $env:APP_ENV='testing'
C:\xampp\php\php.exe -S localhost:8099 -t public public/index.php
```

### 17.2 Matriks hasil

| Suite | Cakupan | Hasil |
|---|---|---|
| `tests/tahap15/ai-platform.php` | multi-provider, key terenkripsi, tes koneksi, monitoring, batas harian, clear chat, FAQ CRUD, RBAC, UI | **66 PASS / 0 FAIL / 2 BLOCKED** |
| `tests/tahap15/ai-tools-revisi.php` | tool baca-only, statistik tanggal, cakupan per peran, profil/riwayat/rating, allowlist | **29 PASS / 0 FAIL** |
| `tests/tahap15/ai-live-roles.php` | login + panggilan live Gemini per peran | **4 PASS (login/endpoint) / 4 BLOCKED (kuota provider)** |
| `tests/tahap10/ai-read-tools.php` | function calling mock, tolak coding, RBAC tool | **26 PASS** |
| `tests/tahap10/ai-widget.php` | widget, CSRF, konfigurasi, tidak ada key bocor | **81 PASS / 1 SKIP** |
| `tests/tahap10/ai-gemini-resilience.php` | retry/fallback/timeout/error via mock | **23 PASS** |
| `tests/tahap11/ai-settings.php` | halaman Admin, RBAC, enkripsi, tes koneksi | **67 PASS** |
| `tests/tahap11/crypto.php` | libsodium + read-only | **19 PASS** |
| `tests/tahap11/config-priority.php` | prioritas DB vs `.env` | **17 PASS** |
| `tests/tahap10/e2e.php` | regresi E2E | **79 PASS** |
| `tests/tahap10/security.php` | keamanan/tepi | **34 PASS** |
| `tests/tahap10/auth.php` | autentikasi/sesi | **15 PASS** |
| `tests/tahap10/login-layout.php` | login full-screen | **43 PASS** |
| `tests/tahap12/prompt7.php` | revisi 7 bug | **75 PASS** |
| `tests/tahap12/revisi-final.php` | revisi final | **86 PASS** |
| `tests/tahap12/role-sidebar.php` | role/sidebar | **28 PASS** |
| `tests/tahap13/keamanan.php` | proteksi folder/auth | **102 PASS / 1 SKIP** |
| Lint PHP seluruh proyek | 166 berkas | **0 error** |
| `node --check` JS AI | `ai-widget.js`, `ai-settings.js`, `ai-render.js` | **3/3 OK** |

### 17.3 Bukti migrasi & read-only

- `007_ai_platform.sql` diterapkan ke `wificare_cdn_v1` dan
  `wificare_cdn_test`; ketiga tabel baru ada, kolom `daily_limit` ada, dan 5 FAQ
  awal tersedia di kedua DB.
- Akun MySQL read-only sementara diuji nyata:
  - `SELECT COUNT(*) FROM pengaduan` berhasil (106 baris pada DB uji saat uji);
  - `UPDATE pengaduan` ditolak `SQLSTATE 42000`;
  - `DELETE FROM pengaduan` ditolak `SQLSTATE 42000`.
- Akun sementara dihapus setelah verifikasi.
- Kondisi akhir: `wificare_cdn_test.ai_settings` dan
  `wificare_cdn_test.ai_provider_keys` kosong (0 baris); tidak ada key uji
  tersisa. DB utama memiliki 0 provider key dan 5 FAQ (konfigurasi provider
  tetap di `.env` sampai Admin menyimpannya).

### 17.4 Rincian `ai-platform.php` (66 PASS)

Cakupan yang lulus antara lain:

- Admin dapat membuka pengaturan; CS/Teknisi/Pelanggan 403; anonymous 302 login.
- Simpan Gemini/DeepSeek/OpenAI: key terenkripsi `v1:`, ciphertext berbeda,
  key provider lama tidak hilang saat pindah provider, key kosong tidak
  menimpa key tersimpan.
- HTML Admin menampilkan tiga provider, monitoring, form FAQ, batas harian;
  tidak memuat API key uji maupun ciphertext.
- Tes koneksi Gemini dengan key tidak valid berjalan nyata dan aman; DeepSeek
  dan OpenAI memakai key tersimpan dan mengembalikan error aman.
- `ai_usage_log` tidak memiliki kolom pesan/jawaban/key; tes koneksi tercatat.
- Batas harian 1: request pertama dieksekusi, request kedua HTTP 429
  `daily_limit`; request yang ditolak tidak menambah log.
- Chatbot dinonaktifkan: request berikutnya mengembalikan kode `disabled` dan
  pesan jelas, bukan jawaban palsu.
- Monitoring menampilkan ringkasan hari ini/bulan dan penggunaan per pengguna
  (nama, peran, jumlah, gagal, waktu terakhir).
- `/ai/clear` berhasil dengan CSRF, 419 tanpa CSRF, 401/302 tanpa login; tidak
  ada tabel riwayat percakapan permanen.
- FAQ: CS 403; Admin tambah/cari/tampil/nonaktif/edit/validasi/hapus; audit log
  tidak memuat key/ciphertext.
- JS widget mendukung salin/bersihkan; renderer tidak memakai `.innerHTML =`
  untuk teks AI; CSS tombol salin tersedia.

### 17.5 BLOCKED dan FAIL lingkungan

| Item | Status | Alasan |
|---|---|---|
| Live DeepSeek berhasil | **BLOCKED** | Kredensial nyata tidak tersedia; tes key uji menghasilkan `invalid_key` aman |
| Live OpenAI berhasil | **BLOCKED** | Kredensial nyata tidak tersedia; tes key uji menghasilkan `invalid_key` aman |
| Live Gemini Admin/CS/Teknisi/Pelanggan | **BLOCKED** | Login/endpoint PASS, tetapi provider membalas `server`/`quota` (free tier) |
| `ai-gemini.php` (3 pertanyaan live) | **13 PASS / 6 FAIL** | Run terakhir: semua request dibalas `server`/`quota`; run sebelumnya Q1/Q2 sempat PASS |
| Cookie `Secure` HTTPS | **SKIP** | Tidak ada TLS lokal |

Catatan: kegagalan live di atas **tidak** diubah menjadi PASS. Pesan yang
diterima pengguna aman dan tidak membocorkan key; kode error yang tercatat
adalah `server`/`quota`, bukan error aplikasi.

Catatan lingkungan: saat membuat akun MySQL read-only sementara, MariaDB
melaporkan korupsi tabel sistem Aria (`tables_priv`) dan `mysql.db`.
Dijalankan `REPAIR TABLE`/`mysqlcheck`; **data aplikasi tidak diubah**. Setelah
perbaikan, `mysql.db` berisi 0 baris dan user MySQL yang ada hanya `root` dan
`pma`; akses aplikasi (root) diuji tetap normal. Bila di server ini ada user
MySQL lain dengan grant level database, periksa kembali dengan `SHOW GRANTS`.

### 17.6 Pengujian manual browser (langkah)

1. Jalankan Apache + MySQL, buka `http://localhost/aduan-pelanggan/public/`.
2. Login Admin: buka **Pengaturan -> Pengaturan AI Chatbot**; ganti provider,
   isi model, uji koneksi, simpan; cek monitoring dan CRUD FAQ.
3. Login CS: pastikan menu AI tidak ada di sidebar, widget muncul, dan buka
   halaman pengaturan AI langsung -> 403.
4. Login Teknisi: tanyakan tugas aktif; pastikan hanya tiket miliknya.
5. Login Pelanggan: tanyakan username PPPoE/status tiket; pastikan hanya data
   sendiri; coba Enter, Shift+Enter, salin, bersihkan, minimize, dan tema
   Light/Dark.
6. Coba kirim pesan saat chatbot dinonaktifkan, key salah, dan pesan terlalu
   panjang; pastikan pesan aman, bukan jawaban palsu.

### 17.7 Kesimpulan

Implementasi Tahap 15 **lulus seluruh pengujian otomatis yang dapat dijalankan**
(66 + 29 + 26 + 81 + 23 + 67 + 19 + 17 + regresi 462 assertion; 2 SKIP).
Verifikasi live lintas provider/peran belum dapat dinyatakan PASS karena
kredensial/kuota provider, sehingga dicatat **BLOCKED** secara jujur.

---

## 18. Addendum Tahap 17 — Pusat API & WhatsApp (26 Oktober 2026)

### 18.1 Hasil

| Harness | Hasil |
|---|---|
| `tests/tahap17/api-center.php` | **94 PASS, 0 FAIL** |
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

### 18.2 Cakupan Tahap 17

- Enkripsi kredensial, master key hilang, blank-preserve, replace, revoke.
- Koneksi bersama/aktif + mapping per fitur.
- OTP single-use, purpose, nomor, expiry, max attempts, batas akun/nomor.
- Notifikasi tiket idempoten; accepted ≠ delivered; webhook signature;
  webhook duplikat; kegagalan provider; isolasi fitur.
- Monitoring nyata + filter; audit tanpa secret; RBAC/CSRF; menu unik;
  secret tidak muncul di HTML/JSON; tema; floating AI tetap ada.

### 18.3 BLOCKED

- Live WhatsApp Cloud API (tes koneksi/kirim/webhook Meta) **BLOCKED** tanpa
  access token, Phone Number ID, pengirim/template approved. Mock lokal dan
  signature HMAC nyata sudah diuji.
- Live DeepSeek/OpenAI tetap **BLOCKED** tanpa kredensial nyata.
- Laporan WhatsApp otomatis belum diimplementasikan (hanya konfigurasi).
- Cookie `Secure` penuh tetap SKIP tanpa TLS lokal.

### 18.4 Catatan regresi dataset

`tests/tahap16/identity.php` memiliki 3 assertion unit yang menghardcode nama
Admin `Budi Santoso Herman`; DB utama saat ini memakai `admin.cdn`/`Nur Muhammad`.
Pengujian identitas HTTP per peran tetap lulus. `tests/tahap13/keamanan.php`
memakai akun `admin.budi` pada Apache/DB utama yang sudah tidak ada; pengujian
setara pada DB uji lulus. Keduanya adalah drift dataset, bukan regresi kode
Tahap 17.

---

## 19. Addendum Tahap 18 — Hybrid AI, Database Lokal & Ollama (26 Oktober 2026)

### 19.1 Hasil

| Harness | Hasil |
|---|---|
| `tests/tahap18/local-ai.php` | **135 PASS, 0 FAIL, 1 SKIP** |
| `tests/tahap17/api-center.php` | 94 PASS, 0 FAIL |
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

### 19.2 Cakupan `tests/tahap18/local-ai.php`

- Jumlah/data karyawan sesuai DB; hanya `admin`/`cs`/`teknisi`; pelanggan tidak
  dihitung karyawan; tidak ada pola secret.
- Pencarian/detail pelanggan dan follow-up PPPoE; Admin melihat kontak lengkap,
  CS melihat kontak tersamar.
- Akurasi pengaduan, filter periode, statistik selesai, tanpa duplikasi baris.
- Fallback lokal tanpa API key, kejujuran saat database gagal, dan kejujuran
  untuk intent yang tidak didukung.
- RBAC dan prompt injection; tool baca luas ditolak untuk peran yang tidak
  berwenang; tidak ada tool SQL/tulis.
- Tidak ada password hash, password/PPPoE plaintext, API key, token, session ID,
  master key, atau kredensial MySQL pada jawaban.
- Perilaku multi-provider cloud dan pengaturan hybrid/Ollama lewat HTTP.
- Tes MySQL read-only nyata, status tes, CSRF, dan RBAC Admin.
- Opsi Ollama diuji bila runtime tersedia; jika tidak, hasilnya **SKIP**.

### 19.3 Perintah

```powershell
# Server aplikasi DB uji (port 8099)
$env:DB_NAME='wificare_cdn_test'; $env:APP_ENV='testing'
C:\xampp\php\php.exe -S localhost:8099 -t public public/index.php

# Terminal lain
$env:DB_NAME='wificare_cdn_test'; $env:APP_ENV='testing'
C:\xampp\php\php.exe tests\tahap18\local-ai.php
```

Untuk `tests/tahap17/api-center.php`, jalankan juga mock WhatsApp di
`127.0.0.1:8098`.

### 19.4 Catatan

- **Ollama live BLOCKED**: runtime Ollama tidak tersedia (`network`), dan tidak
  ada model yang diunduh. SKIP bukan PASS.
- **Live Gemini/DeepSeek/OpenAI BLOCKED** tanpa kredensial/kuota nyata.
- **Live WhatsApp Cloud API BLOCKED** tanpa kredensial Meta/template approved.
- Dua harness regresi diperbaiki agar tidak bergantung pada ID pelanggan atau
  nomor tiket hardcode yang bergeser setelah data uji bertambah. Ini menjaga
  isolasi pengujian, bukan melonggarkan assertion keamanan.
- Assertion unit lama `tests/tahap16/identity.php` yang hardcode nama akun masih
  gagal karena drift dataset; pengujian identitas HTTP per peran lulus.
- Lint PHP (`app/`, `config/`, `routes/`, `tests/`) dan `node --check`
  (`public/assets/js/*.js`) tanpa error.

---

## 20. Addendum Tahap 19 - Local-First AI, Intent, dan Monitoring (26 Oktober 2026)

### 20.1 Hasil wajib

Perintah:

```powershell
$env:DB_NAME='wificare_cdn_test'; $env:APP_ENV='testing'
$env:T10_BASE='http://localhost:8099'
C:\xampp\php\php.exe tests\tahap19\local-first.php
```

Hasil: **TOTAL 25 | PASS 25 | FAIL 0 | BLOCKED 0**.

| Kode | Pemeriksaan | Status |
|---|---|---|
| T01 | `customers.count` akurat dan bukan pencarian nama | PASS |
| T02 | "pelanggan siapa aja" -> daftar, konteks dibersihkan | PASS |
| T03 | `customers.search` akurat dan konteks pelanggan dipakai | PASS |
| T04 | `customers.count` memisahkan aktif/nonaktif | PASS |
| T05 | `technicians.count` akurat | PASS |
| T06 | `technicians.list` hanya akun teknisi | PASS |
| T07 | `most_assignments` sesuai MySQL | PASS |
| T08 | `least_assignments` sesuai MySQL | PASS |
| T09 | "kerjaannya banyak" -> peringkat, bukan jumlah akun | PASS |
| T10 | `most_completed` memakai tiket selesai | PASS |
| T11 | `most_active` membedakan tugas aktif dan tie | PASS |
| T12 | `never_assigned` mendeteksi 0 penugasan | PASS |
| T13 | `least_assignments` menampilkan tie 0 penugasan | PASS |
| T14 | `employees.count` tidak menghitung pelanggan | PASS |
| T15 | jawaban lokal tetap tersedia tanpa key cloud | PASS |
| T16 | semua intent database masuk jalur local-first | PASS |
| T17 | intent tak dikenal dijawab jujur tanpa angka | PASS |
| T18 | timeout cloud terpisah dan tidak mengarang data | PASS |
| T19 | cloud terkonfigurasi tidak dipanggil untuk data lokal | PASS |
| T20 | kegagalan MySQL -> pesan khusus, pulih setelah DB kembali | PASS |
| T21 | permintaan API key/kredensial ditolak tanpa kebocoran | PASS |
| T22 | SQL bebas ditolak dan tidak mengubah data | PASS |
| T23 | RBAC peringkat teknisi: CS ditolak | PASS |
| T24 | RBAC daftar pelanggan: teknisi ditolak | PASS |
| T25 | monitoring sumber + audit metadata tanpa data sensitif | PASS |

### 20.2 Matriks 25 area regresi

| No | Area | Harness utama | Status |
|---:|---|---|---|
| 1 | Alur pengaduan end-to-end | `tahap10/e2e.php` | PASS |
| 2 | RBAC empat peran | `tahap10/e2e.php`, `tahap12/role-sidebar.php` | PASS |
| 3 | CSRF endpoint | `tahap10/e2e.php`, `tahap11/ai-settings.php` | PASS |
| 4 | Ekspor PDF/Excel | `tahap10/e2e.php` | PASS |
| 5 | Upload/foto dan IDOR | `tahap10/security.php` | PASS |
| 6 | Autentikasi/throttle/session | `tahap10/auth.php` | PASS |
| 7 | Login full-screen dan redirect per peran | `tahap10/login-layout.php` | PASS |
| 8 | Floating AI widget dan CSRF chat | `tahap10/ai-widget.php` | PASS |
| 9 | Pengaturan AI Admin | `tahap11/ai-settings.php` | PASS |
| 10 | Enkripsi kredensial | `tahap11/crypto.php` | PASS |
| 11 | Prioritas konfigurasi DB vs `.env` | `tahap11/config-priority.php` | PASS |
| 12 | Ketahanan retry/fallback provider | `tahap10/ai-gemini-resilience.php` | PASS |
| 13 | Tool-loop cloud lama | `tahap10/ai-read-tools.php` | FAIL |
| 14 | Role/sidebar dan sesi paralel | `tahap12/role-sidebar.php` | PASS |
| 15 | Perbaikan bug prompt/revisi | `tahap12/prompt7.php` | PASS |
| 16 | Kartu tiket, Markdown, tema | `tahap12/revisi-final.php` | PASS |
| 17 | Pusat API/WhatsApp, OTP, webhook | `tahap17/api-center.php` | PASS |
| 18 | Platform AI multi-provider | `tahap15/ai-platform.php` | PASS, 2 BLOCKED live |
| 19 | Tool baca-only dan RBAC | `tahap15/ai-tools-revisi.php` | PASS |
| 20 | Hybrid/local AI baseline | `tahap18/local-ai.php` | PASS, 1 SKIP Ollama |
| 21 | Local-first dan intent | `tahap19/local-first.php` | PASS |
| 22 | Monitoring sumber dan audit | `tahap19/local-first.php` T25 | PASS |
| 23 | Proteksi folder/header keamanan | `tahap13/keamanan.php` | FAIL (akun/foto hardcode) |
| 24 | Identitas HTTP per peran | `tahap16/identity.php` bagian HTTP | PASS |
| 25 | Data mutable/harness identity unit | `tahap16/identity.php` | FAIL (17 assertion hardcode) |

### 20.3 Catatan FAIL / BLOCKED

- **FAIL** `tahap10/ai-read-tools.php`: 16 PASS, 10 FAIL. Harness menghardcode
  ID pengguna lama dan mengharapkan cloud tool-loop untuk pertanyaan data yang
  sekarang dijawab lokal. Local-first memang mengubah jalur tersebut.
- **FAIL** `tahap13/keamanan.php`: 25 FAIL pada Apache/DB utama karena akun
  `admin.budi`/`cs.rizki`/`cdn.romiz` dan foto hardcode tidak cocok dengan
  dataset mutable. Bagian proteksi folder tetap PASS; alur HTTP setara diuji
  oleh suite lain.
- **FAIL** `tahap16/identity.php`: 44 PASS, 17 FAIL karena assertion unit
  hardcode ID/nama akun lama. Seluruh alur identitas HTTP per peran PASS.
- **BLOCKED** `tahap10/ai-gemini.php`, `tahap10/ai-live.php`, dan
  `tahap15/ai-live-roles.php` karena memerlukan kredensial/mock provider live.
- **BLOCKED** Ollama live karena runtime/model tidak tersedia. SKIP bukan PASS.
- **BLOCKED** WhatsApp Cloud API live tanpa kredensial Meta/template approved.
- **BLOCKED** verifikasi cookie `Secure` penuh tanpa TLS lokal.

### 20.4 Perbaikan harness

Dua harness diperbaiki agar tidak menghasilkan FAIL palsu akibat isolasi data:

- `tests/tahap11/config-priority.php` sekarang mem-backup dan memulihkan
  `ai_provider_keys`, serta menghapus baris provider key saat menguji key legacy
  di `ai_settings`. Assertion keamanan tidak dilonggarkan.
- `tests/tahap12/revisi-final.php` memeriksa `dibuat_panjang` untuk format
  tanggal Indonesia, bukan regex yang mengasumsikan format field pendek.

`tests/tahap10/ai-read-tools.php` dan harness dataset-hardcoded lain **tidak**
diubah menjadi PASS palsu; kegagalannya dicatat terbuka sebagai utang pengujian.

### 20.5 BLOCKED / sisa risiko

- Ollama live, Gemini/DeepSeek/OpenAI live, dan WhatsApp Cloud API live tetap
  **BLOCKED** tanpa runtime/kredensial/kuota terkontrol.
- Laporan WhatsApp otomatis belum diimplementasikan.
- Cookie `Secure` penuh belum diverifikasi tanpa TLS.
- Harness lama perlu migrasi ke fixture dinamis sebelum dijadikan gerbang
  rilis otomatis.

### 20.6 Perintah regresi

```powershell
$env:DB_NAME='wificare_cdn_test'; $env:APP_ENV='testing'
$env:T10_BASE='http://localhost:8099'
C:\xampp\php\php.exe tests\tahap19\local-first.php
C:\xampp\php\php.exe tests\tahap18\local-ai.php
C:\xampp\php\php.exe tests\tahap17\api-center.php
C:\xampp\php\php.exe tests\tahap10\e2e.php
C:\xampp\php\php.exe tests\tahap10\ai-widget.php
C:\xampp\php\php.exe tests\tahap10\security.php
C:\xampp\php\php.exe tests\tahap11\ai-settings.php
C:\xampp\php\php.exe tests\tahap12\revisi-final.php
C:\xampp\php\php.exe tests\tahap15\ai-platform.php
C:\xampp\php\php.exe tests\tahap15\ai-tools-revisi.php
```

Untuk `tahap17/api-center.php`, jalankan mock WhatsApp di `127.0.0.1:8098`
terlebih dahulu. Jangan menjalankan harness terhadap database produksi.
## 21. Addendum Tahap 23 - Ganti Nomor WhatsApp Pelanggan (28 Oktober 2026)

### 21.1 Hasil

| Area | Harness | Hasil |
| --- | --- | --- |
| Backend + UI ganti nomor | `tests/tahap23/ganti-nomor.php` | **90 PASS, 0 FAIL, 1 BLOCKED live** |
| Regresi kunci nomor Profil | `tests/tahap21/profile-phone-lock.php` | PASS |
| Regresi backend OTP | `tests/tahap21/phone-verification-backend.php` | PASS |
| Regresi UI verifikasi Profil | `tests/tahap21/profile-verification-ui.php` | PASS |
| Regresi snapshot kontak pengaduan | `tests/tahap21/complaint-phone-snapshot.php` | PASS |
| Regresi provider Fonnte/Meta | `tests/tahap22/fonnte-provider.php` | PASS |
| Regresi Pusat API/OTP | `tests/tahap17/api-center.php` | PASS |
| Regresi E2E utama | `tests/tahap10/e2e.php` | PASS |

### 21.2 Cakupan alur ganti nomor

- Password WiFiCare saat ini diverifikasi sebelum OTP diminta.
- OTP dikirim ke nomor **baru** melalui provider aktif; mock Fonnte menerima
  target E.164 dan `countryCode=0`.
- Nomor lama tetap aktif dan tidak berubah saat password salah, OTP salah,
  cooldown, kirim ulang, maupun pembatalan.
- OTP lama tidak berlaku setelah kirim ulang.
- Nomor baru dan status terverifikasi disimpan atomik setelah OTP benar.
- Pembatalan menghapus permintaan session dan OTP khusus nomor baru.
- CSRF, RBAC pelanggan, rate limit password, cooldown OTP, single-use OTP, dan
  audit tanpa nomor lengkap/kode/password diuji.
- Form Profil biasa tetap tidak memiliki input editable `nomor_whatsapp`;
  form Buat Pengaduan tetap berfungsi.

### 21.3 BLOCKED

- **BLOCKED** pengiriman Fonnte live dengan token nyata dan penerima yang
  diizinkan. Pengujian di atas memakai mock, sehingga status yang terbukti
  adalah *accepted*, bukan *delivered*.
- **BLOCKED** verifikasi OTP live dan perubahan nomor live sampai token gateway
  nyata dimasukkan melalui Admin dan pengiriman dari PHP benar-benar diterima.

### 21.4 Perintah

```powershell
$env:APP_BASE_PATH='/'
$env:FONNTE_MOCK_LOG="$env:TEMP\wificare_fonnte_mock_t23.log"
C:\xampp\php\php.exe tests\tahap23\ganti-nomor.php
```

Prasyarat server uji dan mock:

```powershell
$env:APP_BASE_PATH='/'; $env:DB_NAME='wificare_cdn_test'
C:\xampp\php\php.exe -S 127.0.0.1:8099 -t public public/index.php

$env:FONNTE_MOCK_LOG="$env:TEMP\wificare_fonnte_mock_t23.log"
C:\xampp\php\php.exe -S 127.0.0.1:8097 tests\tahap22\mock-fonnte.php
```

Fixture, kredensial, pengaturan, OTP, audit, dan penggunaan API dibersihkan
serta pengaturan/kredensial aktif dipulihkan setelah pengujian.

## 22. Addendum Tahap 3 - Lupa Password melalui OTP WhatsApp (28 Oktober 2026)

### 22.1 Hasil

| Area | Harness | Hasil |
| --- | --- | --- |
| Backend + UI Lupa Password | `tests/tahap24/lupa-password.php` | **88 PASS, 0 FAIL, 1 BLOCKED live** |
| Regresi ganti nomor WhatsApp | `tests/tahap23/ganti-nomor.php` | **90 PASS, 0 FAIL, 1 BLOCKED live** |
| Regresi kunci nomor Profil | `tests/tahap21/profile-phone-lock.php` | **20 PASS** |
| Regresi backend verifikasi nomor | `tests/tahap21/phone-verification-backend.php` | **49 PASS** |
| Regresi UI verifikasi nomor | `tests/tahap21/profile-verification-ui.php` | **37 PASS** |
| Regresi snapshot kontak pengaduan | `tests/tahap21/complaint-phone-snapshot.php` | **25 PASS** |
| Regresi provider Fonnte/Meta | `tests/tahap22/fonnte-provider.php` | **66 PASS** |
| Regresi Pusat API/OTP | `tests/tahap17/api-center.php` | **94 PASS** |
| Regresi E2E utama | `tests/tahap10/e2e.php` | **79 PASS** |
| Regresi autentikasi/session | `tests/tahap10/auth.php` | **15 PASS** |
| Pemeriksaan visual | Browser lokal halaman Login dan Lupa Password | Tampilan Light Mode sesuai; tombol tema tersedia |

Semua harness di atas dijalankan nyata pada `wificare_cdn_test` dengan mock
Fonnte di port `8097` dan mock Meta di port `8098`. Tidak ada hasil live yang
diklaim lulus.

### 22.2 Cakupan alur Lupa Password

- Tautan **Lupa Password?** tersedia di Login dan membuka alur tiga tahap:
  identifikasi username PPPoE, verifikasi OTP, lalu password baru.
- Username tidak dikenal, akun staf, akun nonaktif, dan nomor belum
  terverifikasi mendapat respons netral yang sama; tidak ada OTP dikirim dan
  tidak ada detail akun/nomor yang dibocorkan.
- OTP hanya dikirim ke nomor WhatsApp terverifikasi dari database. Nomor dari
  browser tidak pernah dipercaya; nomor lengkap tidak ditampilkan di halaman
  publik.
- Purpose yang dipakai adalah `reset_password` melalui `WhatsAppOtpService`
  yang sudah ada. OTP `phone_verification` tidak dapat dipakai untuk reset.
- OTP salah, kedaluwarsa, sudah dipakai, milik akun lain, atau milik purpose
  lain ditolak. Kode OTP tidak disimpan plaintext.
- Cooldown kirim ulang dan batas harian per akun/nomor ditegakkan server;
  rate limit sumber permintaan juga diterapkan.
- Setelah OTP benar, dibuat otorisasi reset server-side berumur pendek dan
  sekali pakai yang terikat pada akun serta session. Token hanya disimpan
  sebagai HMAC, tidak dikirim ke frontend.
- Password baru wajib 8-72 karakter, memuat huruf dan angka, disimpan dengan
  `password_hash`, dan tidak boleh sama dengan username.
- Konsumsi otorisasi, penyimpanan password, dan kenaikan
  `users.session_version` dijalankan dalam satu transaksi. Sesi login lama
  langsung ditolak setelah reset.
- Password lama tidak dapat dipakai lagi; password baru dapat dipakai login.
  Username PPPoE dan nomor WhatsApp pelanggan tidak berubah.
- CSRF wajib pada seluruh form publik. Audit tidak memuat password, OTP,
  token reset, nomor lengkap, atau API token.
- Profil, Ganti Nomor WhatsApp, Buat Pengaduan, provider Fonnte/Meta, Pusat
  API, dan E2E utama tetap lulus regresi.

### 22.3 Migrasi & kompatibilitas

Migrasi `database/migrations/012_password_reset.sql` menambahkan:

- `users.session_version` untuk pencabutan sesi lama;
- `password_reset_authorizations` untuk otorisasi reset sekali pakai;
- `password_reset_events` untuk rate limit sumber tanpa menyimpan username,
  nomor, OTP, atau password.

Migrasi diterapkan ke `wificare_cdn_test` dan `wificare_cdn_v1` tanpa mengubah
password pengguna atau menghapus data. Kolom/tabel baru bersifat aditif.

### 22.4 BLOCKED

- **BLOCKED** pengujian live Fonnte/Meta dengan token nyata dan penerima yang
  diizinkan. Harness memakai mock, sehingga status yang terbukti adalah
  *accepted*, bukan *delivered*.
- **BLOCKED** reset password end-to-end melalui WhatsApp live sampai OTP nyata
  benar-benar diterima penerima yang diizinkan dan password berhasil direset.
- **BLOCKED** verifikasi bahwa password jaringan PPPoE tidak berubah pada
  perangkat jaringan nyata. Implementasi aplikasi tidak menyentuh kredensial
  jaringan PPPoE; yang diuji adalah data aplikasi (`users`, `pelanggan`)
  tetap tidak berubah selain password akun WiFiCare.

### 22.5 Perintah

```powershell
$env:APP_BASE_PATH='/'
$env:FONNTE_MOCK_LOG="$env:TEMP\wificare_fonnte_mock_t24.log"
C:\xampp\php\php.exe tests\tahap24\lupa-password.php
```

Prasyarat server uji dan mock:

```powershell
$env:APP_BASE_PATH='/'; $env:DB_NAME='wificare_cdn_test'
C:\xampp\php\php.exe -S 127.0.0.1:8099 -t public public/index.php

$env:FONNTE_MOCK_LOG="$env:TEMP\wificare_fonnte_mock_t24.log"
C:\xampp\php\php.exe -S 127.0.0.1:8097 tests\tahap22\mock-fonnte.php

# Untuk regresi verifikasi nomor/Meta:
$env:WA_MOCK_LOG="$env:TEMP\wificare_wa_mock_t24.log"
C:\xampp\php\php.exe -S 127.0.0.1:8098 tests\tahap17\mock-whatsapp.php
```

Fixture, kredensial, pengaturan, OTP, audit, dan penggunaan API dibersihkan
serta pengaturan/kredensial aktif dipulihkan setelah pengujian.

---

## 23. Addendum Tahap 4 - Template Pesan OTP per Purpose (28 Oktober 2026)

### 23.1 Hasil

- Suite baru `tests/tahap25/otp-templates.php`: **45 PASS, 0 FAIL**.
- **BLOCKED** pengujian live Fonnte/Meta dengan token nyata dan delivery ke
  penerima yang diizinkan. Status yang terbukti pada mock adalah *accepted*,
  bukan *delivered*.
- Regresi yang dijalankan ulang dan lulus:
  - `tests/tahap17/api-center.php`: **94 PASS, 0 FAIL**;
  - `tests/tahap21/phone-verification-backend.php`: **49 PASS, 0 FAIL**;
  - `tests/tahap21/profile-verification-ui.php`: **37 PASS, 0 FAIL**;
  - `tests/tahap21/profile-phone-lock.php`: **20 PASS, 0 FAIL**;
  - `tests/tahap21/complaint-phone-snapshot.php`: **25 PASS, 0 FAIL**;
  - `tests/tahap22/fonnte-provider.php`: **66 PASS, 0 FAIL**;
  - `tests/tahap23/ganti-nomor.php`: **90 PASS, 0 FAIL**;
  - `tests/tahap24/lupa-password.php`: **88 PASS, 0 FAIL** (+ 1 BLOCKED live);
  - `tests/tahap10/auth.php`: **15 PASS, 0 FAIL**;
  - `tests/tahap10/e2e.php`: **79 PASS, 0 FAIL**.
- `php -l` pada seluruh berkas PHP yang diubah: **0 error**.

### 23.2 Perubahan yang diuji

- Template pesan OTP kini terpisah per purpose:
  - `template_phone_verification` untuk verifikasi nomor;
  - `template_reset_password` untuk Lupa Password;
  - `template_phone_change` untuk ganti nomor.
- Admin -> WhatsApp Gateway -> OTP menampilkan tiga field template tersebut,
  masing-masing wajib memuat `{kode}` dan mendukung `{masa_berlaku}` serta
  `{nama_aplikasi}`.
- Backend memilih template dari purpose yang ditentukan alur server
  (`WhatsAppOtpService`), bukan dari field/nama template kiriman browser.
- Purpose `phone_change` ditambahkan ke enum `whatsapp_otp.purpose` dan alur
  ganti nomor memakainya bila diaktifkan Admin. Instalasi lama yang belum
  mengaktifkan `phone_change` tetap memakai `phone_verification` sebagai
  fallback kompatibel.
- OTP tetap terikat pada user, fingerprint nomor, dan purpose. Kode dari
  purpose lain ditolak dan tidak dapat dipakai lintas alur.
- Konfigurasi lama `template` tetap dibaca sebagai fallback untuk template
  purpose yang belum tersimpan. Tidak ada data existing yang dihapus.
- Provider Meta tetap wajib memakai Authentication template. Mode teks biasa
  ditolak sebelum OTP dibuat, sehingga teks bebas tidak menjadi bypass.
- Fonnte mengirim teks sesuai template purpose. Placeholder diganti backend;
  kode OTP tidak dicatat pada `api_usage_log`, `audit_log`, `api_config_audit`,
  atau log aplikasi.

### 23.3 Migrasi

`database/migrations/013_otp_template_purposes.sql` hanya mengubah enum
`whatsapp_otp.purpose` untuk menambahkan `phone_change`. Migrasi bersifat
aditif, tidak menghapus baris OTP, dan sudah diterapkan ke
`wificare_cdn_test` serta `wificare_cdn_v1`.

### 23.4 Catatan penyesuaian harness

Regresi `tests/tahap17/api-center.php` disesuaikan agar konfigurasi OTP Meta
memakai mode template/Authentication, sesuai kebijakan baru. Helper pembacaan
mock juga membaca kode dari komponen template, bukan hanya payload teks.
Tidak ada fitur aplikasi yang dilonggarkan untuk membuat pengujian lulus.

### 23.5 Perintah

```powershell
$env:APP_BASE_PATH='/'
$env:FONNTE_MOCK_LOG="$env:TEMP\wificare_fonnte_mock_t25.log"
$env:WA_MOCK_LOG="$env:TEMP\wificare_wa_mock_t25.log"
C:\xampp\php\php.exe tests\tahap25\otp-templates.php
```

Prasyarat server uji dan mock:

```powershell
$env:APP_BASE_PATH='/'; $env:DB_NAME='wificare_cdn_test'
C:\xampp\php\php.exe -S 127.0.0.1:8099 -t public public/index.php

$env:FONNTE_MOCK_LOG="$env:TEMP\wificare_fonnte_mock_t25.log"
C:\xampp\php\php.exe -S 127.0.0.1:8097 tests\tahap22\mock-fonnte.php

$env:WA_MOCK_LOG="$env:TEMP\wificare_wa_mock_t25.log"
C:\xampp\php\php.exe -S 127.0.0.1:8098 tests\tahap17\mock-whatsapp.php
```

Fixture, kredensial, pengaturan, OTP, audit, dan penggunaan API dibersihkan
serta pengaturan/kredensial aktif dipulihkan setelah pengujian.

---

## 24. Tahap 5 - Refactor Navigasi WhatsApp Gateway

### 24.1 Ringkasan

Halaman panjang dengan lima tab diubah menjadi submenu sidebar yang
expandable/collapsible dan lima halaman terpisah:

- `GET /admin/whatsapp/gateway`
- `GET /admin/whatsapp/otp`
- `GET /admin/whatsapp/notifikasi`
- `GET /admin/whatsapp/laporan`
- `GET /admin/whatsapp/audit`

Menu induk `WhatsApp Gateway` memakai ikon yang sama dengan sidebar Admin,
terbuka otomatis saat salah satu submenu aktif, dan submenu aktif diberi
highlight biru. Halaman baru hanya merender konfigurasi yang relevan; tab
horizontal lama dihapus.

### 24.2 Kompatibilitas

- `GET /admin/pengaturan/whatsapp` diarahkan 302 ke Gateway.
- `?tab=otp`, `?tab=notifikasi`, `?tab=laporan`, `?tab=audit` dan padanan
  `?section=...` dipetakan ke halaman yang sesuai.
- `?edit=ID` lama dipertahankan ke `gateway?edit=ID`.
- Hash lama (`#otp`, `#notifikasi`, `#laporan`, `#audit`) dipetakan oleh
  JavaScript setelah redirect, karena fragment tidak dikirim ke server.
- Semua route POST lama tetap diterima sebagai alias. Form baru memakai route
  kanonis `/admin/whatsapp/...`; redirect setelah simpan kembali ke submenu
  yang benar.

### 24.3 Hasil pengujian

- Suite baru `tests/tahap26/whatsapp-navigation.php`: **70 PASS, 0 FAIL**.
  Mencakup menu induk/submenu, lima halaman, active state, breadcrumb,
  kompatibilitas URL lama, form action, CSRF, RBAC CS, dark/light, CSS mobile,
  dan JavaScript toggle.
- Regresi yang dijalankan ulang:
  - `tests/tahap17/api-center.php`: **95 PASS, 0 FAIL**;
  - `tests/tahap22/fonnte-provider.php`: **67 PASS, 0 FAIL**;
  - `tests/tahap25/otp-templates.php`: **45 PASS, 0 FAIL** (+ 1 BLOCKED live);
  - `tests/tahap23/ganti-nomor.php`: **90 PASS, 0 FAIL** (+ 1 BLOCKED live);
  - `tests/tahap24/lupa-password.php`: **88 PASS, 0 FAIL** (+ 1 BLOCKED live);
  - `tests/tahap21/phone-verification-backend.php`: **49 PASS, 0 FAIL**;
  - `tests/tahap21/profile-verification-ui.php`: **37 PASS, 0 FAIL**;
  - `tests/tahap21/profile-phone-lock.php`: **20 PASS, 0 FAIL**;
  - `tests/tahap21/complaint-phone-snapshot.php`: **25 PASS, 0 FAIL**;
  - `tests/tahap12/role-sidebar.php`: **28 PASS, 0 FAIL**;
  - `tests/tahap10/auth.php`: **15 PASS, 0 FAIL**.
- `tests/tahap12/revisi-final.php`: **80 PASS, 1 FAIL** pada pemeriksaan kartu
  tiket AI yang sudah menjadi kegagalan lama dan tidak terkait refactor ini.
- `php -l` pada controller, helper, view, dan routes yang diubah: **0 error**.
- `node --check public/assets/js/main.js`: **0 error**.
- Smoke browser: submenu induk berhasil dibuka/ditutup (`aria-expanded`
  berubah `true` ke `false`, panel `hidden`), dan URL
  `/admin/whatsapp/gateway#otp` diarahkan ke `/admin/whatsapp/otp`.

### 24.4 BLOCKED

Pengujian visual pada viewport perangkat fisik, live Fonnte/Meta dengan token
nyata, dan delivery OTP nyata belum dapat dilakukan. Pemeriksaan mobile pada
tahap ini memakai CSS breakpoint dan struktur HTML yang sudah diuji otomatis,
bukan emulasi perangkat fisik.

### 24.5 Perintah

```powershell
$env:APP_BASE_PATH='/'
$env:DB_NAME='wificare_cdn_test'
C:\xampp\php\php.exe -S 127.0.0.1:8099 -t public public/index.php
C:\xampp\php\php.exe tests\tahap26\whatsapp-navigation.php
```

Pemeriksaan browser: masuk sebagai Admin, buka
`/admin/whatsapp/gateway`, lalu klik induk `WhatsApp Gateway` untuk
membuka/menutup submenu. Cek URL dan breadcrumb setiap submenu, dark/light
melalui tombol tema, dan persempit jendela untuk menguji sidebar mobile.

---

## 25. Revisi Keamanan Tampilan, Audit, WhatsApp, Monitoring, dan Penutupan Rating

Tanggal: 30 Oktober 2026

### 25.1 Ruang lingkup

Revisi ini mengubah **presentasi dan kontrol akses tampilan**, tanpa
menghapus data, mengubah skema database, atau membangun ulang aplikasi:

1. Identitas WiFiCare AI hanya menyebut nama dan peran. Username tidak lagi
   dimasukkan otomatis ke prompt atau jawaban identitas gabungan. Password,
   token, hash, dan kredensial tetap dilarang muncul.
2. Audit Log memakai label aktivitas berbahasa Indonesia, objek yang aman,
   IP loopback sebagai `Lokal`, dan detail berlabel. JSON mentah, SQL, stack
   trace, nilai rahasia, dan nama field teknis tidak dirender.
3. Audit Konfigurasi WhatsApp menghapus kolom `Field`; halaman menampilkan
   waktu, administrator, aktivitas, modul, dan ringkasan aman. Data audit
   asli tetap tersimpan.
4. Halaman OTP dan Notifikasi WhatsApp ditata ulang dengan spacing dan panel
   scoped. Nama field, validasi, template, antrean, retry, provider, dan
   perilaku pengiriman tidak diubah.
5. Monitoring API memakai wrapper halaman dan kartu `Estimasi Biaya` yang
   lebih terbaca. Rumus biaya, harga token, data monitoring, kuota, dan
   integrasi provider tidak diubah.
6. Tiket yang dibatalkan hanya menampilkan satu badge efektif `Dibatalkan`.
   Status asli, atribut pembatalan, detail, dan riwayat tetap tersimpan.
7. Rating Layanan dinonaktifkan sementara melalui flag
   `rating_layanan_enabled()`. Default nonaktif; set
   `RATING_LAYANAN_ENABLED=1` untuk mengaktifkan kembali tanpa migrasi.
   Menu, kartu, form, kolom, ringkasan, grafik, dan endpoint rating ditutup.
   Tabel `rating_layanan` dan data historis tidak dihapus.

### 25.2 Perubahan berkas utama

- `app/Helpers/audit.php` (baru), `app/Helpers/functions.php`,
  `app/Helpers/menu.php`
- `app/Services/AiIdentity.php`, `app/Services/AiAssistantService.php`
- `app/Controllers/PelangganController.php`,
  `app/Controllers/AdminLaporanController.php`
- `app/Views/admin/audit.php`, `app/Views/admin/laporan.php`,
  `app/Views/admin/monitoring_api.php`, `app/Views/admin/pengaduan.php`,
  `app/Views/admin/pengaduan_detail.php`, `app/Views/admin/teknisi.php`,
  `app/Views/admin/teknisi_detail.php`, `app/Views/pelanggan/tiket.php`
- `app/Views/admin/whatsapp/audit.php`,
  `app/Views/admin/whatsapp/otp.php`,
  `app/Views/admin/whatsapp/notifikasi.php`
- `public/assets/css/style.css`, `.env.example` (dokumentasi flag rating)
- Pengujian: `tests/tahap16/identity.php`, `tests/tahap10/e2e.php`,
  `tests/tahap10/security.php`, `tests/tahap26/whatsapp-navigation.php`,
  dan suite baru `tests/tahap27/revisi.php`

### 25.3 Hasil pengujian

| Suite | Hasil | Catatan |
|-------|-------|---------|
| `tests/tahap27/revisi.php` | **50 PASS, 0 FAIL** | Audit, badge, rating, OTP/Notif/Monitoring |
| `tests/tahap26/whatsapp-navigation.php` | **70 PASS, 0 FAIL** | Navigasi WhatsApp tetap utuh |
| `tests/tahap16/identity.php` | **62 PASS, 0 FAIL** | Identitas AI tanpa username otomatis |
| `tests/tahap10/e2e.php` | **80 PASS, 0 FAIL** | Alur pengaduan, rating ditutup, laporan |
| `tests/tahap10/security.php` | **36 PASS, 0 FAIL** | XSS, IDOR, CSRF, endpoint rating 404 |
| `tests/tahap17/api-center.php` | **95 PASS, 0 FAIL** | Kredensial, OTP, monitoring, audit |
| `tests/tahap25/otp-templates.php` | **45 PASS, 0 FAIL** | Template OTP per purpose |
| `tests/tahap24/lupa-password.php` | **88 PASS, 0 FAIL** | Reset password via OTP |
| `tests/tahap23/ganti-nomor.php` | **90 PASS, 0 FAIL** | Ganti nomor via OTP |
| `tests/tahap12/role-sidebar.php` | **28 PASS, 0 FAIL** | RBAC dan sidebar |
| `tests/tahap18/local-ai.php` | **135 PASS, 0 FAIL, 1 SKIP** | Skip pemeriksaan live AI |
| `tests/tahap22/fonnte-provider.php` | **67 PASS, 0 FAIL** | Provider Fonnte/Meta |
| `tests/tahap10/auth.php` | **15 PASS, 0 FAIL** | Login, logout, session |
| `tests/tahap12/revisi-final.php` | **86 PASS, 0 FAIL** | Run final setelah fixture uji tersedia |
| `tests/tahap15/ai-tools-revisi.php` | **29 PASS, 0 FAIL** | Tool baca AI tetap aman |

**Total run final: 976 PASS, 0 FAIL, 1 SKIP.**

Pemeriksaan statis:

- `php -l` seluruh berkas `app/`, `routes/`, `public/`, dan `tests/`:
  **PASS, 0 syntax error**.
- `node --check public/assets/js/main.js`: **PASS**.
- Server uji: `php -S 127.0.0.1:8099 -t public` dengan
  `DB_NAME=wificare_cdn_test` dan `APP_BASE_PATH=/`.
- Tidak ada perubahan skema database dan tidak ada penghapusan data
  pelanggan, tiket, audit, atau rating.

Catatan transparansi: run awal `tests/tahap12/revisi-final.php` sempat
menghasilkan satu FAIL karena fixture tiket uji tidak tersedia pada saat itu.
Setelah fixture dipulihkan oleh suite database, run final lulus 86/86.
Tidak ada assertion yang dilonggarkan untuk membuatnya lulus.

### 25.4 BLOCKED

- Pengujian live Fonnte/Meta dengan token nyata dan delivery ke penerima
  belum dapat dilakukan.
- Approval template Authentication Meta dan OTP nyata belum dapat diuji.
- Pengujian visual pada perangkat fisik dan emulasi viewport perangkat
  belum tersedia; validasi responsif memakai struktur HTML dan breakpoint CSS.

### 25.5 Langkah verifikasi browser

1. Masuk sebagai Admin dan buka `/admin/audit`.
   Pastikan aktivitas tampil sebagai kalimat Indonesia, IP loopback tampil
   `Lokal`, detail dapat dibuka dengan label, dan tidak ada JSON/SQL mentah.
2. Buka `/admin/whatsapp/audit`.
   Pastikan tidak ada kolom `Field` atau nama field/token teknis.
3. Buka `/admin/whatsapp/otp` dan `/admin/whatsapp/notifikasi`.
   Periksa jarak antar panel, akordeon event, dan pastikan semua input
   lama masih dapat disimpan.
4. Buka `/admin/monitoring-api`.
   Periksa kartu `Estimasi Biaya` pada mode terang dan gelap.
5. Buka `/admin/pengaduan`, cari tiket yang dibatalkan.
   Pastikan hanya badge `Dibatalkan` yang tampil; buka detail untuk melihat
   status asli dan riwayat.
6. Masuk sebagai Pelanggan.
   Pastikan menu dan form Rating Layanan tidak muncul, lalu akses langsung
   `/pelanggan/rating` untuk memastikan respons 404.
7. Uji dark/light mode dan sidebar mobile pada setiap halaman di atas.

---

## 26. Laporan WhatsApp Manual (Teks)

Tanggal: 31 Oktober 2026

### 26.1 Ruang lingkup

Halaman `Admin → WhatsApp Gateway → Laporan WhatsApp` diubah dari rencana
konfigurasi menjadi **pengirim laporan teks manual** berbasis data pengaduan
dan teknisi yang aktual. Tidak ada scheduler, PDF/Excel, persetujuan
implementasi, atau laporan kepuasan pelanggan.

Alur manual:

1. Admin memilih koneksi WhatsApp aktif, jenis laporan, periode, dan penerima
   dari daftar penerima berwenang.
2. Admin menekan **Preview Laporan**. Server membaca data aktual dan menyimpan
   token pratinjau di session beserta fingerprint SHA-256. Isi pesan tidak
   disimpan; nomor penerima hanya dikirim sebagai indeks daftar berwenang.
3. Admin memeriksa teks, penerima tersamarkan, status pratinjau, dan panjang
   pesan.
4. Admin menekan **Kirim ke WhatsApp** dan mengonfirmasi dialog. Server
   membangun ulang pesan dari database, membandingkan fingerprint dengan
   `hash_equals`, memakai token sekali pakai, lalu mengirim tepat satu kali.
5. Bila data berubah, token sudah dipakai, CSRF tidak valid, batas harian
   tercapai, atau provider menolak, pengiriman dihentikan dengan pesan jelas.
   Tidak ada retry otomatis atau pengiriman saat halaman dibuka/disimpan.

Jenis laporan:

- **Ringkasan Pengaduan**: total, enam status aktif, dan tiket dibatalkan
  secara terpisah. Dibatalkan bukan status ketujuh dan tidak dihitung ganda.
- **Kinerja Teknisi**: total penugasan, jumlah teknisi, dan rincian per
  teknisi. Tiket dibatalkan dikecualikan dari beban/kinerja teknisi.
- **Rekap Bulanan**: total, selesai, belum selesai, ditolak, dibatalkan, dan
  kategori teratas. Hitungan selesai/ditolak memakai data non-cancelled.

Periode memakai filter analitik yang sudah ada: hari ini, 7 hari, bulan ini,
bulan lalu, tahun ini, semua waktu, dan pilih tanggal. Daftar panjang
diringkas eksplisit dengan catatan “dan N lainnya”; pesan di atas 4.000
karakter ditolak dengan peringatan, bukan dipotong diam-diam.

Status `accepted` berarti **permintaan diterima provider**, bukan sudah
dibaca/delivered. Status `delivered/read` hanya boleh berasal dari webhook
provider yang terverifikasi.

### 26.2 Perubahan berkas utama

- `app/Services/WhatsAppReportService.php` (baru): normalisasi periode,
  validasi koneksi/penerima, fingerprint pratinjau, token sekali pakai,
  builder pesan, batas harian, riwayat, audit, dan error ramah.
- `app/Views/admin/whatsapp/laporan.php`: form manual, pratinjau, tombol
  kirim, status terakhir, riwayat, pengaturan penerima/batas harian.
- `app/Controllers/AdminWhatsAppController.php`: data halaman, simpan
  pengaturan, dan aksi kirim.
- `app/Services/WhatsAppSettingsService.php`: penerima berwenang, batas
  harian, jenis laporan teks tetap, dan status fitur tanpa approval/scheduler.
- `app/Services/WhatsAppMessagingService.php`: pengiriman langsung dengan
  koneksi per-request dan opsi bypass aman khusus laporan manual.
- `app/Services/ApiCredentialService.php`: metadata `is_active` pada koneksi
  internal agar koneksi terpilih tervalidasi tanpa membocorkan secret.
- `app/Models/WhatsAppOutbox.php`: pencatatan `template_key` dan riwayat
  pengiriman laporan.
- `app/Services/ApiFeatureRegistry.php`: default laporan teks tanpa
  schedule/PDF/approval/rating.
- `app/Views/admin/whatsapp/_init.php`, `routes/web.php`,
  `app/bootstrap.php`, `app/Helpers/audit.php`, `public/assets/css/style.css`:
  default view, route simpan/kirim, registrasi service, label audit, dan
  styling scoped.
- Pengujian: `tests/tahap27/laporan-whatsapp.php` (baru),
  `tests/tahap26/whatsapp-navigation.php` (marker halaman disesuaikan),
  `TEST_REPORT.md`.

### 26.3 Hasil pengujian baru

`tests/tahap27/laporan-whatsapp.php` memakai DB uji, mock Fonnte lokal
`127.0.0.1:8097`, dan fixture yang dipulihkan di blok `finally`.

| Pemeriksaan | Hasil |
|-------------|-------|
| Tiga jenis laporan, format teks, tanpa scheduler/approval/rating | **PASS** |
| Penerima berwenang, normalisasi nomor, invalid recipient ditolak | **PASS** |
| Preview memakai data aktual, tanpa request provider, penerima tersamarkan | **PASS** |
| Data berubah setelah preview → stale ditolak, tanpa outbox/request | **PASS** |
| Kirim sukses Fonnte → satu request, outbox `accepted`, isi sama dengan preview | **PASS** |
| Klik/request ulang token yang sama → ditolak, tidak menambah outbox/request | **PASS** |
| Batas harian → tombol kirim hilang, backend menolak, tanpa request | **PASS** |
| Provider gagal → error jelas, tanpa outbox accepted, tanpa retry otomatis | **PASS** |
| CSRF 419, RBAC CS ditolak, token/secret tidak bocor | **PASS** |
| Audit/usage log aman dan data pengaduan tidak berubah | **PASS** |
| **Total suite baru** | **104 PASS, 0 FAIL, 0 SKIP, 0 BLOCKED** |

### 26.4 Regresi penuh

15 suite regresi sebelumnya tetap lulus, ditambah suite baru:

| Kelompok | Hasil |
|----------|-------|
| 15 suite regresi (Tahap 10/12/15/16/17/18/22–27) | **976 PASS, 0 FAIL, 1 SKIP** |
| `tests/tahap27/laporan-whatsapp.php` | **104 PASS, 0 FAIL** |
| **Total** | **1.080 PASS, 0 FAIL, 1 SKIP** |

Pemeriksaan statis:

- `php -l` 236 berkas PHP di `app/`, `routes/`, `public/`, `tests/`, `bin/`,
  `config/`, dan `database/`: **0 syntax error**.
- `node --check public/assets/js/main.js`, `dashboard.js`, `ai.js`:
  **3/3 OK**.
- Tidak ada perubahan skema database, penghapusan tiket/pelanggan/audit,
  penghapusan provider Meta, atau pengaktifan kembali Rating Layanan.

### 26.5 BLOCKED

- Pengiriman live Fonnte/Meta dengan token nyata dan konfirmasi delivery ke
  penerima nyata belum dapat dilakukan; pengujian memakai mock lokal.
- Approval template Authentication Meta dan OTP nyata belum tersedia.
- Pengujian visual pada perangkat fisik/emulasi viewport belum tersedia;
  validasi responsif memakai HTML dan breakpoint CSS yang diuji otomatis.
- Pemeriksaan live pada Tahap 23, 24, dan 25 tetap ditandai `BLOCKED` oleh
  suite masing-masing (bukan kegagalan assertion).

### 26.6 Langkah verifikasi browser

1. Masuk sebagai Admin, buka `/admin/whatsapp/laporan`.
   Pastikan kartu **Kirim Laporan WhatsApp** berstatus `Siap` dan hanya ada
   teks WhatsApp (tanpa PDF/Excel/scheduler/rencana/persetujuan).
2. Isi bagian **Penerima Berwenang & Batas Harian** (format
   `Nama|08xxxxxxxxxx`, satu per baris), simpan, lalu pastikan tidak ada pesan
   yang terkirim dan penerima tampil tersamarkan.
3. Pilih koneksi aktif, jenis laporan, periode, dan penerima. Tekan
   **Preview Laporan**; periksa total/status/kategori, panjang pesan, dan
   pastikan provider belum dihubungi.
4. Tekan **Kirim ke WhatsApp** dan konfirmasi. Pastikan muncul status
   **diterima provider**, bukan “dibaca/delivered”, dan riwayat bertambah satu.
5. Tekan kembali/kirim ulang token lama; pastikan muncul pesan pratinjau sudah
   digunakan dan riwayat tidak bertambah.
6. Buka pratinjau, ubah status tiket di tab lain, lalu kirim; pastikan muncul
   pesan data laporan berubah dan tidak ada pesan keluar.
7. Set batas harian ke jumlah pemakaian saat ini; buka pratinjau baru dan
   pastikan tombol kirim tidak tersedia serta request langsung ditolak.
8. Uji dark/light mode, sidebar mobile, breadcrumb, submenu WhatsApp, dan
   widget AI mengambang. Pastikan layout responsif tetap utuh.

---

## 27. Notifikasi WhatsApp Otomatis Setelah Commit

Tanggal: 6 November 2026

### 27.1 Ruang lingkup

Notifikasi WhatsApp untuk delapan event tiket kini dipicu otomatis dari
backend setelah perubahan tiket berhasil di-commit. Admin tidak perlu membuka
halaman Notifikasi atau menekan **Proses Antrean Sekarang**. OTP, lupa
password, ganti nomor, AI Chatbot, laporan manual, dan alur status pengaduan
tidak diubah.

Event yang didukung tetap delapan event yang sudah ada: pengaduan baru,
konfirmasi tiket, verifikasi, penolakan, penugasan teknisi, mulai penanganan,
tiket selesai, dan pembatalan. Rating Layanan tetap nonaktif.

### 27.2 Berkas yang diubah

- `app/Services/WhatsAppNotificationService.php`: identitas kejadian unik,
  pemrosesan otomatis setelah commit, pemeriksaan event aktif saat kirim, dan
  retry oportunistik.
- `app/Services/WhatsAppMessagingService.php`: pemrosesan antrean dengan
  timeout pendek, penolakan fitur nonaktif, dan pelewatan event yang sudah
  dinonaktifkan tanpa meninggalkan pending permanen.
- `app/Models/WhatsAppOutbox.php`: status akhir untuk event yang dilewati.
- `app/Services/PengaduanService.php` dan
  `app/Services/PengaduanFlowService.php`: pemanggilan pengiriman otomatis
  tepat setelah `commit()` dan occurrence unik per penugasan/transisi.
- `app/bootstrap.php`: retry otomatis baris pending yang sudah jatuh tempo pada
  akhir request web.
- `app/Controllers/AdminWhatsAppController.php` dan
  `app/Views/admin/whatsapp/notifikasi.php`: teks status otomatis dan tombol
  proses sebagai pemulihan opsional, bukan langkah wajib.
- `tests/tahap28/notifikasi-otomatis.php`: suite fokus baru.
- `TEST_REPORT.md`: bagian hasil ini.

### 27.3 Mekanisme otomasi

Mekanisme yang dipakai adalah **antrean transaksional + pengiriman otomatis
setelah commit**:

1. Di dalam transaksi tiket, service hanya menulis satu baris `whatsapp_outbox`
   dengan `event_key` unik. Tidak ada panggilan HTTP sebelum commit.
2. Setelah `commit()` berhasil, service langsung memproses baris yang jatuh
   tempo. Timeout pengiriman otomatis dibatasi 5 detik.
3. Kegagalan provider ditangkap; status tiket yang sudah commit tidak berubah.
4. Baris gagal yang masih boleh retry menjadi `pending` dengan waktu tunggu.
   Retry diproses otomatis pada request web berikutnya. Untuk host tanpa
   trafik, worker tetap tersedia: `php bin/whatsapp-worker.php --loop=30`.
5. Tombol **Proses Antrean Sekarang** dipertahankan hanya sebagai pemulihan
   manual opsional.

Status yang disimpan adalah `accepted` setelah provider menerima request.
Status `delivered`/`read` hanya ditulis dari webhook provider terverifikasi.

### 27.4 Pencegahan duplikasi

`event_key` dibentuk dari event, tiket, hash penerima, dan identitas kejadian
(created, status transisi, atau ID penugasan). Constraint UNIQUE pada
`event_key` dan klaim baris `pending → processing` yang bersyarat mencegah
pesan ganda saat refresh, klik ganda, request paralel, atau retry. Request
verifikasi ganda juga ditolak oleh transisi status tiket yang sudah ada.

### 27.5 Perilaku saat provider gagal

- Tiket tetap tersimpan dan statusnya tetap berubah sesuai aksi yang sah.
- Outbox mencatat `failed` atau `pending` beserta kode error yang aman.
- Tidak ada status `delivered` palsu.
- Tidak ada token, OTP, password, atau isi rahasia pada log/tampilan.
- Bila event dinonaktifkan setelah baris masuk antrean, baris tidak dikirim
  dan tidak dibiarkan pending permanen.

### 27.6 Hasil pengujian

| Pemeriksaan | Hasil |
|-------------|-------|
| A. Pelanggan membuat tiket → notifikasi otomatis | **PASS** |
| B. CS memverifikasi tiket → notifikasi otomatis | **PASS** |
| C. CS menugaskan teknisi → notifikasi otomatis | **PASS** |
| D. Teknisi mulai penanganan → notifikasi otomatis | **PASS** |
| E. Teknisi menyelesaikan tiket → notifikasi otomatis | **PASS** |
| F. Notifikasi nonaktif → tidak ada pesan | **PASS** |
| G. Provider gagal → tiket tetap tersimpan | **PASS** |
| H. Request ganda → tidak ada pesan duplikat | **PASS** |
| I. Tidak perlu tombol Proses Antrean | **PASS** |
| J. Pending retry diproses otomatis pada request berikutnya | **PASS** |
| Status hanya `accepted` sampai webhook valid | **PASS** |
| **Total suite baru** | **40 PASS, 0 FAIL, 0 SKIP, 0 BLOCKED** |

Regresi penuh 15 suite sebelumnya: **976 PASS, 0 FAIL, 1 SKIP**. Ditambah
`tests/tahap27/laporan-whatsapp.php` **104 PASS** dan suite baru **40 PASS**,
total menjadi **1.120 PASS, 0 FAIL, 1 SKIP**. Satu skip tetap pemeriksaan live
AI pada `tests/tahap18/local-ai.php`.

### 27.7 BLOCKED

- Pengiriman live Fonnte/Meta dengan token nyata dan bukti pesan benar-benar
  diterima penerima belum dapat dilakukan; pengujian memakai mock lokal dan
  hanya membuktikan status `accepted`.
- Approval template Authentication Meta dan OTP live tetap belum tersedia.
- Pengujian visual pada perangkat fisik/emulasi viewport tetap terbatas pada
  HTML, CSS breakpoint, dan pemeriksaan otomatis.

### 27.8 Cara menguji melalui browser XAMPP

1. Jalankan MySQL XAMPP, lalu siapkan database uji dan server:
   ```powershell
   $env:DB_NAME='wificare_cdn_test'; $env:APP_BASE_PATH='/'
   C:\xampp\php\php.exe -S 127.0.0.1:8099 -t public public/index.php
   $env:FONNTE_MOCK_LOG="$env:TEMP\wificare_fonnte_mock.log"
   C:\xampp\php\php.exe -S 127.0.0.1:8097 tests\tahap22\mock-fonnte.php
   ```
2. Masuk Admin → **WhatsApp Gateway → Gateway**, aktifkan gateway dengan
   koneksi Fonnte yang sah (untuk lokal gunakan Base URL mock `8097`).
3. Buka **Notifikasi WhatsApp**, aktifkan fitur, pilih koneksi, isi nomor
   operasional, aktifkan event, lalu simpan. Pastikan panel menjelaskan
   pengiriman otomatis dan tombol proses hanya untuk pemulihan.
4. Masuk sebagai Pelanggan, buat pengaduan baru. Tanpa menekan tombol antrean,
   periksa halaman **Notifikasi WhatsApp**: event pengaduan baru dan konfirmasi
   tiket harus segera berstatus **diterima provider**.
5. Masuk CS, verifikasi tiket dan tugaskan teknisi. Masuk Teknisi, mulai
   penanganan lalu kirim laporan perbaikan. Setiap langkah harus menambah satu
   status `accepted` tanpa proses manual.
6. Nonaktifkan notifikasi, buat tiket baru, dan pastikan tidak ada pesan baru.
   Aktifkan kembali, hentikan mock provider, lalu jalankan transisi; tiket harus
   tetap tersimpan dan outbox mencatat kegagalan.
7. Klik aksi yang sama dua kali atau refresh; pastikan tidak ada baris outbox
   baru. Pulihkan provider, tunggu retry berikutnya atau buka halaman Admin;
   baris pending harus diproses otomatis.
8. Uji dark/light mode, sidebar mobile, breadcrumb, submenu WhatsApp, dan widget
   AI mengambang. Untuk bukti delivery nyata, gunakan nomor uji berwenang dan
   pastikan status baru berubah setelah webhook provider valid.
