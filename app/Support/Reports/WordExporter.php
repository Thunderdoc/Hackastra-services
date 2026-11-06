<?php

namespace App\Support\Reports;

use ZipArchive;

/**
 * Word (.docx) export for the Universal Report Builder (owner,
 * 2026-11-01: "aur download in word" — PDF is the pakka final copy,
 * Word is the teacher's editable worksheet).
 *
 * A real OOXML package written with ZipArchive (ext-zip ships in
 * both Docker images) — no PhpWord dependency. The input is the
 * SAME resolved document array ReportEngine::resolve() produces for
 * the print view, so the Word file can never disagree with the PDF
 * about rows, columns, totals or the truncation note; only the
 * rendering differs. Branding comes from Settings at generation
 * time, exactly like the PDF.
 */
class WordExporter
{
    /** Build the .docx bytes for a resolved document. */
    public static function build(array $doc, array $settings): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new \InvalidArgumentException('Word export is unavailable on this server (missing zip extension).');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'hkword');
        if ($tmp === false) {
            throw new \InvalidArgumentException('Word export could not create a temporary file.');
        }
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new \InvalidArgumentException('Word export could not create the document package.');
        }
        $zip->addFromString('[Content_Types].xml', self::contentTypes());
        $zip->addFromString('_rels/.rels', self::packageRels());
        $zip->addFromString('word/styles.xml', self::styles());
        $zip->addFromString('word/document.xml', self::documentXml($doc, $settings));
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }

    protected static function e($value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    protected static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            . '</Types>';
    }

    protected static function packageRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>';
    }

    protected static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:docDefaults><w:rPrDefault><w:rPr>'
            . '<w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/>'
            . '<w:sz w:val="22"/><w:szCs w:val="22"/>'
            . '</w:rPr></w:rPrDefault></w:docDefaults>'
            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal">'
            . '<w:name w:val="Normal"/><w:qFormat/>'
            . '</w:style>'
            . '</w:styles>';
    }

    /** One run: text with size (half-points), bold, italic, colour. */
    protected static function run(string $text, array $opts = []): string
    {
        $rpr = '';
        if (!empty($opts['bold'])) {
            $rpr .= '<w:b/>';
        }
        if (!empty($opts['italic'])) {
            $rpr .= '<w:i/>';
        }
        if (!empty($opts['size'])) {
            $rpr .= '<w:sz w:val="' . (int) $opts['size'] . '"/>';
        }
        if (!empty($opts['color'])) {
            $rpr .= '<w:color w:val="' . self::e($opts['color']) . '"/>';
        }
        return '<w:r>' . ($rpr !== '' ? '<w:rPr>' . $rpr . '</w:rPr>' : '')
            . '<w:t xml:space="preserve">' . self::e($text) . '</w:t></w:r>';
    }

    /** One paragraph: runs from run() plus alignment. */
    protected static function para(array $runs, string $align = 'left', int $spaceAfter = 120): string
    {
        $jc = $align !== 'left' ? '<w:jc w:val="' . $align . '"/>' : '';
        return '<w:p><w:pPr>' . $jc . '<w:spacing w:after="' . $spaceAfter . '"/></w:pPr>'
            . implode('', $runs) . '</w:p>';
    }

    /** One bordered table cell. */
    protected static function cell(string $text, array $opts = []): string
    {
        $shd = !empty($opts['fill'])
            ? '<w:shd w:val="clear" w:color="auto" w:fill="' . self::e($opts['fill']) . '"/>'
            : '';
        $align = $opts['align'] ?? 'left';
        $runOpts = [
            'bold' => !empty($opts['bold']),
            'size' => $opts['size'] ?? 20,
            'color' => $opts['color'] ?? null,
        ];
        $runs = [];
        foreach (explode("\n", $text) as $i => $line) {
            if ($i > 0) {
                $runs[] = '<w:r><w:br/></w:r>';
            }
            $runs[] = self::run($line, $runOpts);
        }
        return '<w:tc><w:tcPr>' . $shd . '</w:tcPr>'
            . self::para($runs, $align, 0)
            . '</w:tc>';
    }

    protected static function documentXml(array $doc, array $s): string
    {
        $options = $doc['options'];
        $parts = [];

        // School band: name + the guarded meta line (the controller
        // already replaced the phone via Qs::getSchoolPhone(), so a
        // placeholder number can never reach this document).
        if (!empty($options['show_header'])) {
            $parts[] = self::para(
                [self::run($s['system_name'] ?? 'Hackstra Academy', ['bold' => true, 'size' => 32, 'color' => '10263B'])],
                'left', 40
            );
            $metaBits = [];
            if (!empty($s['address'])) {
                $metaBits[] = $s['address'];
            }
            if (!empty($s['phone'])) {
                $metaBits[] = 'Tel: ' . $s['phone'];
            }
            if (!empty($s['system_email'])) {
                $metaBits[] = 'Email: ' . $s['system_email'];
            }
            if ($metaBits) {
                $parts[] = self::para([self::run(implode('  |  ', $metaBits), ['size' => 18, 'color' => '4A5A6E'])], 'left', 160);
            }
            $parts[] = self::para(
                [self::run(strtoupper((string) $doc['title']), ['bold' => true, 'size' => 28, 'color' => '10263B'])],
                'left', 40
            );
            if (($doc['subtitle'] ?? '') !== '') {
                $parts[] = self::para([self::run($doc['subtitle'], ['size' => 20, 'color' => '718096'])], 'left', 80);
            }
            if (!empty($options['show_meta']) && !empty($doc['meta'])) {
                $parts[] = self::para(
                    [self::run(implode(' · ', $doc['meta']) . '  ·  ' . $doc['data_count'] . ' record(s)', ['size' => 18, 'color' => '4A5A6E'])],
                    'left', 160
                );
            }
        }

        if (($doc['instructions'] ?? '') !== '') {
            $parts[] = self::para([self::run($doc['instructions'], ['size' => 20, 'color' => '4A5A6E'])], 'left', 160);
        }

        // Table geometry: usable width from paper + orientation +
        // the configured margins, shared by width_pct.
        $landscape = ($options['orientation'] ?? 'portrait') === 'landscape';
        $paperW = (($options['paper'] ?? 'a4') === 'letter') ? 12240 : 11906;
        $paperH = (($options['paper'] ?? 'a4') === 'letter') ? 15840 : 16838;
        if ($landscape) {
            [$paperW, $paperH] = [$paperH, $paperW];
        }
        $marginTwips = (int) round(((int) ($options['margin_mm'] ?? 10)) * 56.6929);
        $usable = max(2000, $paperW - 2 * $marginTwips);

        $columns = $doc['columns'];
        $grid = '';
        foreach ($columns as $col) {
            $grid .= '<w:gridCol w:w="' . (int) round($usable * ($col['width_pct'] ?? 10) / 100) . '"/>';
        }
        $borders = '<w:tblBorders>'
            . '<w:top w:val="single" w:sz="6" w:color="B9C6D4"/>'
            . '<w:left w:val="single" w:sz="6" w:color="B9C6D4"/>'
            . '<w:bottom w:val="single" w:sz="6" w:color="B9C6D4"/>'
            . '<w:insideH w:val="single" w:sz="6" w:color="B9C6D4"/>'
            . '<w:insideV w:val="single" w:sz="6" w:color="B9C6D4"/>'
            . '<w:right w:val="single" w:sz="6" w:color="B9C6D4"/>'
            . '</w:tblBorders>';

        $table = '<w:tbl><w:tblPr><w:tblW w:w="' . $usable . '" w:type="dxa"/>' . $borders . '</w:tblPr>'
            . '<w:tblGrid>' . $grid . '</w:tblGrid>';

        // Header row: navy band, white bold labels — mirrors print.
        $table .= '<w:tr><w:trPr><w:tblHeader/></w:trPr>';
        foreach ($columns as $col) {
            $table .= self::cell($col['label'], [
                'fill' => '10263B', 'bold' => true, 'color' => 'FFFFFF', 'size' => 20,
                'align' => $col['align'] ?? 'left',
            ]);
        }
        $table .= '</w:tr>';

        if (empty($doc['rows'])) {
            $parts[] = self::para([self::run('No records found for the selected filters.', ['size' => 20, 'color' => '718096'])], 'left', 120);
        } else {
            foreach ($doc['rows'] as $row) {
                $table .= '<w:tr>';
                foreach ($columns as $col) {
                    $type = $col['type'];
                    if ($type === 'checkbox' || $type === 'box') {
                        $text = '';
                    } elseif ($type === 'yes_no') {
                        $text = '□ Yes   □ No';
                    } elseif ($type === 'signature') {
                        $text = '';
                    } else {
                        $text = (string) ($row['cells'][$col['key']] ?? '');
                    }
                    $align = $col['align'] ?? (($type === 'number' || $type === 'currency') ? 'right' : 'left');
                    $table .= self::cell($text, ['align' => $align]);
                }
                $table .= '</w:tr>';
            }
            if (!empty($doc['totals'])) {
                $table .= '<w:tr>';
                foreach ($columns as $idx => $col) {
                    $text = '';
                    if ($idx === 0) {
                        $text = 'Total';
                        if (isset($doc['totals_formatted'][$col['key']])) {
                            $text .= ' — ' . $doc['totals_formatted'][$col['key']];
                        }
                    } elseif (isset($doc['totals_formatted'][$col['key']])) {
                        $text = (string) $doc['totals_formatted'][$col['key']];
                    }
                    $table .= self::cell($text, ['bold' => true, 'align' => $col['align'] ?? 'left']);
                }
                $table .= '</w:tr>';
            }
            $table .= '</w:tbl>';
            $parts[] = $table;
        }

        if (!empty($doc['truncation_note'])) {
            $parts[] = self::para([self::run($doc['truncation_note'], ['italic' => true, 'size' => 18, 'color' => '4A5A6E'])], 'left', 120);
        }

        if (!empty($options['show_signatures'])) {
            $sig = '<w:tbl><w:tblPr><w:tblW w:w="' . $usable . '" w:type="dxa"/></w:tblPr>'
                . '<w:tblGrid><w:gridCol w:w="' . (int) ($usable / 3) . '"/><w:gridCol w:w="' . (int) ($usable / 3) . '"/><w:gridCol w:w="' . (int) ($usable / 3) . '"/></w:tblGrid>'
                . '<w:tr>'
                . self::cell('_________________________' . "\n" . 'Prepared by', ['size' => 20])
                . self::cell('_________________________' . "\n" . 'Checked by', ['size' => 20])
                . self::cell('_________________________' . "\n" . 'Authorised by', ['size' => 20])
                . '</w:tr></w:tbl>';
            $parts[] = self::para([self::run('', [])], 'left', 200);
            $parts[] = $sig;
        }

        if (!empty($options['show_footer'])) {
            $footer = ($options['footer_text'] ?? '') !== ''
                ? $options['footer_text']
                : 'Generated on: ' . date('d M Y | g:i A');
            $parts[] = self::para([self::run($footer, ['size' => 18, 'color' => '718096'])], 'left', 120);
        }

        $sect = '<w:sectPr>'
            . '<w:pgSz w:w="' . $paperW . '" w:h="' . $paperH . '"' . ($landscape ? ' w:orient="landscape"' : '') . '/>'
            . '<w:pgMar w:top="' . $marginTwips . '" w:right="' . $marginTwips . '" w:bottom="' . $marginTwips . '" w:left="' . $marginTwips . '" w:header="0" w:footer="0" w:gutter="0"/>'
            . '</w:sectPr>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body>' . implode('', $parts) . $sect . '</w:body></w:document>';
    }
}
