<?php

namespace App\Support\Bulk;

/**
 * Wave 10 — a minimal, dependency-free .xlsx reader for the bulk
 * pipeline (no PhpSpreadsheet in composer, and none is needed: an
 * .xlsx is a ZIP of XML parts, and PHP ships ZipArchive +
 * XMLReader in both the local and the Render builds).
 *
 * Reads the FIRST sheet only — bulk files are single-sheet by
 * construction — and returns rows of strings, exactly like the CSV
 * path's fgetcsv output, so validation downstream never knows the
 * difference. Shared strings are resolved; inline strings, formula
 * results and booleans pass through; numeric cells styled as dates
 * (built-in date formats, or custom formats carrying y/m/d) are
 * converted from Excel serials to Y-m-d, because the bulk modules
 * parse dates as strings. Anything else stays a raw number string.
 *
 * Throws \RuntimeException on an unreadable or malformed workbook;
 * the caller turns that into a failed job, never a 500.
 */
class XlsxReader
{
    /** Built-in Excel number formats that are dates. */
    protected const DATE_FORMAT_IDS = [
        14, 15, 16, 17, 18, 19, 20, 21, 22,
        27, 28, 29, 30, 31, 32, 33, 34, 35, 36,
        45, 46, 47, 50, 57,
    ];

    public static function looksLikeXlsx(string $path): bool
    {
        $head = @file_get_contents($path, false, null, 0, 4);
        return $head === "PK\x03\x04";
    }

    /** @return array<int, array<int, string>> */
    public static function rows(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('The Excel file could not be opened.');
        }
        try {
            $shared = self::sharedStrings($zip);
            $sheetPath = self::firstSheetPath($zip);
            $dateStyles = self::dateStyles($zip);
            return self::sheetRows($zip, $sheetPath, $shared, $dateStyles);
        } finally {
            $zip->close();
        }
    }

    /** @return array<int, string> */
    protected static function sharedStrings(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }
        $strings = [];
        $reader = new \XMLReader();
        $reader->XML($xml);
        $current = null;
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->name === 'si') {
                $current = '';
            } elseif ($reader->nodeType === \XMLReader::ELEMENT && $reader->name === 't' && $current !== null) {
                $current .= $reader->readString();
            } elseif ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->name === 'si' && $current !== null) {
                $strings[] = $current;
                $current = null;
            }
        }
        $reader->close();
        return $strings;
    }

    protected static function firstSheetPath(\ZipArchive $zip): string
    {
        $wb = $zip->getFromName('xl/workbook.xml');
        if ($wb === false) {
            throw new \RuntimeException('The Excel file has no workbook part.');
        }
        $reader = new \XMLReader();
        $reader->XML($wb);
        $rid = null;
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->name === 'sheet') {
                $rid = $reader->getAttribute('r:id');
                break;
            }
        }
        $reader->close();
        if ($rid === null) {
            throw new \RuntimeException('The Excel file has no sheets.');
        }

        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($rels === false) {
            throw new \RuntimeException('The Excel file has no workbook relationships.');
        }
        $reader = new \XMLReader();
        $reader->XML($rels);
        $target = null;
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->name === 'Relationship'
                && $reader->getAttribute('Id') === $rid) {
                $target = $reader->getAttribute('Target');
                break;
            }
        }
        $reader->close();
        if ($target === null) {
            throw new \RuntimeException('The Excel file’s first sheet could not be located.');
        }

        $target = ltrim($target, '/');
        if (strpos($target, 'xl/') !== 0) {
            $target = 'xl/' . $target;
        }
        if ($zip->getFromName($target) === false) {
            throw new \RuntimeException('The Excel file’s first sheet could not be read.');
        }
        return $target;
    }

    /**
     * Style indexes whose number format is a date, from styles.xml.
     * @return array<int, bool>
     */
    protected static function dateStyles(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/styles.xml');
        if ($xml === false) {
            return [];
        }
        $doc = simplexml_load_string($xml);
        if ($doc === false) {
            return [];
        }
        $ns = $doc->getNamespaces(true);
        $main = isset($ns['']) ? $doc->children($ns['']) : $doc;

        $custom = [];
        if (isset($main->numFmts)) {
            foreach ($main->numFmts->numFmt as $fmt) {
                $attrs = $fmt->attributes();
                $custom[(int) $attrs['numFmtId']] = (string) $attrs['formatCode'];
            }
        }

        $isDateFormat = function ($formatCode) use ($custom) {
            if (is_int($formatCode)) {
                return in_array($formatCode, self::DATE_FORMAT_IDS, true);
            }
            // Custom format: strip quoted literals and [...] sections,
            // then a date if it carries y or d (month-only formats are
            // vanishingly rare in bulk sheets; m alone is ambiguous
            // with minutes, so it does not count on its own).
            $code = preg_replace('/"[^"]*"/', '', (string) $formatCode);
            $code = preg_replace('/\[[^\]]*\]/', '', $code);
            return (bool) preg_match('/[yd]/i', $code);
        };

        $dateStyles = [];
        if (isset($main->cellXfs)) {
            // NOTE: foreach over SimpleXML children yields the element
            // NAME as the key, never an index — count position by hand.
            $idx = 0;
            foreach ($main->cellXfs->xf as $xf) {
                $attrs = $xf->attributes();
                $fmtId = (int) $attrs['numFmtId'];
                $format = $custom[$fmtId] ?? $fmtId;
                if ($isDateFormat($format)) {
                    $dateStyles[$idx] = true;
                }
                $idx++;
            }
        }
        return $dateStyles;
    }

    /** @return array<int, array<int, string>> */
    protected static function sheetRows(\ZipArchive $zip, string $sheetPath, array $shared, array $dateStyles): array
    {
        $xml = $zip->getFromName($sheetPath);
        if ($xml === false) {
            throw new \RuntimeException('The Excel file’s first sheet could not be read.');
        }
        $reader = new \XMLReader();
        $reader->XML($xml);

        $rows = [];
        $cells = null;
        $cell = null;
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->name === 'row') {
                $cells = [];
            } elseif ($reader->nodeType === \XMLReader::ELEMENT && $reader->name === 'c' && $cells !== null) {
                $cell = [
                    'ref' => $reader->getAttribute('r') ?? '',
                    't' => $reader->getAttribute('t'),
                    's' => $reader->getAttribute('s'),
                    'v' => null,
                    'is' => null,
                ];
                if ($reader->isEmptyElement) {
                    self::storeCell($cells, $cell, $shared, $dateStyles);
                    $cell = null;
                }
            } elseif ($reader->nodeType === \XMLReader::ELEMENT && $reader->name === 'v' && $cell !== null) {
                $cell['v'] = $reader->readString();
            } elseif ($reader->nodeType === \XMLReader::ELEMENT && $reader->name === 'is' && $cell !== null) {
                $cell['is'] = '';
            } elseif ($reader->nodeType === \XMLReader::ELEMENT && $reader->name === 't' && $cell !== null && $cell['is'] !== null) {
                $cell['is'] .= $reader->readString();
            } elseif ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->name === 'c' && $cell !== null) {
                self::storeCell($cells, $cell, $shared, $dateStyles);
                $cell = null;
            } elseif ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->name === 'row' && $cells !== null) {
                if (empty($cells)) {
                    $rows[] = [''];
                } else {
                    // Dense row: gaps (a missing <c> for a column)
                    // pad with '' so values never slide left.
                    ksort($cells);
                    $dense = [];
                    for ($i = 0; $i <= max(array_keys($cells)); $i++) {
                        $dense[] = $cells[$i] ?? '';
                    }
                    $rows[] = $dense;
                }
                $cells = null;
            }
        }
        $reader->close();
        return $rows;
    }

    protected static function storeCell(array &$cells, array $cell, array $shared, array $dateStyles): void
    {
        $index = self::columnIndex($cell['ref']);
        $type = $cell['t'];
        $value = '';

        if ($type === 's') {
            $value = $shared[(int) ($cell['v'] ?? 0)] ?? '';
        } elseif ($type === 'inlineStr') {
            $value = (string) ($cell['is'] ?? '');
        } elseif ($type === 'str' || $type === 'b') {
            $value = (string) ($cell['v'] ?? '');
        } elseif ($type === 'e') {
            $value = '';
        } else {
            $raw = (string) ($cell['v'] ?? '');
            if ($raw !== '' && is_numeric($raw) && $cell['s'] !== null && isset($dateStyles[(int) $cell['s']])) {
                $value = self::serialToDate($raw);
            } else {
                $value = $raw;
            }
        }

        $cells[$index] = $value;
    }

    /** Column letters (A, B, …, AA) → 0-based index. */
    protected static function columnIndex(string $ref): int
    {
        preg_match('/^([A-Z]+)/', $ref, $m);
        $letters = $m[1] ?? 'A';
        $index = 0;
        for ($i = 0; $i < strlen($letters); $i++) {
            $index = $index * 26 + (ord($letters[$i]) - ord('A') + 1);
        }
        return $index - 1;
    }

    /**
     * Excel serial → Y-m-d. Serial 1 is 1900-01-01, so the base is
     * 1899-12-31; serials above 60 shift by one day for Excel's
     * phantom 29 Feb 1900 (serial 60 itself).
     */
    protected static function serialToDate(string $raw): string
    {
        $serial = (float) $raw;
        if ($serial <= 0 || $serial > 2958465) {
            return $raw;
        }
        if ($serial > 60) {
            $serial -= 1;
        }
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', '1899-12-31');
        if ($date === false) {
            return $raw;
        }
        return $date->modify('+' . (int) floor($serial) . ' days')->format('Y-m-d');
    }
}
