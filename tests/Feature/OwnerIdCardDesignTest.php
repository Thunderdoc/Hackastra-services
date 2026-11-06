<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\StudentRecord;
use App\Support\IdCardFactory;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Item 14 (owner, 2026-11-01 + amendments 1-7): ID cards for every
 * role on ONE common design — dynamic Settings branding, premium
 * field TILES on the front, Roll No from the admission-order
 * roster, stable facts only, a real Code39 barcode (round trip
 * pinned in Tests\Unit\Code39Test), the session-validity line on
 * STUDENT cards only, the SCHOOL panel + property line on staff
 * fronts, and the identity strip + SCHOOL OFFICE box on backs.
 * Permissions are unchanged: student cards keep their teamSA /
 * teamSAT-bulk / mine gates; staff cards are teamSA.
 */
class OwnerIdCardDesignTest extends TestCase
{
    use DatabaseTransactions;

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function aStudent(): StudentRecord
    {
        return StudentRecord::with(['user', 'my_class', 'section'])
            ->where('grad', 0)->whereNotNull('section_id')->orderBy('id')->firstOrFail();
    }

    public function test_student_card_renders_the_new_design_with_real_fields()
    {
        $sr = $this->aStudent();
        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('students.id_card', \App\Helpers\Qs::hash($sr->id)))
            ->assertOk()->getContent();

        $this->assertStringContainsString('idc2-student', $html);
        $this->assertStringContainsString('STUDENT', $html);
        $this->assertStringContainsString($sr->adm_no, $html);
        $this->assertStringContainsString('Roll No', $html);
        $roll = IdCardFactory::rollNo($sr);
        $this->assertStringContainsString(str_pad((string) $roll, 2, '0', STR_PAD_LEFT), $html);
        $this->assertStringContainsString('Valid for session', $html);
        $this->assertStringContainsString('data:image/png;base64,', $html, 'real barcode image');
        $this->assertStringNotContainsString('THIS CARD IS THE PROPERTY OF', $html,
            'the property line belongs to staff fronts');
        // Back: identity strip + school office box.
        $this->assertStringContainsString('STUDENT DETAILS', $html);
        $this->assertStringContainsString('SCHOOL OFFICE', $html);
        $this->assertStringContainsString('If found, please return to the school office', $html);
    }

    public function test_missing_student_values_omit_their_tiles()
    {
        $bare = StudentRecord::with(['user'])->where('grad', 0)
            ->whereNull('house')->orderBy('id')->first();
        if (!$bare) {
            $this->markTestSkipped('seed has no house-less student record');
        }
        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('students.id_card', \App\Helpers\Qs::hash($bare->id)))
            ->assertOk()->getContent();
        $this->assertStringNotContainsString('>House<', $html);

        $housed = StudentRecord::with(['user'])->where('grad', 0)
            ->whereNotNull('house')->where('house', '!=', '')->orderBy('id')->first();
        if ($housed) {
            $html2 = $this->actingAs($this->user('admin@hackstra.app'))
                ->get(route('students.id_card', \App\Helpers\Qs::hash($housed->id)))
                ->assertOk()->getContent();
            $this->assertStringContainsString($housed->house, $html2);
        }
    }

    public function test_branding_follows_settings_not_constants()
    {
        Setting::where('type', 'system_name')->update(['description' => 'Test Valley School']);
        $sr = $this->aStudent();
        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('students.id_card', \App\Helpers\Qs::hash($sr->id)))
            ->assertOk()->getContent();
        $this->assertStringContainsString('Test Valley School', $html);
        $this->assertStringNotContainsString('Hackstra Academy', $html);
    }

    public function test_staff_cards_render_their_pill_code_and_property_line()
    {
        $admin = $this->user('admin@hackstra.app');
        $cases = [
            'teacher@hackstra.app' => 'TEACHER',
            'principal@hackstra.app' => 'PRINCIPAL',
            'admin@hackstra.app' => 'ADMINISTRATOR',
            'accountant@hackstra.app' => 'ACCOUNTANT',
            'librarian@hackstra.app' => 'LIBRARIAN',
        ];
        foreach ($cases as $email => $pill) {
            $u = $this->user($email);
            $html = $this->actingAs($admin)->get(route('staff.id_card', $u->id))
                ->assertOk()->getContent();
            $this->assertStringContainsString($pill, $html, $email);
            $this->assertStringContainsString($u->code, $html, $email . ' emp id / barcode value');
            $this->assertStringContainsString('THIS CARD IS THE PROPERTY OF', $html, $email);
            $this->assertStringContainsString('>SCHOOL<', $html, $email);
            $this->assertStringNotContainsString('Valid for session', $html,
                'session validity is student-only (' . $email . ')');
        }
    }

    public function test_teacher_card_prints_stable_facts_only()
    {
        $teacher = $this->user('teacher@hackstra.app');
        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('staff.id_card', $teacher->id))->assertOk()->getContent();

        $this->assertStringContainsString('Designation', $html);
        $this->assertStringContainsString('>Teacher<', $html);
        $this->assertStringContainsString('School Office', $html);
        $this->assertStringNotContainsString('Periods', $html);
        $this->assertStringNotContainsString('Class Teacher', $html);
        // Her taught subject names must not leak onto the card.
        foreach (\App\Support\SectionTeacher::unitSubjectIds($teacher) as $sid) {
            $name = \App\Models\Subject::find($sid)->name ?? null;
            if ($name) {
                $this->assertStringNotContainsString($name, $html);
            }
        }
    }

    public function test_principal_card_carries_the_signatory_line_not_a_signature()
    {
        $principal = $this->user('principal@hackstra.app');
        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('staff.id_card', $principal->id))->assertOk()->getContent();
        $this->assertStringContainsString('Authorised Signatory', $html);
    }

    public function test_staff_card_gates_are_teamSA()
    {
        $teacher = $this->user('teacher@hackstra.app');
        $target = $this->user('accountant@hackstra.app');

        // teamSA refuses non-SA callers (403 or the middleware's
        // redirect — either way the card is never served).
        $r1 = $this->actingAs($teacher)->get(route('staff.id_card', $target->id));
        $this->assertContains($r1->getStatusCode(), [302, 403]);
        $r2 = $this->actingAs($teacher)->get(route('staff.id_cards.bulk'));
        $this->assertContains($r2->getStatusCode(), [302, 403]);
        // A super_admin has no school card.
        $sa = $this->user('superadmin@hackstra.app');
        $resp = $this->actingAs($this->user('admin@hackstra.app'))->get(route('staff.id_card', $sa->id));
        $this->assertNotSame(200, $resp->getStatusCode());
    }

    public function test_student_and_staff_bulk_pdfs_still_generate()
    {
        $admin = $this->user('admin@hackstra.app');

        $students = $this->actingAs($admin)->get(route('students.id_cards.bulk.pdf', ['class_id' => 1]));
        $students->assertOk();
        $this->assertStringStartsWith('%PDF', $students->getContent());

        $staff = $this->actingAs($admin)->get(route('staff.id_cards.bulk.pdf', ['role' => 'teacher']));
        $staff->assertOk();
        $this->assertStringStartsWith('%PDF', $staff->getContent());
    }

    public function test_student_mine_shows_the_new_card()
    {
        $sr = $this->aStudent();
        $html = $this->actingAs($sr->user)->get(route('id_card.mine'))
            ->assertOk()->getContent();
        $this->assertStringContainsString('idc2-student', $html);
        $this->assertStringContainsString($sr->adm_no, $html);
    }
}
