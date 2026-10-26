<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\StudentRecord;
use App\Models\TimeTableRecord;
use App\Support\FeeDefaulter;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Regression tests for the 2026-10-25 owner wave 5: teacher
 * marksheet download (owner YES, scoped to classes taught),
 * section-wise bulk marksheet PDF for the Admin panel, fee
 * reminders to defaulters, and the timetable-validation visibility
 * fix (admin-only route + button).
 */
class OwnerWave5Test extends TestCase
{
    use DatabaseTransactions;

    protected function teacher()
    {
        return User::find(4); // Ananya Sharma — teaches classes {1,4,7,10,13}
    }

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->first();
    }

    public function test_teacher_can_print_marksheet_of_a_class_they_teach()
    {
        // student@ (Aarav Sharma) is Prep (class 1) — Ananya teaches
        // it. Resolved by email, never by id: teacher additions
        // shift every student id (42 → 44 when commerce teachers
        // were added), and hardcoded ids silently test the wrong user.
        $studentId = User::where('email', 'student@hackstra.app')->first()->id;
        $this->actingAs($this->teacher())
            ->get(route('marks.print', [Qs::hash($studentId), 1, '2026-2027']))
            ->assertStatus(200);
    }

    public function test_teacher_cannot_print_marksheet_of_a_foreign_class()
    {
        $foreign = StudentRecord::where('my_class_id', 2)->first();
        $this->assertNotNull($foreign, 'seed must hold a Class 1 student');

        $this->actingAs($this->teacher())
            ->get(route('marks.print', [Qs::hash($foreign->user_id), 1, '2026-2027']))
            ->assertRedirect(route('dashboard'));
    }

    public function test_admin_print_still_works_after_gate_unification()
    {
        $studentId = User::where('email', 'student@hackstra.app')->first()->id;
        $this->actingAs($this->admin())
            ->get(route('marks.print', [Qs::hash($studentId), 1, '2026-2027']))
            ->assertStatus(200);
    }

    public function test_timetable_validation_is_admin_only()
    {
        $ttr = TimeTableRecord::first();
        $this->assertNotNull($ttr, 'seed must hold a timetable record');

        $this->actingAs($this->admin())
            ->get(route('ttr.validate', $ttr->id))
            ->assertStatus(200);

        $this->actingAs($this->teacher())
            ->get(route('ttr.validate', $ttr->id))
            ->assertRedirect(route('login'));

        $this->actingAs(User::where('email', 'student@hackstra.app')->first())
            ->get(route('ttr.validate', $ttr->id))
            ->assertRedirect(route('login'));
    }

    public function test_validate_button_hidden_for_teachers_on_timetable_index()
    {
        $this->actingAs($this->teacher())
            ->get(route('tt.index'))
            ->assertStatus(200)
            ->assertDontSee('records/validate');

        $this->actingAs($this->admin())
            ->get(route('tt.index'))
            ->assertStatus(200)
            ->assertSee('records/validate');
    }

    public function test_bulk_marksheet_pdf_for_a_section_is_admin_only()
    {
        // Section 48 = Class 10 A (my_class_id 11), Term 1 exam 1.
        $response = $this->actingAs($this->admin())
            ->get(route('marks.bulk_download', ['exam_id' => 1, 'my_class_id' => 11, 'section_id' => 48]));
        $response->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
        $this->assertGreaterThan(0, strlen($response->getContent()));

        $this->actingAs($this->teacher())
            ->get(route('marks.bulk_download', ['exam_id' => 1, 'my_class_id' => 11, 'section_id' => 48]))
            ->assertRedirect(route('login'));
    }

    public function test_fee_reminders_notify_defaulters_and_dedupe_on_resend()
    {
        $rows = FeeDefaulter::rows(Qs::getCurrentSession());
        $this->assertNotEmpty($rows, 'seed must hold at least one fee defaulter');
        $first = $rows->first()['student'];

        $this->actingAs($this->admin())
            ->post(route('payments.defaulters.remind'))
            ->assertRedirect()
            ->assertSessionHas('flash_success');

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $first->user_id,
            'type' => 'fee_reminder',
        ]);

        // Resend refreshes instead of duplicating: still one unread row.
        $this->actingAs($this->admin())
            ->post(route('payments.defaulters.remind'))
            ->assertRedirect();
        $this->assertEquals(1, \App\Models\UserNotification::where('user_id', $first->user_id)
            ->where('type', 'fee_reminder')->whereNull('read_at')->count());

        // Teachers are not the fee team: refused at the route gate.
        $this->actingAs($this->teacher())
            ->post(route('payments.defaulters.remind'))
            ->assertRedirect(route('login'));
    }
}
