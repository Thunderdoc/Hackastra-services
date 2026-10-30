<?php

namespace Tests\Feature;

use App\User;
use Tests\TestCase;

/**
 * Owner order 2026-10-30: in the teacher sidebar, the LMS items
 * (Assignments, Study Materials, Quizzes) and WhatsApp Groups
 * live INSIDE the Academics submenu — the separate LMS group is
 * gone. WhatsApp Groups lands on the teacher-home panel where
 * the section links are kept (anchor #whatsapp-groups).
 */
class OwnerAcademicsMenuMergeTest extends TestCase
{
    public function test_teacher_menu_has_lms_and_whatsapp_inside_academics()
    {
        $teacher = User::where('email', 'teacher@hackstra.app')->firstOrFail();
        $html = $this->actingAs($teacher)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('WhatsApp Groups', $html);
        $this->assertStringContainsString('#whatsapp-groups', $html);
        $this->assertStringNotContainsString('Manage LMS', $html);
        $this->assertStringNotContainsString('>LMS</span>', $html);

        // Order inside the menu: Timetables < Assignments <
        // Study Materials < Quizzes < WhatsApp Groups.
        $pos = function ($needle) use ($html) { return strpos($html, $needle); };
        $tt = $pos(route('tt.index'));
        $as = $pos(route('assignments.index'));
        $ma = $pos(route('materials.index'));
        $qz = $pos(route('quizzes.index'));
        $wa = $pos('#whatsapp-groups');
        $this->assertTrue($tt < $as && $as < $ma && $ma < $qz && $qz < $wa, 'LMS + WhatsApp sit inside Academics, after the timetables');
    }

    public function test_teacher_home_carries_the_whatsapp_anchor()
    {
        $teacher = User::where('email', 'teacher@hackstra.app')->firstOrFail();
        $this->actingAs($teacher)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('id="whatsapp-groups"', false)
            ->assertSee('Class WhatsApp Groups');
    }
}
