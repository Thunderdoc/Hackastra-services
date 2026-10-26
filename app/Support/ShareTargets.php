<?php

namespace App\Support;

use App\Models\Section;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Cross-section sharing (batch 2026-10-26, owner ask: a teacher
 * teaching in 9-A and 9-C shares one upload to both, without
 * uploading again). Eligible targets are sections of the SAME
 * class where the teacher teaches the SAME subject — section
 * subject-teacher overrides honoured — excluding the section
 * the item already lives in. Whole-class items (null section)
 * already reach every section, so they have no targets.
 */
class ShareTargets
{
    public static function forSubject(int $teacherId, $subject, ?int $excludeSectionId)
    {
        if (!$subject) {
            return collect();
        }
        return Section::where('my_class_id', $subject->my_class_id)
            ->orderBy('id')->get()
            ->filter(function ($section) use ($teacherId, $subject, $excludeSectionId) {
                if ($excludeSectionId && (int) $section->id === (int) $excludeSectionId) {
                    return false;
                }
                return SectionTeacher::teacherIdFor($section->id, $subject) === $teacherId;
            })->values();
    }

    /** Server-side copy of a public-disk file under a fresh unique name. */
    public static function copyPublicFile(string $path, string $dir): string
    {
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $base = pathinfo($path, PATHINFO_FILENAME);
        do {
            $dest = $dir . '/' . $base . '-' . Str::random(8) . ($ext ? '.' . $ext : '');
        } while (Storage::disk('public')->exists($dest));
        Storage::disk('public')->copy($path, $dest);
        return $dest;
    }
}
