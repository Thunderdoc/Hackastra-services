<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Half-day To-lock, second pass (2026-11-08). The first build
 * passed a programmatic proof but failed live in two ways, both
 * pinned here at the wiring level (the behaviour itself is
 * proven by real keyboard/mouse interaction — see
 * hk-tmp/leavefix/repro-*.log):
 *
 *  1. The lock never engaged: #day_part is Select2-enhanced and
 *     Select2 announces changes through jQuery's trigger, which
 *     a vanilla addEventListener('change') never hears — so
 *     partSync (mirror + lock + note) never ran when a user
 *     actually chose a half-day. partSync must be bound through
 *     jQuery as well as vanilla.
 *  2. The mirror ran one step behind the From date: it read the
 *     bridged .value from 'input' listeners that fire BEFORE
 *     hk_date's own commit handlers in the same dispatch. The
 *     mirror must run on hk_date's 'hkdate:settled' signal, and
 *     hk_date must dispatch that signal after a commit lands.
 *
 * The lock also carries readOnly alongside disabled, and the To
 * field guards itself: any settled write to it while a
 * single-day duration is chosen is mirrored straight back, so a
 * stale To (e.g. from an hk-draft restore) cannot survive.
 */
class OwnerLeaveHalfDayLockTest extends TestCase
{
    use DatabaseTransactions;

    protected function ananya(): User
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    public function test_my_leave_page_dispatches_and_consumes_the_settled_signal()
    {
        $html = $this->actingAs($this->ananya())->get(route('leave.my'))
            ->assertOk()->getContent();

        // hk_date (rendered by the master layout on this page)
        // dispatches the settled signal after a commit lands.
        $this->assertStringContainsString("new CustomEvent('hkdate:settled'", $html);
        // The leave form mirrors on that signal, not on raw input.
        $this->assertStringContainsString("addEventListener('hkdate:settled'", $html);
        $this->assertStringNotContainsString("start.addEventListener('input', function () { mirrorEnd(); })", $html);
    }

    public function test_part_sync_is_bound_for_select2_and_the_lock_is_two_barred()
    {
        $html = $this->actingAs($this->ananya())->get(route('leave.my'))
            ->assertOk()->getContent();

        // Select2 speaks jQuery: partSync must be bound that way too.
        $this->assertStringContainsString("jQuery(part).on('change', partSync)", $html);
        // The visible To field locks with BOTH bars + the note.
        $this->assertStringContainsString('end.disabled = locked', $html);
        $this->assertStringContainsString('end.readOnly = locked', $html);
        $this->assertStringContainsString('id="lv_same_day_note"', $html);
        // The To self-guard rides the same settled signal.
        $this->assertStringContainsString("end.addEventListener('hkdate:settled', guardEnd)", $html);
    }

    public function test_partial_leave_still_stores_a_single_day_whatever_the_form_sent()
    {
        $ananya = $this->ananya();
        $before = LeaveRequest::where('user_id', $ananya->id)->count();

        $this->actingAs($ananya)->post(route('leave.store'), [
            'start_date' => '2026-12-14',
            'end_date' => '2026-12-20',
            'leave_type' => 'normal',
            'day_part' => 'first_half',
            'reason' => 'Half-day lock server pin',
        ])->assertRedirect();

        $this->assertSame($before + 1, LeaveRequest::where('user_id', $ananya->id)->count());
        $row = LeaveRequest::where('user_id', $ananya->id)->latest('id')->first();
        $this->assertSame('2026-12-14', substr((string) $row->start_date, 0, 10));
        $this->assertSame('2026-12-14', substr((string) $row->end_date, 0, 10));
    }
}
