<?php

namespace App\Support;

/**
 * Class 11/12 streams (batch 2026-10-25, owner decision:
 * sections-as-streams). A section carries one stream tag; a
 * subject carries a comma-separated tag list because shared
 * subjects (Physics, Chemistry) belong to two streams, while a
 * blank tag means common to every stream (English, Hindi).
 *
 * Section subject list rule: a section with no stream tag sees
 * every subject of its class (old behaviour, unchanged). A
 * streamed section sees only common subjects plus subjects
 * tagged with its own stream.
 */
class Streams
{
    public const ALL = [
        'pcm' => 'PCM (Science – Maths)',
        'pcb' => 'PCB (Science – Biology)',
        'commerce' => 'Commerce',
        'arts' => 'Arts',
    ];

    /** Stream cycle for seeded sections past D (Class 11 runs A–I, Class 12 A–M). */
    public const CYCLE = ['pcm', 'pcb', 'commerce', 'arts'];

    public static function label(?string $stream): ?string
    {
        return $stream ? (self::ALL[$stream] ?? ucfirst($stream)) : null;
    }

    /**
     * Display grouping for subject pickers (timetable generate page):
     * Common subjects first, then the CBSE streams in stream order.
     * A subject tagged with TWO streams (Physics, Chemistry = pcm,pcb)
     * appears exactly ONCE, under "PCM & PCB (shared)" — duplicating
     * the checkbox would post the id twice. Untagged = common to every
     * stream. Classes with no streamed subjects return a single Common
     * group, so their pickers render flat with no stream headings.
     * Returns ordered [['key', 'label', 'subjects']] — empty groups
     * omitted.
     */
    public static function groupForDisplay($subjects): array
    {
        $groups = [
            'common' => ['key' => 'common', 'label' => 'Common (all streams)', 'subjects' => collect()],
            'pcm' => ['key' => 'pcm', 'label' => self::ALL['pcm'], 'subjects' => collect()],
            'shared' => ['key' => 'shared', 'label' => 'PCM & PCB (shared)', 'subjects' => collect()],
            'pcb' => ['key' => 'pcb', 'label' => self::ALL['pcb'], 'subjects' => collect()],
            'commerce' => ['key' => 'commerce', 'label' => self::ALL['commerce'], 'subjects' => collect()],
            'arts' => ['key' => 'arts', 'label' => self::ALL['arts'], 'subjects' => collect()],
            'other' => ['key' => 'other', 'label' => 'Other streams', 'subjects' => collect()],
        ];
        foreach ($subjects as $subject) {
            $tags = self::tags($subject);
            if (empty($tags)) {
                $groups['common']['subjects']->push($subject);
            } elseif ($tags === ['pcm']) {
                $groups['pcm']['subjects']->push($subject);
            } elseif ($tags === ['pcb']) {
                $groups['pcb']['subjects']->push($subject);
            } elseif (count($tags) === 2 && in_array('pcm', $tags, true) && in_array('pcb', $tags, true)) {
                $groups['shared']['subjects']->push($subject);
            } elseif ($tags === ['commerce']) {
                $groups['commerce']['subjects']->push($subject);
            } elseif ($tags === ['arts']) {
                $groups['arts']['subjects']->push($subject);
            } else {
                $groups['other']['subjects']->push($subject);
            }
        }

        return array_values(array_filter($groups, function ($group) {
            return $group['subjects']->isNotEmpty();
        }));
    }

    /** Tag list of a subject row (stdClass from the query builder or a model). */
    public static function tags($subject): array
    {
        $raw = is_object($subject) ? ($subject->stream ?? null) : null;
        if (!$raw) {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', (string) $raw))));
    }

    /** Does this subject belong in a section of the given stream? Null stream = every section. */
    public static function appliesTo($subject, ?string $stream): bool
    {
        if (!$stream) {
            return true;
        }
        $tags = self::tags($subject);
        return empty($tags) || in_array($stream, $tags, true);
    }

    /** Filter a subject collection to a section's stream (untagged section = all subjects). */
    public static function subjectsForSection($subjects, $section)
    {
        $stream = is_object($section) ? ($section->stream ?? null) : null;
        if (!$stream) {
            return $subjects;
        }
        return $subjects->filter(function ($s) use ($stream) {
            return self::appliesTo($s, $stream);
        })->values();
    }

    /**
     * Is this subject offered anywhere in its class? A class
     * with no sections teaches whole-class, so everything
     * counts there; otherwise at least one section must apply
     * (an untagged section offers everything). Round 13
     * allotment fix: planning surfaces must use the same
     * universe as the generator and the readiness check, or
     * they demand staff for subjects nobody takes.
     */
    public static function offeredInClass($subject, $sectionsOfClass): bool
    {
        if ($sectionsOfClass->isEmpty()) {
            return true;
        }
        foreach ($sectionsOfClass as $section) {
            if (self::appliesTo($subject, $section->stream ?? null)) {
                return true;
            }
        }
        return false;
    }

    /** Distinct streams present in a class's sections, in canonical order. */
    public static function streamsOfClass($sections): array
    {
        $present = [];
        foreach ($sections as $section) {
            if (!empty($section->stream)) {
                $present[$section->stream] = true;
            }
        }
        return array_values(array_intersect(array_keys(self::ALL), array_keys($present)));
    }
}
