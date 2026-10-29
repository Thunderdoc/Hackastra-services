<?php

namespace App\Support;

/**
 * A stable colour identity per subject (signature documents,
 * owner order 2026-10-29: the school's documents must look like
 * nobody else's). The same subject always wears the same colour
 * — on the class timetable print, the teacher's week print and
 * any future document — because the colour is a pure function of
 * the subject's name: no setting to configure, no column to
 * migrate, and two schools with the same subjects get the same
 * calm, print-safe palette. The palette is muted on purpose:
 * chips and tints must read as designed paper, never as a
 * spreadsheet's conditional formatting.
 */
class SubjectColor
{
    private const PALETTE = [
        '#2563eb', // blue
        '#059669', // emerald
        '#d97706', // amber
        '#dc2626', // red
        '#7c3aed', // violet
        '#0891b2', // cyan
        '#db2777', // pink
        '#65a30d', // lime
        '#9333ea', // purple
        '#ea580c', // orange
        '#0f766e', // teal
        '#4f46e5', // indigo
    ];

    public static function forName(?string $name): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return '#64748b';
        }
        $hash = hexdec(substr(md5(strtolower($name)), 0, 6));

        return self::PALETTE[$hash % count(self::PALETTE)];
    }
}
