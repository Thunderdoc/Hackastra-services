<?php

namespace App\Support;

use App\Helpers\Qs;
use App\Models\ExamDate;
use App\Models\Subject;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Exam Date Sheet assembly (owner-ordered module, 2026-10-22).
 *
 * ONE assembly feeds the on-screen date sheet, the print document
 * and the PDF — the three can never disagree on a date. Dates come
 * only from the exam_dates table (written by the school Admin);
 * a subject with no row has NO date, and the renderings say so
 * instead of inventing one.
 */
class ExamDateSheet
{
    /**
     * Every subject of the class with its stored date (or null),
     * ordered the way a date sheet reads: dated subjects first, by
     * date, then start time (untimed last within a day), then name;
     * undated subjects follow, by name.
     */
    public static function rows($exam_id, $class_id)
    {
        $dates = ExamDate::where('exam_id', $exam_id)
            ->where('my_class_id', $class_id)
            ->get()
            ->keyBy('subject_id');

        return Subject::where('my_class_id', $class_id)
            ->orderBy('name')
            ->get()
            ->map(function ($subject) use ($dates) {
                $date = $dates->get($subject->id);

                return [
                    'subject' => $subject,
                    'entry' => $date,
                    'date_label' => $date ? Carbon::parse($date->exam_date)->format('D, d M Y') : null,
                    'time_label' => ($date && $date->start_time)
                        ? Carbon::parse($date->start_time)->format('h:i A')
                        : null,
                    'sort' => $date
                        ? '0|' . $date->exam_date . '|' . ($date->start_time ?: '99:99:99') . '|' . $subject->name
                        : '1|' . $subject->name,
                ];
            })
            ->sortBy('sort')
            ->values();
    }

    /** How many subjects of the class have an announced date. */
    public static function announcedCount($exam_id, $class_id): int
    {
        return ExamDate::where('exam_id', $exam_id)
            ->where('my_class_id', $class_id)
            ->count();
    }

    /** School identity + digital signature/seal for the document. */
    public static function schoolInfo(): array
    {
        return [
            'name' => Qs::getSetting('system_name'),
            'address' => Qs::getSetting('address'),
            'phone' => Qs::getSetting('phone'),
            'email' => Qs::getSetting('system_email'),
            'logo' => Qs::getSetting('logo'),
            'session' => Qs::getCurrentSession(),
            /* Same digital-docs contract as the report card / admit
               card (owner ruling 2026-10-22): the seal and signature
               render only while uploaded in Settings; otherwise the
               document keeps its blank line / empty space. */
            'stamp' => Qs::getSetting('school_stamp'),
            'principal_name' => Qs::getSetting('principal_name'),
            'principal_signature' => Qs::getSetting('principal_signature'),
        ];
    }

    /* Map a stored upload URL (/storage/...) to a base64 data URI read
       from the local file, for renderers that cannot fetch URLs
       (dompdf) — the same helper MarkController uses for the annual
       PDF. Anything that is not a local storage file is returned
       unchanged. */
    public static function pdfImageDataUri($url)
    {
        $path = parse_url($url, PHP_URL_PATH);
        if ($path && Str::startsWith($path, '/storage/')) {
            $local = storage_path('app/public/' . Str::after($path, '/storage/'));
            if (is_file($local)) {
                $mime = mime_content_type($local) ?: 'image/png';
                return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($local));
            }
        }
        return $url;
    }
}
