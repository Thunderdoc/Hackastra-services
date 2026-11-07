<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Section;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Item 15 (owner order 2026-11-01): the WhatsApp LOCK rule + panel
 * redesign. Once a section's group link is saved, the teacher can
 * never change or clear it (server-side refusal in
 * SectionController::updateWhatsapp); only the school Admin can
 * reset it. The teacher home panel renders saved rows locked
 * (chip + badge + Open Group, no input) and unsaved rows with the
 * Save form, grouped by class with the linked-progress header.
 */
class OwnerWhatsappLockTest extends TestCase
{
    use DatabaseTransactions;

    private function teacher(): User
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    public function test_teacher_can_save_an_empty_sections_link()
    {
        $section = Section::findOrFail(2); // Prep B, led by Ananya, unlinked
        $this->assertEmpty($section->whatsapp_link);

        $this->actingAs($this->teacher())->post(route('sections.whatsapp', Qs::hash($section->id)), [
            'whatsapp_link' => 'https://chat.whatsapp.com/PrepBGroup1',
        ])->assertSessionHas('flash_success');

        $this->assertSame('https://chat.whatsapp.com/PrepBGroup1', $section->fresh()->whatsapp_link);
    }

    public function test_teacher_cannot_change_or_clear_a_saved_link()
    {
        $teacher = $this->teacher();
        $section = Section::findOrFail(2);
        $section->update(['whatsapp_link' => 'https://chat.whatsapp.com/Original1']);

        // A different, perfectly valid link is refused.
        $this->actingAs($teacher)->post(route('sections.whatsapp', Qs::hash($section->id)), [
            'whatsapp_link' => 'https://chat.whatsapp.com/Different2',
        ])->assertSessionHas('flash_danger');
        $this->assertSame('https://chat.whatsapp.com/Original1', $section->fresh()->whatsapp_link);

        // Clearing it is a change too — refused.
        $this->actingAs($teacher)->post(route('sections.whatsapp', Qs::hash($section->id)), [
            'whatsapp_link' => '',
        ])->assertSessionHas('flash_danger');
        $this->assertSame('https://chat.whatsapp.com/Original1', $section->fresh()->whatsapp_link);

        // Re-posting the SAME link is a no-op, not a lockout.
        $this->actingAs($teacher)->post(route('sections.whatsapp', Qs::hash($section->id)), [
            'whatsapp_link' => 'https://chat.whatsapp.com/Original1',
        ])->assertSessionHas('flash_success');
        $this->assertSame('https://chat.whatsapp.com/Original1', $section->fresh()->whatsapp_link);
    }

    public function test_admin_can_reset_a_saved_link()
    {
        $section = Section::findOrFail(2);
        $section->update(['whatsapp_link' => 'https://chat.whatsapp.com/TypoLink99']);

        // The escape hatch: admin clears it (the section edit page's
        // Reset button posts exactly this).
        $this->actingAs($this->admin())->post(route('sections.whatsapp', Qs::hash($section->id)), [
            'whatsapp_link' => '',
        ])->assertSessionHas('flash_success');
        $this->assertNull($section->fresh()->whatsapp_link);

        // After the reset the teacher can save the corrected link.
        $this->actingAs($this->teacher())->post(route('sections.whatsapp', Qs::hash($section->id)), [
            'whatsapp_link' => 'https://chat.whatsapp.com/CorrectLink1',
        ])->assertSessionHas('flash_success');
        $this->assertSame('https://chat.whatsapp.com/CorrectLink1', $section->fresh()->whatsapp_link);
    }

    public function test_saved_row_shows_no_input_and_unsaved_rows_keep_the_form()
    {
        $teacher = $this->teacher();
        Section::findOrFail(1)->update(['whatsapp_link' => 'https://chat.whatsapp.com/PrepAGrp111']);

        $html = $this->actingAs($teacher)->get(route('dashboard'))->assertOk()->getContent();
        $start = strpos($html, 'id="whatsapp-groups"');
        $this->assertNotFalse($start);
        $panel = substr($html, $start, strpos($html, '</section>', $start) - $start);

        // Header progress + warning + admin-reset footer.
        $this->assertStringContainsString('1 of 9', $panel);
        $this->assertStringContainsString('groups linked', $panel);
        // Copy literals re-expressed 2026-11-07 (beauty Batch 6 / E1): the panel's
        // Hinglish warning lines were replaced with English of the same meaning;
        // the thesis (warning + admin-reset footer present) is unchanged.
        $this->assertStringContainsString('once a link is saved, it cannot be changed', $panel);
        $this->assertStringContainsString('only the school Admin can reset it', $panel);

        // The saved row: chip + lock badge + Open Group, grouped label.
        $this->assertStringContainsString('Saved · Locked', $panel);
        $this->assertStringContainsString('https://chat.whatsapp.com/PrepAGrp111', $panel);
        $this->assertStringContainsString('Open Group', $panel);
        $this->assertStringContainsString('PREP', $panel);
        $this->assertStringContainsString('CLASS 7', $panel);

        // Exactly the 8 unsaved sections carry an input; the saved
        // one does not (9 led sections in total).
        $this->assertSame(8, substr_count($panel, 'name="whatsapp_link"'));
    }

    public function test_section_edit_page_carries_the_admin_reset()
    {
        $section = Section::findOrFail(2);
        $section->update(['whatsapp_link' => 'https://chat.whatsapp.com/TypoLink99']);

        $html = $this->actingAs($this->admin())->get(route('sections.edit', $section->id))->assertOk()->getContent();
        $this->assertStringContainsString('Reset link', $html);
        $this->assertStringContainsString('action="' . route('sections.whatsapp', Qs::hash($section->id)) . '"', $html);
    }
}
