<?php
/**
 * Penulis berkas Excel (.xlsx) native — Tahap 9.
 *
 * Membuat berkas OOXML asli (ZIP berisi XML) memakai ekstensi ZipArchive,
 * TANPA pustaka eksternal/Composer. Bukan CSV yang diganti ekstensi.
 *
 * Keamanan:
 * - Semua teks pengguna ditulis sebagai `inlineStr` (tipe string), sehingga
 *   tidak pernah ditafsirkan sebagai formula → mencegah formula injection.
 * - Karakter kontrol XML dibersihkan.
 * - Tidak ada kredensial/rahasia yang ditulis.
 */

declare(strict_types=1);

final class XlsxWriter
{
    private string $sheetName;

    private array $meta = [];

    /** @var array<int, array{label: string, type: string, width: float}> */
    private array $columns = [];

    /** @var array<int, array<int, mixed>> */
    private array $rows = [];

    private string $title = '';

    public function __construct(string $sheetName = 'Laporan')
    {
        $this->sheetName = self::sanitizeSheetName($sheetName);
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    /**
     * @param array<int, array{label: string, value: string}> $meta
     */
    public function setMeta(array $meta): void
    {
        $this->meta = $meta;
    }

    /**
     * @param array<int, array{label: string, type?: string, width?: float}> $columns
     */
    public function setColumns(array $columns): void
    {
        $this->columns = array_map(static function (array $column): array {
            return [
                'label' => (string) ($column['label'] ?? ''),
                'type' => (string) ($column['type'] ?? 'text'),
                'width' => (float) ($column['width'] ?? 16.0),
            ];
        }, $columns);
    }

    /**
     * @param array<int, mixed> $cells
     */
    public function addRow(array $cells): void
    {
        $this->rows[] = $cells;
    }

    /**
     * Simpan ke path. Mengembalikan true bila berhasil.
     */
    public function save(string $path): bool
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRels());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet());

        return $zip->close();
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::xml($this->sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private function styles(): string
    {
        // cellXfs: 0 default, 1 header, 2 datetime, 3 integer, 4 percent, 5 title, 6 decimal, 7 bordered text
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="4">'
            . '<numFmt numFmtId="164" formatCode="yyyy-mm-dd hh:mm"/>'
            . '<numFmt numFmtId="165" formatCode="#,##0"/>'
            . '<numFmt numFmtId="166" formatCode="0.0&quot;%&quot;"/>'
            . '<numFmt numFmtId="167" formatCode="0.0"/>'
            . '</numFmts>'
            . '<fonts count="3">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="14"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFE2E8F0"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"><color rgb="FFCBD5E1"/></left><right style="thin"><color rgb="FFCBD5E1"/></right><top style="thin"><color rgb="FFCBD5E1"/></top><bottom style="thin"><color rgb="FFCBD5E1"/></bottom><diagonal/></border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="8">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'
            . '<xf numFmtId="166" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="167" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>'
            . '</cellXfs>'
            . '</styleSheet>';
    }

    private function sheet(): string
    {
        $columnCount = count($this->columns);
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        // Lebar kolom.
        if ($columnCount > 0) {
            $xml .= '<cols>';
            for ($i = 0; $i < $columnCount; $i++) {
                $width = $this->columns[$i]['width'];
                $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $width . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';

        $rowIndex = 1;

        // Judul.
        if ($this->title !== '') {
            $xml .= $this->textRow($rowIndex, [$this->title], 5, $columnCount);
            $rowIndex++;
        }

        // Meta (label di kolom A, nilai di kolom B).
        foreach ($this->meta as $item) {
            $xml .= $this->textRow($rowIndex, [(string) $item['label'], (string) $item['value']], 0, max(2, $columnCount));
            $rowIndex++;
        }

        // Baris kosong pemisah.
        if (!empty($this->meta) || $this->title !== '') {
            $xml .= $this->textRow($rowIndex, [''], 0, max(2, $columnCount));
            $rowIndex++;
        }

        // Header kolom.
        if ($columnCount > 0) {
            $headerCells = [];
            foreach ($this->columns as $column) {
                $headerCells[] = ['type' => 'text', 'style' => 1, 'value' => $column['label']];
            }
            $xml .= $this->buildRow($rowIndex, $headerCells);
            $rowIndex++;
        }

        // Data.
        foreach ($this->rows as $row) {
            $cells = [];
            foreach ($this->columns as $index => $column) {
                $cells[] = ['type' => $column['type'], 'value' => $row[$index] ?? ''];
            }
            $xml .= $this->buildRow($rowIndex, $cells);
            $rowIndex++;
        }

        $xml .= '</sheetData></worksheet>';

        return $xml;
    }

    /**
     * @param array<int, string> $values
     */
    private function textRow(int $rowIndex, array $values, int $style, int $minColumns): string
    {
        $cells = [];
        $highest = max($minColumns, count($values));
        for ($i = 0; $i < $highest; $i++) {
            $value = $values[$i] ?? '';
            $cellStyle = $i === 0 ? $style : 0;
            if ($value === '') {
                continue;
            }
            $cells[] = ['type' => 'text', 'style' => $cellStyle, 'value' => $value, 'col' => $i];
        }

        return $this->buildRow($rowIndex, $cells);
    }

    /**
     * @param array<int, array{type: string, value: mixed, style?: int, col?: int}> $cells
     */
    private function buildRow(int $rowIndex, array $cells): string
    {
        $xml = '<row r="' . $rowIndex . '">';
        $auto = 0;
        foreach ($cells as $cell) {
            $colIndex = array_key_exists('col', $cell) ? (int) $cell['col'] : $auto;
            $auto = $colIndex + 1;
            $ref = self::columnLetter($colIndex) . $rowIndex;
            $type = (string) $cell['type'];
            $value = $cell['value'];

            if (array_key_exists('style', $cell)) {
                $style = (int) $cell['style'];
            } else {
                $style = self::styleForType($type);
            }

            $xml .= $this->buildCell($ref, $type, $value, $style);
        }

        return $xml . '</row>';
    }

    private function buildCell(string $ref, string $type, mixed $value, int $style): string
    {
        switch ($type) {
            case 'number':
            case 'minutes_number':
            case 'rating':
                if ($value === '' || $value === null) {
                    return '<c r="' . $ref . '" s="' . self::styleForType('text') . '"/>';
                }

                return '<c r="' . $ref . '" s="3"><v>' . self::number($value) . '</v></c>';
            case 'percent':
                if ($value === '' || $value === null) {
                    return '<c r="' . $ref . '" s="' . self::styleForType('text') . '"/>';
                }

                return '<c r="' . $ref . '" s="4"><v>' . self::number($value) . '</v></c>';
            case 'rating_float':
                if ($value === '' || $value === null) {
                    return '<c r="' . $ref . '" s="' . self::styleForType('text') . '"/>';
                }

                return '<c r="' . $ref . '" s="6"><v>' . self::number($value) . '</v></c>';
            case 'datetime':
                $serial = self::excelSerial((string) $value);
                if ($serial === null) {
                    return '<c r="' . $ref . '" s="' . self::styleForType('text') . '"/>';
                }

                return '<c r="' . $ref . '" s="2"><v>' . $serial . '</v></c>';
            default:
                // Teks selalu sebagai inlineStr → tidak pernah dieksekusi sebagai formula.
                return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">'
                    . self::xml((string) $value) . '</t></is></c>';
        }
    }

    private static function styleForType(string $type): int
    {
        return match ($type) {
            'text' => 7,
            'number', 'minutes_number', 'rating' => 3,
            'percent' => 4,
            'rating_float' => 6,
            'datetime' => 2,
            default => 0,
        };
    }

    private static function number(mixed $value): string
    {
        if (is_string($value)) {
            $value = (float) str_replace(',', '.', $value);
        }

        $float = (float) $value;
        if ($float === (float) (int) $float) {
            return (string) (int) $float;
        }

        return rtrim(rtrim(number_format($float, 4, '.', ''), '0'), '.');
    }

    /**
     * Konversi tanggal 'Y-m-d H:i:s' ke serial Excel (epoch 1899-12-30).
     */
    private static function excelSerial(string $value): ?float
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $utc = strtotime($value . ' UTC');
        if ($utc === false) {
            return null;
        }

        return $utc / 86400 + 25569;
    }

    private static function columnLetter(int $index): string
    {
        $letters = '';
        $index++;
        while ($index > 0) {
            $index--;
            $letters = chr(65 + ($index % 26)) . $letters;
            $index = intdiv($index, 26);
        }

        return $letters;
    }

    private static function sanitizeSheetName(string $name): string
    {
        $name = str_replace(['[', ']', ':', '*', '?', '/', '\\'], '', $name);
        $name = trim($name);
        if ($name === '') {
            $name = 'Laporan';
        }

        return mb_substr($name, 0, 31);
    }

    private static function xml(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? $value;

        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
