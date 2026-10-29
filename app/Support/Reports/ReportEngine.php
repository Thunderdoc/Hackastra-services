<?php

namespace App\Support\Reports;

use App\Helpers\Qs;
use App\Models\Exam;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\Subject;

/**
 * The ONE rendering engine for every custom report (wave 31b).
 *
 * A report config is a plain array — presets, saved templates, and
 * one-off builder forms are all the same shape, so no report type
 * ever gets its own generator:
 *
 *   title, subtitle, source, filters{class_id, section_id,
 *   subject_id, exam_id, date_from, date_to, year, status},
 *   columns[{key|custom_N, label, type, width}],
 *   options{orientation, margin_mm, font_size, show_header,
 *   show_meta, show_footer, show_signatures, blank_rows,
 *   totals{key: sum|count|avg}, sort{key, dir}}, instructions
 *
 * Column types: text, number, currency, date, checkbox, box,
 * dropdown, yes_no, signature, remarks, blank. A column whose key
 * matches a source column always takes the source's type (the config
 * type is ignored for those keys); custom_N columns keep theirs.
 * P/A/L are three 'box' columns. A marks report automatically gains
 * its configured component columns after the chosen columns.
 *
 * resolve() returns display-ready cells plus raw numerics, so the
 * print blade never computes and totals never re-derive from text.
 */
class ReportEngine
{
    public const COLUMN_TYPES = [
        'text', 'number', 'currency', 'date', 'checkbox', 'box',
        'dropdown', 'yes_no', 'signature', 'remarks', 'blank',
    ];

    /**
     * Validate + normalize a raw config for this user.
     * Throws InvalidArgumentException on bad shape, and aborts 403
     * when the role may not use the source at all.
     */
    public static function validateConfig(array $config, $user): array
    {
        $type = $user->user_type ?? null;
        $source = $config['source'] ?? null;
        if (!is_string($source) || !ReportSources::definition($source)) {
            throw new \InvalidArgumentException('Unknown report source.');
        }
        if (!ReportSources::allowed($source, $type)) {
            abort(403, 'This report source is not available for your role.');
        }

        $title = trim((string) ($config['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 120) {
            throw new \InvalidArgumentException('A report title of 1–120 characters is required.');
        }

        $def = ReportSources::definition($source);
        $rawColumns = $config['columns'] ?? null;
        if (!is_array($rawColumns) || empty($rawColumns)) {
            throw new \InvalidArgumentException('At least one column is required.');
        }
        $columns = [];
        foreach ($rawColumns as $col) {
            if (!is_array($col) || empty($col['key']) || empty($col['label'])) {
                throw new \InvalidArgumentException('Every column needs a key and a label.');
            }
            $key = (string) $col['key'];
            $label = trim((string) $col['label']);
            if ($label === '' || mb_strlen($label) > 60) {
                throw new \InvalidArgumentException('Column labels must be 1–60 characters.');
            }
            if (isset($def['columns'][$key])) {
                // Source columns carry the source's own type.
                $colType = $def['columns'][$key][1];
            } elseif (preg_match('/^custom_\d+$/', $key)) {
                $colType = (string) ($col['type'] ?? 'text');
                if (!in_array($colType, self::COLUMN_TYPES, true)) {
                    throw new \InvalidArgumentException('Unknown column type: ' . $colType);
                }
            } else {
                throw new \InvalidArgumentException('Unknown column: ' . $key);
            }
            $width = (int) ($col['width'] ?? 10);
            $width = max(1, min(100, $width));
            // Alignment (builder v3): left/center/right, or null
            // for the type default (numbers/currency right, the
            // rest left). Anything else falls back to the default
            // rather than refusing the whole report.
            $align = strtolower((string) ($col['align'] ?? ''));
            $align = in_array($align, ['left', 'center', 'right'], true) ? $align : null;
            $columns[] = ['key' => $key, 'label' => $label, 'type' => $colType, 'width' => $width, 'align' => $align];
        }

        $filters = is_array($config['filters'] ?? null) ? $config['filters'] : [];
        $filters = array_intersect_key($filters, array_flip([
            'class_id', 'section_id', 'subject_id', 'exam_id',
            'date_from', 'date_to', 'year', 'status',
        ]));
        foreach (['class_id', 'section_id', 'subject_id', 'exam_id'] as $intKey) {
            if (isset($filters[$intKey]) && $filters[$intKey] !== '' && !ctype_digit((string) $filters[$intKey])) {
                throw new \InvalidArgumentException('Invalid filter value.');
            }
            if (isset($filters[$intKey]) && $filters[$intKey] !== '') {
                $filters[$intKey] = (int) $filters[$intKey];
            }
        }

        $options = is_array($config['options'] ?? null) ? $config['options'] : [];
        $totals = [];
        if (is_array($options['totals'] ?? null)) {
            $numericKeys = [];
            foreach ($columns as $c) {
                if (in_array($c['type'], ['number', 'currency'], true)) {
                    $numericKeys[] = $c['key'];
                }
            }
            foreach ($options['totals'] as $k => $op) {
                if (in_array($k, $numericKeys, true) && in_array($op, ['sum', 'count', 'avg'], true)) {
                    $totals[$k] = $op;
                }
            }
        }
        $sort = null;
        if (is_array($options['sort'] ?? null) && !empty($options['sort']['key'])) {
            $sortKey = (string) $options['sort']['key'];
            $sortDir = strtolower((string) ($options['sort']['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
            $sort = ['key' => $sortKey, 'dir' => $sortDir];
        }

        return [
            'title' => $title,
            'subtitle' => trim((string) ($config['subtitle'] ?? '')),
            'source' => $source,
            'filters' => $filters,
            'columns' => $columns,
            'options' => [
                'orientation' => ($options['orientation'] ?? 'portrait') === 'landscape' ? 'landscape' : 'portrait',
                'margin_mm' => max(5, min(25, (int) ($options['margin_mm'] ?? 10))),
                'font_size' => max(8, min(14, (int) ($options['font_size'] ?? 11))),
                'show_header' => !isset($options['show_header']) || (bool) $options['show_header'],
                'show_meta' => !isset($options['show_meta']) || (bool) $options['show_meta'],
                'show_footer' => !isset($options['show_footer']) || (bool) $options['show_footer'],
                'show_signatures' => !empty($options['show_signatures']),
                'blank_rows' => max(0, min(200, (int) ($options['blank_rows'] ?? 0))),
                'totals' => $totals,
                'sort' => $sort,
            ],
            'instructions' => trim((string) ($config['instructions'] ?? '')),
        ];
    }

    /**
     * Resolve a validated config to a print-ready document array:
     * columns (with width shares summing to 100), rows of display
     * cells + raw values, totals, and the meta line parts.
     */
    public static function resolve(array $config, $user): array
    {
        $source = $config['source'];
        $filters = $config['filters'];
        $rawRows = ReportSources::rows($source, $filters, $user);

        $columns = $config['columns'];
        // Marks: configured component columns are part of the sheet
        // whether or not the builder listed them.
        if ($source === 'marks') {
            $existing = array_column($columns, 'key');
            foreach (ReportSources::markComponentColumns($filters) as $compCol) {
                if (!in_array($compCol['key'], $existing, true)) {
                    $columns[] = $compCol;
                }
            }
        }

        // Sort (data rows only; blank rows are appended after).
        if (!empty($config['options']['sort'])) {
            $sortKey = $config['options']['sort']['key'];
            $dir = $config['options']['sort']['dir'];
            usort($rawRows, function ($a, $b) use ($sortKey, $dir) {
                $va = $a[$sortKey] ?? null;
                $vb = $b[$sortKey] ?? null;
                if (is_numeric($va) && is_numeric($vb)) {
                    $cmp = $va <=> $vb;
                } else {
                    $cmp = strcmp((string) $va, (string) $vb);
                }
                return $dir === 'desc' ? -$cmp : $cmp;
            });
        }

        // Blank rows: manual-writing space. A blank-source sheet with
        // no count requested still gets a usable 20 rows.
        $blankRows = (int) $config['options']['blank_rows'];
        if ($source === 'blank' && $blankRows === 0) {
            $blankRows = 20;
        }

        $rows = [];
        foreach ($rawRows as $raw) {
            $cells = [];
            $rawOut = [];
            foreach ($columns as $col) {
                $value = $raw[$col['key']] ?? null;
                $cells[$col['key']] = self::formatCell($value, $col['type']);
                $rawOut[$col['key']] = is_numeric($value) ? $value + 0 : null;
            }
            $rows[] = ['cells' => $cells, 'raw' => $rawOut];
        }
        for ($i = 0; $i < $blankRows; $i++) {
            $cells = [];
            $rawOut = [];
            foreach ($columns as $col) {
                $cells[$col['key']] = '';
                $rawOut[$col['key']] = null;
            }
            $rows[] = ['cells' => $cells, 'raw' => $rawOut, 'blank' => true];
        }

        // dompdf exhausts even the 512M request headroom past ~1,500
        // rows in a single table (proven: unfiltered whole-school
        // roster 500s with an empty body). Refuse with a plain
        // message instead of a blank 500. 800 covers the largest
        // whole class (520) plus blank rows, with headroom.
        $totalRows = count($rows);
        if ($totalRows > 800) {
            throw new \InvalidArgumentException(
                "Too many rows for one PDF ({$totalRows}). Filter by class or section first, then generate."
            );
        }

        // Totals over data rows only (blank rows never inflate a sum).
        $totals = [];
        foreach ($config['options']['totals'] as $key => $op) {
            $values = [];
            foreach ($rawRows as $raw) {
                if (isset($raw[$key]) && is_numeric($raw[$key])) {
                    $values[] = $raw[$key] + 0;
                }
            }
            if ($op === 'sum') {
                $totals[$key] = array_sum($values);
            } elseif ($op === 'count') {
                $totals[$key] = count($values);
            } elseif ($op === 'avg') {
                $totals[$key] = $values ? round(array_sum($values) / count($values), 1) : null;
            }
        }

        // Width shares: weights normalised to 100, last column absorbs rounding.
        $weightSum = 0;
        foreach ($columns as $c) {
            $weightSum += $c['width'];
        }
        $shareSum = 0;
        foreach ($columns as $i => $c) {
            $share = $weightSum > 0 ? round($c['width'] / $weightSum * 100, 1) : 0;
            $columns[$i]['width_pct'] = $share;
            $shareSum += $share;
        }
        if (!empty($columns)) {
            $columns[count($columns) - 1]['width_pct'] += round(100 - $shareSum, 1);
        }

        return [
            'title' => $config['title'],
            'subtitle' => $config['subtitle'],
            'source' => $source,
            'columns' => $columns,
            'rows' => $rows,
            'data_count' => count($rawRows),
            'totals' => $totals,
            'totals_formatted' => self::formatTotals($totals, $columns),
            'meta' => self::metaLine($config, $user),
            'options' => $config['options'],
            'instructions' => $config['instructions'],
        ];
    }

    /** Display formatting for one raw value under a column type. */
    public static function formatCell($value, string $type): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if ($type === 'currency') {
            return 'Rs ' . number_format((float) $value, 0);
        }
        if ($type === 'number') {
            return is_numeric($value) ? (string) ($value + 0) : (string) $value;
        }
        if ($type === 'date') {
            try {
                $ts = strtotime((string) $value);
                return $ts ? date('d M Y', $ts) : (string) $value;
            } catch (\Throwable $e) {
                return (string) $value;
            }
        }
        return (string) $value;
    }

    protected static function formatTotals(array $totals, array $columns): array
    {
        $types = [];
        foreach ($columns as $c) {
            $types[$c['key']] = $c['type'];
        }
        $out = [];
        foreach ($totals as $key => $value) {
            $out[$key] = $value === null ? '' : self::formatCell($value, $types[$key] ?? 'number');
        }
        return $out;
    }

    /** Human meta line parts from the filters (names looked up, never raw ids). */
    public static function metaLine(array $config, $user): array
    {
        $f = $config['filters'];
        $parts = [];
        if (!empty($f['class_id']) && ($c = MyClass::find($f['class_id']))) {
            $parts[] = 'Class: ' . $c->name;
        }
        if (!empty($f['section_id']) && ($s = Section::find($f['section_id']))) {
            $parts[] = 'Section: ' . $s->name;
        }
        if (!empty($f['subject_id']) && ($s = Subject::find($f['subject_id']))) {
            $parts[] = 'Subject: ' . $s->name;
        }
        if (!empty($f['exam_id']) && ($e = Exam::find($f['exam_id']))) {
            $parts[] = 'Exam: ' . $e->name;
        }
        if (!empty($f['year'])) {
            $parts[] = 'Session: ' . $f['year'];
        } elseif ($config['source'] !== 'platform') {
            $parts[] = 'Session: ' . (Qs::getCurrentSession() ?: '');
        }
        if (!empty($f['date_from']) || !empty($f['date_to'])) {
            $parts[] = 'Period: ' . ($f['date_from'] ?? '…') . ' to ' . ($f['date_to'] ?? '…');
        }
        if (($user->user_type ?? null) === 'teacher') {
            $parts[] = 'Teacher: ' . $user->name;
        }
        return array_values(array_filter($parts));
    }
}
