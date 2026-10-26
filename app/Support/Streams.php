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
