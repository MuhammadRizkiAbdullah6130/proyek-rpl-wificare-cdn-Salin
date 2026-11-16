<?php
/**
 * Layout error WiFiCare CDN (403/404/500).
 *
 * Layout ringkas tanpa sidebar/navbar publik maupun dashboard. Hanya memuat
 * stylesheet, skrip dasar, tombol tema melayang, dan isi halaman error. Floating
 * AI Chatbot TIDAK dimuat di sini (hanya untuk pengguna yang sudah masuk di area
 * dashboard).
 *
 * Variabel:
 * - $title   : Judul dokumen.
 * - $content : Isi halaman (section error).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../Helpers/functions.php';

$title = $title ?? 'Terjadi Kesalahan — WiFiCare CDN';
?>
<!DOCTYPE html>
<html lang="id" class="no-js" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <script>
        /* Anti-flash: pasang tema tersimpan sebelum stylesheet dimuat. */
        (function () {
            try {
                var saved = window.localStorage.getItem('wificare-theme');
                var theme = saved === 'dark' ? 'dark' : 'light';
                document.documentElement.setAttribute('data-theme', theme);
                document.documentElement.setAttribute('data-bs-theme', theme);
            } catch (error) {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        })();
    </script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body class="auth-body">
    <a class="skip-link" href="#konten-utama">Lompat ke konten utama</a>

    <?= component_html('theme-toggle', ['floating' => true]) ?>

    <div class="error-shell" id="konten-utama">
        <?= $content ?>
    </div>

    <?= component_html('toast') ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script src="<?= e(asset('js/main.js')) ?>"></script>
</body>
</html>
