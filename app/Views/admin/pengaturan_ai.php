<?php
/**
 * Admin — Pengaturan AI Chatbot, Monitoring Penggunaan, dan FAQ.
 *
 * API key yang tersimpan TIDAK PERNAH ditampilkan kembali; hanya statusnya
 * ("Tersimpan"/"Belum Dikonfigurasi") yang ditampilkan. Setiap provider punya
 * key/model sendiri dan key provider lain tidak dihapus saat berganti provider.
 *
 * Variabel:
 * - $ai              : data konfigurasi AMAN (tanpa API key).
 * - $suggestedModels : saran model untuk provider aktif.
 * - $providerMeta    : metadata provider (label, model, base).
 * - $faqs/$faqEdit/$faqCategories/$faqSearch/$faqCategory.
 * - $flash_sukses / $flash_error, $csrf, $saveUrl, $testUrl, $faqSaveUrl, ...
 */

declare(strict_types=1);

$ai = is_array($ai ?? null) ? $ai : [];
$providers = is_array($ai['providers'] ?? null) ? $ai['providers'] : [];
$providerMeta = is_array($providerMeta ?? null) ? $providerMeta : [];
$suggestedModels = is_array($suggestedModels ?? null) ? $suggestedModels : [];
$usage = is_array($ai['usage'] ?? null) ? $ai['usage'] : [];
$sourceUsage = is_array($ai['source_usage'] ?? null) ? $ai['source_usage'] : [];
$faqs = is_array($faqs ?? null) ? $faqs : [];
$faqCategories = is_array($faqCategories ?? null) ? $faqCategories : [];
$faqEdit = is_array($faqEdit ?? null) ? $faqEdit : null;

$flashSukses = $flash_sukses ?? null;
$flashError = $flash_error ?? null;
$csrf = (string) ($csrf ?? csrf_token());
$saveUrl = (string) ($saveUrl ?? url('/admin/pengaturan/ai'));
$testUrl = (string) ($testUrl ?? url('/admin/pengaturan/ai/test'));
$testDatabaseUrl = (string) ($testDatabaseUrl ?? url('/admin/pengaturan/ai/test-database'));
$faqSaveUrl = (string) ($faqSaveUrl ?? url('/admin/pengaturan/ai/faq'));
$faqToggleUrl = (string) ($faqToggleUrl ?? url('/admin/pengaturan/ai/faq/status'));
$faqDeleteUrl = (string) ($faqDeleteUrl ?? url('/admin/pengaturan/ai/faq/hapus'));
$faqSearch = (string) ($faqSearch ?? '');
$faqCategory = (string) ($faqCategory ?? '');

$activeProvider = (string) ($ai['provider'] ?? 'gemini');
$activeMeta = is_array($providerMeta[$activeProvider] ?? null) ? $providerMeta[$activeProvider] : [];
$enabled = !empty($ai['is_enabled']);
$hasKey = !empty($ai['has_stored_key']);
$encryptionReady = !empty($ai['encryption_ready']);
$libsodiumAvailable = !empty($ai['libsodium_available']);
$masterKeyPresent = !empty($ai['master_key_present']);
$activeConfig = is_array($providers[$activeProvider] ?? null) ? $providers[$activeProvider] : [];
$ollama = is_array($ai['ollama'] ?? null) ? $ai['ollama'] : [];
$lastTests = is_array($ai['last_tests'] ?? null) ? $ai['last_tests'] : [];
$hybridMode = !empty($ai['hybrid_mode']);
$localDbEnabled = !empty($ai['local_db_enabled']);
$preferLocalDb = !empty($ai['prefer_local_db']);
$fallbackEnabled = !empty($ai['fallback_enabled']);
$ollamaEnabled = !empty($ai['ollama_enabled']);
$ollamaTest = is_array($ollama['tested'] ?? null) ? $ollama['tested'] : ['status' => 'untested', 'label' => 'Belum diuji'];
$mysqlTest = is_array($lastTests['mysql'] ?? null) ? $lastTests['mysql'] : ['status' => 'untested', 'label' => 'Belum diuji'];

$v = static fn (string $key, string $default = ''): string => (string) ($ai[$key] ?? $default);
$lastTest = is_array($usage['last_test'] ?? null) ? $usage['last_test'] : null;
$usageByUser = is_array($usage['today_by_user'] ?? null) ? $usage['today_by_user'] : [];
$providersMonth = is_array($usage['providers_month'] ?? null) ? $usage['providers_month'] : [];
$providerMonitor = is_array($ai['provider_monitor'] ?? null) ? $ai['provider_monitor'] : [];
$monitorStatuses = is_array($providerMonitor['statuses'] ?? null) ? $providerMonitor['statuses'] : [];
$monitorEvents = is_array($providerMonitor['events'] ?? null) ? $providerMonitor['events'] : [];
$monitorCounts = is_array($providerMonitor['counts'] ?? null) ? $providerMonitor['counts'] : [];
$monitorAvailable = !empty($providerMonitor['available']);
$tokenReportsMonth = 0;
foreach ($providersMonth as $providerRow) {
    $tokenReportsMonth += (int) ($providerRow['token_reports'] ?? 0);
}

$formatNumber = static fn (mixed $value): string => number_format((float) $value, 0, ',', '.');
$toneClass = static fn (string $tone): string => match ($tone) {
    'success' => 'chip--success',
    'danger' => 'chip--danger',
    'warning' => 'chip--warning',
    default => 'chip--muted',
};
?>
<?= component('page-header', [
    'title' => 'Pengaturan AI Chatbot',
    'subtitle' => 'Kelola provider (Gemini, DeepSeek, OpenAI), API key terenkripsi, monitoring penggunaan, dan FAQ resmi WiFiCare AI.',
]) ?>

<?php if ($flashSukses !== null): ?>
    <div class="callout callout--success"><?= icon('check') ?><p><?= e($flashSukses) ?></p></div>
<?php endif; ?>
<?php if ($flashError !== null): ?>
    <div class="callout callout--danger"><?= icon('alert') ?><p><?= e($flashError) ?></p></div>
<?php endif; ?>

<?php if (!$encryptionReady): ?>
    <div class="callout callout--warning">
        <?= icon('lock') ?>
        <p>
            <strong>Enkripsi API key belum siap.</strong>
            <?php if (!$libsodiumAvailable): ?>
                Ekstensi <code>sodium</code> (libsodium) tidak aktif di server. Aktifkan ekstensi tersebut lalu muat ulang halaman ini.
            <?php elseif (!$masterKeyPresent): ?>
                Master key enkripsi (<code>AI_SETTINGS_KEY</code>) belum disiapkan di berkas <code>.env</code> server.
                Jalankan <code>php bin/ai-keygen.php</code> lalu salin hasilnya ke <code>AI_SETTINGS_KEY</code>.
            <?php endif; ?>
            Demi keamanan, API key <strong>tidak akan disimpan</strong> selama enkripsi belum siap.
        </p>
    </div>
<?php endif; ?>

<form id="aiSettingsForm" class="form-stack" method="post" action="<?= e($saveUrl) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="hybrid_settings_present" value="1">

    <div class="grid-2">
        <section class="card">
            <div class="card-head">
                <h3 class="card-title">Status &amp; Provider</h3>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <span class="form-label">Status layanan AI</span>
                    <label class="switch" for="aiEnabled">
                        <input type="checkbox" id="aiEnabled" name="is_enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
                        <span class="switch-track" aria-hidden="true"></span>
                        <span class="switch-label" id="aiEnabledLabel"><?= $enabled ? 'Aktif' : 'Nonaktif' ?></span>
                    </label>
                    <p class="form-hint">
                        Bila dinonaktifkan, floating AI menampilkan pesan layanan nonaktif. API key
                        <strong>tidak dihapus</strong> sehingga dapat diaktifkan kembali kapan saja.
                    </p>
                </div>

                <div class="form-group">
                    <label class="form-label" for="aiProvider">Provider AI aktif</label>
                    <select class="form-control" id="aiProvider" name="provider">
                        <?php foreach ($providerMeta as $id => $meta): ?>
                            <option value="<?= e((string) $id) ?>"
                                    data-model="<?= e((string) ($providers[$id]['model'] ?? $meta['default_model'] ?? '')) ?>"
                                    data-base="<?= e((string) ($providers[$id]['base'] ?? $meta['default_base'] ?? '')) ?>"
                                    data-has-key="<?= !empty($providers[$id]['has_stored_key']) ? '1' : '0' ?>"
                                    data-label="<?= e((string) ($meta['label'] ?? $id)) ?>"
                                    <?= (string) $id === $activeProvider ? 'selected' : '' ?>>
                                <?= e((string) ($meta['label'] ?? $id)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="form-hint">Mengganti provider tidak menghapus API key provider lain. Key hanya dipakai setelah disimpan.</p>
                </div>

                <dl class="detail-list">
                    <div>
                        <dt>API Key Provider Aktif</dt>
                        <dd>
                            <?php if ($hasKey): ?>
                                <span class="chip chip--success">API Key Tersimpan</span>
                            <?php else: ?>
                                <span class="chip chip--muted">Belum Dikonfigurasi</span>
                            <?php endif; ?>
                        </dd>
                    </div>
                    <div>
                        <dt>Sumber kunci aktif</dt>
                        <dd>
                            <?php
                            $source = (string) $v('key_source', 'none');
                            echo e(match ($source) {
                                'database' => 'Tersimpan (terenkripsi)',
                                'env' => 'Environment (.env)',
                                default => 'Belum tersedia',
                            });
                            ?>
                        </dd>
                    </div>
                    <div>
                        <dt>Terakhir diperbarui</dt>
                        <dd><?= e((string) ($v('updated_at') !== '' ? $v('updated_at') : '—')) ?></dd>
                    </div>
                </dl>
            </div>
        </section>

        <section class="card">
            <div class="card-head">
                <h3 class="card-title">API Key &amp; Model</h3>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label" for="aiApiKey">API Key <span id="aiProviderKeyLabel"><?= e((string) ($activeMeta['label'] ?? 'Provider')) ?></span></label>
                    <div class="input-with-action">
                        <input class="form-control" id="aiApiKey" name="api_key" type="password"
                               autocomplete="new-password" spellcheck="false"
                               placeholder="<?= $hasKey ? 'Biarkan kosong untuk mempertahankan key lama' : 'Tempel API key provider baru' ?>"
                               aria-describedby="aiApiKeyHint" <?= $encryptionReady ? '' : 'disabled' ?>>
                        <button class="btn btn-outline-light btn-sm" type="button" id="aiKeyToggle"
                                aria-pressed="false" aria-controls="aiApiKey"<?= $encryptionReady ? '' : ' disabled' ?>>
                            <?= icon('eye') ?><span id="aiKeyToggleText">Tampilkan</span>
                        </button>
                    </div>
                    <p class="form-hint" id="aiApiKeyHint">
                        API key dienkripsi (libsodium) sebelum disimpan dan tidak pernah ditampilkan kembali.
                        Kosongkan bila tidak ingin mengganti key. Key provider lain dipertahankan.
                    </p>
                </div>

                <div class="form-group">
                    <label class="form-label" for="aiModel">Nama model</label>
                    <input class="form-control" id="aiModel" name="model" list="aiModelList"
                           value="<?= e($v('model', (string) ($activeMeta['default_model'] ?? ''))) ?>" maxlength="120" required>
                    <datalist id="aiModelList">
                        <?php foreach ($suggestedModels as $modelName): ?>
                            <option value="<?= e((string) $modelName) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <p class="form-hint">Model harus tersedia untuk provider dan API key yang dipakai. Gunakan Tes Koneksi untuk memastikan.</p>
                </div>

                <div class="form-group">
                    <label class="form-label" for="aiBaseUrl">Base URL provider</label>
                    <input class="form-control" id="aiBaseUrl" name="base_url" type="url"
                           value="<?= e($v('base_url_none', (string) ($activeConfig['base'] ?? $activeMeta['default_base'] ?? ''))) ?>"
                           placeholder="https://..." maxlength="255">
                    <p class="form-hint">Hanya URL HTTPS. Ubah hanya bila memang diperlukan.</p>
                </div>

                <div class="callout callout--info">
                    <?= icon('shield') ?>
                    <p>API key tidak pernah dikirim ke browser, tidak muncul di HTML/JSON, dan tidak dicatat pada audit log.</p>
                </div>
            </div>
        </section>
    </div>

    <section class="card">
        <div class="card-head">
            <h3 class="card-title">Mode Hybrid &amp; Asisten Database Lokal</h3>
            <span class="chip <?= $localDbEnabled ? 'chip--success' : 'chip--muted' ?>">
                <?= $localDbEnabled ? 'Database lokal aktif' : 'Database lokal nonaktif' ?>
            </span>
        </div>
        <div class="card-body">
            <div class="grid-2">
                <div>
                    <div class="form-group">
                        <label class="switch" for="aiHybridMode">
                            <input type="checkbox" id="aiHybridMode" name="hybrid_mode" value="1" <?= $hybridMode ? 'checked' : '' ?>>
                            <span class="switch-track" aria-hidden="true"></span>
                            <span class="switch-label">Mode hybrid</span>
                        </label>
                        <p class="form-hint">Utamakan data database untuk pertanyaan operasional, lalu provider AI untuk troubleshooting/percakapan umum.</p>
                    </div>
                    <div class="form-group">
                        <label class="switch" for="aiLocalDbEnabled">
                            <input type="checkbox" id="aiLocalDbEnabled" name="local_db_enabled" value="1" <?= $localDbEnabled ? 'checked' : '' ?>>
                            <span class="switch-track" aria-hidden="true"></span>
                            <span class="switch-label">Asisten database lokal (tanpa API key)</span>
                        </label>
                        <p class="form-hint">Menjawab pertanyaan data yang didukung dari MySQL read-only. Tidak memanggil provider cloud dan tidak mengarang angka.</p>
                    </div>
                    <div class="form-group">
                        <label class="switch" for="aiPreferLocalDb">
                            <input type="checkbox" id="aiPreferLocalDb" name="prefer_local_db" value="1" <?= $preferLocalDb ? 'checked' : '' ?>>
                            <span class="switch-track" aria-hidden="true"></span>
                            <span class="switch-label">Prioritaskan database lokal</span>
                        </label>
                        <p class="form-hint">Bila aktif, intent data operasional dijawab langsung dari database tanpa memakai kuota AI.</p>
                    </div>
                    <div class="form-group">
                        <label class="switch" for="aiFallbackEnabled">
                            <input type="checkbox" id="aiFallbackEnabled" name="fallback_enabled" value="1" <?= $fallbackEnabled ? 'checked' : '' ?>>
                            <span class="switch-track" aria-hidden="true"></span>
                            <span class="switch-label">Fallback otomatis</span>
                        </label>
                        <p class="form-hint">Bila cloud gagal, coba Ollama lokal (jika aktif) lalu asisten database lokal. Tidak ada retry tanpa batas.</p>
                    </div>
                </div>
                <div>
                    <div class="form-group">
                        <label class="switch" for="aiOllamaEnabled">
                            <input type="checkbox" id="aiOllamaEnabled" name="ollama_enabled" value="1" <?= $ollamaEnabled ? 'checked' : '' ?>>
                            <span class="switch-track" aria-hidden="true"></span>
                            <span class="switch-label">Ollama lokal (opsional)</span>
                        </label>
                        <p class="form-hint">Adapter OpenAI-compatible untuk Ollama di komputer server. Tidak mengunduh model dan tidak memberi akses SQL.</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="aiOllamaModel">Model Ollama</label>
                        <input class="form-control" id="aiOllamaModel" name="ollama_model" maxlength="120"
                               value="<?= e((string) ($ollama['model'] ?? '')) ?>" placeholder="llama3.2:3b">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="aiOllamaBase">Base URL Ollama</label>
                        <input class="form-control" id="aiOllamaBase" name="ollama_base_url" type="url" maxlength="255"
                               value="<?= e((string) ($ollama['base'] ?? '')) ?>" placeholder="http://127.0.0.1:11434/v1">
                        <p class="form-hint">HTTP hanya diizinkan untuk loopback/localhost. Model harus sudah tersedia di Ollama.</p>
                    </div>
                    <dl class="detail-list">
                        <div>
                            <dt>Status tes Ollama</dt>
                            <dd><span class="chip <?= ($ollamaTest['status'] ?? '') === 'success' ? 'chip--success' : (($ollamaTest['status'] ?? '') === 'failed' ? 'chip--danger' : 'chip--muted') ?>"><?= e((string) ($ollamaTest['label'] ?? 'Belum diuji')) ?></span></dd>
                        </div>
                        <div>
                            <dt>Status tes MySQL read-only</dt>
                            <dd><span class="chip <?= ($mysqlTest['status'] ?? '') === 'success' ? 'chip--success' : (($mysqlTest['status'] ?? '') === 'failed' ? 'chip--danger' : 'chip--muted') ?>"><?= e((string) ($mysqlTest['label'] ?? 'Belum diuji')) ?></span></dd>
                        </div>
                    </dl>
                    <p class="form-hint">Status “teruji” hanya muncul setelah tes nyata. Aplikasi tidak menampilkan “Online” tanpa bukti koneksi.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card-head">
            <h3 class="card-title">Daftar Provider &amp; Status Kunci</h3>
        </div>
        <div class="card-body">
            <div class="table-wrap">
                <table class="app-table">
                    <thead>
                    <tr><th>Provider</th><th>Model tersimpan</th><th>API Key</th><th>Status</th><th>Tes terakhir</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($providers as $id => $provider): ?>
                        <?php $providerTest = is_array($lastTests[$id] ?? null) ? $lastTests[$id] : ['status' => 'untested', 'label' => 'Belum diuji']; ?>
                        <tr>
                            <td><strong><?= e((string) ($provider['label'] ?? $id)) ?></strong><?= (string) $id === $activeProvider ? ' <span class="chip chip--primary">Aktif</span>' : '' ?></td>
                            <td><code><?= e((string) ($provider['model'] ?? '—')) ?></code></td>
                            <td>
                                <?php if (!empty($provider['has_stored_key'])): ?>
                                    <span class="chip chip--success">Tersimpan</span>
                                <?php elseif (($provider['key_source'] ?? 'none') === 'env'): ?>
                                    <span class="chip chip--warning">Dari .env</span>
                                <?php elseif ((string) $id === 'ollama'): ?>
                                    <span class="chip chip--muted">Tidak perlu API key</span>
                                <?php else: ?>
                                    <span class="chip chip--muted">Belum ada</span>
                                <?php endif; ?>
                            </td>
                            <td><?= !empty($provider['configured']) ? 'Siap dipakai' : 'Belum lengkap' ?></td>
                            <td>
                                <span class="chip <?= ($providerTest['status'] ?? '') === 'success' ? 'chip--success' : (($providerTest['status'] ?? '') === 'failed' ? 'chip--danger' : 'chip--muted') ?>">
                                    <?= e((string) ($providerTest['label'] ?? 'Belum diuji')) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card-head">
            <h3 class="card-title">Model &amp; Batas Layanan</h3>
        </div>
        <div class="card-body">
            <div class="grid-2">
                <div class="form-group">
                    <label class="form-label" for="aiMaxOutput">Batas output token</label>
                    <input class="form-control" id="aiMaxOutput" name="max_output_tokens" type="number"
                           min="64" max="8192" step="1" value="<?= e($v('max_output_tokens', '1500')) ?>">
                    <p class="form-hint">64–8192 token.</p>
                </div>
                <div class="form-group">
                    <label class="form-label" for="aiTimeout">Timeout API (detik)</label>
                    <input class="form-control" id="aiTimeout" name="timeout_seconds" type="number"
                           min="5" max="120" step="1" value="<?= e($v('timeout_seconds', '60')) ?>">
                    <p class="form-hint">5–120 detik.</p>
                </div>
                <div class="form-group">
                    <label class="form-label" for="aiRateLimit">Batas request per jendela</label>
                    <input class="form-control" id="aiRateLimit" name="rate_limit" type="number"
                           min="1" max="1000" step="1" value="<?= e($v('rate_limit', '20')) ?>">
                    <p class="form-hint">1–1000 permintaan per jendela waktu.</p>
                </div>
                <div class="form-group">
                    <label class="form-label" for="aiRateWindow">Jendela waktu (detik)</label>
                    <input class="form-control" id="aiRateWindow" name="rate_window" type="number"
                           min="10" max="3600" step="1" value="<?= e($v('rate_window', '60')) ?>">
                    <p class="form-hint">10–3600 detik.</p>
                </div>
                <div class="form-group">
                    <label class="form-label" for="aiDailyLimit">Batas request harian per pengguna</label>
                    <input class="form-control" id="aiDailyLimit" name="daily_limit" type="number"
                           min="1" max="10000" step="1" value="<?= e($v('daily_limit', '100')) ?>">
                    <p class="form-hint">Ditegakkan server-side berdasarkan log penggunaan nyata.</p>
                </div>
                <div class="form-group">
                    <label class="form-label" for="aiMaxMessage">Batas panjang pesan</label>
                    <input class="form-control" id="aiMaxMessage" name="max_message_length" type="number"
                           min="100" max="10000" step="1" value="<?= e($v('max_message_length', '2000')) ?>">
                    <p class="form-hint">100–10000 karakter.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card-head">
            <h3 class="card-title">System Instruction (Opsional)</h3>
        </div>
        <div class="card-body">
            <div class="form-group">
                <label class="form-label" for="aiSystemInstruction">Instruksi tambahan untuk WiFiCare AI</label>
                <textarea class="form-control" id="aiSystemInstruction" name="system_instruction" rows="5"
                          maxlength="2000" placeholder="Contoh: Selalu sebut jam operasional CS 08.00–20.00 untuk pertanyaan gangguan."><?= e($v('system_instruction')) ?></textarea>
                <p class="form-hint">
                    Hanya menambah gaya/konteks. Instruksi ini <strong>tidak dapat</strong> menonaktifkan aturan
                    keamanan backend, RBAC, aturan read-only database, atau penolakan permintaan coding.
                </p>
            </div>
        </div>
    </section>

    <div class="form-actions">
        <button class="btn btn-outline-light" type="button" id="aiTestBtn"
                data-test-url="<?= e($testUrl) ?>">
            <?= icon('activity') ?><span id="aiTestBtnText">Tes Koneksi Provider</span>
        </button>
        <button class="btn btn-outline-light" type="button" id="aiDbTestBtn"
                data-test-url="<?= e($testDatabaseUrl) ?>">
            <?= icon('database') ?><span id="aiDbTestBtnText">Tes MySQL Read-only</span>
        </button>
        <button class="btn btn-primary" type="button" data-modal-open="aiConfirmModal">
            <?= icon('check') ?><span>Simpan Pengaturan</span>
        </button>
    </div>

    <p class="form-hint" id="aiTestResult" role="status" aria-live="polite"></p>
    <p class="form-hint" id="aiDbTestResult" role="status" aria-live="polite"></p>
    <p class="form-hint">Catatan: Tes Koneksi Provider menggunakan API sungguhan dan <strong>dapat mengurangi kuota API</strong> Anda. Tes MySQL hanya menjalankan <code>SELECT 1</code> melalui koneksi read-only.</p>
</form>

<section class="card" id="monitoring-ai">
    <div class="card-head">
        <h3 class="card-title">Monitoring Penggunaan AI</h3>
        <span class="chip chip--primary"><?= e((string) ($ai['provider_label'] ?? 'Provider')) ?> · <?= e($v('model', '—')) ?></span>
    </div>
    <div class="card-body">
        <div class="stat-grid">
            <div class="stat-card">
                <span class="stat-label">Request hari ini</span>
                <strong class="stat-value"><?= e($formatNumber($usage['today_total'] ?? 0)) ?></strong>
                <span class="stat-meta"><?= e($formatNumber($usage['today_success'] ?? 0)) ?> berhasil · <?= e($formatNumber($usage['today_failed'] ?? 0)) ?> gagal</span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Request bulan ini</span>
                <strong class="stat-value"><?= e($formatNumber($usage['month_total'] ?? 0)) ?></strong>
                <span class="stat-meta"><?= e($formatNumber($usage['month_success'] ?? 0)) ?> berhasil · <?= e($formatNumber($usage['month_failed'] ?? 0)) ?> gagal</span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Token bulan ini</span>
                <strong class="stat-value"><?= $tokenReportsMonth > 0 ? e($formatNumber($usage['tokens_month'] ?? 0)) : 'Tidak tersedia' ?></strong>
                <span class="stat-meta"><?= $tokenReportsMonth > 0 ? 'Dilaporkan provider pada ' . e($formatNumber($tokenReportsMonth)) . ' request' : 'Provider belum melaporkan token' ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Batas harian / pengguna</span>
                <strong class="stat-value"><?= e($formatNumber($usage['daily_limit'] ?? $v('daily_limit', '100'))) ?></strong>
                <span class="stat-meta">Rate limit: <?= e($v('rate_limit', '20')) ?> / <?= e($v('rate_window', '60')) ?> detik</span>
            </div>
        </div>

        <div class="stat-grid">
            <div class="stat-card">
                <span class="stat-label">Akses data lokal (30 hari)</span>
                <strong class="stat-value"><?= e($formatNumber($sourceUsage['local'] ?? 0)) ?></strong>
                <span class="stat-meta"><?= e($formatNumber($sourceUsage['tool'] ?? 0)) ?> lewat tool cloud</span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Request cloud bulan ini</span>
                <strong class="stat-value"><?= e($formatNumber($usage['cloud_ai_month'] ?? $usage['cloud_month'] ?? 0)) ?></strong>
                <span class="stat-meta"><?= e($formatNumber($usage['local_database_month'] ?? 0)) ?> database &middot; <?= e($formatNumber($usage['local_faq_month'] ?? 0)) ?> FAQ lokal</span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Timeout cloud bulan ini</span>
                <strong class="stat-value"><?= e($formatNumber($usage['timeout_month'] ?? 0)) ?></strong>
                <span class="stat-meta">Data lokal tidak bergantung pada timeout ini</span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Intent lokal tak dikenal</span>
                <strong class="stat-value"><?= e($formatNumber($sourceUsage['unknown_intent'] ?? 0)) ?></strong>
                <span class="stat-meta">30 hari terakhir, metadata saja</span>
            </div>
        </div>

        <h4 class="section-title">Status provider &amp; batas API</h4>
        <?php if (!$monitorAvailable): ?>
            <div class="callout callout--warning">
                <?= icon('alert') ?>
                <p>Tabel monitoring provider belum tersedia. Jalankan migrasi <code>database/migrations/010_ai_provider_events.sql</code>. Jawaban database lokal tetap berfungsi.</p>
            </div>
        <?php elseif ($monitorStatuses === []): ?>
            <p class="form-hint">Belum ada data status provider.</p>
        <?php else: ?>
            <div class="stat-grid">
                <?php foreach ($monitorStatuses as $status): ?>
                    <div class="stat-card">
                        <span class="stat-label"><?= e((string) ($status['label'] ?? '-')) ?></span>
                        <strong class="stat-value"><span class="chip <?= e($toneClass((string) ($status['tone'] ?? 'muted'))) ?>"><?= e((string) ($status['status_label'] ?? 'Belum ada data')) ?></span></strong>
                        <span class="stat-meta">
                            Model: <?= e((string) ($status['model'] ?? '') !== '' ? (string) $status['model'] : 'Tidak tersedia') ?><br>
                            <?php if (!empty($status['last_event_at'])): ?>
                                Kejadian terakhir: <?= e((string) $status['last_event_at']) ?>
                                <?php if (!empty($status['last_event_label'])): ?> &middot; <?= e((string) $status['last_event_label']) ?><?php endif; ?>
                            <?php else: ?>
                                Belum ada kejadian provider tercatat.
                            <?php endif; ?>
                            <?php if ((int) ($status['retry_after'] ?? 0) > 0): ?>
                                <br>Provider menyarankan coba lagi dalam <?= e($formatNumber($status['retry_after'])) ?> detik.
                            <?php endif; ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="form-hint">Status hanya berasal dari respons/error provider yang tercatat. Aplikasi tidak mengarang sisa kuota, saldo kredit, atau waktu reset.</p>
        <?php endif; ?>

        <h4 class="section-title">Ringkasan event provider (30 hari)</h4>
        <?php if (!$monitorAvailable): ?>
            <p class="form-hint">Tidak tersedia sampai migrasi monitoring dijalankan.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Kategori</th>
                            <th>Jumlah</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $countLabels = [
                            'quota_exceeded' => 'Kuota/kredit habis',
                            'rate_limit' => 'Rate limit sementara',
                            'authentication_error' => 'Kunci API tidak valid',
                            'permission_error' => 'Izin API ditolak',
                            'timeout' => 'Timeout',
                            'network_error' => 'Gangguan jaringan',
                            'model_unavailable' => 'Model tidak tersedia',
                            'server_error' => 'Gangguan server',
                            'other' => 'Lainnya',
                        ];
                        ?>
                        <?php foreach ($countLabels as $countKey => $countLabel): ?>
                            <tr>
                                <td><?= e($countLabel) ?></td>
                                <td><?= e($formatNumber($monitorCounts[$countKey] ?? 0)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td><strong>Total event</strong></td>
                            <td><strong><?= e($formatNumber($monitorCounts['total'] ?? 0)) ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <h4 class="section-title">Penggunaan per provider/model (bulan ini)</h4>
        <?php if ($providersMonth === []): ?>
            <p class="form-hint">Belum ada request provider bulan ini.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Provider</th>
                            <th>Model</th>
                            <th>Sumber</th>
                            <th>Request</th>
                            <th>Berhasil</th>
                            <th>Gagal</th>
                            <th>Token masuk</th>
                            <th>Token keluar</th>
                            <th>Total token</th>
                            <th>Error terakhir</th>
                            <th>Durasi rata-rata</th>
                            <th>Terakhir</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($providersMonth as $providerRow): ?>
                            <?php
                            $rowProvider = (string) ($providerRow['provider'] ?? '');
                            $rowSource = (string) ($providerRow['source'] ?? 'other');
                            $rowSourceLabel = match ($rowSource) {
                                'local_database' => 'Database lokal',
                                'local_faq' => 'FAQ lokal',
                                'cloud_ai' => 'Cloud AI',
                                default => 'Lainnya',
                            };
                            $tokenAvailable = (int) ($providerRow['token_reports'] ?? 0) > 0;
                            ?>
                            <tr>
                                <td><?= e((string) ($providers[$rowProvider]['label'] ?? ucfirst($rowProvider))) ?></td>
                                <td><?= e((string) ($providerRow['model'] ?? '') !== '' ? (string) $providerRow['model'] : 'Tidak tersedia') ?></td>
                                <td><?= e($rowSourceLabel) ?></td>
                                <td><?= e($formatNumber($providerRow['total'] ?? 0)) ?></td>
                                <td><?= e($formatNumber($providerRow['success'] ?? 0)) ?></td>
                                <td><?= e($formatNumber($providerRow['failed'] ?? 0)) ?></td>
                                <td><?= $tokenAvailable ? e($formatNumber($providerRow['prompt_tokens'] ?? 0)) : 'Tidak tersedia' ?></td>
                                <td><?= $tokenAvailable ? e($formatNumber($providerRow['completion_tokens'] ?? 0)) : 'Tidak tersedia' ?></td>
                                <td><?= $tokenAvailable ? e($formatNumber($providerRow['total_tokens'] ?? 0)) : 'Tidak tersedia' ?></td>
                                <td><?= !empty($providerRow['last_error']) ? e((string) $providerRow['last_error']) : '-' ?></td>
                                <td><?= e($formatNumber($providerRow['avg_duration_ms'] ?? 0)) ?> ms</td>
                                <td><?= e((string) ($providerRow['last_at'] ?? '-')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <h4 class="section-title">Kejadian provider terbaru</h4>
        <?php if (!$monitorAvailable): ?>
            <p class="form-hint">Tidak tersedia sampai migrasi monitoring dijalankan.</p>
        <?php elseif ($monitorEvents === []): ?>
            <p class="form-hint">Belum ada event provider tercatat.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Waktu</th>
                            <th>Provider</th>
                            <th>Model</th>
                            <th>Kejadian</th>
                            <th>HTTP</th>
                            <th>Retry</th>
                            <th>Notifikasi Admin</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($monitorEvents as $event): ?>
                            <tr>
                                <td><?= e((string) ($event['created_at_label'] ?? $event['created_at'] ?? '-')) ?></td>
                                <td><?= e((string) ($event['provider_label'] ?? $event['provider'] ?? '-')) ?></td>
                                <td><?= e((string) ($event['model'] ?? '') !== '' ? (string) $event['model'] : 'Tidak tersedia') ?></td>
                                <td>
                                    <?= e((string) ($event['event_label'] ?? '-')) ?>
                                    <?php if (!empty($event['provider_code'])): ?><br><code><?= e((string) $event['provider_code']) ?></code><?php endif; ?>
                                </td>
                                <td><?= $event['http_status'] === null ? 'Tidak tersedia' : e((string) $event['http_status']) ?></td>
                                <td><?= $event['retry_after'] === null ? 'Tidak tersedia' : e($formatNumber($event['retry_after'])) . ' detik' ?></td>
                                <td><?= !empty($event['notification_sent']) ? 'Terkirim' : 'Tidak' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <h4 class="section-title">Penggunaan per pengguna (hari ini)</h4>
        <?php if ($usageByUser === []): ?>
            <p class="form-hint">Belum ada permintaan chat hari ini.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Pengguna</th>
                            <th>Peran</th>
                            <th>Request</th>
                            <th>Gagal</th>
                            <th>Terakhir</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usageByUser as $row): ?>
                            <tr>
                                <td><?= e((string) ($row['nama'] ?? '-')) ?></td>
                                <td><?= e(role_label((string) ($row['role'] ?? '-'))) ?></td>
                                <td><?= e($formatNumber($row['total'] ?? 0)) ?></td>
                                <td><?= e($formatNumber($row['gagal'] ?? 0)) ?></td>
                                <td><?= e((string) ($row['terakhir'] ?? '-')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <dl class="detail-list">
            <div>
                <dt>Status koneksi terakhir</dt>
                <dd>
                    <?php if ($lastTest === null): ?>
                        <span class="chip chip--muted">Belum ada pengujian</span>
                    <?php else: ?>
                        <span class="chip <?= (int) ($lastTest['success'] ?? 0) === 1 ? 'chip--success' : 'chip--danger' ?>">
                            <?= (int) ($lastTest['success'] ?? 0) === 1 ? 'Berhasil' : 'Gagal' ?>
                        </span>
                        <?= e((string) ($lastTest['provider'] ?? '')) ?> · <?= e((string) ($lastTest['model'] ?? '')) ?>
                        <?php if (!empty($lastTest['error_code'])): ?> · <code><?= e((string) $lastTest['error_code']) ?></code><?php endif; ?>
                        · <?= e((string) ($lastTest['created_at'] ?? '')) ?>
                    <?php endif; ?>
                </dd>
            </div>
            <div>
                <dt>Estimasi biaya</dt>
                <dd>Tidak ditampilkan sebagai angka pasti. <?= e((string) ($usage['cost_note'] ?? '')) ?></dd>
            </div>
        </dl>
        <p class="form-hint">Monitoring hanya menyimpan metadata (provider, model, status, kode error, token). Isi percakapan dan API key tidak disimpan.</p>
    </div>
</section>

<section class="card" id="faq">
    <div class="card-head">
        <h3 class="card-title">FAQ &amp; Pengetahuan</h3>
        <span class="chip chip--muted"><?= e((string) count($faqs)) ?> entri</span>
    </div>
    <div class="card-body">
        <form class="filter-bar" method="get" action="<?= e(url('/admin/pengaturan/ai')) ?>#faq">
            <div class="form-group">
                <label class="form-label" for="faqSearch">Cari FAQ</label>
                <input class="form-control" id="faqSearch" name="q" value="<?= e($faqSearch) ?>" placeholder="Pertanyaan, jawaban, atau kategori">
            </div>
            <div class="form-group">
                <label class="form-label" for="faqCategoryFilter">Kategori</label>
                <select class="form-control" id="faqCategoryFilter" name="kategori">
                    <option value="">Semua kategori</option>
                    <?php foreach ($faqCategories as $category): ?>
                        <option value="<?= e((string) $category) ?>" <?= $faqCategory === (string) $category ? 'selected' : '' ?>><?= e((string) $category) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn btn-outline-light" type="submit">Cari</button>
            <?php if ($faqSearch !== '' || $faqCategory !== ''): ?>
                <a class="btn btn-outline-light" href="<?= e(url('/admin/pengaturan/ai')) ?>#faq">Reset</a>
            <?php endif; ?>
        </form>

        <div class="table-wrap">
            <table class="app-table">
                <thead>
                <tr><th>Pertanyaan</th><th>Kategori</th><th>Status</th><th>Diperbarui</th><th>Aksi</th></tr>
                </thead>
                <tbody>
                <?php if ($faqs === []): ?>
                    <tr><td colspan="5">Belum ada FAQ yang cocok.</td></tr>
                <?php endif; ?>
                <?php foreach ($faqs as $faq): ?>
                    <?php $faqId = (int) ($faq['id'] ?? 0); ?>
                    <tr>
                        <td>
                            <strong><?= e((string) ($faq['pertanyaan'] ?? '')) ?></strong>
                            <div class="form-hint"><?= e(mb_substr((string) ($faq['jawaban'] ?? ''), 0, 140)) ?><?= mb_strlen((string) ($faq['jawaban'] ?? '')) > 140 ? '…' : '' ?></div>
                        </td>
                        <td><?= e((string) ($faq['kategori'] ?? 'Umum')) ?></td>
                        <td>
                            <span class="chip <?= (int) ($faq['is_active'] ?? 0) === 1 ? 'chip--success' : 'chip--muted' ?>">
                                <?= (int) ($faq['is_active'] ?? 0) === 1 ? 'Aktif' : 'Nonaktif' ?>
                            </span>
                        </td>
                        <td><?= e((string) ($faq['updated_at'] ?? '')) ?></td>
                        <td>
                            <div class="table-actions">
                                <a class="btn btn-outline-light btn-sm" href="<?= e(url('/admin/pengaturan/ai')) ?>?edit=<?= e((string) $faqId) ?>#faq">Edit</a>
                                <form method="post" action="<?= e($faqToggleUrl) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= e((string) $faqId) ?>">
                                    <input type="hidden" name="is_active" value="<?= (int) ($faq['is_active'] ?? 0) === 1 ? '0' : '1' ?>">
                                    <button class="btn btn-outline-light btn-sm" type="submit"><?= (int) ($faq['is_active'] ?? 0) === 1 ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                                </form>
                                <form method="post" action="<?= e($faqDeleteUrl) ?>" data-confirm="Hapus FAQ ini?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= e((string) $faqId) ?>">
                                    <button class="btn btn-outline-danger btn-sm" type="submit">Hapus</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <hr class="divider">

        <h4 class="card-subtitle"><?= $faqEdit !== null ? 'Edit FAQ' : 'Tambah FAQ' ?></h4>
        <form id="aiFaqForm" class="form-stack" method="post" action="<?= e($faqSaveUrl) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= e((string) ($faqEdit['id'] ?? 0)) ?>">
            <div class="form-group">
                <label class="form-label" for="faqPertanyaan">Pertanyaan</label>
                <input class="form-control" id="faqPertanyaan" name="pertanyaan" maxlength="500" required
                       value="<?= e((string) ($faqEdit['pertanyaan'] ?? '')) ?>">
            </div>
            <div class="form-group">
                <label class="form-label" for="faqJawaban">Jawaban resmi</label>
                <textarea class="form-control" id="faqJawaban" name="jawaban" rows="5" maxlength="5000" required><?= e((string) ($faqEdit['jawaban'] ?? '')) ?></textarea>
                <p class="form-hint">Jawaban ini diprioritaskan sebagai informasi resmi. Jangan masukkan kata sandi atau data sensitif.</p>
            </div>
            <div class="grid-2">
                <div class="form-group">
                    <label class="form-label" for="faqKategori">Kategori</label>
                    <input class="form-control" id="faqKategori" name="kategori" list="faqKategoriList" maxlength="100"
                           value="<?= e((string) ($faqEdit['kategori'] ?? 'Umum')) ?>">
                    <datalist id="faqKategoriList">
                        <?php foreach ($faqCategories as $category): ?>
                            <option value="<?= e((string) $category) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="form-group">
                    <label class="form-label" for="faqUrutan">Urutan tampil</label>
                    <input class="form-control" id="faqUrutan" name="urutan" type="number" min="0" max="9999"
                           value="<?= e((string) ($faqEdit['urutan'] ?? 0)) ?>">
                </div>
            </div>
            <div class="form-group">
                <label class="switch" for="faqAktif">
                    <input type="checkbox" id="faqAktif" name="is_active" value="1" <?= $faqEdit === null || (int) ($faqEdit['is_active'] ?? 0) === 1 ? 'checked' : '' ?>>
                    <span class="switch-track" aria-hidden="true"></span>
                    <span class="switch-label">FAQ aktif</span>
                </label>
                <p class="form-hint">FAQ nonaktif tidak dipakai sebagai referensi resmi oleh AI.</p>
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit"><?= $faqEdit !== null ? 'Simpan Perubahan' : 'Tambah FAQ' ?></button>
                <?php if ($faqEdit !== null): ?>
                    <a class="btn btn-outline-light" href="<?= e(url('/admin/pengaturan/ai')) ?>#faq">Batal</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</section>

<?= component_html('modal-confirm', [
    'id' => 'aiConfirmModal',
    'title' => 'Simpan pengaturan AI?',
    'message' => 'Perubahan konfigurasi AI akan langsung berlaku untuk permintaan chatbot berikutnya. API key baru (bila diisi) akan dienkripsi sebelum disimpan dan hanya berlaku untuk provider yang dipilih.',
    'variant' => 'primary',
    'icon' => 'settings',
    'footer' => '<button class="btn btn-outline-light btn-sm" type="button" data-modal-close>Batal</button>'
        . '<button class="btn btn-primary btn-sm" type="button" data-modal-confirm data-modal-submit="aiSettingsForm">'
        . '<span data-modal-confirm-label>Ya, simpan</span></button>',
]) ?>
<script src="<?= e(asset('js/ai-settings.js')) ?>"></script>
