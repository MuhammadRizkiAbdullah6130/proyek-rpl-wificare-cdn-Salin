<?php
/**
 * Komponen: Pagination.
 *
 * Dua mode:
 * - Pratinjau statis : tanpa $baseUrl → tombol (navigasi JS pratinjau).
 * - Server-side     : dengan $baseUrl → tautan nyata berisi query `page`.
 *
 * Variabel:
 * - $page    : Halaman aktif (1-basis).
 * - $last    : Jumlah halaman.
 * - $total   : Total data (opsional).
 * - $baseUrl : URL tujuan tanpa query (mis. url('/pelanggan/pengaduan')).
 * - $query   : Parameter query tambahan yang dipertahankan.
 * - $demo    : Tandai pratinjau statis.
 */

declare(strict_types=1);

$page = max(1, (int) ($page ?? 1));
$last = max(1, (int) ($last ?? 1));
$total = $total ?? null;
$baseUrl = $baseUrl ?? null;
$query = $query ?? [];
$demo = $demo ?? false;

$windowStart = max(1, $page - 1);
$windowEnd = min($last, $page + 1);

$halamanUrl = static function (int $nomor) use ($baseUrl, $query): string {
    $params = array_merge($query, ['page' => $nomor]);

    return $baseUrl . '?' . http_build_query($params);
};
?>
<nav class="app-pagination" aria-label="Navigasi halaman" data-pagination>
    <div class="app-pagination-info">
        Halaman <?= e((string) $page) ?> dari <?= e((string) $last) ?>
        <?php if ($total !== null): ?>
            &middot; <?= e((string) $total) ?> data
        <?php endif; ?>
        <?php if ($demo): ?><span class="demo-badge">DEMO</span><?php endif; ?>
    </div>
    <ul class="app-pagination-list">
        <?php if ($baseUrl !== null): ?>
            <li>
                <?php if ($page <= 1): ?>
                    <span class="app-pagination-link is-disabled" aria-disabled="true"><?= icon('arrow-left') ?></span>
                <?php else: ?>
                    <a class="app-pagination-link" href="<?= e($halamanUrl($page - 1)) ?>" aria-label="Halaman sebelumnya"><?= icon('arrow-left') ?></a>
                <?php endif; ?>
            </li>
            <?php for ($p = $windowStart; $p <= $windowEnd; $p++): ?>
                <li>
                    <?php if ($p === $page): ?>
                        <span class="app-pagination-link is-active" aria-current="page"><?= e((string) $p) ?></span>
                    <?php else: ?>
                        <a class="app-pagination-link" href="<?= e($halamanUrl($p)) ?>"><?= e((string) $p) ?></a>
                    <?php endif; ?>
                </li>
            <?php endfor; ?>
            <li>
                <?php if ($page >= $last): ?>
                    <span class="app-pagination-link is-disabled" aria-disabled="true"><?= icon('logout') ?></span>
                <?php else: ?>
                    <a class="app-pagination-link" href="<?= e($halamanUrl($page + 1)) ?>" aria-label="Halaman berikutnya"><?= icon('logout') ?></a>
                <?php endif; ?>
            </li>
        <?php else: ?>
            <li>
                <button class="app-pagination-link" type="button" data-page="<?= e((string) max(1, $page - 1)) ?>"
                        <?= $page <= 1 ? 'disabled' : '' ?> aria-label="Halaman sebelumnya">
                    <?= icon('arrow-left') ?>
                </button>
            </li>
            <?php for ($p = $windowStart; $p <= $windowEnd; $p++): ?>
                <li>
                    <button class="app-pagination-link<?= $p === $page ? ' is-active' : '' ?>" type="button"
                            data-page="<?= e((string) $p) ?>"<?= $p === $page ? ' aria-current="page"' : '' ?>>
                        <?= e((string) $p) ?>
                    </button>
                </li>
            <?php endfor; ?>
            <li>
                <button class="app-pagination-link" type="button" data-page="<?= e((string) min($last, $page + 1)) ?>"
                        <?= $page >= $last ? 'disabled' : '' ?> aria-label="Halaman berikutnya">
                    <?= icon('logout') ?>
                </button>
            </li>
        <?php endif; ?>
    </ul>
</nav>
