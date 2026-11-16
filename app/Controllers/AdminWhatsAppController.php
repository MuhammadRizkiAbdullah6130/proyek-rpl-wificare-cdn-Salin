<?php
/**
 * Controller Admin — WhatsApp Gateway, OTP, Notifikasi, dan Laporan.
 *
 * Hanya Admin (middleware role). Secret TIDAK PERNAH dikirim ke view; form edit
 * hanya menampilkan status "tersimpan" dan input kosong mempertahankan secret.
 */

declare(strict_types=1);

require_once __DIR__ . '/AdminBaseController.php';

final class AdminWhatsAppController extends AdminBaseController
{
    /**
     * Metadata halaman submenu WhatsApp. Satu sumber untuk view, menu aktif,
     * judul, dan breadcrumb agar tidak ada halaman yang kehilangan konteks.
     *
     * @var array<string, array{view: string, active: string, title: string, crumb: string, subtitle: string}>
     */
    private const SECTIONS = [
        'gateway' => [
            'view' => 'admin/whatsapp/gateway',
            'active' => 'whatsapp_gateway',
            'title' => 'Gateway WhatsApp',
            'crumb' => 'Gateway',
            'subtitle' => 'Pengaturan WhatsApp Gateway — kelola kredensial Meta/Fonnte, koneksi default, enkripsi token, dan tes koneksi.',
        ],
        'otp' => [
            'view' => 'admin/whatsapp/otp',
            'active' => 'whatsapp_otp',
            'title' => 'OTP WhatsApp',
            'crumb' => 'OTP WhatsApp',
            'subtitle' => 'Konfigurasi OTP, masa berlaku, batas percobaan, koneksi, dan template pesan per purpose.',
        ],
        'notifikasi' => [
            'view' => 'admin/whatsapp/notifikasi',
            'active' => 'whatsapp_notifikasi',
            'title' => 'Notifikasi WhatsApp',
            'crumb' => 'Notifikasi WhatsApp',
            'subtitle' => 'Notifikasi tiket terkirim otomatis setelah transaksi berhasil. Pantau status dan gunakan proses antrean hanya untuk pemulihan.',
        ],
        'laporan' => [
            'view' => 'admin/whatsapp/laporan',
            'active' => 'whatsapp_laporan',
            'title' => 'Laporan WhatsApp',
            'crumb' => 'Laporan WhatsApp',
            'subtitle' => 'Kirim laporan teks secara manual ke penerima berwenang melalui koneksi WhatsApp aktif.',
        ],
        'audit' => [
            'view' => 'admin/whatsapp/audit',
            'active' => 'whatsapp_audit',
            'title' => 'Audit Konfigurasi',
            'crumb' => 'Audit Konfigurasi',
            'subtitle' => 'Riwayat perubahan konfigurasi WhatsApp tanpa menampilkan token, secret, atau isi pesan sensitif.',
        ],
    ];

    /**
     * URL lama /admin/pengaturan/whatsapp (dan base /admin/whatsapp) diarahkan
     * ke submenu kanonis. Parameter tab/section lama dipetakan dengan aman.
     * Fragment (#gateway/#otp) tidak dikirim ke server, sehingga pemetaan
     * berbasis query dipertahankan.
     */
    public function index(): void
    {
        $section = $this->legacySection();
        $query = '';

        if ($section === 'gateway') {
            $editId = (int) $this->queryString('edit', '0');
            if ($editId > 0) {
                $query = '?edit=' . $editId;
            }
        }

        redirect('/admin/whatsapp/' . $section . $query);
    }

    public function gateway(): void
    {
        $this->renderSection('gateway');
    }

    public function otp(): void
    {
        $this->renderSection('otp');
    }

    public function notifications(): void
    {
        $this->renderSection('notifikasi');
    }

    public function report(): void
    {
        $this->renderSection('laporan');
    }

    public function audit(): void
    {
        $this->renderSection('audit');
    }

    private function legacySection(): string
    {
        $requested = strtolower(trim((string) ($_GET['tab'] ?? $_GET['section'] ?? '')));
        $map = [
            'gateway' => 'gateway',
            'otp' => 'otp',
            'notifikasi' => 'notifikasi',
            'notification' => 'notifikasi',
            'notifications' => 'notifikasi',
            'laporan' => 'laporan',
            'report' => 'laporan',
            'reports' => 'laporan',
            'audit' => 'audit',
            'audit-konfigurasi' => 'audit',
            'audit_konfigurasi' => 'audit',
        ];

        return $map[$requested] ?? 'gateway';
    }

    /**
     * Muat hanya data yang relevan untuk halaman submenu yang sedang dibuka.
     */
    private function renderSection(string $section): void
    {
        if (!isset(self::SECTIONS[$section])) {
            $section = 'gateway';
        }
        $meta = self::SECTIONS[$section];

        $connections = ApiCredentialService::whatsappConnections();
        $editConnection = null;
        if ($section === 'gateway') {
            $editId = (int) $this->queryString('edit', '0');
            $editConnection = $editId > 0 ? ApiCredentialService::safe($editId) : null;
            if ($editConnection !== null && !empty($editConnection['is_revoked'])) {
                $editConnection = null;
            }
        }

        $data = [
            'section' => $section,
            'connections' => $connections,
            'editConnection' => $editConnection,
            'connectionOptions' => ApiCredentialService::connectionOptions(),
            'providerOptions' => WhatsAppProviderRegistry::labels(),
            'encryptionReady' => Crypto::isReady(),
            'masterKeyPresent' => Crypto::hasMasterKey(),
            'libsodiumAvailable' => Crypto::available(),
            'flash_sukses' => flash_get('sukses'),
            'flash_error' => flash_get('error'),
            'csrf' => csrf_token(),
            'pageSubtitle' => $meta['subtitle'],
        ];

        if ($section === 'gateway') {
            $dbToday = (string) Database::pdo()->query('SELECT CURRENT_DATE')->fetchColumn();
            $data['gateway'] = ApiIntegrationSetting::get(ApiFeatureRegistry::WHATSAPP_GATEWAY);
            $data['gatewayUsage'] = ApiUsageLog::summary(
                ApiFeatureRegistry::WHATSAPP_GATEWAY,
                substr($dbToday, 0, 7) . '-01',
                $dbToday
            );
            $data['webhookUrls'] = array_reduce(
                $connections,
                static function (array $carry, array $row): array {
                    $carry[(int) $row['id']] = ApiCredentialService::webhookUrl((int) $row['id']);
                    return $carry;
                },
                []
            );
        }

        if ($section === 'otp') {
            $otpSettings = ApiIntegrationSetting::get(ApiFeatureRegistry::WHATSAPP_OTP);
            $otpConnectionId = (int) ($otpSettings['connection_id'] ?? 0);
            $otpConnection = $otpConnectionId > 0
                ? ApiCredential::find($otpConnectionId)
                : ApiCredential::active(ApiCredentialService::INTEGRATION_WHATSAPP);
            $otpProvider = strtolower(trim((string) ($otpConnection['provider'] ?? '')));
            $data['otp'] = $otpSettings;
            $data['otpProvider'] = $otpProvider;
            $data['otpProviderLabel'] = WhatsAppProviderRegistry::labels()[$otpProvider]
                ?? ($otpProvider !== '' ? $otpProvider : 'Belum dipilih');
            $data['otpProviderSupportsTemplates'] = $otpProvider !== ''
                && ApiCredentialService::providerSupportsTemplates($otpProvider);
            $data['otpMonitoring'] = WhatsAppOtpService::monitoring();
        }

        if ($section === 'notifikasi') {
            $data['notification'] = ApiIntegrationSetting::get(ApiFeatureRegistry::WHATSAPP_NOTIFICATION);
            $data['notificationMonitoring'] = WhatsAppNotificationService::monitoring();
            $data['notificationEvents'] = ApiFeatureRegistry::notificationEvents();
            $data['recipientOptions'] = ApiFeatureRegistry::recipientOptions();
            $data['placeholders'] = ApiFeatureRegistry::placeholders();
        }

        if ($section === 'laporan') {
            $data = array_merge($data, WhatsAppReportService::pageData($_GET));
        }

        if ($section === 'audit') {
            $data['audits'] = [
                ApiFeatureRegistry::WHATSAPP_GATEWAY => ApiConfigAudit::recent(ApiFeatureRegistry::WHATSAPP_GATEWAY),
                ApiFeatureRegistry::WHATSAPP_OTP => ApiConfigAudit::recent(ApiFeatureRegistry::WHATSAPP_OTP),
                ApiFeatureRegistry::WHATSAPP_NOTIFICATION => ApiConfigAudit::recent(ApiFeatureRegistry::WHATSAPP_NOTIFICATION),
                ApiFeatureRegistry::WHATSAPP_REPORT => ApiConfigAudit::recent(ApiFeatureRegistry::WHATSAPP_REPORT),
            ];
        }

        $this->renderRole(
            $meta['view'],
            'admin',
            $meta['active'],
            $meta['title'],
            [
                ['label' => 'Admin', 'url' => url('/admin/dashboard')],
                ['label' => 'WhatsApp Gateway', 'url' => url('/admin/whatsapp/gateway')],
                ['label' => $meta['crumb']],
            ],
            $data
        );
    }

    public function saveCredential(): void
    {
        $this->actAdmin(
            'Kredensial WhatsApp berhasil disimpan dengan enkripsi.',
            '/admin/whatsapp/gateway',
            static function (array $actor): void {
                ApiCredentialService::saveWhatsApp($actor, $_POST);
            }
        );
    }

    public function activateCredential(): void
    {
        $this->actAdmin(
            'Koneksi WhatsApp aktif berhasil diubah.',
            '/admin/whatsapp/gateway',
            static function (array $actor): void {
                ApiCredentialService::setActive($actor, (int) ($_POST['id'] ?? 0));
            }
        );
    }

    public function revokeCredential(): void
    {
        $this->actAdmin(
            'Kredensial WhatsApp dicabut dan secret telah dihapus.',
            '/admin/whatsapp/gateway',
            static function (array $actor): void {
                ApiCredentialService::revoke($actor, (int) ($_POST['id'] ?? 0));
            }
        );
    }

    public function testCredential(): void
    {
        $this->actAdmin(
            'Tes koneksi selesai. Status terakhir tampil pada tabel kredensial.',
            '/admin/whatsapp/gateway',
            static function (array $actor): void {
                ApiCredentialService::testWhatsApp($actor, (int) ($_POST['id'] ?? 0));
            }
        );
    }

    public function saveGateway(): void
    {
        $this->actAdmin(
            'Konfigurasi WhatsApp Gateway berhasil disimpan.',
            '/admin/whatsapp/gateway',
            static function (array $actor): void {
                WhatsAppSettingsService::saveGateway($actor, $_POST);
            }
        );
    }

    public function saveOtp(): void
    {
        $this->actAdmin(
            'Konfigurasi OTP WhatsApp berhasil disimpan.',
            '/admin/whatsapp/otp',
            static function (array $actor): void {
                WhatsAppSettingsService::saveOtp($actor, $_POST);
            }
        );
    }

    public function saveNotifications(): void
    {
        $this->actAdmin(
            'Konfigurasi notifikasi WhatsApp berhasil disimpan.',
            '/admin/whatsapp/notifikasi',
            static function (array $actor): void {
                WhatsAppSettingsService::saveNotifications($actor, $_POST);
            }
        );
    }

    public function saveReport(): void
    {
        $this->actAdmin(
            'Penerima berwenang dan batas harian laporan WhatsApp berhasil disimpan. Tidak ada laporan yang dikirim.',
            '/admin/whatsapp/laporan',
            static function (array $actor): void {
                WhatsAppSettingsService::saveReport($actor, $_POST);
            }
        );
    }

    public function sendReport(): void
    {
        $this->actAdmin(
            'Permintaan laporan sudah diterima provider WhatsApp. Status delivery belum tentu sudah diterima penerima.',
            '/admin/whatsapp/laporan',
            static function (array $actor): void {
                $result = WhatsAppReportService::sendManual(
                    $actor,
                    (string) ($_POST['preview_token'] ?? '')
                );
                if (empty($result['ok'])) {
                    throw new ValidationException([
                        'kirim' => (string) ($result['message'] ?? 'Laporan gagal dikirim.'),
                    ]);
                }
            }
        );
    }

    public function processQueue(): void
    {
        $this->actAdmin(
            'Antrean WhatsApp selesai diproses. Status delivery tetap menunggu webhook provider.',
            '/admin/whatsapp/notifikasi',
            static function (array $actor): void {
                $result = WhatsAppMessagingService::processQueue(20);
                if ((string) ($result['error'] ?? '') !== '') {
                    $messages = [
                        'encryption_not_ready' => 'Master encryption key belum siap.',
                        'gateway_disabled' => 'Aktifkan WhatsApp Gateway terlebih dahulu.',
                        'feature_disabled' => 'Aktifkan fitur Notifikasi WhatsApp terlebih dahulu.',
                    ];
                    throw new ValidationException([
                        'antrean' => $messages[(string) $result['error']] ?? 'Antrean tidak dapat diproses.',
                    ]);
                }

                ApiConfigAudit::record((int) ($actor['id'] ?? 0), ApiFeatureRegistry::WHATSAPP_NOTIFICATION, 'antrean_diproses', [
                    'processed',
                    'accepted',
                    'failed',
                ]);
            }
        );
    }
}
