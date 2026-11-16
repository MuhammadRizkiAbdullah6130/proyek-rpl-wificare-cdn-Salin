<?php
/**
 * Bootstrap aplikasi WiFiCare CDN.
 *
 * Memuat environment, konfigurasi, helper, service, middleware, dan model.
 * Dipakai oleh front controller (`public/index.php`) dan skrip CLI di `bin/`.
 */

declare(strict_types=1);

require_once __DIR__ . '/Helpers/env.php';

// Muat berkas .env (tidak wajib, tetapi disarankan).
env_load(__DIR__ . '/../.env');

$appConfig = require __DIR__ . '/../config/app.php';
$dbConfig = require __DIR__ . '/../config/database.php';

$GLOBALS['app_config'] = $appConfig;
$GLOBALS['db_config'] = $dbConfig;

$appTimezone = (string) ($appConfig['app']['timezone'] ?? '');
if ($appTimezone !== '') {
    date_default_timezone_set($appTimezone);
}

require_once __DIR__ . '/Helpers/functions.php';
require_once __DIR__ . '/Helpers/auth.php';

require_once __DIR__ . '/Services/Session.php';
require_once __DIR__ . '/Services/Database.php';
require_once __DIR__ . '/Services/Csrf.php';
require_once __DIR__ . '/Services/Auth.php';
require_once __DIR__ . '/Services/DashboardService.php';
require_once __DIR__ . '/Services/ValidationException.php';
require_once __DIR__ . '/Services/Upload.php';
require_once __DIR__ . '/Services/PengaduanService.php';
require_once __DIR__ . '/Services/PengaduanFlowService.php';
require_once __DIR__ . '/Services/NotificationService.php';
require_once __DIR__ . '/Services/UserService.php';
require_once __DIR__ . '/Services/PelangganService.php';
require_once __DIR__ . '/Services/KategoriService.php';
require_once __DIR__ . '/Services/PengaturanService.php';
require_once __DIR__ . '/Services/ArsipService.php';
require_once __DIR__ . '/Services/RatingService.php';
require_once __DIR__ . '/Services/LaporanService.php';
require_once __DIR__ . '/Services/PdfReport.php';
require_once __DIR__ . '/Services/XlsxWriter.php';
require_once __DIR__ . '/Services/AiKnowledge.php';
require_once __DIR__ . '/Services/AiIdentity.php';
require_once __DIR__ . '/Services/Crypto.php';
require_once __DIR__ . '/Services/Ai/AiProviderInterface.php';
require_once __DIR__ . '/Services/Ai/AbstractOpenAiCompatibleProvider.php';
require_once __DIR__ . '/Services/Ai/GeminiProvider.php';
require_once __DIR__ . '/Services/Ai/DeepSeekProvider.php';
require_once __DIR__ . '/Services/Ai/OpenAiProvider.php';
require_once __DIR__ . '/Services/Ai/OllamaProvider.php';
require_once __DIR__ . '/Services/Ai/AiProviderRegistry.php';
require_once __DIR__ . '/Services/Ai/AiProviderErrorClassifier.php';
require_once __DIR__ . '/Services/AiReadService.php';
require_once __DIR__ . '/Services/AiOperationsReadService.php';
require_once __DIR__ . '/Services/AiToolService.php';
require_once __DIR__ . '/Models/AiDataAccessLog.php';
require_once __DIR__ . '/Services/AiContext.php';
require_once __DIR__ . '/Services/LocalDatabaseAssistant.php';
require_once __DIR__ . '/Models/AiSetting.php';
require_once __DIR__ . '/Models/AiProviderKey.php';
require_once __DIR__ . '/Models/AiFaq.php';
require_once __DIR__ . '/Services/AiSettingsService.php';
require_once __DIR__ . '/Services/AiUsage.php';
require_once __DIR__ . '/Services/AiConversation.php';
require_once __DIR__ . '/Services/AiRateLimiter.php';
require_once __DIR__ . '/Services/AiAssistantService.php';

// Pusat API & WhatsApp (Tahap 17).
require_once __DIR__ . '/Services/ApiFeatureRegistry.php';
require_once __DIR__ . '/Models/ApiCredential.php';
require_once __DIR__ . '/Models/ApiIntegrationSetting.php';
require_once __DIR__ . '/Models/ApiConfigAudit.php';
require_once __DIR__ . '/Models/WhatsAppOutbox.php';
require_once __DIR__ . '/Models/WhatsAppOtp.php';
require_once __DIR__ . '/Models/PasswordReset.php';
require_once __DIR__ . '/Services/ApiUsageLog.php';
require_once __DIR__ . '/Services/WhatsApp/WhatsAppProviderInterface.php';
require_once __DIR__ . '/Services/WhatsApp/WhatsAppCloudProvider.php';
require_once __DIR__ . '/Services/WhatsApp/WhatsAppFonnteProvider.php';
require_once __DIR__ . '/Services/WhatsApp/WhatsAppProviderRegistry.php';
require_once __DIR__ . '/Services/ApiCredentialService.php';
require_once __DIR__ . '/Services/WhatsAppMessagingService.php';
require_once __DIR__ . '/Services/WhatsAppOtpService.php';
require_once __DIR__ . '/Services/PasswordResetService.php';
require_once __DIR__ . '/Services/PelangganWhatsAppVerificationService.php';
require_once __DIR__ . '/Services/WhatsAppNotificationService.php';
require_once __DIR__ . '/Services/WhatsAppSettingsService.php';
require_once __DIR__ . '/Services/WhatsAppReportService.php';
require_once __DIR__ . '/Services/WhatsAppWebhookService.php';
require_once __DIR__ . '/Services/ApiMonitoringService.php';

require_once __DIR__ . '/Middleware/AuthMiddleware.php';

require_once __DIR__ . '/Models/User.php';
require_once __DIR__ . '/Models/Pelanggan.php';
require_once __DIR__ . '/Models/AuditLog.php';
require_once __DIR__ . '/Models/LoginAttempt.php';
require_once __DIR__ . '/Models/Pengaduan.php';
require_once __DIR__ . '/Models/Penugasan.php';
require_once __DIR__ . '/Models/LaporanTeknisi.php';
require_once __DIR__ . '/Models/Kategori.php';
require_once __DIR__ . '/Models/CatatanCs.php';
require_once __DIR__ . '/Models/PenyelesaianCs.php';
require_once __DIR__ . '/Models/Notifikasi.php';
require_once __DIR__ . '/Services/AiProviderMonitor.php';
require_once __DIR__ . '/Models/Rating.php';
require_once __DIR__ . '/Models/Pengaturan.php';
require_once __DIR__ . '/Models/Laporan.php';

/**
 * Ambil nilai konfigurasi aplikasi.
 */
if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        $config = $GLOBALS['app_config'] ?? [];
        foreach (explode('.', $key) as $segment) {
            if (!is_array($config) || !array_key_exists($segment, $config)) {
                return $default;
            }
            $config = $config[$segment];
        }

        return $config;
    }
}

/**
 * Ambil nilai konfigurasi database.
 */
if (!function_exists('db_config')) {
    function db_config(string $key, mixed $default = null): mixed
    {
        $config = $GLOBALS['db_config'] ?? [];

        return $config[$key] ?? $default;
    }
}

/**
 * Retry otomatis notifikasi WhatsApp pada akhir request web.
 *
 * Ini bukan pengganti worker: host tanpa trafik tetap dapat menjalankan
 * `php bin/whatsapp-worker.php --loop=30`. Mekanisme ini memastikan baris
 * pending yang sudah jatuh tempo tidak hanya bergantung pada tombol manual.
 */
if (PHP_SAPI !== 'cli') {
    register_shutdown_function(static function (): void {
        try {
            WhatsAppNotificationService::dispatchAutomatically(3);
        } catch (Throwable $exception) {
            app_log('Retry otomatis notifikasi WhatsApp pada akhir request dilewati.');
        }
    });
}
