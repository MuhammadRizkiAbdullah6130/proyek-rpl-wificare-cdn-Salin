<?php
/**
 * Komponen: Floating AI Assistant widget (Tahap 10 — update UI).
 *
 * Tombol bulat di pojok kanan bawah + panel chat kecil. Dipasang sekali melalui
 * layout aplikasi (layouts/app) sehingga muncul pada semua halaman yang
 * memerlukan login (admin, cs, teknisi, pelanggan).
 *
 * Tidak ada jawaban AI yang di-hardcode: pesan dikirim ke endpoint PHP yang
 * sudah ada (/ai/chat) melalui JavaScript. Bila layanan AI belum dikonfigurasi,
 * panel hanya menampilkan pemberitahuan dan input dinonaktifkan.
 *
 * Variabel:
 * - $configured    : bool, apakah layanan AI siap dipakai.
 * - $quickQuestions: array<int, string>, pertanyaan cepat sesuai peran.
 * - $chatUrl       : URL endpoint chat (default /ai/chat).
 * - $token         : token CSRF.
 */

declare(strict_types=1);

$configured = (bool) ($configured ?? false);

// Status layanan AI: active | disabled | unconfigured.
$aiState = (string) ($aiState ?? ($configured ? 'active' : 'unconfigured'));
if (!in_array($aiState, ['active', 'disabled', 'unconfigured'], true)) {
    $aiState = $configured ? 'active' : 'unconfigured';
}
$active = $aiState === 'active';

$quickQuestions = is_array($quickQuestions ?? null) ? $quickQuestions : [];
$chatUrl = (string) ($chatUrl ?? url('/ai/chat'));
$token = (string) ($token ?? csrf_token());

$botSvg = '<svg class="aiw-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
    . 'stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
    . '<rect x="4.5" y="8" width="15" height="10" rx="3.2"/><path d="M12 8V5.2"/>'
    . '<circle cx="12" cy="4" r="1.1"/><path d="M9.3 12.3h.01"/><path d="M14.7 12.3h.01"/>'
    . '<path d="M9.5 15.2h5"/></svg>';

$sendSvg = '<svg class="aiw-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
    . 'stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
    . '<path d="M4.8 12 19.5 4.8 14.6 19.2 11.4 13.2Z"/><path d="m11.4 13.2 8.1-8.4"/></svg>';

$minSvg = '<svg class="aiw-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
    . 'stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
    . '<path d="M6 12h12"/></svg>';

$xSvg = '<svg class="aiw-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
    . 'stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
    . '<path d="M6 6l12 12M18 6 6 18"/></svg>';

$trashSvg = '<svg class="aiw-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
    . 'stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
    . '<path d="M4 7h16"/><path d="M10 11v6M14 11v6"/>'
    . '<path d="M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12"/><path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/></svg>';
?>
<div class="aiw" data-ai-widget>
    <section class="aiw-panel" data-aiw-panel id="aiw-panel" role="dialog" aria-modal="false"
             aria-label="WiFiCare AI — Asisten Virtual" hidden>
        <header class="aiw-head">
            <span class="aiw-head-icon" aria-hidden="true"><?= $botSvg ?></span>
            <span class="aiw-head-text">
                <strong>WiFiCare AI</strong>
                <small>Asisten Virtual</small>
            </span>
            <button class="aiw-icon-btn" type="button" data-aiw-clear
                    aria-label="Bersihkan percakapan" title="Bersihkan percakapan"><?= $trashSvg ?></button>
            <button class="aiw-icon-btn" type="button" data-aiw-minimize
                    aria-label="Perkecil jendela" title="Perkecil" aria-expanded="true"><?= $minSvg ?></button>
            <button class="aiw-icon-btn" type="button" data-aiw-close
                    aria-label="Tutup jendela" title="Tutup"><?= $xSvg ?></button>
        </header>

        <div class="aiw-messages" data-aiw-messages role="log" aria-live="polite" aria-relevant="additions">
            <?php if ($aiState === 'disabled'): ?>
                <div class="aiw-notice" role="alert">
                    <?= icon('alert') ?>
                    <span>Layanan AI sedang dinonaktifkan oleh administrator. Silakan coba lagi nanti.</span>
                </div>
            <?php elseif ($aiState === 'unconfigured'): ?>
                <div class="aiw-notice" role="alert">
                    <?= icon('alert') ?>
                    <span>Layanan AI belum dikonfigurasi. Silakan hubungi administrator.</span>
                </div>
            <?php else: ?>
                <div class="chat-bubble chat-bubble--assistant">
                    <div class="chat-bubble-body">
                        <div class="chat-rich">
                            <p class="chat-lead">Halo! Saya WiFiCare AI.</p>
                            <p class="chat-para">Saya siap membantu Anda dengan panduan aplikasi, troubleshooting WiFi,
                                FAQ, dan pemeriksaan status tiket.</p>
                        </div>
                    </div>
                </div>
                <?php if ($quickQuestions !== []): ?>
                    <div class="aiw-quick" data-aiw-quick-list>
                        <?php foreach ($quickQuestions as $question): ?>
                            <button class="aiw-quick-btn" type="button"
                                    data-aiw-quick="<?= e($question) ?>"><?= e($question) ?></button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <form class="aiw-form" data-aiw-form data-endpoint="<?= e($chatUrl) ?>" data-token="<?= e($token) ?>"
              data-clear-endpoint="<?= e($clearUrl ?? url('/ai/clear')) ?>">
            <label class="visually-hidden" for="aiw-input">Tulis pesan untuk WiFiCare AI</label>
            <textarea class="aiw-input" id="aiw-input" data-aiw-input rows="1" maxlength="2000"
                      placeholder="Tulis pertanyaan Anda&hellip;" <?= $active ? '' : 'disabled' ?>></textarea>
            <button class="aiw-send" type="submit" data-aiw-send aria-label="Kirim pesan"
                    <?= $active ? '' : 'disabled' ?>><?= $sendSvg ?><span class="aiw-send-spinner" aria-hidden="true"></span></button>
        </form>
    </section>

    <button class="aiw-fab" type="button" data-aiw-toggle aria-label="Tanya WiFiCare AI"
            title="Tanya WiFiCare AI" aria-haspopup="dialog" aria-controls="aiw-panel"
            aria-expanded="false"><?= $botSvg ?></button>
</div>
