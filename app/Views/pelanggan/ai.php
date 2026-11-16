<?php
/**
 * Pelanggan — AI Assistant.
 *
 * Hanya antarmuka. Integrasi API AI (kunci di server, timeout, rate limit,
 * fallback) dibangun pada tahap berikutnya. Tidak ada percakapan palsu:
 * area percakapan dimulai dari empty state.
 */

declare(strict_types=1);
$content = '';
?>
<?= component('page-header', [
    'title' => 'AI Assistant',
    'subtitle' => 'Bantuan panduan aplikasi, troubleshooting WiFi, dan cek status tiket Anda.',
    'demo' => true,
]) ?>

<div class="grid-2 grid-2--detail">
    <section class="card chat-card">
        <div class="card-head">
            <h3 class="card-title">Percakapan</h3>
            <span class="demo-badge">DEMO</span>
        </div>
        <div class="card-body chat-body" data-chat>
            <?= component_html('empty-state', [
                'icon' => 'chat',
                'title' => 'Mulai percakapan',
                'message' => 'Tanyakan cara memakai aplikasi, keluhan WiFi, atau status tiket Anda. AI belum aktif pada tahap ini.',
            ]) ?>
        </div>
        <form class="chat-form" onsubmit="return false;">
            <label class="visually-hidden" for="chat-input">Pesan untuk AI Assistant</label>
            <input class="form-control" type="text" id="chat-input" placeholder="Tulis pertanyaan Anda…" autocomplete="off">
            <button class="btn btn-primary" type="submit" data-toast-message="AI Assistant belum aktif. Koneksi API akan dibangun pada tahap berikutnya.">
                <?= icon('chat') ?><span>Kirim</span>
            </button>
        </form>
    </section>

    <div class="stack">
        <section class="card">
            <div class="card-head">
                <h3 class="card-title">Contoh Pertanyaan</h3>
            </div>
            <div class="card-body">
                <div class="quick-actions quick-actions--stack">
                    <button class="quick-action" type="button" data-toast-message="Contoh pertanyaan (pratinjau).">Bagaimana cara membuat pengaduan?</button>
                    <button class="quick-action" type="button" data-toast-message="Contoh pertanyaan (pratinjau).">Kenapa internet saya lambat?</button>
                    <button class="quick-action" type="button" data-toast-message="Contoh pertanyaan (pratinjau).">Bagaimana status tiket saya?</button>
                    <button class="quick-action" type="button" data-toast-message="Contoh pertanyaan (pratinjau).">Langkah awal sebelum menghubungi CS?</button>
                </div>
            </div>
        </section>

        <section class="card">
            <div class="card-head">
                <h3 class="card-title">Ketentuan Keamanan</h3>
            </div>
            <div class="card-body">
                <ul class="checklist">
                    <li>API key hanya disimpan di server, tidak di frontend.</li>
                    <li>AI tidak mengakses database secara langsung.</li>
                    <li>Data pribadi yang dikirim ke API dibatasi seminimal mungkin.</li>
                    <li>Tersedia timeout, rate limit, dan pesan fallback bila API gagal.</li>
                    <li>AI tidak mengubah tiket tanpa aksi dan otorisasi pengguna.</li>
                </ul>
            </div>
        </section>
    </div>
</div>
