<?php

namespace Tests\Feature;

use App\Models\MyClass;
use App\Models\SchoolEvent;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Models\Substitution;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Item 21 (2026-11-01): admin quick wins — each sub-item verified
 * on the tree first; these pins cover the fixes and the
 * already-correct verdicts alike.
 */
class OwnerAdminQuickWinsTest extends TestCase
{
    use DatabaseTransactions;

    private function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    public function test_students_page_has_one_search_mechanism()
    {
        $html = $this->actingAs($this->admin())->get(route('students.list'))->assertOk()->getContent();
        $this->assertStringContainsString('Search Students', $html);
        $this->assertStringNotContainsString('Choose a Class', $html);
        $this->assertStringNotContainsString('picker_class', $html);
    }

    public function test_classes_table_carries_section_and_student_counts()
    {
        $response = $this->actingAs($this->admin())->get(route('classes.index'))->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('<th>Sections</th>', $html);
        $this->assertStringContainsString('<th>Students</th>', $html);
        $class = $response->viewData('my_classes')->firstWhere('id', 13);
        $this->assertSame(Section::where('my_class_id', 13)->count(), $class->section_count);
        $this->assertSame(StudentRecord::where('my_class_id', 13)->where('grad', 0)->count(), $class->student_record_count);
    }

    public function test_users_search_narrows_by_name()
    {
        $all = $this->actingAs($this->admin())->get(route('users.index'))->assertOk();
        $total = $all->viewData('users')->count();
        $found = $this->actingAs($this->admin())->get(route('users.index', ['q' => 'Ananya']))->assertOk();
        $users = $found->viewData('users');
        $this->assertLessThan($total, $users->count());
        $this->assertTrue($users->every(function ($u) {
            return stripos($u->name . ' ' . $u->email . ' ' . ($u->username ?? ''), 'ananya') !== false;
        }));
        $this->assertStringContainsString('name="q"', $found->getContent());
    }

    public function test_leave_filters_are_labelled()
    {
        $html = $this->actingAs($this->admin())->get(route('leave.index'))->assertOk()->getContent();
        foreach (['lv_status', 'lv_leave_type', 'lv_coverage', 'lv_from', 'lv_to'] as $id) {
            $this->assertStringContainsString('for="' . $id . '"', $html);
            $this->assertStringContainsString('id="' . $id . '"', $html);
        }
    }

    public function test_substitutions_board_orders_by_period_time_and_date_filter_narrows()
    {
        $response = $this->actingAs($this->admin())->get(route('substitutions.index'))->assertOk();
        $rows = $response->viewData('substitutions');
        // Within any single date, slot starts are non-decreasing,
        // and dates run newest-first.
        $prevDate = null; $prevSlot = null;
        foreach ($rows as $row) {
            $date = $row->date instanceof \Carbon\Carbon ? $row->date->toDateString() : (string) $row->date;
            $slot = (int) (optional(optional($row->time_table)->time_slot)->timestamp_from ?? PHP_INT_MAX);
            if ($prevDate !== null) {
                $this->assertTrue($date <= $prevDate, 'dates must run newest first');
                if ($date === $prevDate) {
                    $this->assertTrue($slot >= $prevSlot, 'periods within a day must run in time order');
                }
            }
            $prevDate = $date; $prevSlot = $slot;
        }

        $any = Substitution::first();
        if ($any) {
            $date = $any->date instanceof \Carbon\Carbon ? $any->date->toDateString() : (string) $any->date;
            $filtered = $this->actingAs($this->admin())->get(route('substitutions.index', ['date' => $date]))->assertOk();
            foreach ($filtered->viewData('substitutions') as $row) {
                $d = $row->date instanceof \Carbon\Carbon ? $row->date->toDateString() : (string) $row->date;
                $this->assertSame($date, $d);
            }
            $this->assertNotCount(0, $filtered->viewData('substitutions'));
        }
    }

    public function test_events_cards_carry_date_tile_and_description()
    {
        $event = SchoolEvent::create([
            'title' => 'I21 Fixture Event',
            'description' => 'I21DESC an annual day rehearsal in the main hall.',
            'type' => array_key_first(SchoolEvent::types()) ?: 'event',
            'starts_at' => now()->addDays(5),
            'all_day' => false,
        ]);
        $html = $this->actingAs($this->admin())->get(route('events.index'))->assertOk()->getContent();
        $this->assertStringContainsString('class="ev-date"', $html);
        $this->assertStringContainsString('I21DESC', $html);
    }

    public function test_settings_teaching_load_shows_effective_values_and_nav()
    {
        $html = $this->actingAs($this->admin())->get(route('settings'))->assertOk()->getContent();
        // No teacher_max_periods_* rows are stored on the seed, so
        // the fields must show the EFFECTIVE defaults (8 / 40),
        // not blanks.
        $this->assertMatchesRegularExpression('/name="teacher_max_periods_day"[^>]*value="8"/', $html);
        $this->assertMatchesRegularExpression('/name="teacher_max_periods_week"[^>]*value="40"/', $html);
        $this->assertStringContainsString('class="set-nav"', $html);
        $this->assertStringContainsString('href="#set-teaching-load"', $html);
        $this->assertStringContainsString('id="set-teaching-load"', $html);
    }
}
