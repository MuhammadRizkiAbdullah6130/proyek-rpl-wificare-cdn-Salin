<?php
/**
 * AI Assistant — antarmuka chat (Tahap 9).
 *
 * Tersedia untuk semua peran yang sudah masuk. Jawaban berasal dari API AI
 * melalui backend PHP; tidak ada jawaban palsu yang di-hardcode. Bila layanan
 * AI belum dikonfigurasi, antarmuka menampilkan pesan bahwa layanan belum aktif.
 */

declare(strict_types=1);

$configured = (bool) ($configured ?? false);
$quickQuestions = $quickQuestions ?? [];
$chatUrl = (string) ($chatUrl ?? url('/ai/chat'));
$token = (string) ($csrf ?? csrf_token());
?>
<?= component('page-header', [
    'title' => 'AI Assistant',
    'subtitle' => 'Bantuan panduan aplikasi, troubleshooting WiFi, FAQ, dan pemeriksaan status tiket Anda.',
]) ?>

<?php if (!$configured): ?>
    <div class="alert-inline alert-inline--warning" role="alert">
        <?= icon('alert') ?>
        <span>Layanan AI belum tersedia. Administrator belum mengonfigurasi kunci API. Silakan gunakan panduan pada aplikasi atau hubungi Customer Service.</span>
    </div>
<?php endif; ?>

<div class="ai-layout">
    <section class="card chat-card">
        <div class="card-head">
            <h3 class="card-title">Percakapan</h3>
            <span class="chip ms-auto"><?= icon('chat') ?> WiFiCare Assistant</span>
        </div>

        <div class="card-body chat-body" data-ai-messages>
            <?= component_html('empty-state', [
                'icon' => 'chat',
                'title' => 'Mulai percakapan',
                'message' => $configured
                    ? 'Tanyakan cara memakai aplikasi, keluhan WiFi, atau status tiket Anda.'
                    : 'Layanan AI belum aktif. Anda tetap dapat membaca panduan aplikasi.',
            ]) ?>
        </div>

        <form class="chat-form" data-ai-form data-endpoint="<?= e($chatUrl) ?>" data-token="<?= e($token) ?>">
            <label class="visually-hidden" for="ai-input">Tulis pesan</label>
            <textarea class="form-control chat-input" id="ai-input" data-ai-input rows="2"
                      maxlength="2000" placeholder="Tulis pertanyaan Anda…" <?= $configured ? '' : 'disabled' ?>></textarea>
            <button class="btn btn-primary chat-send" type="submit" data-ai-send <?= $configured ? '' : 'disabled' ?>>
                <?= icon('chat') ?><span>Kirim</span>
            </button>
        </form>
    </section>

    <aside class="card ai-side">
        <div class="card-head">
            <h3 class="card-title">Pertanyaan Cepat</h3>
        </div>
        <div class="card-body">
            <div class="quick-questions">
                <?php foreach ($quickQuestions as $question): ?>
                    <button class="quick-question" type="button" data-ai-quick="<?= e($question) ?>" <?= $configured ? '' : 'disabled' ?>>
                        <?= icon('chat') ?><span><?= e($question) ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
    </aside>
</div>
