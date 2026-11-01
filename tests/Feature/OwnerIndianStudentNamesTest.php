<?php

namespace Tests\Feature;

use App\User;
use Tests\TestCase;

/**
 * Owner fix 2026-10-30: seeded student names were raw Faker
 * en_US output with honorifics and suffixes — "Prof. Alysha
 * Braun", "Lilian Rempel DDS", "Willa Koepp MD", "Mrs. Jackie
 * Nader II" printed on the school's own attendance documents.
 * StudentRecordsTableSeeder now builds names from curated
 * Indian first/last lists (indianStudentName()). These pins
 * hold the line: no seeded student name may carry a title or
 * suffix token, and the curated demo student stays untouched.
 */
class OwnerIndianStudentNamesTest extends TestCase
{
    public function test_no_seeded_student_name_carries_honorifics_or_suffixes()
    {
        $names = User::where('user_type', 'student')->pluck('name')->all();

        // The seed actually ran (3,041 students on the demo seed).
        $this->assertGreaterThan(3000, count($names));

        $offenders = [];
        foreach ($names as $name) {
            if (preg_match('/\b(Prof|Dr|DDS|MD|PhD|DVM|Mrs|Mr|Miss|Jr|Sr)\b|\b(II|III|IV)$/', (string) $name)) {
                $offenders[] = $name;
            }
        }

        $this->assertSame([], $offenders, 'Seeded student names with honorifics/suffixes: ' . implode(', ', array_slice($offenders, 0, 10)));
    }

    public function test_curated_demo_student_name_is_unchanged()
    {
        $aarav = User::where('email', 'student@hackstra.app')->firstOrFail();
        $this->assertSame('Aarav Sharma', $aarav->name);
    }
}
