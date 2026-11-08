<?php

namespace App\Support;

use App\Models\AdmitCardSnapshot;
use App\Models\Exam;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\StudentRecord;
use App\User;
use Illuminate\Support\Facades\DB;

/**
 * ONE-TIME GENERATION freeze for admit cards (owner standing
 * rule 2026-11-08; freeze design in hk-tmp/onetime/AUDIT.md §c,
 * approved by the owner the same day).
 *
 * At the moment the Principal publishes an exam's admit cards,
 * freeze() snapshots one payload per student in scope — identity
 * fields, the schedule rows exactly as the card renders them,
 * and the branding values in force — into admit_card_snapshots.
 * Student/parent self-service then renders the SNAPSHOT, so a
 * later live edit (section transfer, Settings branding change,
 * a date sheet corrected through the Principal return path)
 * cannot rewrite a released card. Unpublishing keeps the rows
 * (the published flag hides them); re-publishing re-freezes
 * from live data.
 *
 * Deliberately NOT frozen: the fee gate (evaluated live on
 * every self-service view, before the snapshot is read — a
 * frozen card still blocks a student with unpaid dues) and the
 * office/bulk print surface (the correction surface, which
 * stays on live data and never reads this table).
 */
class AdmitCardFreezer
{
    /**
     * (Re)build the snapshots for an exam from live data.
     * Returns the number of cards frozen.
     */
    public static function freeze(Exam $exam, $frozenBy = null): int
    {
        $school = AdmitCardBuilder::schoolInfo();

        $q = StudentRecord::where('grad', 0)
            ->with(['user', 'my_class', 'section', 'section.teacher']);
        if (!empty($exam->my_class_id)) {
            $q->where('my_class_id', $exam->my_class_id);
        }
        $records = $q->get();

        $now = now();
        $rows = [];
        /* The schedule depends only on (class, stream, section),
           never on the individual student — build it once per
           combination instead of once per card (a whole-school
           exam freezes ~3,000 cards). */
        $schedules = [];
        foreach ($records as $sr) {
            if (!$sr->user) {
                continue;
            }
            $key = $sr->my_class_id . '|' . ($sr->section->stream ?? '') . '|' . $sr->section_id;
            if (!isset($schedules[$key])) {
                $schedules[$key] = AdmitCardBuilder::buildSchedule(
                    $exam, $sr->my_class_id, $sr->section->stream ?? null, $sr->section_id
                );
            }
            $rows[] = [
                'exam_id' => $exam->id,
                'student_id' => $sr->user_id,
                'payload' => json_encode(self::payload($sr, $exam, $school, $schedules[$key])),
                'frozen_by' => $frozenBy,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($exam, $rows) {
            AdmitCardSnapshot::where('exam_id', $exam->id)->delete();
            foreach (array_chunk($rows, 200) as $chunk) {
                AdmitCardSnapshot::insert($chunk);
            }
        });

        return count($rows);
    }

    /** The frozen payload for one (exam, student), or null. */
    public static function payloadFor($examId, $studentUserId): ?array
    {
        $snap = AdmitCardSnapshot::where('exam_id', $examId)
            ->where('student_id', $studentUserId)
            ->first();

        return $snap && is_array($snap->payload) ? $snap->payload : null;
    }

    /**
     * The full card payload for one student, built from the same
     * AdmitCardBuilder output the live card renders — schedule
     * rows are stored as subject/date/time exactly as printed
     * (the builder's internal sort key is ordering metadata and
     * is not part of the card).
     */
    public static function payload(StudentRecord $sr, Exam $exam, array $school, ?array $schedule = null): array
    {
        if ($schedule === null) {
            $schedule = AdmitCardBuilder::buildSchedule(
                $exam,
                $sr->my_class_id,
                $sr->section->stream ?? null,
                $sr->section_id
            );
        }

        $rows = [];
        foreach ($schedule['rows'] as $row) {
            $rows[] = [
                'subject' => $row['subject'],
                'date' => $row['date'],
                'time' => $row['time'],
            ];
        }

        return [
            'version' => 1,
            'exam' => [
                'name' => $exam->name,
                'term' => $exam->term,
                'year' => $exam->year,
            ],
            'student' => [
                'name' => $sr->user->name,
                'photo' => $sr->user->photo,
                'adm_no' => $sr->adm_no,
                'class_name' => $sr->my_class->name ?? null,
                'section_name' => $sr->section->name ?? null,
                'class_teacher_name' => $sr->section->teacher->name ?? null,
                'house' => $sr->house,
                'dob' => $sr->user->dob ?? null,
            ],
            'schedule' => [
                'rows' => $rows,
                'fallback' => $schedule['fallback'],
            ],
            'school' => $school,
        ];
    }

    /**
     * Rebuild the admit_print view data from a frozen payload.
     * The card blade reads model-shaped data ($sr->user->name,
     * $sr->section->teacher->name, …), so the payload is
     * rehydrated into unsaved model instances wired with
     * setRelation — the rendered HTML is then identical to the
     * live render at freeze time, byte for byte.
     */
    public static function viewData(array $payload): array
    {
        $student = $payload['student'] ?? [];

        $user = new User();
        $user->forceFill([
            'name' => $student['name'] ?? '',
            'photo' => $student['photo'] ?? null,
        ]);

        $section = new Section();
        $section->forceFill(['name' => $student['section_name'] ?? null]);
        if (!empty($student['class_teacher_name'])) {
            $teacher = new User();
            $teacher->forceFill(['name' => $student['class_teacher_name']]);
            $section->setRelation('teacher', $teacher);
        }

        $myClass = new MyClass();
        $myClass->forceFill(['name' => $student['class_name'] ?? '']);

        $sr = new StudentRecord();
        $sr->forceFill(['adm_no' => $student['adm_no'] ?? '']);
        $sr->setRelation('user', $user);
        $sr->setRelation('my_class', $myClass);
        $sr->setRelation('section', $section);

        $exam = new Exam();
        $exam->forceFill([
            'name' => $payload['exam']['name'] ?? '',
            'term' => $payload['exam']['term'] ?? null,
            'year' => $payload['exam']['year'] ?? '',
        ]);

        return [
            'exam' => $exam,
            'my_class' => $myClass,
            'students' => collect([$sr]),
            'school' => $payload['school'] ?? [],
            'schedule' => $payload['schedule']['rows'] ?? [],
            'schedule_fallback' => $payload['schedule']['fallback'] ?? false,
        ];
    }
}
