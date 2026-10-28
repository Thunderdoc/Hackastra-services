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
 * Agent D round 11 — generate-refusal RENDERING.
 *
 * Earlier rounds proved the refusal LOGIC (fuzz: days=7, cap 0,
 * fully-streamed, lunch/break-after-periods all refused; atomicity:
 * no partial grids). What was never proven is the RENDERED outcome:
 * each refusal must land the admin back on the generate form with a
 * readable message — never a 500, never a half-written grid — and
 * the teacher-less case must WARN inside a successful run, not
 * refuse it.
 *
 * Test-craft lessons applied (from E round 10): bare assertSee on
 * generic words is vacuous (layout boilerplate contains common
 * phrases), so flash assertions target the exact toast call
 * (partials/js/custom_js: flash({msg : '...', type : 'danger'}))
 * and validation assertions target the exact error-summary text.
 * Blade escapes apostrophes to &#039; in the toast, so the
 * fully-streamed assertion stops before "stream's"; the em-dash
 * passes through unescaped.
 */
class OwnerTimetableRefusalRenderRound11Test extends TestCase
{
    use DatabaseTransactions;

    private $n = 0;

    private function tag(): string
    {
        $this->n++;
        return 'R11-' . $this->n;
    }

    private function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    private function teacher(string $code): User
    {
        return User::create([
            'name' => 'R11 Teacher ' . $code,
            'email' => 'r11-' . strtolower($code) . '@test.local',
            'password' => bcrypt('teacher'),
            'user_type' => 'teacher',
            'code' => $code,
        ]);
    }

    private function mkClass(string $tag): MyClass
    {
        return MyClass::create(['name' => $tag . ' Class']);
    }

    private function mkSubject(MyClass $class, string $tag, $teacherId, $stream = null): Subject
    {
        return Subject::create([
            'name' => $tag . ' Sub', 'slug' => strtolower($tag) . '-sub',
            'my_class_id' => $class->id, 'teacher_id' => $teacherId,
            'periods_per_week' => 6, 'stream' => $stream,
        ]);
    }

    private function generate(array $extra)
    {
        return $this->actingAs($this->admin())
            ->from(route('tt.generate.form'))
            ->post(route('tt.generate'), array_merge([
                'start_time' => '09:00', 'period_minutes' => 40, 'periods_per_day' => 6,
                'lunch_after' => 4, 'lunch_minutes' => 45, 'break_after' => 2, 'break_minutes' => 15,
                'days' => 5, 'scope' => 'one', 'mode' => 'replace',
            ], $extra));
    }

    /** Follow a redirect response back to its target page and return it. */
    private function follow($resp)
    {
        $resp->assertRedirect();
        return $this->actingAs($this->admin())->get($resp->headers->get('Location'));
    }

    private function recordsFor(MyClass $class): int
    {
        return TimeTableRecord::where('my_class_id', $class->id)->whereNull('exam_id')->count();
    }

    public function test_days_seven_renders_validation_error_on_form()
    {
        $tag = $this->tag();
        $class = $this->mkClass($tag);
        $this->mkSubject($class, $tag, $this->teacher($tag . 'T')->id);

        $resp = $this->generate(['class_id' => $class->id, 'days' => 7]);
        $resp->assertSessionHasErrors('days');
        $resp->assertRedirect(route('tt.generate.form'));
        $this->follow($resp)->assertSee('The selected days is invalid.', false);
        $this->assertSame(0, $this->recordsFor($class));
    }

    public function test_cap_zero_renders_validation_error_on_form()
    {
        $tag = $this->tag();
        $class = $this->mkClass($tag);
        $this->mkSubject($class, $tag, $this->teacher($tag . 'T')->id);

        $resp = $this->generate(['class_id' => $class->id, 'max_teacher_periods_per_day' => 0]);
        $resp->assertSessionHasErrors('max_teacher_periods_per_day');
        $resp->assertRedirect(route('tt.generate.form'));
        $this->follow($resp)->assertSee('The max teacher periods per day must be at least 1.', false);
        $this->assertSame(0, $this->recordsFor($class));
    }

    public function test_nonexistent_class_renders_validation_error_on_form()
    {
        $resp = $this->generate(['class_id' => 999999]);
        $resp->assertSessionHasErrors('class_id');
        $resp->assertRedirect(route('tt.generate.form'));
        $page = $this->follow($resp);
        $page->assertSee('id="form-error-summary"', false);
        $page->assertSee('The selected class id is invalid.', false);
    }

    public function test_scope_one_without_class_renders_toast_on_form()
    {
        $resp = $this->generate([]);
        $resp->assertSessionHas('flash_danger', 'Choose the class to generate for.');
        $resp->assertRedirect(route('tt.generate.form'));
        $this->follow($resp)->assertSee(
            "flash({msg : 'Choose the class to generate for.', type : 'danger'})", false);
    }

    /**
     * Section posted without a class: the scope=one "choose the
     * class" check fires FIRST, so this is the message the admin
     * actually sees. Note (observation, not a bug): the later
     * "Choose the class the section belongs to." branch is
     * unreachable — scope=one is already refused above it, and
     * scope=all nulls section_id before it. The outcome is still
     * a correct refusal; the dead branch is a cleanup candidate
     * only and is deliberately left untouched here.
     */
    public function test_section_without_class_renders_toast_on_form()
    {
        $tag = $this->tag();
        $class = $this->mkClass($tag);
        $section = Section::create(['name' => $tag . ' Sec', 'my_class_id' => $class->id, 'active' => 1]);

        $resp = $this->generate(['section_id' => $section->id]);
        $resp->assertSessionHas('flash_danger', 'Choose the class to generate for.');
        $resp->assertRedirect(route('tt.generate.form'));
        $this->follow($resp)->assertSee(
            "flash({msg : 'Choose the class to generate for.', type : 'danger'})", false);
        $this->assertSame(0, $this->recordsFor($class));
    }

    public function test_section_from_another_class_renders_toast_on_form()
    {
        $tag = $this->tag();
        $classA = $this->mkClass($tag . 'A');
        $classB = $this->mkClass($tag . 'B');
        $foreign = Section::create(['name' => $tag . ' Sec', 'my_class_id' => $classB->id, 'active' => 1]);

        $resp = $this->generate(['class_id' => $classA->id, 'section_id' => $foreign->id]);
        $resp->assertSessionHas('flash_danger', 'That section does not belong to the chosen class.');
        $resp->assertRedirect(route('tt.generate.form'));
        $this->follow($resp)->assertSee(
            "flash({msg : 'That section does not belong to the chosen class.', type : 'danger'})", false);
        $this->assertSame(0, $this->recordsFor($classA));
    }

    public function test_cross_class_posted_subjects_render_toast_on_form()
    {
        $tag = $this->tag();
        $classA = $this->mkClass($tag . 'A');
        $classB = $this->mkClass($tag . 'B');
        $foreignSub = $this->mkSubject($classB, $tag . 'B', $this->teacher($tag . 'TB')->id);

        $resp = $this->generate([
            'class_id' => $classA->id,
            'subjects' => [$classA->id => [$foreignSub->id]],
        ]);
        $resp->assertSessionHas('flash_danger',
            'The chosen subjects must belong to their class — a subject from another class cannot be placed here.');
        $resp->assertRedirect(route('tt.generate.form'));
        $this->follow($resp)->assertSee(
            "flash({msg : 'The chosen subjects must belong to their class", false);
        $this->assertSame(0, $this->recordsFor($classA));
    }

    public function test_fully_streamed_without_section_renders_toast_on_form()
    {
        $tag = $this->tag();
        $class = $this->mkClass($tag);
        Section::create(['name' => $tag . ' PCM', 'my_class_id' => $class->id, 'active' => 1, 'stream' => 'pcm']);
        Section::create(['name' => $tag . ' PCB', 'my_class_id' => $class->id, 'active' => 1, 'stream' => 'pcb']);
        $this->mkSubject($class, $tag . 'C', $this->teacher($tag . 'TC')->id, null);
        $this->mkSubject($class, $tag . 'P', $this->teacher($tag . 'TP')->id, 'pcm');

        $resp = $this->generate(['class_id' => $class->id]);
        $flash = (string) $resp->getSession()->get('flash_danger');
        $this->assertStringContainsString('fully streamed', $flash);
        $resp->assertRedirect(route('tt.generate.form'));
        // Toast call prefix only: the message's "stream's" apostrophe
        // renders as &#039;, but everything up to it is literal.
        $this->follow($resp)->assertSee(
            "flash({msg : 'This class is fully streamed — choose a section to generate its grid. A whole-class grid would mix every stream", false);
        $this->assertSame(0, $this->recordsFor($class));
    }

    public function test_day_running_past_930pm_renders_toast_on_form()
    {
        $tag = $this->tag();
        $class = $this->mkClass($tag);
        $this->mkSubject($class, $tag, $this->teacher($tag . 'T')->id);

        $resp = $this->generate([
            'class_id' => $class->id, 'period_minutes' => 90, 'periods_per_day' => 12,
            'lunch_after' => 11, 'lunch_minutes' => 90, 'break_after' => 2, 'break_minutes' => 45,
        ]);
        $resp->assertSessionHas('flash_danger',
            'That school day would run past 9:30 PM — reduce the periods, shorten them, or start earlier.');
        $resp->assertRedirect(route('tt.generate.form'));
        $this->follow($resp)->assertSee(
            "flash({msg : 'That school day would run past 9:30 PM", false);
        $this->assertSame(0, $this->recordsFor($class));
    }

    public function test_lunch_after_last_period_renders_toast_on_form()
    {
        $tag = $this->tag();
        $class = $this->mkClass($tag);
        $this->mkSubject($class, $tag, $this->teacher($tag . 'T')->id);

        $resp = $this->generate(['class_id' => $class->id, 'lunch_after' => 6]);
        $resp->assertSessionHas('flash_danger',
            'Lunch must fall inside the school day — lunch_after must be less than periods_per_day.');
        $resp->assertRedirect(route('tt.generate.form'));
        $this->follow($resp)->assertSee(
            "flash({msg : 'Lunch must fall inside the school day", false);
        $this->assertSame(0, $this->recordsFor($class));
    }

    public function test_break_after_last_period_renders_toast_on_form()
    {
        $tag = $this->tag();
        $class = $this->mkClass($tag);
        $this->mkSubject($class, $tag, $this->teacher($tag . 'T')->id);

        $resp = $this->generate(['class_id' => $class->id, 'break_after' => 6]);
        $resp->assertSessionHas('flash_danger',
            'The short break must fall inside the school day — break_after must be less than periods_per_day.');
        $resp->assertRedirect(route('tt.generate.form'));
        $this->follow($resp)->assertSee(
            "flash({msg : 'The short break must fall inside the school day", false);
        $this->assertSame(0, $this->recordsFor($class));
    }

    public function test_teacherless_subject_warns_inside_successful_run()
    {
        $tag = $this->tag();
        $class = $this->mkClass($tag);
        $staffed = $this->mkSubject($class, $tag . 'S', $this->teacher($tag . 'TS')->id);
        $unmanned = $this->mkSubject($class, $tag . 'U', null);

        $resp = $this->generate(['class_id' => $class->id, 'mode' => 'replace']);
        $resp->assertRedirect(route('tt.index'));
        $flash = (string) $resp->getSession()->get('flash_success');
        $this->assertStringContainsString('Timetables generated for 1 class(es)', $flash);
        $this->assertStringContainsString('left empty (no teacher assigned', $flash);
        $this->assertStringContainsString('No teacher assigned (those periods may stay empty)', $flash);
        $this->assertStringContainsString($unmanned->name, $flash);

        $record = TimeTableRecord::where('my_class_id', $class->id)->whereNull('exam_id')
            ->where('year', Qs::getCurrentSession())->firstOrFail();
        $this->assertGreaterThan(0, TimeTable::where('ttr_id', $record->id)
            ->where('subject_id', $staffed->id)->count());
        $this->assertSame(0, TimeTable::where('ttr_id', $record->id)
            ->where('subject_id', $unmanned->id)->count());

        // The warning also renders on the landing page, not just in session.
        $this->actingAs($this->admin())->get(route('tt.index'))
            ->assertSee('left empty (no teacher assigned', false);
    }
}
