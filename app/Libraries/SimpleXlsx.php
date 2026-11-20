<?php

namespace App\Libraries;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * A small, dependency-free reader and writer for .xlsx workbooks.
 *
 * It covers what the portal needs for upload templates: plain cell values (text and numbers),
 * a styled header row, column widths, a frozen header, and drop-down lists. It is NOT a general
 * spreadsheet library: formulas are read as their cached result, and styling beyond the header is
 * not preserved.
 *
 * Writer sheet spec:
 *   [
 *     'name'        => 'Users',
 *     'rows'        => [['Username', ...], ['jdoe', ...]],   // first row is the header when 'header' is true
 *     'header'      => true,
 *     'widths'      => [18, 28, ...],                         // characters
 *     'validations' => [['sqref' => 'E2:E500', 'formula' => 'Lists!$A$2:$A$40']],
 *   ]
 */
class SimpleXlsx
{
    /** Safety limits when reading uploaded files. */
    private const MAX_UNCOMPRESSED_BYTES = 30 * 1024 * 1024;
    private const MAX_ROWS               = 25000;
    private const MAX_COLUMNS            = 60;

    // =====================================================================
    // Writing
    // =====================================================================

    /**
     * @param list<array<string,mixed>> $sheets
     *
     * @return string the .xlsx file contents
     */
    public static function write(array $sheets): string
    {
        if ($sheets === []) {
            throw new RuntimeException('A workbook needs at least one sheet.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the workbook.');
        }

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $workbookSheets = '';
        $workbookRels   = '';

        foreach ($sheets as $i => $sheet) {
            $n = $i + 1;
            $contentTypes .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" '
                . 'ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $workbookSheets .= '<sheet name="' . self::xml(self::sheetName((string) $sheet['name'])) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $workbookRels   .= '<Relationship Id="rId' . $n . '" '
                . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" '
                . 'Target="worksheets/sheet' . $n . '.xml"/>';
            $zip->addFromString('xl/worksheets/sheet' . $n . '.xml', self::sheetXml($sheet));
        }
        $styleRel = count($sheets) + 1;
        $workbookRels .= '<Relationship Id="rId' . $styleRel . '" '
            . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $contentTypes .= '</Types>';

        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $workbookSheets . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $workbookRels . '</Relationships>');
        $zip->addFromString('xl/styles.xml', self::stylesXml());
        $zip->close();

        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    /** @param array<string,mixed> $sheet */
    private static function sheetXml(array $sheet): string
    {
        $rows   = $sheet['rows'] ?? [];
        $header = $sheet['header'] ?? true;
        $widths = $sheet['widths'] ?? [];

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        if ($header) {
            $xml .= '<sheetViews><sheetView workbookViewId="0">'
                . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
                . '</sheetView></sheetViews>';
        }

        if ($widths !== []) {
            $xml .= '<cols>';
            foreach (array_values($widths) as $i => $w) {
                $c = $i + 1;
                $xml .= '<col min="' . $c . '" max="' . $c . '" width="' . (float) $w . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';
        foreach (array_values($rows) as $r => $row) {
            $rowNo = $r + 1;
            $xml  .= '<row r="' . $rowNo . '">';
            foreach (array_values($row) as $c => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $ref   = self::columnLetter($c) . $rowNo;
                $style = ($header && $r === 0) ? ' s="1"' : '';
                if (is_int($value) || is_float($value)) {
                    $xml .= '<c r="' . $ref . '"' . $style . '><v>' . $value . '</v></c>';
                } else {
                    $xml .= '<c r="' . $ref . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">'
                        . self::xml((string) $value) . '</t></is></c>';
                }
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';

        $validations = $sheet['validations'] ?? [];
        if ($validations !== []) {
            $xml .= '<dataValidations count="' . count($validations) . '">';
            foreach ($validations as $v) {
                $xml .= '<dataValidation type="list" allowBlank="1" showErrorMessage="1" '
                    . 'errorTitle="Invalid value" error="Please pick a value from the list." '
                    . 'sqref="' . self::xml((string) $v['sqref']) . '">'
                    . '<formula1>' . self::xml((string) $v['formula']) . '</formula1></dataValidation>';
            }
            $xml .= '</dataValidations>';
        }

        return $xml . '</worksheet>';
    }

    private static function stylesXml(): string
    {
        // xf 0 = default, xf 1 = bold white text on dark blue (header)
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF1F3A5F"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    // =====================================================================
    // Reading
    // =====================================================================

    /**
     * Read one sheet (the first by default) as a list of rows. Each row is a list of strings,
     * padded so that column positions line up. Completely empty rows are dropped, but the
     * original spreadsheet row number is kept as the array key (1-based), so error reports can
     * say "row 7".
     *
     * @return array<int,list<string>>
     */
    public static function read(string $path, ?string $sheetName = null): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('This does not look like a valid .xlsx file.');
        }

        try {
            self::guardSize($zip);

            $sheetPath = self::findSheetPath($zip, $sheetName);
            $shared    = self::readSharedStrings($zip);

            $xml = self::loadXml((string) $zip->getFromName($sheetPath));
            if ($xml === null) {
                throw new RuntimeException('The worksheet could not be read.');
            }

            $rows = [];
            foreach ($xml->sheetData->row ?? [] as $row) {
                $rowNo = (int) $row['r'];
                if ($rowNo > self::MAX_ROWS) {
                    throw new RuntimeException('The file has more than ' . self::MAX_ROWS . ' rows.');
                }

                $cells = [];
                $max   = -1;
                $auto  = 0;
                foreach ($row->c as $cell) {
                    $col = isset($cell['r']) ? self::columnIndex((string) $cell['r']) : $auto;
                    $auto = $col + 1;
                    if ($col >= self::MAX_COLUMNS) {
                        continue;
                    }
                    $cells[$col] = self::cellValue($cell, $shared);
                    $max = max($max, $col);
                }

                if ($max < 0) {
                    continue;
                }

                $line = [];
                for ($i = 0; $i <= $max; $i++) {
                    $line[] = $cells[$i] ?? '';
                }

                if (trim(implode('', $line)) === '') {
                    continue;
                }

                $rows[$rowNo ?: count($rows) + 1] = $line;
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    /**
     * Read a CSV file the same way (UTF-8, optional BOM, comma or semicolon separated).
     *
     * @return array<int,list<string>>
     */
    public static function readCsv(string $path): array
    {
        $raw = (string) file_get_contents($path, false, null, 0, self::MAX_UNCOMPRESSED_BYTES);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);

        $firstLine = strtok($raw, "\n") ?: '';
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        $rows  = [];
        $n     = 0;
        $lines = explode("\n", $raw);
        foreach ($lines as $line) {
            $n++;
            if ($n > self::MAX_ROWS) {
                throw new RuntimeException('The file has more than ' . self::MAX_ROWS . ' rows.');
            }
            if (trim($line) === '') {
                continue;
            }
            $cells = array_map('strval', str_getcsv($line, $delimiter, '"', ''));
            if (trim(implode('', $cells)) === '') {
                continue;
            }
            $rows[$n] = $cells;
        }

        return $rows;
    }

    // ---------------------------------------------------------------------

    private static function guardSize(ZipArchive $zip): void
    {
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat   = $zip->statIndex($i);
            $total += (int) ($stat['size'] ?? 0);
        }
        if ($total > self::MAX_UNCOMPRESSED_BYTES) {
            throw new RuntimeException('The file is too large to read safely.');
        }
    }

    private static function findSheetPath(ZipArchive $zip, ?string $sheetName): string
    {
        $workbook = self::loadXml((string) $zip->getFromName('xl/workbook.xml'));
        $rels     = self::loadXml((string) $zip->getFromName('xl/_rels/workbook.xml.rels'));
        if ($workbook === null || $rels === null) {
            throw new RuntimeException('This does not look like a valid .xlsx file.');
        }

        $targets = [];
        foreach ($rels->Relationship ?? [] as $rel) {
            $targets[(string) $rel['Id']] = (string) $rel['Target'];
        }

        $workbook->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        foreach ($workbook->sheets->sheet ?? [] as $sheet) {
            $attrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $rid   = (string) ($attrs['id'] ?? '');
            $name  = (string) $sheet['name'];

            if ($sheetName !== null && strcasecmp($name, $sheetName) !== 0) {
                continue;
            }
            if (! isset($targets[$rid])) {
                continue;
            }

            $target = $targets[$rid];
            $target = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;

            return $target;
        }

        throw new RuntimeException($sheetName === null
            ? 'The workbook has no worksheets.'
            : "The workbook has no sheet named '{$sheetName}'.");
    }

    /** @return list<string> */
    private static function readSharedStrings(ZipArchive $zip): array
    {
        $raw = $zip->getFromName('xl/sharedStrings.xml');
        if ($raw === false) {
            return [];
        }

        $xml = self::loadXml($raw);
        if ($xml === null) {
            return [];
        }

        $strings = [];
        foreach ($xml->si ?? [] as $si) {
            $strings[] = self::richText($si);
        }

        return $strings;
    }

    /** Concatenate the text pieces of a shared string or inline string (plain or rich text). */
    private static function richText(SimpleXMLElement $node): string
    {
        $text = '';
        if (isset($node->t)) {
            $text .= (string) $node->t;
        }
        foreach ($node->r ?? [] as $run) {
            $text .= (string) $run->t;
        }

        return $text;
    }

    /** @param list<string> $shared */
    private static function cellValue(SimpleXMLElement $cell, array $shared): string
    {
        $type = (string) ($cell['t'] ?? 'n');

        if ($type === 'inlineStr') {
            return trim(self::richText($cell->is));
        }

        $v = isset($cell->v) ? (string) $cell->v : '';

        return match ($type) {
            's'     => trim($shared[(int) $v] ?? ''),
            'b'     => $v === '1' ? 'TRUE' : 'FALSE',
            'str', 'e' => trim($v),
            default => self::normaliseNumber($v),
        };
    }

    /** Whole numbers that Excel stores as 9876543210 or 9.87654321E9 come back as plain digits. */
    private static function normaliseNumber(string $v): string
    {
        if ($v === '' || ! is_numeric($v)) {
            return trim($v);
        }

        $f = (float) $v;
        if ($f == floor($f) && abs($f) < 1e15) {
            return number_format($f, 0, '', '');
        }

        return rtrim(rtrim(sprintf('%.15F', $f), '0'), '.');
    }

    private static function loadXml(string $raw): ?SimpleXMLElement
    {
        if ($raw === '') {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        // LIBXML_NONET: never fetch anything over the network while parsing
        $xml = simplexml_load_string($raw, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $xml === false ? null : $xml;
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    public static function columnLetter(int $index): string
    {
        $letters = '';
        $index++;
        while ($index > 0) {
            $mod     = ($index - 1) % 26;
            $letters = chr(65 + $mod) . $letters;
            $index   = intdiv($index - 1, 26);
        }

        return $letters;
    }

    /** "C7" -> 2 (zero-based column). */
    private static function columnIndex(string $ref): int
    {
        preg_match('/^([A-Z]+)/i', $ref, $m);
        $letters = strtoupper($m[1] ?? 'A');
        $n       = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }

    /** Escape for XML text/attributes and drop control characters that XML 1.0 forbids. */
    private static function xml(string $text): string
    {
        $text = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';

        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Excel sheet names: max 31 characters, none of  \ / ? * [ ] : */
    private static function sheetName(string $name): string
    {
        $name = preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $name) ?? 'Sheet';

        return mb_substr(trim($name) ?: 'Sheet', 0, 31);
    }
}
