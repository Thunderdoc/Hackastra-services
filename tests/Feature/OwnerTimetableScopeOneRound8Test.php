<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\Subject;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Agent D round 8 — scope=one headline probe.
 *
 * Round 7 fixed the scope=all headline to split class vs section
 * targets but deliberately left scope=one wording untouched. The
 * question: does a scope=one generate on a FULLY-STREAMED class
 * still say "class(es)" while it actually built a section grid?
 *
 * Answer proven here: NO. Scope=one has exactly two streamed
 * outcomes, both already worded right:
 *  - with a section picked: a single section target, and the
 *    headline reads "1 section(s)" (the $sectionId branch).
 *  - with NO section picked on a fully-streamed class: refused
 *    before any write ("fully streamed — choose a section").
 * A scope=one run can never silently build sections while
 * claiming classes, so no fix is needed — these tests lock that.
 */
class OwnerTimetableScopeOneRound8Test extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    private function teacher(string $code): User
    {
        return User::create([
            'name' => 'R8 Teacher ' . $code,
            'email' => 'r8-' . strtolower($code) . '@test.local',
            'password' => bcrypt('teacher'),
            'user_type' => 'teacher',
            'code' => $code,
        ]);
    }

    private function streamedClass(): array
    {
        $class = MyClass::create(['name' => 'R8 Streamed']);
        $pcm = Section::create(['name' => 'R8 PCM', 'my_class_id' => $class->id, 'active' => 1, 'stream' => 'pcm']);
        $arts = Section::create(['name' => 'R8 Arts', 'my_class_id' => $class->id, 'active' => 1, 'stream' => 'arts']);
        $mk = function ($name, $stream) use ($class) {
            return Subject::create([
                'name' => $name, 'my_class_id' => $class->id,
                'slug' => 'r8-' . $class->id . '-' . Subject::where('my_class_id', $class->id)->count(),
                'teacher_id' => $this->teacher(strtoupper(substr($name, 0, 4)) . rand(100, 999))->id,
                'periods_per_week' => 6, 'stream' => $stream,
            ]);
        };
        $mk('R8 Common', null);
        $mk('R8 PCM Subject', 'pcm');
        $mk('R8 Arts Subject', 'arts');

        return [$class, $pcm, $arts];
    }

    private function generateOne(array $extra)
    {
        return $this->actingAs($this->admin())
            ->from(route('tt.generate.form'))
            ->post(route('tt.generate'), array_merge([
                'start_time' => '09:00', 'period_minutes' => 40, 'periods_per_day' => 8,
                'lunch_after' => 4, 'lunch_minutes' => 45, 'break_after' => 2, 'break_minutes' => 15,
                'days' => 5, 'scope' => 'one', 'mode' => 'skip',
            ], $extra));
    }

    public function test_scope_one_with_section_reports_sections_not_classes()
    {
        [$class, $pcm] = $this->streamedClass();

        $resp = $this->generateOne(['class_id' => $class->id, 'section_id' => $pcm->id]);
        $resp->assertRedirect(route('tt.index'));
        $flash = (string) $resp->getSession()->get('flash_success');
        $this->assertStringContainsString('Timetables generated for 1 section(s)', $flash);
        $this->assertStringNotContainsString('class(es)', $flash);

        $record = TimeTableRecord::where('my_class_id', $class->id)->where('section_id', $pcm->id)
            ->whereNull('exam_id')->where('year', Qs::getCurrentSession())->first();
        $this->assertNotNull($record);
        $this->assertGreaterThan(0, TimeTable::where('ttr_id', $record->id)->count());
    }

    public function test_scope_one_fully_streamed_without_section_is_refused()
    {
        [$class] = $this->streamedClass();

        $resp = $this->generateOne(['class_id' => $class->id]);
        $flash = (string) $resp->getSession()->get('flash_danger');
        $this->assertStringContainsString('fully streamed', $flash);
        $this->assertEquals(0, TimeTableRecord::where('my_class_id', $class->id)
            ->whereNull('exam_id')->where('year', Qs::getCurrentSession())->count());
    }

    public function test_scope_one_plain_class_still_reports_classes()
    {
        $class = MyClass::create(['name' => 'R8 Plain']);
        Subject::create([
            'name' => 'R8 Plain Subject', 'my_class_id' => $class->id, 'slug' => 'r8-plain-' . $class->id,
            'teacher_id' => $this->teacher('R8PL')->id, 'periods_per_week' => 6, 'stream' => null,
        ]);

        $resp = $this->generateOne(['class_id' => $class->id]);
        $resp->assertRedirect(route('tt.index'));
        $flash = (string) $resp->getSession()->get('flash_success');
        $this->assertStringContainsString('Timetables generated for 1 class(es)', $flash);
    }
}
