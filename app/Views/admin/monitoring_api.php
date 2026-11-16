<?php
/**
 * Admin — Monitoring Penggunaan API (AI + WhatsApp).
 *
 * Menampilkan data nyata. Tidak ada token, isi pesan, OTP, atau isi laporan.
 * Biaya/kuota resmi tidak dikarang.
 */

declare(strict_types=1);

$rows = is_array($rows ?? null) ? $rows : [];
$summary = is_array($summary ?? null) ? $summary : [];
$filters = is_array($filters ?? null) ? $filters : [];
$featureOptions = is_array($feature_options ?? null) ? $feature_options : [];
$providerOptions = is_array($provider_options ?? null) ? $provider_options : [];
$periodOptions = is_array($period_options ?? null) ? $period_options : [];
$range = is_array($range ?? null) ? $range : ['', ''];
$costNote = (string) ($cost_note ?? '');
$flashSukses = $flash_sukses ?? null;
$flashError = $flash_error ?? null;
$formatNumber = static fn (mixed $value): string => number_format((int) $value, 0, ',', '.');
$selected = static fn (mixed $value, mixed $expected): string => (string) $value === (string) $expected ? ' selected' : '';
$rangeLabel = ($range[0] ?? '') !== ''
    ? format_tanggal((string) $range[0]) . ' – ' . format_tanggal((string) $range[1])
    : 'Semua data';
?>

<div class="monitoring-page">
<?= component('page-header', [
    'title' => 'Monitoring Penggunaan API',
    'subtitle' => 'Pantau pemakaian AI Chatbot dan WhatsApp berdasarkan data nyata, bukan estimasi kuota resmi.',
]) ?>

<?php if ($flashSukses !== null): ?>
    <div class="callout callout--success"><?= icon('check') ?><p><?= e((string) $flashSukses) ?></p></div>
<?php endif; ?>
<?php if ($flashError !== null): ?>
    <div class="callout callout--danger"><?= icon('alert') ?><p><?= e((string) $flashError) ?></p></div>
<?php endif; ?>

<div class="stat-grid stat-grid--4">
    <div class="stat-card">
        <div class="stat-card-body">
            <div class="stat-card-label">Request hari ini</div>
            <div class="stat-card-value"><?= $formatNumber($summary['today_requests'] ?? 0) ?></div>
            <div class="stat-card-hint">AI + WhatsApp</div>
        </div>
    </div>
    <div class="stat-card stat-card--info">
        <div class="stat-card-body">
            <div class="stat-card-label">Request bulan ini</div>
            <div class="stat-card-value"><?= $formatNumber($summary['month_requests'] ?? 0) ?></div>
            <div class="stat-card-hint">Sejak awal bulan</div>
        </div>
    </div>
    <div class="stat-card stat-card--success">
        <div class="stat-card-body">
            <div class="stat-card-label">Sukses periode</div>
            <div class="stat-card-value"><?= $formatNumber($summary['success'] ?? 0) ?></div>
            <div class="stat-card-hint"><?= e($rangeLabel) ?></div>
        </div>
    </div>
    <div class="stat-card stat-card--danger">
        <div class="stat-card-body">
            <div class="stat-card-label">Gagal periode</div>
            <div class="stat-card-value"><?= $formatNumber($summary['failed'] ?? 0) ?></div>
            <div class="stat-card-hint"><?= e($rangeLabel) ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-card-body">
            <div class="stat-card-label">Token AI periode</div>
            <div class="stat-card-value"><?= $formatNumber($summary['tokens'] ?? 0) ?></div>
            <div class="stat-card-hint">Bila provider melaporkan token</div>
        </div>
    </div>
    <div class="stat-card stat-card--warning">
        <div class="stat-card-body">
            <div class="stat-card-label">Pesan pending</div>
            <div class="stat-card-value"><?= $formatNumber($summary['messages_pending'] ?? 0) ?></div>
            <div class="stat-card-hint">Belum diproses provider</div>
        </div>
    </div>
    <div class="stat-card stat-card--success">
        <div class="stat-card-body">
            <div class="stat-card-label">Delivered/read</div>
            <div class="stat-card-value"><?= $formatNumber($summary['messages_delivered'] ?? 0) ?></div>
            <div class="stat-card-hint">Dari webhook terverifikasi</div>
        </div>
    </div>
    <div class="stat-card stat-card--danger">
        <div class="stat-card-body">
            <div class="stat-card-label">Pesan gagal</div>
            <div class="stat-card-value"><?= $formatNumber($summary['messages_failed'] ?? 0) ?></div>
            <div class="stat-card-hint">Setelah retry</div>
        </div>
    </div>
</div>

<section class="monitoring-cost-card" aria-labelledby="monitoring-cost-title">
    <span class="monitoring-cost-card__icon"><?= icon('info') ?></span>
    <div class="monitoring-cost-card__body">
        <h4 id="monitoring-cost-title">Estimasi Biaya</h4>
        <p><?= e($costNote) ?></p>
    </div>
</section>

<section class="card">
    <div class="card-head">
        <h3 class="card-title">Rincian per Fitur &amp; Provider</h3>
        <span class="chip chip--muted"><?= e($rangeLabel) ?></span>
    </div>
    <div class="card-body">
        <form class="api-filter" method="get" action="<?= e(url('/admin/monitoring-api')) ?>">
            <div class="api-filter-field">
                <label class="form-label" for="filter-feature">Fitur</label>
                <select class="form-select" id="filter-feature" name="fitur">
                    <option value="">Semua fitur</option>
                    <?php foreach ($featureOptions as $key => $label): ?>
                        <option value="<?= e((string) $key) ?>"<?= $selected($filters['feature'] ?? '', $key) ?>><?= e((string) $label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="api-filter-field">
                <label class="form-label" for="filter-provider">Provider</label>
                <select class="form-select" id="filter-provider" name="provider">
                    <option value="">Semua provider</option>
                    <?php foreach ($providerOptions as $key => $label): ?>
                        <option value="<?= e((string) $key) ?>"<?= $selected($filters['provider'] ?? '', $key) ?>><?= e((string) $label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="api-filter-field">
                <label class="form-label" for="filter-period">Periode</label>
                <select class="form-select" id="filter-period" name="periode">
                    <?php foreach ($periodOptions as $key => $label): ?>
                        <option value="<?= e((string) $key) ?>"<?= $selected($filters['period'] ?? 'bulan_ini', $key) ?>><?= e((string) $label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="api-filter-actions">
                <button class="btn btn-primary btn-sm" type="submit"><?= icon('filter') ?><span>Terapkan</span></button>
                <a class="btn btn-outline-light btn-sm" href="<?= e(url('/admin/monitoring-api')) ?>">Reset</a>
            </div>
        </form>

        <?php if ($rows === []): ?>
            <div class="empty-state empty-state--compact">
                <div class="empty-state-icon"><?= icon('activity') ?></div>
                <p class="empty-state-title">Belum ada data pada filter ini</p>
                <p class="empty-state-message">Data akan muncul setelah AI atau WhatsApp benar-benar dipakai. Tidak ada angka contoh.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="app-table">
                    <thead>
                    <tr>
                        <th>Fitur</th>
                        <th>Provider</th>
                        <th>Hari Ini</th>
                        <th>Bulan Ini</th>
                        <th>Periode</th>
                        <th>Sukses</th>
                        <th>Gagal</th>
                        <th>Token</th>
                        <th>Pesan WA</th>
                        <th>Status Koneksi</th>
                        <th>Aktivitas Terakhir</th>
                        <th>Estimasi Biaya</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td>
                                <strong><?= e((string) ($row['feature_label'] ?? '')) ?></strong>
                                <?php if ((string) ($row['model'] ?? '') !== ''): ?>
                                    <div class="table-note">Model: <?= e((string) $row['model']) ?></div>
                                <?php endif; ?>
                                <div class="table-note"><?= !empty($row['configured']) ? 'Terkonfigurasi' : 'Belum terkonfigurasi' ?></div>
                            </td>
                            <td><?= e((string) ($row['provider_label'] ?? '')) ?></td>
                            <td><?= $formatNumber($row['today_requests'] ?? 0) ?></td>
                            <td><?= $formatNumber($row['month_requests'] ?? 0) ?></td>
                            <td><?= $formatNumber($row['period_requests'] ?? 0) ?></td>
                            <td><?= $formatNumber($row['success'] ?? 0) ?></td>
                            <td><?= $formatNumber($row['failed'] ?? 0) ?></td>
                            <td><?= $formatNumber($row['tokens'] ?? 0) ?></td>
                            <td>
                                <?php if ((int) ($row['messages'] ?? 0) > 0): ?>
                                    <strong><?= $formatNumber($row['messages'] ?? 0) ?></strong>
                                    <div class="table-note">
                                        Diterima <?= $formatNumber($row['messages_accepted'] ?? 0) ?>
                                        · Delivered <?= $formatNumber($row['messages_delivered'] ?? 0) ?>
                                    </div>
                                    <div class="table-note">
                                        Pending <?= $formatNumber($row['messages_pending'] ?? 0) ?>
                                        · Gagal <?= $formatNumber($row['messages_failed'] ?? 0) ?>
                                    </div>
                                <?php else: ?>
                                    <span class="table-note">—</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="table-note"><?= e((string) ($row['last_connection'] ?? '—')) ?></span></td>
                            <td><?= e((string) (($row['last_activity'] ?? '') !== '' ? format_waktu((string) $row['last_activity']) : '—')) ?></td>
                            <td><span class="table-note monitoring-cost-value">Belum tersedia</span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
</div>
