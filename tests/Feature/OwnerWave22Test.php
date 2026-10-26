<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\Support\Streams;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression tests for the 2026-10-25 Class 11/12 streams build:
 * section stream tags + stream-tagged subjects (shared sciences
 * carry both science streams, blank = common), promotion routing
 * by per-student stream, and the timetable generator's stream
 * filter for section grids.
 */
class OwnerWave22Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->first();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->first();
    }

    public function test_streams_helper_semantics()
    {
        $common = (object) ['stream' => null];
        $pcm = (object) ['stream' => 'pcm'];
        $shared = (object) ['stream' => 'pcm,pcb'];

        $this->assertTrue(Streams::appliesTo($common, 'pcm'));
        $this->assertTrue(Streams::appliesTo($pcm, 'pcm'));
        $this->assertFalse(Streams::appliesTo($pcm, 'pcb'));
        $this->assertTrue(Streams::appliesTo($shared, 'pcm'));
        $this->assertTrue(Streams::appliesTo($shared, 'pcb'));
        $this->assertFalse(Streams::appliesTo($shared, 'commerce'));
        $this->assertTrue(Streams::appliesTo($pcm, null));

        $filtered = Streams::subjectsForSection(collect([$common, $pcm, $shared]), (object) ['stream' => 'pcb']);
        $this->assertSame([null, 'pcm,pcb'], $filtered->map(function ($s) { return $s->stream; })->all());

        $sections = collect([(object) ['stream' => 'arts'], (object) ['stream' => 'pcm'], (object) ['stream' => null]]);
        $this->assertSame(['pcm', 'arts'], Streams::streamsOfClass($sections));
    }

    public function test_section_stream_saved_from_edit_form()
    {
        $section = Section::create(['name' => 'W22 Sec', 'my_class_id' => 2, 'active' => 1]);
        $this->actingAs($this->admin());
        $this->get(route('sections.edit', $section->id))->assertOk()
            ->assertSee('Stream (Class 11/12)', false);
        $this->put(route('sections.update', $section->id), ['name' => 'W22 Sec', 'stream' => 'pcm'])
            ->assertRedirect(route('sections.index'));
        $this->assertSame('pcm', $section->fresh()->stream);
    }

    public function test_subject_stream_tags_saved_from_form()
    {
        $subject = Subject::create([
            'name' => 'W22 Subject', 'my_class_id' => 2, 'slug' => 'w22-sub',
            'teacher_id' => $this->teacher()->id, 'periods_per_week' => 6,
        ]);
        $this->actingAs($this->admin());
        $this->put(route('subjects.update', $subject->id), [
            'name' => 'W22 Subject', 'my_class_id' => 2, 'stream_tags' => ['pcm', 'pcb'],
        ])->assertRedirect(route('subjects.index'));
        $this->assertSame('pcm,pcb', $subject->fresh()->stream);
        // Unchecking every box returns the subject to common.
        $this->put(route('subjects.update', $subject->id), [
            'name' => 'W22 Subject', 'my_class_id' => 2,
        ])->assertRedirect(route('subjects.index'));
        $this->assertNull($subject->fresh()->stream);
    }

    public function test_promotion_routes_stream_to_matching_section()
    {
        $sectionA = Section::where('my_class_id', 12)->where('name', 'A')->first();
        $sectionD = Section::where('my_class_id', 12)->where('name', 'D')->first();
        $this->assertNotNull($sectionA);
        $this->assertNotNull($sectionD);
        Section::where('id', $sectionA->id)->update(['stream' => 'pcm']);
        Section::where('id', $sectionD->id)->update(['stream' => 'arts']);

        $students = StudentRecord::where('my_class_id', 11)->where('section_id', 48)
            ->where('session', '2026-2027')->where('grad', 0)->orderBy('id')->get();
        $this->assertTrue($students->count() >= 3);
        [$pcmStudent, $skippedStudent, $artsStudent] = [$students[0], $students[1], $students[2]];

        $payload = [];
        foreach ($students as $sr) {
            $payload['p-' . $sr->id] = 'D';
        }
        $payload['p-' . $pcmStudent->id] = 'P';
        $payload['s-' . $pcmStudent->id] = 'pcm';
        $payload['p-' . $skippedStudent->id] = 'P';
        // No stream posted for the skipped student on purpose.
        $payload['p-' . $artsStudent->id] = 'P';
        $payload['s-' . $artsStudent->id] = 'arts';

        $this->actingAs($this->admin());
        $resp = $this->post(route('students.promote', [11, 48, 12, $sectionA->id]), $payload);
        $resp->assertRedirect();
        $this->assertStringContainsString('Not promoted (no stream chosen)', (string) $resp->getSession()->get('flash_success'));

        $this->assertSame(12, (int) $pcmStudent->fresh()->my_class_id);
        $this->assertSame((int) $sectionA->id, (int) $pcmStudent->fresh()->section_id);
        $this->assertSame('2027-2028', $pcmStudent->fresh()->session);
        $this->assertSame(12, (int) $artsStudent->fresh()->my_class_id);
        $this->assertSame((int) $sectionD->id, (int) $artsStudent->fresh()->section_id);
        $this->assertSame('2026-2027', $skippedStudent->fresh()->session);
        $this->assertSame(11, (int) $skippedStudent->fresh()->my_class_id);
        $this->assertSame(0, (int) DB::table('promotions')->where('student_id', $skippedStudent->user_id)->where('to_class', 12)->count());
    }

    public function test_promotion_list_shows_stream_column_when_target_streamed()
    {
        $sectionA = Section::where('my_class_id', 12)->where('name', 'A')->first();
        Section::where('id', $sectionA->id)->update(['stream' => 'pcm']);
        $this->actingAs($this->admin());
        $this->get(route('students.promotion', [11, 48, 12, $sectionA->id]))->assertOk()
            ->assertSee('stream per student', false)
            ->assertSee('Choose stream', false);
    }

    public function test_generate_section_grid_uses_stream_subjects_only()
    {
        $class = MyClass::create(['name' => 'Wave22 Class']);
        $section = Section::create(['name' => 'W22 A', 'my_class_id' => $class->id, 'active' => 1, 'stream' => 'pcm']);
        $mk = function ($name, $stream) use ($class) {
            return Subject::create([
                'name' => $name, 'my_class_id' => $class->id,
                'slug' => 'w22-' . $class->id . '-' . Subject::where('my_class_id', $class->id)->count(),
                'teacher_id' => $this->teacher()->id, 'periods_per_week' => 6, 'stream' => $stream,
            ]);
        };
        $common = $mk('W22 Common', null);
        $pcmSubject = $mk('W22 PCM', 'pcm');
        $artsSubject = $mk('W22 Arts', 'arts');

        $this->actingAs($this->admin());
        $resp = $this->post(route('tt.generate'), [
            'start_time' => '09:00', 'period_minutes' => 40, 'periods_per_day' => 8,
            'lunch_after' => 4, 'lunch_minutes' => 45, 'break_after' => 2, 'break_minutes' => 15,
            'days' => 5, 'scope' => 'one', 'mode' => 'skip',
            'class_id' => $class->id, 'section_id' => $section->id,
        ]);
        $resp->assertRedirect(route('tt.index'));

        $record = TimeTableRecord::where('my_class_id', $class->id)->where('section_id', $section->id)
            ->whereNull('exam_id')->first();
        $this->assertNotNull($record);
        $placed = TimeTable::where('ttr_id', $record->id)->pluck('subject_id')->unique()->all();
        $this->assertNotEmpty($placed);
        $this->assertContains($common->id, $placed);
        $this->assertContains($pcmSubject->id, $placed);
        $this->assertNotContains($artsSubject->id, $placed);
    }
}
