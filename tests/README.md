# Pengujian — WiFiCare CDN

Repositori ini memiliki dua lapis pengujian:

1. **PHPUnit** untuk aturan bisnis inti (login, RBAC, validasi pengaduan,
   transisi status, penugasan teknisi, dan logika AI lokal) — tidak memanggil
   API AI atau WhatsApp sungguhan.
2. **Harness integrasi HTTP** lama (Tahap 10+) yang membutuhkan server aplikasi
   dan database uji terpisah.

> **Penting:** jangan menunjuk pengujian ke database produksi. Gunakan database
> uji khusus (`wificare_cdn_phpunit` untuk PHPUnit, `wificare_cdn_test` untuk
> harness HTTP).

## PHPUnit — unit & integration test

### Versi dan keamanan

- PHPUnit `9.6` (kompatibel dengan PHP 8.0 lokal dan tetap berjalan pada PHP
  8.2+ yang menjadi target aplikasi).
- Bootstrap `tests/bootstrap.php` **menolak berjalan** bila nama database tidak
  berakhiran `_test` atau `_phpunit`.
- Setiap test integrasi berjalan di dalam transaksi dan selalu di-*rollback*.
  Tidak ada data yang tersimpan permanen.
- Tidak ada panggilan API AI, Ollama, WhatsApp, atau provider eksternal.

### Persiapan

```powershell
# 1) Dependensi development (vendor tidak di-commit)
# Jika Composer belum tersedia, unduh composer.phar dari getcomposer.org lalu:
C:\xampp\php\php.exe composer.phar install
# Jika Composer tersedia di PATH:
composer install

# Catatan: bila memakai PHP 8.0 (XAMPP saat ini) sedangkan composer.json
# menargetkan PHP 8.2+, tambahkan --ignore-platform-req=php. Pada PHP 8.2+
# jalankan tanpa flag tersebut.
C:\xampp\php\php.exe composer.phar install --ignore-platform-req=php

# 2) Buat database uji terpisah (JANGAN gunakan database aktif)
$env:DB_NAME='wificare_cdn_phpunit'
$env:APP_BASE_PATH=''
C:\xampp\php\php.exe bin\migrate.php
```

Jika MySQL memakai password, isi lewat environment sebelum menjalankan
`bin\migrate.php`/PHPUnit (jangan menulis password ke repository):

```powershell
$env:DB_USER='root'
$env:DB_PASSWORD='<PASSWORD_DATABASE_UJI>'
```

### Menjalankan

```powershell
# Semua suite (Unit + Integration)
C:\xampp\php\php.exe vendor\phpunit\phpunit\phpunit

# Hanya unit test (tanpa database)
C:\xampp\php\php.exe vendor\phpunit\phpunit\phpunit --testsuite Unit

# Hanya integration test (database uji terpisah)
C:\xampp\php\php.exe vendor\phpunit\phpunit\phpunit --testsuite Integration

# Alternatif melalui Composer
composer test
```

Nama database uji dapat diganti selama berakhiran `_test` atau `_phpunit`:

```powershell
$env:PHPUNIT_DB_NAME='wificare_cdn_phpunit'
```

### Cakupan

| Suite | Berkas | Aturan yang diuji |
|---|---|---|
| Unit | `tests/Unit/AuthRulesTest.php` | Validasi input login, peran kanonik, URL dashboard |
| Unit | `tests/Unit/StatusTransitionTest.php` | Transisi status CS/teknisi, status aktif/akhir, batas teks |
| Unit | `tests/Unit/LocalAiTest.php` | Normalisasi, deteksi intent, penolakan kredensial/SQL, batas baris |
| Unit | `tests/Unit/AiAccessRulesTest.php` | Allowlist tool AI per peran, tidak ada tool tulis |
| Integration | `tests/Integration/AuthAttemptTest.php` | Login berhasil/gagal/nonaktif, throttle 5 percobaan |
| Integration | `tests/Integration/RoleAccessTest.php` | Peran dibaca dari database, bukan session/input |
| Integration | `tests/Integration/ComplaintValidationTest.php` | Validasi pengaduan, fallback WhatsApp, batas deskripsi |
| Integration | `tests/Integration/AssignmentValidationTest.php` | Teknisi valid/aktif, batas instruksi penugasan |

Hasil terakhir pada PHP 8.0.30 + PHPUnit 9.6.39:

```text
OK (57 tests, 274 assertions)
```

## Harness integrasi HTTP (lama)


| Berkas | Cakupan | Jumlah |
|--------|---------|-------:|
| `tahap10/e2e.php` | Skenario A–E, RBAC, CSRF, alur tiket, rating, laporan, ekspor, AI | 79 |
| `tahap10/security.php` | XSS, unggahan, transisi ilegal, IDOR, ketahanan route | 34 |
| `tahap10/auth.php` | Throttle, akun nonaktif, invalidasi sesi, anti-fixation | 15 |
| `tahap10/ai-widget.php` | Floating AI widget: menu hilang, widget di semua peran, CSRF, pesan konfigurasi, tidak ada kunci bocor | 81 (+1 skip) |
| `tahap10/ai-gemini.php` | **Live Google Gemini** via Floating AI: 3 pertanyaan nyata, proteksi endpoint, tidak ada kunci bocor | 19 |
| `tahap10/ai-gemini-resilience.php` | Ketahanan Gemini: retry + fallback model via mock lokal (tanpa internet/kunci) | 23 |
| `tahap10/ai-read-tools.php` | **WiFiCare AI read-only**: tolak coding, function calling via mock, argumen kosong, RBAC tool, tanpa tool tulis, baca ter-scope | 26 |
| `tahap10/mock-gemini.php` | Mock Google Gemini untuk `ai-gemini-resilience.php` & `ai-read-tools.php` (bukan bagian aplikasi) | — |
| `tahap10/ai-live.php` | Alur AI via mock provider kompatibel OpenAI (butuh mock 8097 + server AI aktif) | 12 |
| `tahap10/login-layout.php` | Login full-screen: tanpa sidebar/navbar, isi form, CSRF, redirect per peran, dashboard utuh | 43 |
| `tahap15/ai-platform.php` | Platform AI: multi-provider, key per provider, tes koneksi, monitoring, batas harian, clear chat, FAQ CRUD, RBAC | 66 (+2 blocked) |
| `tahap15/ai-tools-revisi.php` | Tool baca-only: statistik tanggal, cakupan Admin/CS/Teknisi/Pelanggan, profil/riwayat/rating, allowlist | 29 |
| `tahap15/ai-live-roles.php` | Live Gemini per peran (butuh kredensial + kuota) | 4 (+4 blocked) |
| `tahap18/local-ai.php` | Hybrid AI + database lokal: jumlah/daftar pelanggan, karyawan, pengaduan, RBAC, audit, Ollama opsional | 135 (+1 skip) |
| `tahap19/local-first.php` | Local-first, intent pelanggan/teknisi, konteks, RBAC, timeout, MySQL gagal, monitoring sumber | 25 |
| `tahap21/profile-phone-lock.php` | Profil pelanggan: nama tetap dapat diubah, nomor WhatsApp terkunci, bypass/CSRF/RBAC ditolak, audit tanpa nomor | 20 |
| `tahap21/complaint-phone-snapshot.php` | Buat Pengaduan: kontak tiket adalah snapshot, nomor utama tidak berubah, fallback nomor utama, manipulasi ditolak, detail/riwayat tetap jalan | 25 |
| `tahap19/mock-timeout.php` | Provider mock lambat untuk verifikasi timeout cloud tanpa kredensial | — |

## Menyiapkan database uji

```powershell
$env:DB_NAME='wificare_cdn_test'
$env:APP_BASE_PATH=''
C:\xampp\php\php.exe bin\migrate.php
C:\xampp\php\php.exe bin\create-user.php --role=admin --username=<USERNAME_ADMIN_UJI> --nama="Admin Uji" --password="<PASSWORD_UJI_KUAT>"

# Password akun sampel wajib diberikan lewat environment (tanpa fallback).
$env:T10_PW_CS='<PASSWORD_CS_UJI>'
$env:T10_PW_TEK='<PASSWORD_TEK_UJI>'
$env:T10_PW_PEL='<PASSWORD_PEL_UJI>'
C:\xampp\php\php.exe bin\seed-sample.php
```

Password akun uji **tidak boleh** ditulis di repository. Tetapkan password kuat
sendiri saat membuat akun, lalu berikan ke harness hanya melalui environment
sesi pengujian (`T10_PW_ADMIN`, `T10_PW_CS`, `T10_PW_TEK`, `T10_PW_PEL`).
Jangan memakai password akun produksi atau password sampel yang pernah
digunakan pada database nyata. Bila variabel belum diisi, harness akan berhenti
dengan status **BLOCKED** dan tidak menjalankan pengujian login.

`bin/seed-sample.php` hanya untuk database uji dan hanya membuat akun yang belum
ada. Perlakukan akun yang dibuatnya sebagai akun sekali pakai dan jangan
dipakai pada database aktif. Skrip tidak mencetak password.

> **Catatan rotasi:** menghapus password dari repository **tidak** mengubah
> password akun yang sudah ada. Jika akun uji atau akun nyata pernah memakai
> password sampel lama, rotasi password tersebut secara manual melalui prosedur
> admin. Jangan menjalankan rotasi otomatis dari skrip pengujian.

Fixture pengujian memakai nama dan nomor telepon sintetis (mis. `0812...` /
`0813...`). Jangan menggantinya dengan data pelanggan nyata.

## Menjalankan server uji

```powershell
$env:DB_NAME='wificare_cdn_test'
$env:APP_BASE_PATH=''
C:\xampp\php\php.exe -S localhost:8099 -t public public/index.php
```

## Menjalankan pengujian

```powershell
C:\xampp\php\php.exe tests\tahap10\e2e.php
C:\xampp\php\php.exe tests\tahap10\security.php
C:\xampp\php\php.exe tests\tahap10\auth.php
C:\xampp\php\php.exe tests\tahap10\ai-widget.php
C:\xampp\php\php.exe tests\tahap10\login-layout.php
```

### Uji WiFiCare AI read-only (tanpa internet + tanpa kunci)

Menguji aturan produk dan *function calling* memakai mock Gemini lokal (server
mock dijalankan otomatis pada port 8098):

```powershell
C:\xampp\php\php.exe tests\tahap10\ai-read-tools.php
```

Harness membuktikan: permintaan coding ditolak dengan kalimat tetap (tanpa
memanggil provider), function calling Gemini berjalan (functionCall → tool
read-only → jawaban), argumen kosong dikodekan `{}` (bukan `[]`), tool di luar
hak peran ditolak, allowlist tidak memuat tool tulis, dan pembacaan data
ter-scope sesuai peran.

> **Catatan Tahap 19:** harness ini adalah harness lama dan saat ini **FAIL**
> (16 PASS, 10 FAIL) karena masih menghardcode ID pengguna serta mengharapkan
> tool-loop cloud untuk pertanyaan data yang sekarang dijawab local-first.
> Kegagalan dicatat terbuka; jalur produksi tidak menggunakan harness ini
> sebagai gerbang. Gunakan `tahap19/local-first.php` untuk verifikasi perilaku
> baru.

### Uji live Google Gemini (butuh internet + kunci)

Jalankan server uji seperti biasa (`.env` sudah berisi `GEMINI_API_KEY`), lalu:

```powershell
C:\xampp\php\php.exe tests\tahap10\ai-gemini.php
```

Harness mengirim tiga pertanyaan nyata ke endpoint `/ai/chat` dan memastikan
jawaban berasal dari Gemini (bukan jawaban palsu). Bila provider belum
dikonfigurasi, harness menandai **SKIP** (bukan FAIL).

### Uji AI via mock provider (opsional, kompatibel OpenAI)

Untuk menguji jalur OpenAI-compatible tanpa memanggil API eksternal, arahkan
server aplikasi ke mock provider. Karena `.env` menyetel `AI_PROVIDER=gemini`,
**paksa** `AI_PROVIDER=openai` di environment server:

```powershell
# 1) mock provider kompatibel OpenAI (port 8097) — skrip dev terpisah
C:\xampp\php\php.exe -S localhost:8097 "<temp>\mock-ai.php"

# 2) server aplikasi diarahkan ke mock (port 8100)
$env:DB_NAME='wificare_cdn_test'
$env:AI_PROVIDER='openai'; $env:AI_API_KEY='test-key-123'; $env:AI_MODEL='mock-model'
$env:AI_API_BASE_URL='http://localhost:8097/v1'; $env:AI_TIMEOUT='3'
C:\xampp\php\php.exe -S localhost:8100 -t public public/index.php

# 3) jalankan harness
$env:T10_BASE='http://localhost:8100'
C:\xampp\php\php.exe tests\tahap10\ai-live.php
```

## Konfigurasi (environment)

Lihat `tahap10/_config.php`. Variabel berikut dapat ditimpa; password akun uji
**wajib diisi** untuk harness yang melakukan login (tidak ada nilai bawaan):

  Variabel   Default
 ---------- ---------
  `T10_BASE`   `http://localhost:8099`
  `T10_DB_DSN`   `mysql:host=127.0.0.1;port=3306;dbname=wificare_cdn_test;charset=utf8mb4`
  `T10_DB_USER`   `root`
  `T10_DB_PASS`   (kosong)
  `T10_PW_ADMIN` / `T10_PW_CS` / `T10_PW_TEK` / `T10_PW_PEL`   **wajib** untuk harness login; isi password akun uji lokal (tanpa default, jangan dari database produksi)

## Integrasi AI

Provider AI utama adalah **Google Gemini** (`generateContent`), dipanggil dari
backend via cURL dengan header `x-goog-api-key`. Kunci API hanya dibaca di sisi
server (`GEMINI_API_KEY`) dan tidak pernah dikirim ke browser, HTML, atau log.

- **Live Gemini**: `tests/tahap10/ai-gemini.php` — mengirim tiga pertanyaan nyata
  dan memverifikasi jawaban asli Gemini, proteksi endpoint (401/419), dan tidak
  ada kebocoran kunci. Bila belum dikonfigurasi → **SKIP**.
- **Ketahanan Gemini** (tanpa internet/kunci): `tests/tahap10/ai-gemini-resilience.php`
  — memakai mock lokal (`mock-gemini.php`) untuk menguji retry (`AI_MAX_RETRIES`)
  dan fallback model (`GEMINI_MODEL_FALLBACK`). Server mock dijalankan otomatis.
- **WiFiCare AI read-only** (tanpa internet/kunci): `tests/tahap10/ai-read-tools.php`
  — memakai mock lokal (`mock-gemini.php`) untuk menguji penolakan permintaan
  coding, *function calling* (functionCall → tool read-only → jawaban), argumen
  kosong (`{}`), pembatasan tool per peran (RBAC), dan tidak adanya tool tulis.
- **Mock provider (kompatibel OpenAI)**: `tests/tahap10/ai-live.php` — opsional,
  memerlukan mock provider terpisah dan `AI_PROVIDER=openai` pada server uji.

## Arsitektur WiFiCare AI (read-only)

```
Floating AI Chat  →  AiController (/ai/chat)
                  →  AiAssistantService (provider Gemini + function calling)
                  →  AiToolService  (Authorization & Tool Service; allowlist + RBAC)
                  →  AiReadService  (fungsi baca terkontrol)
                  →  PDO SELECT     (Database::readPdo(), opsional akun read-only)
                  →  data terfilter → Gemini → jawaban
```

- Model AI **tidak pernah** menerima kredensial MySQL dan tidak pernah menulis
  SQL. Semua fungsi baca memakai prepared statement dan hanya `SELECT`.
- Tidak ada tool `INSERT/UPDATE/DELETE/DROP/ALTER` maupun eksekusi perintah.
- Pembatasan data sesuai peran: pelanggan (tiket miliknya), teknisi (tugasnya),
  CS (seluruh tiket), Admin (seluruh tiket + kinerja teknisi + jumlah pelanggan).
- Opsional: buat akun MySQL dengan izin `SELECT` saja lalu isi `DB_RO_USER` /
  `DB_RO_PASSWORD` agar pembacaan AI terisolasi. Bila kosong, dipakai koneksi
  utama (query tetap SELECT).

Hasil lengkap: lihat `TEST_REPORT.md`.

## Tahap 11 — Pengaturan AI Chatbot

Dua harness baru (jalankan terhadap DB uji `wificare_cdn_test`):

```powershell
# Server uji untuk harness HTTP (port 8099)
$env:DB_NAME='wificare_cdn_test'; $env:APP_ENV='testing'
C:\xampp\php\php.exe -S localhost:8099 -t public public/index.php

# 1) Uji unit CLI: enkripsi API key (libsodium) + mode read-only
$env:DB_NAME='wificare_cdn_test'
C:\xampp\php\php.exe tests\tahap11\crypto.php

# 1b) Uji prioritas konfigurasi (DB vs .env, legacy openai)
C:\xampp\php\php.exe tests\tahap11\config-priority.php

# 2) Uji HTTP 19 poin: RBAC, simpan, enkripsi, tes koneksi, audit
$env:T10_BASE='http://localhost:8099'
C:\xampp\php\php.exe tests\tahap11\ai-settings.php
```

- `crypto.php` (19 assertion): roundtrip enkripsi/dekripsi, nonce acak, format
  `v1:`, kegagalan aman (master key salah), dan allowlist tool tanpa tulis.
- `config-priority.php` (17 assertion): tanpa baris `ai_settings` perilaku `.env`
  (termasuk provider `openai`) dipertahankan; baris Nonaktif mengabaikan `.env`;
  key tersimpan menang atas `.env`.
- `ai-settings.php` (67 assertion): admin-only (CS/teknisi/pelanggan 403),
  simpan API key terenkripsi, key lama dipertahankan saat field kosong, tidak ada
  kebocoran key di HTML/JSON/audit, tes koneksi API sungguhan, nonaktif/aktif,
  ganti model, dan CSRF 419.

Harness `ai-settings.php` membersihkan baris `ai_settings` di awal & akhir agar
harness lain tetap deterministik. Master key enkripsi dibaca dari `.env`
(`AI_SETTINGS_KEY`, buat dengan `php bin/ai-keygen.php`); libsodium harus aktif.

## Tahap 17 — Pusat API & WhatsApp

Harness ini memakai **mock WhatsApp Cloud API** lokal dan server aplikasi DB
uji. Mock tidak menggantikan verifikasi live, tetapi menguji kontrak HTTP,
signature, status, dan antrean secara nyata.

```powershell
# Terminal 1 — mock provider WhatsApp (port 8098)
$env:WA_MOCK_LOG="$env:TEMP\wificare_wa_mock.log"
C:\xampp\php\php.exe -S 127.0.0.1:8098 tests\tahap17\mock-whatsapp.php

# Terminal 2 — server aplikasi DB uji (port 8099)
$env:DB_NAME='wificare_cdn_test'; $env:APP_ENV='testing'
C:\xampp\php\php.exe -S localhost:8099 -t public public/index.php

# Terminal 3 — jalankan harness
$env:WA_MOCK_LOG="$env:TEMP\wificare_wa_mock.log"
C:\xampp\php\php.exe tests\tahap17\api-center.php
```

Cakupan `api-center.php` (94 assertion):

- enkripsi ciphertext `v1:`, penolakan saat master key hilang;
- blank-preserve, replace token, aktivasi, revoke, tes koneksi mock;
- provider allowlist dan penolakan base URL HTTP non-localhost;
- OTP: requested/sent/verified/expired/rate-limited, single-use, purpose,
  nomor, max attempts, batas akun/nomor;
- notifikasi: hook tiket, deduplikasi, accepted ≠ delivered, webhook signature,
  webhook duplikat, kegagalan provider, isolasi fitur;
- monitoring nyata + filter, audit tanpa secret;
- RBAC (CS/Teknisi 403), CSRF 419, secret tidak ada di HTML;
- menu unik, tema, floating AI tetap ada.

Worker antrean produksi:

```powershell
$env:DB_NAME='wificare_cdn_v1'
C:\xampp\php\php.exe bin\whatsapp-worker.php --limit=20
C:\xampp\php\php.exe bin\whatsapp-worker.php --loop=30
```

Catatan: jangan menjalankan harness ini terhadap DB utama. Harness menghapus
hanya tabel baru Tahap 17 di `wificare_cdn_test` dan tidak menyentuh data
pengaduan/pengguna lama.

## Tahap 18 — Hybrid AI & Database Lokal

Harness ini menguji jawaban database lokal deterministik, routing hybrid,
pengaturan Admin, privasi, dan audit metadata. Jalankan terhadap server DB uji
`:8099`.

```powershell
# Terminal 1 — server aplikasi DB uji
$env:DB_NAME='wificare_cdn_test'; $env:APP_ENV='testing'
C:\xampp\php\php.exe -S localhost:8099 -t public public/index.php

# Terminal 2 — harness
$env:DB_NAME='wificare_cdn_test'; $env:APP_ENV='testing'
$env:T10_BASE='http://localhost:8099'
C:\xampp\php\php.exe tests\tahap18\local-ai.php
```

Cakupan `local-ai.php` (135 assertion + 1 SKIP):

- jumlah/data karyawan sesuai DB; hanya `admin`/`cs`/`teknisi`; pelanggan tidak
  dihitung sebagai karyawan;
- pencarian/detail pelanggan, follow-up PPPoE, kontak lengkap Admin vs tersamar
  untuk CS;
- akurasi pengaduan, filter periode, statistik selesai, tanpa duplikasi;
- fallback lokal tanpa API key; kejujuran saat DB gagal dan intent tak didukung;
- RBAC/prompt injection; tool baca luas ditolak untuk peran yang tidak berwenang;
- tidak ada SQL/tool tulis, password hash, password/PPPoE plaintext, API key,
  token, session ID, master key, atau kredensial MySQL pada jawaban;
- pengaturan hybrid/Ollama lewat HTTP, tes MySQL read-only nyata, status tes,
  CSRF, dan RBAC Admin;
- Ollama live hanya diuji bila runtime tersedia; jika tidak, **SKIP** (bukan
  PASS). Jangan mengunduh model tanpa persetujuan pengguna.

Catatan: `tests/tahap18/local-ai.php` memakai dan membersihkan konfigurasi
`ai_settings`/`ai_provider_keys` khusus uji. Jalankan hanya terhadap
`wificare_cdn_test`.

## Tahap 19 - Local-First, Intent, dan Monitoring Sumber

Harness ini memverifikasi enam koreksi final terhadap server aplikasi DB uji
`:8099`. Timeout cloud diuji dengan provider mock lambat, bukan kredensial
nyata.

```powershell
# Terminal 1 - server aplikasi DB uji
$env:DB_NAME='wificare_cdn_test'; $env:APP_ENV='testing'
C:\xampp\php\php.exe -S localhost:8099 -t public public/index.php

# Terminal 2 - harness wajib (25 pemeriksaan)
$env:DB_NAME='wificare_cdn_test'; $env:APP_ENV='testing'
$env:T10_BASE='http://localhost:8099'
C:\xampp\php\php.exe tests\tahap19\local-first.php

# Mock timeout deterministik (dijalankan oleh harness terkait)
C:\xampp\php\php.exe tests\tahap19\mock-timeout.php
```

Cakupan `local-first.php`:

- **T01-T04:** jumlah pelanggan, daftar pelanggan, pencarian pelanggan, serta
  pemisahan aktif/nonaktif. "Pelanggan siapa aja" tidak boleh menjadi
  pencarian nama.
- **T05-T14:** jumlah/daftar teknisi, peringkat penugasan, tiket selesai,
  tugas aktif, tie, teknisi 0 penugasan, dan jumlah karyawan tanpa pelanggan.
- **T15-T19:** local-first tanpa cloud, semua intent data dijawab lokal,
  intent tak dikenal jujur, timeout cloud terpisah, dan cloud tidak dipanggil
  setelah lokal berhasil.
- **T20:** kegagalan MySQL menghasilkan pesan khusus dan pulih setelah DB
  kembali.
- **T21-T24:** penolakan permintaan kredensial/API key, penolakan SQL bebas
  tanpa perubahan data, RBAC CS untuk peringkat teknisi, dan RBAC teknisi untuk
  daftar pelanggan.
- **T25:** pencatatan sumber jawaban dan audit metadata tanpa data sensitif.

Hasil terakhir: **TOTAL 25 | PASS 25 | FAIL 0 | BLOCKED 0**.

Harness regresi lama yang masih menghardcode dataset dapat FAIL dan tidak
diubah menjadi PASS palsu:

- `tahap10/ai-read-tools.php` - 16 PASS, 10 FAIL karena local-first
  menggantikan tool-loop cloud untuk intent data.
- `tahap13/keamanan.php` - akun/foto hardcode pada Apache/DB utama.
- `tahap16/identity.php` - 17 assertion unit hardcode; alur identitas HTTP
  per peran PASS.

Live Ollama/Gemini/DeepSeek/OpenAI dan WhatsApp Cloud API tetap **BLOCKED**
tanpa runtime/kredensial nyata. Jangan menjalankan harness terhadap database
produksi.