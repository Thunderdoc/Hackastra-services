<?php

namespace App\Support;

use App\Helpers\Qs;
use App\Models\Exam;
use App\Models\ExamDate;
use App\Models\Subject;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use Illuminate\Support\Carbon;

/**
 * ONE builder for the admit card's moving parts — the subject
 * schedule and the school branding block — shared by the live
 * surfaces (AdmitCardController office print + self-service) and
 * by App\Support\AdmitCardFreezer, which snapshots exactly this
 * output at publish time. Extracted verbatim from
 * AdmitCardController (2026-11-08) so a frozen card can never
 * drift from what the live card would have rendered at the same
 * moment.
 */
class AdmitCardBuilder
{
    /**
     * Subject schedule for an exam + class. Sources, in order:
     * (1) the exam timetable for the class (time_table_records ->
     * time_tables.exam_date + time_slots.full); (2) the generated
     * date sheet (exam_dates) — the admin's "Generate" run. Before
     * this fix the card read ONLY source (1), so a generated date
     * sheet never reached the card and it fell back to dateless
     * subjects even though dates existed. When neither source has
     * dates, fall back to the class's subject list with no dates —
     * dates are never invented.
     */
    public static function buildSchedule(Exam $exam, $class_id, $stream = null, $section_id = null)
    {
        // Batch 2026-10-28 (exam-timetable audit): the lookup used
        // to take whichever exam timetable row came first — for a
        // class with section exam grids that could be another
        // section's grid. Prefer the requested section's grid,
        // then the whole-class grid, then any grid for the class.
        $ttrQuery = TimeTableRecord::where([
            'exam_id' => $exam->id,
            'my_class_id' => $class_id,
            'year' => $exam->year,
        ]);
        $ttr = null;
        if ($section_id) {
            $ttr = (clone $ttrQuery)->where('section_id', $section_id)->first();
        }
        if (!$ttr) {
            $ttr = (clone $ttrQuery)->whereNull('section_id')->first();
        }
        if (!$ttr) {
            $ttr = $ttrQuery->first();
        }

        $rows = [];

        // Batch 2026-10-26 (streams): with a stream, only that
        // stream's subjects (common + own stream) reach the card
        // — a PCM card used to list all 12 Class 11/12 subjects.
        // The filter applies to every source below, including the
        // dateless subject-list fallback, so a streamed card never
        // falls back to the full class list.
        $keep = function ($subject) use ($stream) {
            return !$stream || Streams::appliesTo($subject, $stream);
        };

        if ($ttr) {
            $entries = TimeTable::with(['subject', 'time_slot'])
                ->where('ttr_id', $ttr->id)
                ->whereNotNull('subject_id')
                ->whereNotNull('exam_date')
                ->get();

            foreach ($entries as $e) {
                if (!$e->subject || !$keep($e->subject)) {
                    continue;
                }
                $rows[] = [
                    'subject' => $e->subject->name,
                    'date' => self::formatExamDate($e->exam_date),
                    'sort' => self::dateSortKey($e->exam_date) . '|' . ($e->timestamp_from ?: ''),
                    'time' => $e->time_slot ? $e->time_slot->full : null,
                ];
            }

            usort($rows, function ($a, $b) {
                return strcmp($a['sort'], $b['sort']);
            });
        }

        if (empty($rows)) {
            $sheet = ExamDate::with('subject')
                ->where('exam_id', $exam->id)
                ->where('my_class_id', $class_id)
                ->whereNotNull('exam_date')
                ->get();

            foreach ($sheet as $e) {
                if (!$e->subject || !$keep($e->subject)) {
                    continue;
                }
                $rows[] = [
                    'subject' => $e->subject->name,
                    'date' => self::formatExamDate($e->exam_date),
                    'sort' => self::dateSortKey($e->exam_date) . '|' . ($e->start_time ?: ''),
                    'time' => self::formatStartTime($e->start_time),
                ];
            }

            usort($rows, function ($a, $b) {
                return strcmp($a['sort'], $b['sort']);
            });
        }

        if (!empty($rows)) {
            // Batch 2026-10-28 (exam-timetable round 2): a
            // partially dated sheet used to HIDE its undated
            // subjects — one dated paper made the whole card list
            // only that paper (a single stray date row exposed it:
            // Prep's card showed only Computer Science). Union the
            // class's remaining subjects (stream-filtered, so a
            // streamed card never gains out-of-stream papers) with
            // null dates; the blade renders those as "—".
            // fallback stays false: a sheet exists, it is just
            // incomplete — the "will be announced" note is only for
            // the fully dateless list below.
            $present = [];
            foreach ($rows as $r) {
                $present[$r['subject']] = true;
            }
            $missing = Subject::where('my_class_id', $class_id)->orderBy('name')->get();
            foreach ($missing as $s) {
                if (!$keep($s) || isset($present[$s->name])) {
                    continue;
                }
                $rows[] = ['subject' => $s->name, 'date' => null, 'sort' => $s->name, 'time' => null];
            }
            return ['rows' => $rows, 'fallback' => false];
        }

        $subjects = Subject::where('my_class_id', $class_id)->orderBy('name')->get();
        foreach ($subjects as $s) {
            if (!$keep($s)) {
                continue;
            }
            $rows[] = ['subject' => $s->name, 'date' => null, 'sort' => $s->name, 'time' => null];
        }

        return ['rows' => $rows, 'fallback' => true];
    }

    public static function formatStartTime($value)
    {
        if (!$value) {
            return null;
        }
        try {
            return Carbon::parse($value)->format('g:i A');
        } catch (\Exception $e) {
            return $value;
        }
    }

    public static function formatExamDate($value)
    {
        try {
            return Carbon::parse($value)->format('D, d M Y');
        } catch (\Exception $e) {
            return $value;
        }
    }

    public static function dateSortKey($value)
    {
        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Exception $e) {
            return (string) $value;
        }
    }

    public static function schoolInfo()
    {
        return [
            'name' => Qs::getSetting('system_name'),
            'address' => Qs::getSetting('address'),
            'phone' => Qs::getSchoolPhone(),
            'email' => Qs::getSetting('system_email'),
            'logo' => Qs::getSetting('logo'),
            'session' => Qs::getCurrentSession(),
            /* Digital signature + seal (owner ruling 2026-10-22):
               rendered on the card only while uploaded in Settings —
               empty values keep the blank lines / empty seal box. */
            'stamp' => Qs::getSetting('school_stamp'),
            'principal_name' => Qs::getSetting('principal_name'),
            'principal_signature' => Qs::getSetting('principal_signature'),
            /* The watermark monogram source (Qs::getAppCode()):
               carried in the block so a frozen card renders the
               code in force at freeze time, not a later one. Live
               callers may omit it — the blade falls back to
               Qs::getAppCode() when the key is absent. */
            'app_code' => Qs::getAppCode(),
        ];
    }
}
