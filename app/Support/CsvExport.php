<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One reusable CSV export for the whole app (Loop 8 — "no pen and paper").
 *
 * composer.json has no spreadsheet package (maatwebsite/excel is not a
 * dependency), so exports are streamed CSV downloads: a .csv opens
 * directly in Excel / LibreOffice / Google Sheets, which is what schools
 * actually ask for. Files start with a UTF-8 BOM so Excel renders ₹ and
 * accented names correctly instead of mojibake.
 *
 * Usage: return CsvExport::download('students-jss-1.csv',
 *            ['Name', 'Admission No'], $rows);
 *
 * Cells that begin with =, +, -, @ (or a tab/CR) are prefixed with a
 * single quote: a name or remark typed into the app must never become a
 * live spreadsheet formula on the accountant's machine.
 */
class CsvExport
{
    /**
     * Write a heading row + data rows to an open stream as CSV.
     * Kept framework-free so it can be unit-exercised without booting
     * Laravel (see the harness notes in docs/LOOP7_PLAN.md, Loop 8).
     *
     * @param resource $stream
     */
    public static function write($stream, array $headings, iterable $rows): void
    {
        fwrite($stream, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
        fputcsv($stream, $headings);

        foreach ($rows as $row) {
            fputcsv($stream, array_map([self::class, 'cell'], array_values((array) $row)));
        }
    }

    /**
     * Stream the CSV straight to the browser as a download — nothing is
     * written to disk, so large lists stay light on the server.
     */
    public static function download(string $filename, array $headings, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headings, $rows) {
            $out = fopen('php://output', 'w');
            self::write($out, $headings, $rows);
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Neutralise spreadsheet formula injection in a single cell.
     *
     * @param mixed $value
     * @return mixed
     */
    protected static function cell($value)
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }
}
