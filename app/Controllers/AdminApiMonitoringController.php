<?php
/**
 * Controller Admin — Monitoring Penggunaan API (AI + WhatsApp).
 *
 * Data nyata dari ai_usage_log, api_usage_log, dan whatsapp_outbox. Halaman ini
 * tidak menampilkan secret, isi percakapan, OTP, atau isi laporan.
 */

declare(strict_types=1);

require_once __DIR__ . '/AdminBaseController.php';

final class AdminApiMonitoringController extends AdminBaseController
{
    public function index(): void
    {
        $feature = $this->queryString('fitur');
        $provider = $this->queryString('provider');
        $period = $this->queryString('periode', 'bulan_ini');
        if (!in_array($period, ['hari_ini', '7_hari', 'bulan_ini', 'bulan_lalu', 'semua'], true)) {
            $period = 'bulan_ini';
        }

        $data = ApiMonitoringService::pageData($feature, $provider, $period);

        $this->renderRole(
            'admin/monitoring_api',
            'admin',
            'monitoring_api',
            'Monitoring Penggunaan API',
            [
                ['label' => 'Admin', 'url' => url('/admin/dashboard')],
                ['label' => 'Monitoring Penggunaan API'],
            ],
            array_merge($data, [
                'flash_sukses' => flash_get('sukses'),
                'flash_error' => flash_get('error'),
                'csrf' => csrf_token(),
            ])
        );
    }
}
