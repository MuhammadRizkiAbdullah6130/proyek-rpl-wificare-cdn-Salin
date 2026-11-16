<?php
/**
 * Penulis PDF native — Tahap 9.
 *
 * Menghasilkan berkas PDF asli (bukan CSV/gambar yang diganti ekstensi) tanpa
 * pustaka eksternal/Composer. Mendukung:
 * - judul laporan, nama ISP, periode laporan, waktu pembuatan;
 * - ringkasan statistik;
 * - tabel berkolom dengan pembungkusan teks otomatis;
 * - pagination halaman dan nomor halaman ("Halaman X dari Y").
 *
 * Keamanan: seluruh teks dari database di-escape sebelum ditulis ke content
 * stream, dan konversi font memakai Windows-1252 sehingga karakter tak dikenal
 * diganti '?', bukan merusak struktur PDF.
 */

declare(strict_types=1);

final class PdfReport
{
    private const PAGE_W = 595.28;

    private const PAGE_H = 841.89;

    private const MARGIN = 40.0;

    private const FOOTER_H = 28.0;

    private string $title;

    private string $brandLine = '';

    private string $generatedAt = '';

    /** @var array<int, string> */
    private array $metaLines = [];

    /** @var array<int, array{label: string, value: string}> */
    private array $summary = [];

    /** @var array<int, array{label: string, weight: float, align: string}> */
    private array $columns = [];

    /** @var array<int, array<int, string>> */
    private array $rows = [];

    private string $noDataText = 'Tidak ada data pada filter ini.';

    /** @var array<int, string> */
    private array $pages = [];

    private string $content = '';

    private float $y = 0.0;

    private float $usableWidth;

    /** @var array<int, float> */
    private array $columnWidths = [];

    public function __construct(string $title)
    {
        $this->title = $title;
        $this->generatedAt = format_waktu(date('Y-m-d H:i:s'));
        $this->usableWidth = self::PAGE_W - 2 * self::MARGIN;
    }

    public function setBrand(string $line): void
    {
        $this->brandLine = $line;
    }

    public function setGeneratedAt(string $line): void
    {
        $this->generatedAt = $line;
    }

    /**
     * @param array<int, string> $lines
     */
    public function setMeta(array $lines): void
    {
        $this->metaLines = $lines;
    }

    /**
     * @param array<int, array{label: string, value: string}> $pairs
     */
    public function setSummary(array $pairs): void
    {
        $this->summary = $pairs;
    }

    /**
     * @param array<int, array{label: string, weight?: float, align?: string}> $columns
     * @param array<int, array<int, string>> $rows
     */
    public function setTable(array $columns, array $rows): void
    {
        $this->columns = array_map(static function (array $column): array {
            return [
                'label' => (string) ($column['label'] ?? ''),
                'weight' => (float) ($column['weight'] ?? 1.0),
                'align' => (string) ($column['align'] ?? 'left'),
            ];
        }, $columns);
        $this->rows = $rows;

        $totalWeight = 0.0;
        foreach ($this->columns as $column) {
            $totalWeight += max(0.2, $column['weight']);
        }
        if ($totalWeight <= 0) {
            $totalWeight = 1.0;
        }

        $this->columnWidths = [];
        foreach ($this->columns as $column) {
            $this->columnWidths[] = (max(0.2, $column['weight']) / $totalWeight) * $this->usableWidth;
        }
    }

    public function output(): string
    {
        $this->buildPages();

        return $this->assemble();
    }

    private function buildPages(): void
    {
        $this->pages = [];
        $this->startPage(true);

        if (empty($this->rows)) {
            $this->content .= $this->text(self::MARGIN, $this->y - 12, 10, false, $this->noDataText);
            $this->y -= 20;
            $this->endPage();

            return;
        }

        foreach ($this->rows as $row) {
            $rowHeight = $this->measureRow($row);
            if ($this->y - $rowHeight < self::MARGIN + self::FOOTER_H) {
                $this->endPage();
                $this->startPage(false);
            }

            $this->drawRow($row, $rowHeight);
            $this->y -= $rowHeight;
        }

        $this->endPage();
    }

    private function startPage(bool $first): void
    {
        $this->content = '';
        $this->y = self::PAGE_H - self::MARGIN;

        if ($first) {
            if ($this->brandLine !== '') {
                $this->content .= $this->text(self::MARGIN, $this->y - 10, 10, true, $this->brandLine);
                $this->y -= 22;
            }

            $this->content .= $this->text(self::MARGIN, $this->y - 16, 15, true, $this->title);
            $this->y -= 24;

            $this->content .= $this->text(self::MARGIN, $this->y - 9, 8.5, false, 'Dibuat: ' . $this->generatedAt);
            $this->y -= 13;

            foreach ($this->metaLines as $line) {
                $this->content .= $this->text(self::MARGIN, $this->y - 9, 8.5, false, $line);
                $this->y -= 12;
            }

            if (!empty($this->summary)) {
                $this->y -= 4;
                foreach ($this->summary as $pair) {
                    $label = $pair['label'] . ': ';
                    $this->content .= $this->text(self::MARGIN, $this->y - 9, 9, true, $label);
                    $labelWidth = $this->textWidth($label, 9);
                    $this->content .= $this->text(self::MARGIN + $labelWidth, $this->y - 9, 9, false, $pair['value']);
                    $this->y -= 12.5;
                }
            }

            $this->y -= 6;
        } else {
            $this->content .= $this->text(self::MARGIN, $this->y - 10, 10, true, $this->title . ' (lanjutan)');
            $this->y -= 20;
        }

        $this->drawTableHeader();
    }

    private function endPage(): void
    {
        $this->pages[] = $this->content;
        $this->content = '';
    }

    private function drawTableHeader(): void
    {
        $height = 18.0;
        $x = self::MARGIN;
        $top = $this->y;

        $this->content .= $this->fillRect(self::MARGIN, $top - $height, $this->usableWidth, $height, [0.886, 0.910, 0.945]);

        foreach ($this->columns as $index => $column) {
            $width = $this->columnWidths[$index];
            $this->content .= $this->cellText($x, $width, $top - 12, 8.5, $column['label'], $column['align'], true);
            $x += $width;
        }

        $this->content .= $this->line(self::MARGIN, $top - $height, self::MARGIN + $this->usableWidth, $top - $height, 0.75);
        $this->y -= $height;
    }

    /**
     * @param array<int, string> $row
     */
    private function measureRow(array $row): float
    {
        $maxLines = 1;
        foreach ($this->columns as $index => $column) {
            $value = (string) ($row[$index] ?? '');
            $lines = $this->wrap($value, $this->columnWidths[$index] - 8, 8.5);
            $maxLines = max($maxLines, count($lines));
        }

        return max(13.0, $maxLines * 10.0 + 4.0);
    }

    /**
     * @param array<int, string> $row
     */
    private function drawRow(array $row, float $height): void
    {
        $top = $this->y;
        $x = self::MARGIN;

        foreach ($this->columns as $index => $column) {
            $width = $this->columnWidths[$index];
            $value = (string) ($row[$index] ?? '');
            $lines = $this->wrap($value, $width - 8, 8.5);
            $lineY = $top - 11;
            foreach ($lines as $line) {
                $this->content .= $this->cellText($x, $width, $lineY, 8.5, $line, $column['align'], false);
                $lineY -= 10;
            }
            $x += $width;
        }

        $this->content .= $this->line(self::MARGIN, $top - $height, self::MARGIN + $this->usableWidth, $top - $height, 0.4);
    }

    private function assemble(): string
    {
        $pageCount = max(1, count($this->pages));

        $objects = [];
        // obj1 catalog, obj2 pages, obj3 F1, obj4 F2.
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';

        $kids = [];
        for ($i = 0; $i < $pageCount; $i++) {
            $kids[] = (5 + $i * 2) . ' 0 R';
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $pageCount . ' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        for ($i = 0; $i < $pageCount; $i++) {
            $pageObj = 5 + $i * 2;
            $contentObj = 6 + $i * 2;

            $objects[$pageObj] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::PAGE_W . ' ' . self::PAGE_H . '] '
                . '/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $contentObj . ' 0 R >>';

            $stream = $this->pages[$i] . $this->pageFooter($i + 1, $pageCount);
            $objects[$contentObj] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
        }

        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $maxObj = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($maxObj + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($n = 1; $n <= $maxObj; $n++) {
            if (isset($offsets[$n])) {
                $pdf .= sprintf("%010d 00000 n \n", $offsets[$n]);
            } else {
                $pdf .= "0000000000 65535 f \n";
            }
        }
        $pdf .= "trailer\n<< /Size " . ($maxObj + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF";

        return $pdf;
    }

    private function pageFooter(int $page, int $total): string
    {
        $y = self::MARGIN;
        $left = $this->brandLine !== '' ? $this->brandLine : 'WiFiCare CDN';
        $center = 'Halaman ' . $page . ' dari ' . $total;

        $content = $this->line(self::MARGIN, $y + 12, self::MARGIN + $this->usableWidth, $y + 12, 0.4);
        $content .= $this->text(self::MARGIN, $y, 8, false, $left);

        $width = $this->textWidth($center, 8);
        $content .= $this->text(self::MARGIN + ($this->usableWidth - $width) / 2, $y, 8, false, $center);

        return $content;
    }

    private function text(float $x, float $y, float $size, bool $bold, string $value): string
    {
        if ($value === '') {
            return '';
        }

        $font = $bold ? '/F2' : '/F1';

        return sprintf("BT %s %.2f Tf %.2f %.2f Td (%s) Tj ET\n", $font, $size, $x, $y, $this->escape($value));
    }

    private function cellText(float $x, float $width, float $y, float $size, string $value, string $align, bool $bold): string
    {
        $textWidth = $this->textWidth($value, $size);
        $tx = $x + 4;
        if ($align === 'right') {
            $tx = $x + $width - 4 - $textWidth;
        } elseif ($align === 'center') {
            $tx = $x + ($width - $textWidth) / 2;
        }

        return $this->text($tx, $y, $size, $bold, $value);
    }

    /**
     * @param array{0: float, 1: float, 2: float} $rgb
     */
    private function fillRect(float $x, float $y, float $width, float $height, array $rgb): string
    {
        return sprintf(
            "%.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f\n",
            $rgb[0],
            $rgb[1],
            $rgb[2],
            $x,
            $y,
            $width,
            $height
        );
    }

    private function line(float $x1, float $y1, float $x2, float $y2, float $width): string
    {
        return sprintf("0.65 0.70 0.78 RG %.2f w %.2f %.2f m %.2f %.2f l S\n", $width, $x1, $y1, $x2, $y2);
    }

    /**
     * @return array<int, string>
     */
    private function wrap(string $text, float $maxWidth, float $size): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return [''];
        }

        $words = explode(' ', $text);
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if ($this->textWidth($candidate, $size) <= $maxWidth || $current === '') {
                // Bila satu kata tunggal tetap lebih lebar, potong paksa.
                if ($current === '' && $this->textWidth($word, $size) > $maxWidth) {
                    $lines[] = $this->hardTruncate($word, $maxWidth, $size);
                    $current = '';
                    continue;
                }
                $current = $candidate;
            } else {
                $lines[] = $current;
                $current = $word;
            }
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines === [] ? [''] : $lines;
    }

    private function hardTruncate(string $word, float $maxWidth, float $size): string
    {
        $result = '';
        $length = mb_strlen($word);
        for ($i = 0; $i < $length; $i++) {
            $candidate = $result . mb_substr($word, $i, 1);
            if ($this->textWidth($candidate, $size) > $maxWidth) {
                break;
            }
            $result = $candidate;
        }

        return $result === '' ? mb_substr($word, 0, 1) : $result;
    }

    private function textWidth(string $text, float $size): float
    {
        $encoded = $this->encode($text);
        $sum = 0.0;
        $length = strlen($encoded);
        for ($i = 0; $i < $length; $i++) {
            $sum += $this->charFactor(ord($encoded[$i]));
        }

        return $sum * $size;
    }

    private function charFactor(int $code): float
    {
        if ($code === 32) {
            return 0.28;
        }
        if ($code >= 48 && $code <= 57) {
            return 0.556;
        }
        if ($code >= 65 && $code <= 90) {
            return 0.66;
        }
        if (in_array($code, [46, 44, 58, 59, 33, 124, 105, 108, 39], true)) {
            return 0.28;
        }
        if ($code >= 97 && $code <= 122) {
            return 0.52;
        }

        return 0.55;
    }

    private function escape(string $text): string
    {
        $text = $this->encode($text);

        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $text);
    }

    private function encode(string $text): string
    {
        $converted = @mb_convert_encoding($text, 'Windows-1252', 'UTF-8');

        return $converted === false ? $text : $converted;
    }
}
