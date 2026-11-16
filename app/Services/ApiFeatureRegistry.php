<?php
/**
 * Registry fitur integrasi API WiFiCare CDN (Tahap 17).
 *
 * Semua fitur memakai kredensial WhatsApp yang sama bila `connection_id`
 * kosong, tetapi masing-masing memiliki status aktif, batas, dan monitoring
 * sendiri. Metadata ini tidak mengandung secret.
 */

declare(strict_types=1);

final class ApiFeatureRegistry
{
    public const AI = 'ai_chatbot';
    public const WHATSAPP_GATEWAY = 'whatsapp_gateway';
    public const WHATSAPP_OTP = 'whatsapp_otp';
    public const WHATSAPP_NOTIFICATION = 'whatsapp_notification';
    public const WHATSAPP_REPORT = 'whatsapp_report';

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return [
            self::AI => [
                'label' => 'AI Chatbot',
                'short' => 'AI',
                'description' => 'Gemini, DeepSeek, atau OpenAI untuk WiFiCare AI.',
                'provider' => 'ai',
                'managed_by' => 'Pengaturan AI Chatbot',
                'default_enabled' => true,
            ],
            self::WHATSAPP_GATEWAY => [
                'label' => 'WhatsApp Gateway',
                'short' => 'Gateway',
                'description' => 'Koneksi WhatsApp Business Cloud API yang dipakai bersama.',
                'provider' => 'whatsapp',
                'managed_by' => 'Pengaturan WhatsApp Gateway',
                'default_enabled' => false,
            ],
            self::WHATSAPP_OTP => [
                'label' => 'OTP WhatsApp',
                'short' => 'OTP',
                'description' => 'Verifikasi nomor, ganti nomor, dan reset password melalui OTP sekali pakai.',
                'provider' => 'whatsapp',
                'managed_by' => 'Pengaturan WhatsApp Gateway',
                'default_enabled' => false,
            ],
            self::WHATSAPP_NOTIFICATION => [
                'label' => 'Notifikasi WhatsApp',
                'short' => 'Notifikasi',
                'description' => 'Notifikasi peristiwa tiket pengaduan.',
                'provider' => 'whatsapp',
                'managed_by' => 'Pengaturan WhatsApp Gateway',
                'default_enabled' => false,
            ],
            self::WHATSAPP_REPORT => [
                'label' => 'Laporan WhatsApp',
                'short' => 'Laporan',
                'description' => 'Pengiriman laporan teks manual oleh Admin melalui WhatsApp.',
                'provider' => 'whatsapp',
                'managed_by' => 'Pengaturan WhatsApp Gateway',
                'default_enabled' => false,
            ],
        ];
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        $labels = [];
        foreach (self::all() as $key => $meta) {
            $labels[$key] = (string) $meta['label'];
        }

        return $labels;
    }

    public static function label(string $key): string
    {
        return self::labels()[$key] ?? $key;
    }

    public static function exists(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    /**
     * Definisi template pesan OTP per purpose.
     *
     * Template hanya dipilih dari purpose yang ditentukan server. Frontend
     * tidak pernah mengirim nama field template atau purpose bebas.
     *
     * @return array<string, array{label:string,setting:string,description:string,default:string}>
     */
    public static function otpTemplates(): array
    {
        return [
            'phone_verification' => [
                'label' => 'Template verifikasi nomor WhatsApp',
                'setting' => 'template_phone_verification',
                'description' => 'Dikirim saat pelanggan memverifikasi nomor WhatsApp utama.',
                'default' => "WiFiCare CDN — Verifikasi Nomor WhatsApp\n\nKode verifikasi nomor WhatsApp Anda adalah {kode}.\n\nKode berlaku selama {masa_berlaku} menit. Jangan berikan kode ini kepada siapa pun.",
            ],
            'reset_password' => [
                'label' => 'Template lupa password',
                'setting' => 'template_reset_password',
                'description' => 'Dikirim saat pelanggan meminta pengaturan ulang password.',
                'default' => "WiFiCare CDN — Reset Password\n\nKami menerima permintaan pengaturan ulang password akun Anda.\n\nKode OTP reset password: {kode}.\n\nKode berlaku selama {masa_berlaku} menit.\n\nJika Anda tidak meminta reset password, abaikan pesan ini. Jangan bagikan kode kepada siapa pun.",
            ],
            'phone_change' => [
                'label' => 'Template ganti nomor WhatsApp',
                'setting' => 'template_phone_change',
                'description' => 'Dikirim saat pelanggan mengganti nomor WhatsApp utama.',
                'default' => "WiFiCare CDN — Ganti Nomor WhatsApp\n\nKode konfirmasi nomor WhatsApp baru Anda adalah {kode}.\n\nKode berlaku selama {masa_berlaku} menit. Jangan bagikan kode ini kepada siapa pun.",
            ],
        ];
    }

    /**
     * Nilai default field template pesan OTP per purpose.
     *
     * @return array<string, string>
     */
    public static function otpTemplateDefaults(): array
    {
        $defaults = [];
        foreach (self::otpTemplates() as $meta) {
            $defaults[(string) $meta['setting']] = (string) $meta['default'];
        }

        return $defaults;
    }

    /**
     * Semua purpose OTP yang masih dikenali service.
     *
     * `verifikasi_nomor` dipertahankan untuk kompatibilitas integrasi lama.
     * Alur baru wajib memakai `phone_verification`, `reset_password`, atau
     * `phone_change`.
     *
     * @return array<string, string>
     */
    public static function purposes(): array
    {
        return [
            'phone_verification' => 'Verifikasi nomor WhatsApp',
            'reset_password' => 'Reset password',
            'phone_change' => 'Ganti nomor WhatsApp',
            'verifikasi_nomor' => 'Verifikasi nomor lama (legacy)',
        ];
    }

    /**
     * Purpose yang ditampilkan pada konfigurasi Admin.
     *
     * @return array<string, string>
     */
    public static function adminPurposes(): array
    {
        return [
            'phone_verification' => 'Verifikasi nomor WhatsApp',
            'reset_password' => 'Reset password',
            'phone_change' => 'Ganti nomor WhatsApp',
        ];
    }

    /**
     * Delapan peristiwa notifikasi. `recipient` adalah penerima default.
     *
     * @return array<string, array<string, string>>
     */
    public static function notificationEvents(): array
    {
        return [
            'pengaduan_baru' => [
                'label' => 'Pengaduan baru',
                'description' => 'Pemberitahuan tiket baru untuk admin/operasional.',
                'recipient' => 'admin',
                'template' => 'Tiket baru {nomor_tiket} dari {nama_pelanggan}. Status: {status}. Mohon segera diverifikasi.',
            ],
            'konfirmasi_tiket' => [
                'label' => 'Konfirmasi tiket pelanggan',
                'description' => 'Konfirmasi nomor tiket diterima pelanggan.',
                'recipient' => 'pelanggan',
                'template' => 'Halo {nama_pelanggan}, pengaduan Anda telah kami terima dengan nomor tiket {nomor_tiket}. Simpan nomor ini untuk pengecekan.',
            ],
            'tiket_diverifikasi' => [
                'label' => 'Tiket diverifikasi',
                'description' => 'Tiket diterima dan diverifikasi CS.',
                'recipient' => 'pelanggan',
                'template' => 'Pengaduan {nomor_tiket} sudah diverifikasi dan sedang kami proses.',
            ],
            'tiket_ditolak' => [
                'label' => 'Tiket ditolak',
                'description' => 'Tiket ditolak dengan alasan.',
                'recipient' => 'pelanggan',
                'template' => 'Pengaduan {nomor_tiket} tidak dapat diproses. Alasan: {alasan}',
            ],
            'penugasan_teknisi' => [
                'label' => 'Penugasan teknisi',
                'description' => 'Teknisi ditugaskan menangani tiket.',
                'recipient' => 'pelanggan',
                'template' => 'Tiket {nomor_tiket} ditugaskan kepada teknisi {teknisi}. Mohon siapkan akses jika diperlukan.',
            ],
            'penanganan_dimulai' => [
                'label' => 'Penanganan dimulai',
                'description' => 'Teknisi mulai menangani tiket.',
                'recipient' => 'pelanggan',
                'template' => 'Penanganan tiket {nomor_tiket} telah dimulai oleh teknisi {teknisi}.',
            ],
            'tiket_selesai' => [
                'label' => 'Tiket selesai',
                'description' => 'Tiket selesai ditangani.',
                'recipient' => 'pelanggan',
                'template' => 'Tiket {nomor_tiket} telah selesai ditangani. Terima kasih telah menggunakan layanan kami.',
            ],
            'tiket_dibatalkan' => [
                'label' => 'Tiket dibatalkan',
                'description' => 'Tiket dibatalkan dengan alasan.',
                'recipient' => 'pelanggan',
                'template' => 'Tiket {nomor_tiket} dibatalkan. Alasan: {alasan}',
            ],
        ];
    }

    /** @return array<string, string> */
    public static function recipientOptions(): array
    {
        return [
            'pelanggan' => 'Nomor pelanggan pada tiket',
            'admin' => 'Nomor operasional Admin',
            'custom' => 'Nomor tertentu (diisi di bawah)',
        ];
    }

    /** @return array<string, string> */
    public static function placeholders(): array
    {
        return [
            '{nomor_tiket}' => 'Nomor tiket',
            '{nama_pelanggan}' => 'Nama pelanggan',
            '{status}' => 'Status tiket',
            '{alasan}' => 'Alasan penolakan/pembatalan',
            '{teknisi}' => 'Nama teknisi',
            '{nama_aplikasi}' => 'Nama aplikasi',
        ];
    }

    /**
     * Nilai default konfigurasi NONRAHASIA per fitur.
     *
     * @return array<string, mixed>
     */
    public static function defaults(string $feature): array
    {
        $country = (string) config('api_center.default_country_code', '62');

        switch ($feature) {
            case self::WHATSAPP_GATEWAY:
                return [
                    'default_country_code' => $country,
                    'daily_limit' => 0,
                    'retry_max' => 3,
                    'timeout' => (int) config('api_center.http_timeout', 20),
                ];

            case self::WHATSAPP_OTP:
                return array_merge(self::otpTemplateDefaults(), [
                    // Template lama dipertahankan sebagai fallback untuk
                    // konfigurasi sebelum pemisahan template per purpose.
                    'template' => 'Kode OTP WiFiCare CDN Anda adalah {kode}. Berlaku {masa_berlaku} menit. Jangan bagikan kode ini kepada siapa pun.',
                    'ttl_seconds' => 300,
                    'max_attempts' => 5,
                    'resend_cooldown' => 60,
                    'daily_user_limit' => 5,
                    'daily_phone_limit' => 5,
                    'send_mode' => 'template',
                    'template_name' => '',
                    'language_code' => 'id',
                    'purposes' => [
                        'phone_verification' => true,
                        'reset_password' => true,
                        'phone_change' => true,
                        'verifikasi_nomor' => false,
                    ],
                ]);

            case self::WHATSAPP_NOTIFICATION:
                $events = [];
                foreach (self::notificationEvents() as $key => $meta) {
                    $events[$key] = [
                        'enabled' => false,
                        'recipient' => (string) $meta['recipient'],
                        'custom_number' => '',
                        'template' => (string) $meta['template'],
                        'send_mode' => 'text',
                        'template_name' => '',
                        'language_code' => 'id',
                    ];
                }

                return [
                    'admin_number' => '',
                    'daily_limit' => 0,
                    'retry_max' => 3,
                    'events' => $events,
                ];

            case self::WHATSAPP_REPORT:
                return [
                    'recipients' => [],
                    'report_types' => [
                        'ringkasan_pengaduan',
                        'kinerja_teknisi',
                        'rekap_bulanan',
                    ],
                    'format' => 'text',
                    'daily_limit' => 0,
                ];

            default:
                return [];
        }
    }
}
