<?php
/**
 * Admin — submenu WhatsApp: Audit konfigurasi WhatsApp.
 *
 * Halaman mandiri; hanya memuat konfigurasi yang relevan.
 */

declare(strict_types=1);

require __DIR__ . '/_init.php';
require __DIR__ . '/_header.php';
?>

<section class="card" id="audit">
    <div class="card-head"><h3 class="card-title">Audit Konfigurasi WhatsApp</h3></div>
    <div class="card-body">
        <p class="form-hint">
            Riwayat perubahan konfigurasi dalam bahasa yang mudah dibaca. Nama field internal,
            token, API key, webhook secret, dan kredensial tidak ditampilkan pada halaman ini.
            Data audit tetap tersimpan lengkap untuk kebutuhan investigasi yang berwenang.
        </p>
        <?php foreach ($audits as $featureKey => $items): ?>
            <div class="api-section-head"><h4><?= e(ApiFeatureRegistry::label((string) $featureKey)) ?></h4></div>
            <?php if ($items === []): ?>
                <p class="table-note">Belum ada perubahan konfigurasi.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="app-table">
                        <thead>
                        <tr>
                            <th>Waktu</th>
                            <th>Administrator</th>
                            <th>Aktivitas</th>
                            <th>Modul</th>
                            <th>Ringkasan</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($items as $item): ?>
                            <?php $action = (string) ($item['action'] ?? ''); ?>
                            <tr>
                                <td><?= e(format_waktu((string) ($item['created_at'] ?? ''))) ?></td>
                                <td><?= e((string) ($item['actor_nama'] ?? 'Sistem')) ?></td>
                                <td><?= e(audit_action_label($action)) ?></td>
                                <td><?= e(ApiFeatureRegistry::label((string) $featureKey)) ?></td>
                                <td><?= e(api_config_action_summary($action)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
