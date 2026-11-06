<?php

namespace Tests\Feature;

use App\Models\Section;
use App\Models\Subject;
use App\Models\TimeTableRecord;
use App\Support\SectionTeacher;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Item 3 + teacher-scoping addendum (owner, 2026-11-01): the
 * timetables index renders UNIFORM summary cards — record rows
 * live only in the filtered single-class LIST view, which gains a
 * Section filter — and a teacher's page is narrowed to what she
 * is assigned: her classes (TeacherScope), her sections inside
 * them (chips + filter options), and her sections' records in
 * the list (whole-class records stay — she teaches the class).
 */
class OwnerTimetablesIndexLayoutTest extends TestCase
{
    use DatabaseTransactions;

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    /** The spec rule, expressed independently of the controller. */
    private function teacherSectionsByClass(User $teacher): array
    {
        $out = [];
        foreach (Section::all() as $sec) {
            $touches = (int) $sec->teacher_id === (int) $teacher->id;
            if (!$touches) {
                foreach (Subject::where('my_class_id', $sec->my_class_id)->get() as $sub) {
                    if (SectionTeacher::teacherIdFor($sec->id, $sub) === (int) $teacher->id) {
                        $touches = true;
                        break;
                    }
                }
            }
            if ($touches) {
                $out[(int) $sec->my_class_id][] = (int) $sec->id;
            }
        }
        return $out;
    }

    public function test_unfiltered_index_is_uniform_summary_cards_only()
    {
        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('tt.index'))->assertOk()->getContent();

        // No record rows (and no records container) anywhere in the grid.
        $this->assertSame(0, substr_count($html, 'ttc-record'));
        // All 13 classes render as summary cards.
        $this->assertSame(13, substr_count($html, 'ttc-name'));
        $this->assertStringContainsString('<span class="ttc-name">Class 12</span>', $html);
        // The Section filter rides the existing filter form.
        $this->assertStringContainsString('id="tt-section"', $html);
        $this->assertStringContainsString('name="section_id"', $html);
    }

    public function test_class_filter_renders_full_width_list_with_every_record_and_action()
    {
        $expected = TimeTableRecord::where('my_class_id', 13)->count();
        $this->assertGreaterThan(1, $expected);

        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('tt.index', ['class_id' => 13]))->assertOk()->getContent();

        $this->assertStringContainsString('ttc-list-card', $html);
        $this->assertSame($expected, substr_count($html, 'class="ttc-record"'));
        // Every existing action survives in the list rows.
        $this->assertStringContainsString('> View</a>', $html);
        $this->assertStringContainsString('> Print</a>', $html);
        $this->assertStringContainsString('> Validate</a>', $html);
        $this->assertStringContainsString('Manage', $html);
        // No other class renders as a grid card while one is listed.
        $this->assertSame(0, substr_count($html, 'ttc-card'));
    }

    public function test_section_filter_narrows_the_list_to_that_section()
    {
        $sectionA = Section::where('my_class_id', 13)->orderBy('id')->firstOrFail();
        $expected = TimeTableRecord::where('my_class_id', 13)
            ->where('section_id', $sectionA->id)->count();
        $this->assertSame(1, $expected);

        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('tt.index', ['class_id' => 13, 'section_id' => $sectionA->id]))
            ->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'class="ttc-record"'));
        $this->assertStringContainsString('Section ' . $sectionA->name . ' ·', $html);
        $sectionB = Section::where('my_class_id', 13)->where('id', '!=', $sectionA->id)
            ->orderBy('id')->firstOrFail();
        $this->assertStringNotContainsString('Section ' . $sectionB->name . ' ·', $html);
    }

    public function test_teacher_index_shows_only_her_classes_and_her_section_chips()
    {
        $teacher = $this->user('teacher@hackstra.app');
        $hers = $this->teacherSectionsByClass($teacher);

        // Ground truth on the seed: she teaches Class 12 English in
        // exactly ONE of its 13 sections, and has no part of Class 1.
        $this->assertArrayHasKey(13, $hers);
        $this->assertCount(1, $hers[13]);
        $this->assertArrayNotHasKey(2, $hers);

        $html = $this->actingAs($teacher)->get(route('tt.index'))->assertOk()->getContent();

        $this->assertStringContainsString('<span class="ttc-name">Class 12</span>', $html);
        $this->assertStringNotContainsString('<span class="ttc-name">Class 1</span>', $html);
        $this->assertStringNotContainsString('<span class="ttc-name">Class 10</span>', $html);

        // Chips inside her Class 12 card: only her section's chip.
        $herSection = Section::findOrFail($hers[13][0]);
        $pos = strpos($html, '<span class="ttc-name">Class 12</span>');
        $cardWindow = substr($html, $pos, 1600);
        $this->assertStringContainsString('<span class="ttc-chip">' . $herSection->name . '</span>', $cardWindow);
        $foreign = Section::where('my_class_id', 13)->where('id', '!=', $herSection->id)
            ->orderBy('id')->firstOrFail();
        $this->assertStringNotContainsString('<span class="ttc-chip">' . $foreign->name . '</span>', $cardWindow);

        // The Section filter offers her section, not the foreign one.
        $this->assertStringContainsString('<option value="' . $herSection->id . '" data-class="13"', $html);
        $this->assertStringNotContainsString('<option value="' . $foreign->id . '" data-class="13"', $html);
    }

    public function test_teacher_list_view_shows_only_her_sections_records()
    {
        $teacher = $this->user('teacher@hackstra.app');
        $hers = $this->teacherSectionsByClass($teacher);
        $herSection = Section::findOrFail($hers[13][0]);

        $html = $this->actingAs($teacher)
            ->get(route('tt.index', ['class_id' => 13]))->assertOk()->getContent();

        $expected = TimeTableRecord::where('my_class_id', 13)
            ->where(function ($q) use ($hers) {
                $q->whereNull('section_id')->orWhereIn('section_id', $hers[13]);
            })->count();
        $this->assertSame($expected, substr_count($html, 'class="ttc-record"'));
        $this->assertStringContainsString('Section ' . $herSection->name . ' ·', $html);
        $foreign = Section::where('my_class_id', 13)->where('id', '!=', $herSection->id)
            ->orderBy('id')->firstOrFail();
        $this->assertStringNotContainsString('Section ' . $foreign->name . ' ·', $html);
    }

    public function test_teacher_filtering_to_a_foreign_class_shows_the_empty_state()
    {
        $html = $this->actingAs($this->user('teacher@hackstra.app'))
            ->get(route('tt.index', ['class_id' => 2]))->assertOk()->getContent();

        $this->assertStringNotContainsString('ttc-list-card', $html);
        $this->assertStringContainsString('No timetables match your filters', $html);
    }

    /* Item 3 visual pass (tt-v3, site style): headbar + card
       counts/foot render from the SAME scoped data. */
    public function test_ttv3_headbar_and_card_chrome()
    {
        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('tt.index'))->assertOk()->getContent();

        $this->assertStringContainsString('tt3-head', $html);
        $this->assertStringContainsString('Session ' . \App\Helpers\Qs::getCurrentSession(), $html);
        $this->assertStringContainsString('View and manage class timetables', $html);
        // Every card carries the counts row + a status foot line.
        $this->assertSame(13, substr_count($html, 'tt3-counts'));
        $this->assertStringContainsString('tt3-foot', $html);
        $this->assertStringNotContainsString('tt3-teacher', $html);
    }

    public function test_ttv3_teacher_cards_say_what_she_is_to_the_class()
    {
        $html = $this->actingAs($this->user('teacher@hackstra.app'))
            ->get(route('tt.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Showing only the classes you teach in', $html);
        $this->assertStringContainsString('tt3-teacher', $html);
        // Prep: she is class teacher (leads its sections).
        $this->assertStringContainsString('You are class teacher', $html);
        // Class 12: she teaches one section but leads none.
        $pos = strpos($html, '<span class="ttc-name">Class 12</span>');
        $this->assertNotFalse($pos);
        $window = substr($html, $pos, 400);
        $this->assertStringContainsString('Your sections only', $window);
    }
}
