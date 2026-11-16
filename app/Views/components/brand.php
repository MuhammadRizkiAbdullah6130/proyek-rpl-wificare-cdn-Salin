<?php
/**
 * Komponen logo CDN (Cimanuk Digital Network).
 *
 * Variabel:
 * - $variant : 'c' (logo C, untuk sidebar), 'signal' (ikon WiFi untuk login),
 *              'mark' (CDN ringkas), atau 'full' (dengan nama perusahaan).
 * - $class   : kelas tambahan opsional pada pembungkus.
 *
 * Varian 'signal' memakai berkas logo WiFiCare yang diunggah pengguna.
 * Varian lain memakai SVG inline agar tetap tajam dan mengikuti tema.
 */

declare(strict_types=1);

$variant = isset($variant) && in_array($variant, ['full', 'mark', 'c', 'signal'], true) ? $variant : 'c';
$class = trim((string) ($class ?? ''));
$isFull = $variant === 'full';
$isMark = $variant === 'mark';
$isSignal = $variant === 'signal';
$svgId = 'cdn-logo-shadow-' . $variant;
?>
<span class="app-brand-mark app-brand-mark--<?= e($variant) ?><?= $class !== '' ? ' ' . e($class) : '' ?>" aria-hidden="true">
    <?php if ($isSignal): ?>
        <img src="<?= e(asset('img/logo-wificare-2-transparent.png')) ?>" alt="" width="547" height="547">
    <?php else: ?>
    <svg viewBox="<?= $isFull ? '0 0 800 258' : ($isMark ? '0 0 255 100' : '0 0 150 150') ?>" xmlns="http://www.w3.org/2000/svg" focusable="false">
        <defs>
            <filter id="<?= e($svgId) ?>" x="-12%" y="-12%" width="135%" height="140%">
                <feDropShadow dx="<?= $isFull ? '5' : ($isMark ? '2' : '3') ?>" dy="<?= $isFull ? '6' : ($isMark ? '2.4' : '4') ?>" stdDeviation="0.4" flood-color="#7f8285" flood-opacity="0.55"/>
            </filter>
        </defs>

        <?php if ($isFull): ?>
            <text x="4" y="188" font-family="Arial Black, Arial, Helvetica, sans-serif" font-size="250" font-weight="900"
                  letter-spacing="-12" fill="#a9abad" filter="url(#<?= e($svgId) ?>)">CDN</text>
            <path d="M665 70 A110 110 0 0 1 775 180" fill="none" stroke="#f58220" stroke-width="24"/>
            <path d="M665 112 A68 68 0 0 1 733 180" fill="none" stroke="#00a651" stroke-width="22"/>
            <circle cx="665" cy="180" r="15" fill="#f58220"/>
            <text x="4" y="244" font-family="Arial, Helvetica, sans-serif" font-size="39" font-weight="400"
                  letter-spacing="2.4" fill="currentColor">CIMANUK DIGITAL NETWORK</text>
        <?php elseif ($isMark): ?>
            <text x="2" y="82" font-family="Arial Black, Arial, Helvetica, sans-serif" font-size="80" font-weight="900"
                  letter-spacing="-3" fill="#a9abad" filter="url(#<?= e($svgId) ?>)">CDN</text>
            <path d="M215 56 A32 32 0 0 1 247 88" fill="none" stroke="#f58220" stroke-width="7.5"/>
            <path d="M215 69 A19 19 0 0 1 234 88" fill="none" stroke="#00a651" stroke-width="6.5"/>
            <circle cx="215" cy="88" r="6.5" fill="#f58220"/>
        <?php else: ?>
            <text x="0" y="132" font-family="Arial Black, Arial, Helvetica, sans-serif" font-size="180" font-weight="900"
                  fill="#a9abad" filter="url(#<?= e($svgId) ?>)">C</text>
        <?php endif; ?>
    </svg>
    <?php endif; ?>
</span>
