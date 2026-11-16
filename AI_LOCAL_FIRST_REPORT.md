# AI Local-First Report - WiFiCare CDN

Tanggal verifikasi: 26 Oktober 2026

Status: **selesai** untuk enam koreksi Tahap 19. Seluruh pengujian wajib
Tahap 19 **PASS**. Beberapa verifikasi live tetap **BLOCKED** karena tidak ada
runtime/kredensial nyata; harness lama yang masih menghardcode dataset juga
dilaporkan **FAIL** tanpa diklaim lulus.

## 1. Tujuan

Tahap 19 memperbaiki perilaku WiFiCare AI tanpa membangun ulang aplikasi dan
tanpa mengubah alur bisnis pengaduan:

- jawaban data yang didukung selalu dijawab dari database lokal lebih dulu;
- intent pelanggan/teknisi tidak tertukar antara jumlah, daftar, dan pencarian;
- jawaban sederhana ringkas 1-2 kalimat, tanpa langkah troubleshooting yang
  tidak diminta;
- konteks percakapan aman, terbatas, dan tidak bocor antar sesi/peran;
- RBAC dan read-only tetap berlaku pada jalur lokal maupun cloud;
- kegagalan cloud, MySQL, intent tidak dikenal, dan akses ditolak dibedakan
  dengan jujur;
- monitoring mencatat sumber jawaban tanpa menyimpan isi percakapan.

## 2. Arsitektur local-first

Urutan yang berlaku:

1. Identitas dari session/DB dan penolakan permintaan coding.
2. `LocalDatabaseAssistant` untuk intent data yang dikenali.
3. Bila lokal berhasil, jawaban langsung dikembalikan dan **provider cloud
   tidak dipanggil**.
4. Cloud hanya menerima pertanyaan yang tidak dikenali asisten lokal.
5. Ollama lokal opsional hanya jika Admin mengaktifkannya.
6. FAQ lokal hanya untuk pertanyaan non-database yang dikenali.
7. Bila tidak ada jalur yang tepat, pengguna mendapat pesan keterbatasan yang
   jujur, bukan angka atau identitas karangan.

Cloud tidak pernah menerima kredensial MySQL, tidak pernah mengeksekusi SQL,
dan tidak pernah diminta membuat query. Semua data operasional dibaca oleh
service PHP dengan `SELECT` prepared statement.

## 3. Routing intent yang diperbaiki

| Pertanyaan | Intent | Perilaku |
|---|---|---|
| "Berapa jumlah pelanggan?" | `customers.count` | Menghitung pelanggan, bukan mencari nama. |
| "Pelanggan siapa aja?" | `customers.list` | Menampilkan daftar pelanggan, bukan mencari frasa "siapa aja". |
| "Siapa pelanggan Rina?" | `customers.search` | Mencari nama pelanggan tersebut. |
| "Berapa teknisi?" | `technicians.count` | Menghitung akun teknisi. |
| "Teknisi siapa aja?" | `technicians.list` | Menampilkan akun teknisi. |
| "Teknisi kerjaannya banyak siapa?" | `technicians.most_assignments` | Peringkat penugasan, bukan jumlah akun. |
| "Teknisi kerjaannya paling sedikit?" | `technicians.least_assignments` | Peringkat terbawah, termasuk tie dan teknisi 0 penugasan. |
| "Siapa yang paling banyak menyelesaikan tiket?" | `technicians.most_completed` | Tiket selesai, bukan akun. |
| "Teknisi yang belum pernah dapat tugas?" | `technicians.never_assigned` | Teknisi tanpa penugasan. |
| "Berapa tugas aktif teknisi?" | `technicians.most_active` | Tugas aktif, tie, dan status tugas dibedakan. |
| "Berapa karyawan?" | `employees.count` | Hanya `admin`, `cs`, `teknisi`; pelanggan tidak dihitung. |
| "Bagaimana status pengaduan saya?" | `personal.tickets` | Tiket milik pengguna yang sedang login. |

Kata **banyak** diperlakukan sebagai petunjuk peringkat/daftar, bukan sebagai
perintah hitung. Hanya `berapa`, `jumlah`, `total`, dan `hitung` yang memicu
intent jumlah. Pengaduan yang bersifat penjelasan (misalnya "mengapa
pengaduan saya lama?") tidak dipaksa menjadi jawaban COUNT.

## 4. Ringkas, tidak bertele-tele

Jawaban sederhana dibuat 1-2 kalimat. Daftar/peringkat memakai tabel atau
kartu terstruktur dengan baris terbatas. AI tidak menambahkan langkah
troubleshooting, daftar saran, atau kesimpulan tambahan bila pengguna hanya
bertanya fakta.

Contoh pola jawaban:

- "Ada 6 pelanggan aktif dan 2 pelanggan nonaktif."
- "Pelanggan: Rina Marlina, Agus Setiawan."
- "Peringkat penugasan teknisi: ..."
- "Data belum dapat diakses saat ini. Data aplikasi tidak dapat dibaca tanpa
  koneksi database."

## 5. Konteks percakapan

- Konteks disimpan per pengguna/sesi di `AiContext`, bukan global.
- Jumlah entitas dibatasi dan hanya berisi referensi aman (misalnya ID/nomor
  tiket yang sudah lolos RBAC), bukan data sensitif mentah.
- Setiap permintaan memvalidasi ulang identitas dan peran dari sesi/DB.
- Konteks dibersihkan saat logout, berganti akun, mengganti dataset, atau
  meminta daftar baru. Dengan demikian "pelanggan siapa aja" tidak memakai
  konteks pencarian nama sebelumnya.
- Pertanyaan ambigu meminta klarifikasi; sistem tidak menebak entitas.

## 6. RBAC dan keamanan

- **Read-only:** semua query operasional memakai `SELECT`, prepared statement,
  placeholder unik, filter/urutan allowlist, dan batas hasil.
- **RBAC berlapis:** pemeriksaan ada di service baca, tool service, dan jalur
  lokal. CS tidak boleh membaca peringkat teknisi; teknisi tidak boleh membaca
  daftar pelanggan; pelanggan hanya melihat tiketnya sendiri.
- **Identitas backend:** user/role diambil dari session dan database, bukan dari
  teks chat atau parameter header yang dapat dipalsukan.
- **CSRF:** endpoint chat dan endpoint Admin tetap memerlukan token CSRF.
- **Anti-SQL bebas:** permintaan "jalankan SQL", "DROP TABLE", atau "buat
  query" ditolak sebelum cloud/lokal membaca data.
- **Anti-fabrikasi:** hasil kosong, DB gagal, dan intent tak dikenal tidak
  menghasilkan angka atau nama palsu.
- **Audit metadata-only:** `ai_data_access_log` hanya menyimpan user, role,
  source, intent, dataset, jumlah hasil, sukses, dan waktu. Isi pesan, kata
  pencarian, nomor telepon, alamat, password, token, API key, dan kredensial
  MySQL tidak disimpan.
- **Secret tidak masuk UI/log:** API key tidak dikirim ke browser, HTML, atau
  log; respons tes provider/MySQL tidak memuat ciphertext/secret.

## 7. Klasifikasi kegagalan

| Kondisi | Perilaku |
|---|---|
| Data lokal berhasil | Jawaban lokal; cloud tidak dipanggil. |
| Cloud timeout/network | Pesan timeout/network terpisah; data lokal tetap tersedia untuk intent yang didukung. |
| MySQL gagal | `Data belum dapat diakses saat ini. Data aplikasi tidak dapat dibaca tanpa koneksi database.` |
| Intent tidak dikenal | Pesan bahwa pertanyaan belum dikenali; tidak ada angka karangan. Bila cloud aktif, cloud boleh membantu interpretasi tanpa SQL. |
| Akses ditolak | Pesan RBAC yang jelas; tidak membocorkan data yang dilindungi. |
| Cloud belum dikonfigurasi/dinonaktifkan | Layanan lokal tetap menjawab intent data; pertanyaan umum mendapat pesan belum dikonfigurasi. |
| Fallback dimatikan Admin | Tidak ada pengalihan diam-diam ke provider lain; error cloud dikembalikan. |

Tidak ada perpindahan otomatis ke provider berbayar lain dan tidak ada retry
tanpa batas.

## 8. Monitoring

`ai_usage_log` mencatat metadata request: provider, model, request type,
sukses/gagal, kode error, durasi, dan token bila tersedia. `AiUsage::stats()`
menyediakan agregat bulanan:

- `local_month` - seluruh jawaban lokal;
- `local_database_month` - jawaban dari database lokal;
- `local_faq_month` - jawaban FAQ lokal;
- `cloud_month` / `cloud_ai_month` - request provider cloud;
- `timeout_month` - timeout/network cloud;
- `estimated_cost` tidak dikarang karena tarif provider belum diverifikasi.

`AiDataAccessLog::stats()` menyediakan jumlah akses lokal, akses lewat tool,
kegagalan, dan intent lokal tak dikenal dalam 30 hari. Halaman Admin
`/admin/pengaturan/ai` menampilkan akses data lokal, request cloud, timeout
cloud, intent tak dikenal, serta rincian database/FAQ lokal.

## 9. Bukti pengujian wajib Tahap 19

Perintah:

```powershell
$env:DB_NAME='wificare_cdn_test'; $env:APP_ENV='testing'
$env:T10_BASE='http://localhost:8099'
C:\xampp\php\php.exe tests\tahap19\local-first.php
```

Hasil: **25 PASS, 0 FAIL, 0 BLOCKED**.

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

Timeout cloud diuji deterministik memakai `tests/tahap19/mock-timeout.php`
tanpa kredensial nyata.

## 10. Regresi

| Harness | Status | Hasil |
|---|---|---|
| `tests/tahap18/local-ai.php` | PASS | 135 PASS, 0 FAIL, 1 SKIP (Ollama live) |
| `tests/tahap17/api-center.php` | PASS | 94 PASS, 0 FAIL |
| `tests/tahap10/e2e.php` | PASS | 79 PASS, 0 FAIL |
| `tests/tahap10/ai-widget.php` | PASS | 81 PASS, 0 FAIL, 1 SKIP |
| `tests/tahap10/security.php` | PASS | 34 PASS, 0 FAIL |
| `tests/tahap10/auth.php` | PASS | 15 PASS, 0 FAIL |
| `tests/tahap10/login-layout.php` | PASS | 43 PASS, 0 FAIL |
| `tests/tahap10/ai-gemini-resilience.php` | PASS | 23 PASS, 0 FAIL |
| `tests/tahap10/ai-read-tools.php` | FAIL | 16 PASS, 10 FAIL - harness lama menghardcode ID dan mengharapkan tool-loop cloud untuk pertanyaan yang kini dijawab lokal |
| `tests/tahap11/ai-settings.php` | PASS | 67 PASS, 0 FAIL |
| `tests/tahap11/crypto.php` | PASS | 19 PASS, 0 FAIL |
| `tests/tahap11/config-priority.php` | PASS | 17 PASS, 0 FAIL (isolasi baris provider key diperbaiki) |
| `tests/tahap12/revisi-final.php` | PASS | 86 PASS, 0 FAIL (assertion tanggal memakai field tanggal panjang) |
| `tests/tahap12/role-sidebar.php` | PASS | 28 PASS, 0 FAIL |
| `tests/tahap12/prompt7.php` | PASS | 75 PASS, 0 FAIL |
| `tests/tahap15/ai-platform.php` | PASS | 66 PASS, 0 FAIL, 2 BLOCKED (live provider) |
| `tests/tahap15/ai-tools-revisi.php` | PASS | 29 PASS, 0 FAIL |
| `tests/tahap13/keamanan.php` | FAIL | 25 FAIL pada Apache/DB utama karena akun `admin.budi`/`cs.rizki`/`cdn.romiz` dan foto hardcode tidak cocok dengan dataset saat ini; alur setara di DB uji tetap diuji oleh suite lain |
| `tests/tahap16/identity.php` | FAIL | 44 PASS, 17 FAIL - assertion unit menghardcode ID/nama akun lama; seluruh alur identitas HTTP per peran PASS |
| `tests/tahap10/ai-gemini.php` | BLOCKED | memerlukan internet + kredensial Gemini nyata |
| `tests/tahap10/ai-live.php` | BLOCKED | mock provider OpenAI-compatible 8097 tidak tersedia |
| `tests/tahap15/ai-live-roles.php` | BLOCKED | memerlukan kredensial live per peran |

Tahap 13 dan Tahap 16 tidak diubah menjadi PASS palsu. Keduanya adalah drift
fixture/dataset; kegagalan tersebut dicatat terbuka. `ai-read-tools.php` juga
tidak diubah untuk memaksa PASS karena perilaku local-first memang menggantikan
tool-loop cloud untuk pertanyaan data yang dikenali.

## 11. BLOCKED / sisa risiko

- **Ollama live BLOCKED** - runtime tidak tersedia dan tidak ada model yang
  diunduh. SKIP bukan PASS.
- **Gemini/DeepSeek/OpenAI live BLOCKED** - tidak ada kredensial/kuota
  terkontrol pada lingkungan ini.
- **WhatsApp Cloud API live BLOCKED** - tidak ada token Meta, Phone Number ID,
  nomor pengirim, atau template approved. Mock lokal dan signature HMAC sudah
  diuji.
- **Laporan WhatsApp otomatis** belum diimplementasikan; hanya konfigurasi.
- **Cookie `Secure` penuh** belum diverifikasi tanpa TLS lokal.
- **Harness lama** `ai-read-tools.php`, `tahap13/keamanan.php`, dan
  `tahap16/identity.php` masih memakai ID/akun/foto hardcode yang tidak cocok
  dengan dataset mutable. Ini adalah utang pengujian, bukan klaim bahwa jalur
  produksi setara tidak diuji.

## 12. Berkas utama yang berubah

| Berkas | Perubahan |
|---|---|
| `app/Services/LocalDatabaseAssistant.php` | Routing intent, jumlah/daftar/pencarian pelanggan, peringkat teknisi, RBAC, pesan DB gagal. |
| `app/Services/AiAssistantService.php` | Local-first untuk semua intent yang dikenali, cloud hanya untuk non-database, FAQ/fallback jujur. |
| `app/Services/AiOperationsReadService.php` | Query baca pelanggan/karyawan/teknisi, metrik penugasan dan tie. |
| `app/Services/AiSettingsService.php` | Data pengaturan hybrid dan monitoring sumber untuk Admin. |
| `app/Services/AiUsage.php` | Agregat sumber lokal database/FAQ, cloud, timeout. |
| `app/Models/AiDataAccessLog.php` | Agregat audit metadata-only dan intent tak dikenal. |
| `app/Services/AiKnowledge.php` | Jawaban FAQ lokal ringkas dan jujur. |
| `app/Views/admin/pengaturan_ai.php` | Kartu monitoring sumber jawaban. |
| `tests/tahap19/local-first.php` | 25 pengujian wajib local-first/intent/RBAC/monitoring. |
| `tests/tahap19/mock-timeout.php` | Provider mock untuk timeout deterministik. |
| `database/migrations/009_ai_hybrid.sql` | Skema aditif hybrid + audit (sudah diterapkan). |

## 13. Catatan operasional

- Jalankan migrasi dengan `php bin/migrate.php` bila database baru.
- Jangan mengaktifkan provider berbayar tanpa persetujuan; tidak ada
  perpindahan provider otomatis.
- Untuk produksi, gunakan akun MySQL read-only (`DB_RO_USER`) agar pembacaan AI
  terisolasi.
- Jangan menjalankan harness terhadap database produksi. Gunakan
  `wificare_cdn_test`.
- Rahasia tidak boleh dicatat di issue, laporan, atau log. Rotasi kredensial
  yang pernah terekspos.

## 14. Pembaruan Tahap 20 - Ekstraksi Nama Pelanggan & Monitoring Provider

### 14.1 Masalah yang diperbaiki

Pertanyaan `apakah ada pelanggan atas nama agus` sebelumnya dijawab
`Pelanggan dengan nama "atas nama agus" tidak ditemukan.` Respons memang cepat
dan berasal dari database lokal, tetapi ekstraksi nama mengambil frasa
pengantar sebagai bagian nama. Ini bukan kegagalan koneksi MySQL.

Akar penyebab ada pada routing lokal, bukan pada data pelanggan:

- pola `pelanggan\s+(.+)$` mengambil seluruh frasa setelah kata `pelanggan`;
- `cleanName()` belum menghapus `atas nama`, `bernama`, `yang bernama`,
  `yang namanya`, dan `dengan nama`;
- pertanyaan `apakah agus terdaftar sebagai pelanggan?` belum memiliki pola
  nama terbalik sehingga menjadi `customers.list`;
- `siapa pelanggan terbaru?` belum memiliki intent khusus sehingga menjadi
  `customers.list`;
- substring `nya` pada `namanya` sempat dianggap pronomina konteks.

### 14.2 Alur local-first setelah perbaikan

```
pertanyaan pelanggan
  -> normalisasi teks
  -> deteksi intent (search/list/count/latest/complaint_summary)
  -> ekstraksi nama + buang frasa pengantar
  -> RBAC Admin/CS
  -> AiOperationsReadService (PDO prepared statement)
  -> jawaban/tabel dari MySQL
  -> cloud TIDAK dipanggil
```

Pemisahan intent yang sekarang dijaga:

| Pertanyaan | Intent | Nama |
|---|---|---|
| `apakah ada pelanggan atas nama agus` | `customers.search` | `agus` |
| `ada pelanggan bernama agus?` | `customers.search` | `agus` |
| `cari pelanggan agus` | `customers.search` | `agus` |
| `pelanggan yang namanya agus` | `customers.search` | `agus` |
| `coba cek pelanggan atas nama agus setiawan` | `customers.search` | `agus setiawan` |
| `apakah agus terdaftar sebagai pelanggan?` | `customers.search` | `agus` |
| `pelanggan siapa aja` | `customers.list` | kosong |
| `berapa jumlah pelanggan` | `customers.count` | kosong |
| `siapa pelanggan terbaru` | `customers.latest` | kosong |
| `siapa yang terakhir membuat pengaduan` | `complaints.latest_customer` | kosong |

### 14.3 Query yang digunakan

Pencarian nama/PPPoE:

```sql
SELECT p.id, p.user_id, u.nama, u.username, u.is_active,
       p.username_pppoe, p.nomor_whatsapp, p.alamat, p.created_at
FROM pelanggan p
INNER JOIN users u ON u.id = p.user_id
WHERE u.nama LIKE :q_nama
   OR u.username LIKE :q_username
   OR p.username_pppoe LIKE :q_pppoe
ORDER BY u.nama ASC, p.id ASC
LIMIT 10;
```

Pelanggan terbaru:

```sql
SELECT ...
FROM pelanggan p
INNER JOIN users u ON u.id = p.user_id
ORDER BY p.created_at DESC, p.id DESC
LIMIT 1;
```

Catatan:

- Hanya tabel `pelanggan` dan `users`; akun karyawan tidak ikut karena harus
  memiliki baris `pelanggan`.
- Tidak ada `JOIN pengaduan` sehingga pelanggan tanpa tiket tetap ditemukan.
- LIKE memakai parameter dan escape `%`, `_`, dan backslash.
- Batas hasil 10 (maksimum 25); tidak ada SQL bebas dari chat.
- Admin/CS boleh mencari; role pelanggan ditolak di service.

### 14.4 Perilaku jawaban

- Ditemukan satu pelanggan:
  `Pelanggan bernama Agus Setiawan ditemukan.`
  dengan tabel ringkas nama, PPPoE, dan status.
- Tidak ditemukan:
  `Pelanggan bernama Agus tidak ditemukan.`
  tanpa mengulang `atas nama`.
- Nama ganda/mirip:
  `Ditemukan 2 pelanggan yang cocok...` dan meminta nama lengkap/PPPoE.
- Pertanyaan keberadaan tidak menampilkan WhatsApp/alamat.
- Jika MySQL gagal, jalur `database_unavailable` tetap berbeda dari
  `tidak ditemukan`.

### 14.5 Bukti sebelum dan sesudah

| Pertanyaan | Sebelum | Sesudah |
|---|---|---|
| `apakah ada pelanggan atas nama agus` | nama=`atas nama agus`, tidak ditemukan | nama=`agus`, ditemukan `Agus Setiawan` |
| `pelanggan atas nama agus setiawan` | nama=`atas nama agus setiawan`, tidak ditemukan | nama=`agus setiawan`, ditemukan |
| `apakah agus terdaftar sebagai pelanggan?` | `customers.list`, 2 pelanggan | `customers.search`, menemukan Agus |
| `pelanggan yang namanya agus` | konteks `nya` -> `customers.detail` | `customers.search` |
| `siapa pelanggan terbaru` | `customers.list` | `customers.latest` |
| `apakah ada pelanggan atas nama zzz tidak ada` | memakai frasa pengantar | `Pelanggan bernama Zzz Tidak Ada tidak ditemukan.` |

Database uji `wificare_cdn_test` memiliki `Agus Setiawan` / `pppoe.agus`.
Database utama tidak memiliki pelanggan Agus saat audit; karena itu jawaban
"tidak ditemukan" pada database utama adalah benar, asalkan nama yang diekstrak
adalah `Agus`, bukan `atas nama agus`.

### 14.6 Bukti pengujian

| Harness | Hasil |
|---|---|
| `tests/tahap20/customer-name-search.php` | **20 PASS, 0 FAIL** |
| `tests/tahap20/provider-monitoring.php` | **24 PASS, 0 FAIL, 0 BLOCKED** |
| `tests/tahap20/local-latest.php` | **14 PASS, 0 FAIL** |
| `tests/tahap19/local-first.php` | **25 PASS, 0 FAIL, 0 BLOCKED** |
| `tests/tahap18/local-ai.php` | 103 PASS, 0 FAIL, 2 SKIP |
| `tests/tahap16/identity.php` | 27 PASS, 0 FAIL, 1 SKIP |

Dua belas skenario inti yang dibuktikan harness baru:

1. frasa `atas nama` tidak masuk nama;
2. `cari pelanggan agus`;
3. `bernama agus`;
4. nama lengkap dua kata;
5. `terdaftar sebagai pelanggan` tidak menjadi daftar;
6. daftar pelanggan tetap daftar;
7. jumlah pelanggan tetap jumlah;
8. pelanggan terbaru memakai `customers.latest`;
9. pembuat pengaduan terbaru tetap `complaints.latest_customer`;
10. nama tidak ada dijawab jujur;
11. nama mirip/sama meminta klarifikasi;
12. kapitalisasi/spasi berbeda, pelanggan tanpa riwayat, RBAC, no-cloud,
    pencocokan query langsung, minimalisasi data, follow-up, dan intent
    teknisi diuji pada harness yang sama.

### 14.7 Monitoring provider dan batasnya

- Event provider disimpan di `ai_provider_events`; notifikasi Admin memakai
  tabel `notifikasi`.
- Kuota/kredit habis dan rate limit sementara dibedakan.
- Deduplikasi: kuota 6 jam, rate limit 1 jam.
- Token/quota yang tidak tersedia ditampilkan `Tidak tersedia`; tidak ada
  reset time atau sisa kuota karangan.
- Tidak ada perpindahan provider berbayar otomatis.
- Live provider tidak diuji tanpa kredensial/kuota; mock provider dipakai untuk
  jalur deterministik.
- `tests/tahap10/ai-read-tools.php` masih FAIL karena menguji tool-loop cloud
  untuk pertanyaan yang sekarang dijawab local-first. `tests/tahap13/keamanan.php`
  masih FAIL karena akun/fixture hardcode tidak ada di DB utama. Keduanya
  dicatat sebagai utang harness, bukan PASS palsu.

Semua perubahan belum di-commit.
