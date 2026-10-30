<?php

namespace App\Support;

use App\Models\Section;
use App\Models\StudentRecord;

/**
 * Section capacity + admission auto-allotment (owner order
 * 2026-10-29): a section may have a fixed number of seats
 * (sections.capacity; NULL/0 = unlimited). When an admission
 * asks for a section that is full, the system allots the next
 * section of the SAME class that still has room — in section
 * order — and the caller tells the admin plainly. When every
 * section of the class is full, the admission is refused with
 * the reason: the system never invents a seat and never
 * overflows a section silently.
 *
 * Strength = active students in the section (student_records
 * with grad = 0) — the same count every roster uses.
 */
class SectionCapacity
{
    public static function strength(int $sectionId): int
    {
        return StudentRecord::where('section_id', $sectionId)
            ->where('grad', 0)->count();
    }

    public static function isFull(Section $section): bool
    {
        $capacity = (int) ($section->capacity ?? 0);
        if ($capacity <= 0) {
            return false;
        }
        return self::strength((int) $section->id) >= $capacity;
    }

    /**
     * Resolve where an admission for ($classId, $requestedId)
     * actually lands.
     *
     * @return array{section_id:int, moved:bool, all_full:bool,
     *               from:?\App\Models\Section, to:?\App\Models\Section}
     */
    /**
     * @param array $claimed section_id => seats already claimed by
     *                       earlier rows of the same bulk import, so
     *                       one file cannot overfill a section by
     *                       validating every row against the same
     *                       pre-import strength. Single admissions
     *                       pass nothing.
     */
    public static function resolve(int $classId, int $requestedSectionId, array $claimed = []): array
    {
        $full = function ($section) use ($claimed) {
            if (!$section) {
                return false;
            }
            $capacity = (int) ($section->capacity ?? 0);
            if ($capacity <= 0) {
                return false;
            }
            return self::strength((int) $section->id) + (int) ($claimed[$section->id] ?? 0) >= $capacity;
        };

        $requested = Section::find($requestedSectionId);
        if (!$requested || (int) $requested->my_class_id !== $classId || !$full($requested)) {
            return ['section_id' => $requestedSectionId, 'moved' => false,
                'all_full' => false, 'from' => $requested, 'to' => $requested];
        }

        $next = Section::where('my_class_id', $classId)
            ->orderBy('id')
            ->get()
            ->first(function ($s) use ($full) { return !$full($s); });

        if (!$next) {
            return ['section_id' => $requestedSectionId, 'moved' => false,
                'all_full' => true, 'from' => $requested, 'to' => null];
        }

        return ['section_id' => (int) $next->id, 'moved' => true,
            'all_full' => false, 'from' => $requested, 'to' => $next];
    }

    /** The refusal message when a whole class has no seat left. */
    public static function allFullMessage($className): string
    {
        return 'Every section of ' . $className . ' is full (section capacity reached). '
            . 'Increase a section\'s capacity or add a section, then admit the student.';
    }

    /** The notice when the system moved an admission. */
    public static function movedMessage(Section $from, Section $to): string
    {
        return 'Section ' . $from->name . ' is full (capacity ' . (int) $from->capacity . '), '
            . 'so the student was placed in Section ' . $to->name . '.';
    }
}
