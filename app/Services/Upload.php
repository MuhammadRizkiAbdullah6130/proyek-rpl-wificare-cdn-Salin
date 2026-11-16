<?php
/**
 * Service unggah berkas (foto bukti pengaduan).
 *
 * Aturan keamanan:
 * - Hanya JPG, PNG, WebP (dicek dari MIME asli via finfo + getimagesize).
 * - Ekstensi berkas disimpan DITENTUKAN dari MIME, bukan dari nama klien,
 *   sehingga berkas .php / executable tidak mungkin tersimpan.
 * - Nama file acak 32 karakter hex.
 * - Disimpan di luar document root (`storage/uploads/pengaduan/`).
 */

declare(strict_types=1);

final class Upload
{
    /** Batas ukuran awal per berkas (5 MB). */
    public const MAX_BYTES = 5242880;

    /** Direktori penyimpanan foto bukti pengaduan (relatif root proyek). */
    public const SUBDIR_PENGADUAN = 'storage/uploads/pengaduan';

    /** Direktori penyimpanan foto hasil perbaikan teknisi (relatif root proyek). */
    public const SUBDIR_LAPORAN = 'storage/uploads/laporan';

    /** Peta MIME yang diizinkan => ekstensi internal. */
    private const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * Direktori penyimpanan relatif terhadap root proyek.
     */
    public static function subdir(string $subdir = self::SUBDIR_PENGADUAN): string
    {
        return $subdir;
    }

    /**
     * Direktori penyimpanan absolut.
     */
    public static function absoluteDir(string $subdir = self::SUBDIR_PENGADUAN): string
    {
        return dirname(__DIR__, 2) . '/' . $subdir;
    }

    /**
     * Ubah path relatif tersimpan menjadi path absolut.
     *
     * Path HANYA boleh menunjuk ke dalam `storage/uploads/`. Nilai yang
     * mengandung `..`, null byte, path absolut, atau menunjuk ke folder lain
     * ditolak (mengembalikan string kosong) untuk mencegah path traversal.
     */
    public static function absolutePath(string $relativePath): string
    {
        $relativePath = str_replace('\\', '/', $relativePath);

        if (
            $relativePath === ''
            || str_contains($relativePath, "\0")
            || str_starts_with($relativePath, '/')
            || preg_match('#(^|/)\.\.(/|$)#', $relativePath) === 1
        ) {
            return '';
        }

        $relativePath = ltrim($relativePath, '/');
        if (!str_starts_with($relativePath, 'storage/uploads/')) {
            return '';
        }

        return dirname(__DIR__, 2) . '/' . $relativePath;
    }

    /**
     * Resolusi aman path foto ke berkas nyata di dalam storage/uploads.
     *
     * Verifikasi realpath memastikan symlink/komponen path tidak keluar dari
     * direktori unggahan. Mengembalikan null bila berkas tidak ada/tidak sah.
     */
    public static function resolve(string $relativePath): ?string
    {
        $absolute = self::absolutePath($relativePath);
        if ($absolute === '' || !is_file($absolute)) {
            return null;
        }

        $root = realpath(dirname(__DIR__, 2) . '/storage/uploads');
        $real = realpath($absolute);
        if ($root === false || $real === false) {
            return null;
        }

        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $real = str_replace('\\', '/', $real);

        return str_starts_with($real, $root) ? $real : null;
    }

    /**
     * Validasi dan simpan satu berkas unggahan.
     *
     * @param array<string, mixed> $file Satu entri dari $_FILES.
     * @param string $subdir Direktori tujuan (default: foto bukti pengaduan).
     * @return array{path: string, original_name: string, mime_type: string, file_size: int}
     *
     * @throws RuntimeException Bila berkas tidak valid atau gagal disimpan.
     */
    public static function store(array $file, string $subdir = self::SUBDIR_PENGADUAN): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('Foto bukti wajib diunggah.');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException(self::errorMessage($error));
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new RuntimeException('Berkas tidak valid.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            throw new RuntimeException('Berkas kosong atau rusak.');
        }
        if ($size > self::MAX_BYTES) {
            throw new RuntimeException('Ukuran foto melebihi 5 MB.');
        }

        $mime = self::detectMime($tmpName);
        if (!isset(self::ALLOWED[$mime])) {
            throw new RuntimeException('Format foto harus JPG, PNG, atau WebP.');
        }

        // Pastikan berkas benar-benar gambar yang dapat dibaca.
        if (@getimagesize($tmpName) === false) {
            throw new RuntimeException('Berkas bukan gambar yang valid.');
        }

        $extension = self::ALLOWED[$mime];
        $directory = self::absoluteDir($subdir);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Gagal menyiapkan folder unggahan.');
        }

        $name = bin2hex(random_bytes(16)) . '.' . $extension;
        $target = $directory . '/' . $name;

        if (!move_uploaded_file($tmpName, $target)) {
            throw new RuntimeException('Gagal menyimpan berkas.');
        }

        @chmod($target, 0644);

        return [
            'path' => self::subdir($subdir) . '/' . $name,
            'original_name' => self::safeOriginalName((string) ($file['name'] ?? ('foto.' . $extension)), $extension),
            'mime_type' => $mime,
            'file_size' => $size,
        ];
    }

    /**
     * Hapus berkas tersimpan (dipakai saat rollback / pembersihan).
     */
    public static function delete(string $relativePath): void
    {
        $absolute = self::absolutePath($relativePath);
        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }

    /**
     * Normalisasi satu entri $_FILES (bisa tunggal atau array `foto[]`)
     * menjadi daftar berkas tunggal. Berkas tanpa file (NO_FILE) dilewati.
     *
     * @param array<string, mixed>|null $entry
     * @return array<int, array<string, mixed>>
     */
    public static function normalizeFiles(?array $entry): array
    {
        if (!is_array($entry) || !isset($entry['name'])) {
            return [];
        }

        // Berkas tunggal.
        if (!is_array($entry['name'])) {
            return ((int) ($entry['error'] ?? UPLOAD_ERR_NO_FILE)) === UPLOAD_ERR_NO_FILE ? [] : [$entry];
        }

        // Banyak berkas (`foto[]`).
        $files = [];
        foreach ($entry['name'] as $index => $name) {
            $error = (int) ($entry['error'][$index] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $files[] = [
                'name' => $name,
                'type' => $entry['type'][$index] ?? '',
                'tmp_name' => $entry['tmp_name'][$index] ?? '',
                'error' => $error,
                'size' => $entry['size'][$index] ?? 0,
            ];
        }

        return $files;
    }

    /**
     * Deteksi MIME asli dari isi berkas.
     */
    private static function detectMime(string $path): string
    {
        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($path);

            return is_string($mime) ? $mime : '';
        }

        $info = @getimagesize($path);

        return is_array($info) && isset($info['mime']) ? (string) $info['mime'] : '';
    }

    /**
     * Bersihkan nama asli agar aman disimpan sebagai metadata (bukan nama file).
     */
    private static function safeOriginalName(string $name, string $extension): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[^A-Za-z0-9._ -]/', '_', $name);
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'foto.' . $extension;
        }

        return mb_substr($name, 0, 180);
    }

    /**
     * Pesan aman untuk kode error unggahan.
     */
    private static function errorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Ukuran foto melebihi batas yang diizinkan.',
            UPLOAD_ERR_PARTIAL => 'Berkas hanya terunggah sebagian. Coba lagi.',
            UPLOAD_ERR_NO_TMP_DIR => 'Folder sementara tidak tersedia.',
            UPLOAD_ERR_CANT_WRITE => 'Gagal menulis berkas ke disk.',
            UPLOAD_ERR_EXTENSION => 'Unggahan dihentikan oleh ekstensi server.',
            default => 'Gagal mengunggah berkas.',
        };
    }
}
