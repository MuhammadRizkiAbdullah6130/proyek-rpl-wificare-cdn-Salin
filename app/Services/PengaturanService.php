<?php
/**
 * Service pengaturan aplikasi (Admin — Tahap 8).
 *
 * Hanya menyimpan konfigurasi NONRAHASIA. Kredensial/API key/koneksi database
 * TIDAK pernah ditulis di sini dan tidak dapat ditampilkan melalui halaman ini.
 */

declare(strict_types=1);

final class PengaturanService
{
    /** Kunci pengaturan yang boleh diubah lewat halaman Admin. */
    private const EDITABLE = [
        'app_name',
        'isp_name',
        'contact_email',
        'contact_phone',
        'contact_whatsapp',
        'upload_max_mb',
    ];

    /**
     * Validasi & simpan pengaturan.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input
     */
    public static function save(array $actor, array $input): void
    {
        $values = [
            'app_name' => trim((string) ($input['app_name'] ?? '')),
            'isp_name' => trim((string) ($input['isp_name'] ?? '')),
            'contact_email' => trim((string) ($input['contact_email'] ?? '')),
            'contact_phone' => trim((string) ($input['contact_phone'] ?? '')),
            'contact_whatsapp' => trim((string) ($input['contact_whatsapp'] ?? '')),
            'upload_max_mb' => trim((string) ($input['upload_max_mb'] ?? '')),
        ];

        $errors = [];

        if ($values['app_name'] === '') {
            $errors['app_name'] = 'Nama aplikasi wajib diisi.';
        } elseif (mb_strlen($values['app_name']) > 120) {
            $errors['app_name'] = 'Nama aplikasi maksimal 120 karakter.';
        }

        if (mb_strlen($values['isp_name']) > 120) {
            $errors['isp_name'] = 'Nama ISP maksimal 120 karakter.';
        }

        if ($values['contact_email'] !== '' && !filter_var($values['contact_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['contact_email'] = 'Format email tidak valid.';
        } elseif (mb_strlen($values['contact_email']) > 120) {
            $errors['contact_email'] = 'Email maksimal 120 karakter.';
        }

        if ($values['contact_phone'] !== '' && !preg_match('/^[0-9+\-\s()]{6,30}$/', $values['contact_phone'])) {
            $errors['contact_phone'] = 'Format telepon tidak valid.';
        }

        if ($values['contact_whatsapp'] !== '' && !preg_match('/^[0-9+\-\s]{9,20}$/', $values['contact_whatsapp'])) {
            $errors['contact_whatsapp'] = 'Format nomor WhatsApp tidak valid.';
        }

        if ($values['upload_max_mb'] === '' || !ctype_digit($values['upload_max_mb'])) {
            $errors['upload_max_mb'] = 'Batas ukuran upload harus berupa angka.';
        } else {
            $mb = (int) $values['upload_max_mb'];
            if ($mb < 1 || $mb > 50) {
                $errors['upload_max_mb'] = 'Batas ukuran upload antara 1–50 MB.';
            }
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        // Hanya kunci yang diizinkan yang disimpan.
        $toSave = array_intersect_key($values, array_flip(self::EDITABLE));

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $before = Pengaturan::all();
            Pengaturan::setMany($toSave);

            // Catat hanya kunci yang benar-benar berubah (nilai nonrahasia aman dicatat).
            $changed = [];
            foreach ($toSave as $key => $value) {
                if (($before[$key] ?? null) !== $value) {
                    $changed[$key] = $value;
                }
            }

            if (!empty($changed)) {
                AuditLog::record((int) ($actor['id'] ?? 0), 'pengaturan_diubah', 'pengaturan', null, [
                    'perubahan' => $changed,
                ]);
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}
