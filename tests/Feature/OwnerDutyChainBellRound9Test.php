<?php

namespace Tests\Feature;

use App\Models\ExamDate;
use App\Support\Invigilation;
use App\Support\Notify;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Roles audit round 9 — duty-replacement bell chain, RECIPIENT
 * angle. Agent E round 9 proved the mechanism (rows deleted on
 * replacement, A→B→C chain, other-sitting bell survives) in
 * OwnerDutyBellReplacementTest. This suite proves what each
 * teacher actually SEES through the notification read path
 * (Notify::unreadCount / latestFor) and that one recipient
 * clearing their bells never touches another's.
 *
 * Setup: A holds TWO sittings; sitting 1 is then replaced
 * B → C. After the chain, A must see exactly one duty bell
 * (sitting 2), B none, C exactly one (sitting 1).
 */
class OwnerDutyChainBellRound9Test extends TestCase
{
    use DatabaseTransactions;

    protected function teacher(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    public function test_chain_leaves_each_recipient_with_exactly_their_own_bells()
    {
        $admin = $this->teacher('admin@hackstra.app');
        $a = $this->teacher('teacher@hackstra.app');
        $b = $this->teacher('teacher1@teacher.com');
        $c = $this->teacher('teacher2@teacher.com');
        $examId = \App\Models\Exam::orderBy('id')->firstOrFail()->id;

        $sitting1 = ExamDate::create([
            'exam_id' => $examId, 'my_class_id' => 1, 'subject_id' => 1,
            'exam_date' => '2026-11-05', 'start_time' => '09:00',
        ]);
        $sitting2 = ExamDate::create([
            'exam_id' => $examId, 'my_class_id' => 1, 'subject_id' => 2,
            'exam_date' => '2026-11-06', 'start_time' => '09:00',
        ]);

        [$ok1] = Invigilation::assign($sitting1, (int) $a->id, (int) $admin->id);
        [$ok2] = Invigilation::assign($sitting2, (int) $a->id, (int) $admin->id);
        [$ok3] = Invigilation::assign($sitting1, (int) $b->id, (int) $admin->id);
        [$ok4] = Invigilation::assign($sitting1, (int) $c->id, (int) $admin->id);
        $this->assertTrue($ok1 && $ok2 && $ok3 && $ok4);

        // Recipient-visible counts: A keeps only sitting 2, B
        // keeps nothing, C holds sitting 1.
        $this->assertSame(1, Notify::unreadCount($a->id));
        $this->assertSame(0, Notify::unreadCount($b->id));
        $this->assertSame(1, Notify::unreadCount($c->id));

        // And the bodies name the right sittings: A's surviving
        // bell is the 2026-11-06 one, C's is the 2026-11-05 one.
        $aBodies = Notify::latestFor($a->id, 5)->pluck('body')->all();
        $this->assertStringContainsString('2026-11-06', implode(' ', $aBodies));
        $this->assertStringNotContainsString('2026-11-05', implode(' ', $aBodies));
        $cBodies = Notify::latestFor($c->id, 5)->pluck('body')->all();
        $this->assertStringContainsString('2026-11-05', implode(' ', $cBodies));

        // Every surviving bell links to the teacher's own duties
        // page (the link the topbar bell renders).
        foreach (Notify::latestFor($c->id, 5) as $bell) {
            $this->assertSame(route('exam_duties.my'), $bell->link);
        }
    }

    public function test_one_recipient_clearing_bells_never_touches_anothers()
    {
        $admin = $this->teacher('admin@hackstra.app');
        $a = $this->teacher('teacher@hackstra.app');
        $c = $this->teacher('teacher2@teacher.com');
        $examId = \App\Models\Exam::orderBy('id')->firstOrFail()->id;

        $sitting = ExamDate::create([
            'exam_id' => $examId, 'my_class_id' => 1, 'subject_id' => 1,
            'exam_date' => '2026-11-05', 'start_time' => '09:00',
        ]);
        Invigilation::assign($sitting, (int) $a->id, (int) $admin->id);
        Invigilation::assign($sitting, (int) $c->id, (int) $admin->id);
        $this->assertSame(0, Notify::unreadCount($a->id));
        $this->assertSame(1, Notify::unreadCount($c->id));

        // A clearing (even with nothing unread) leaves C's bell.
        Notify::markAllRead($a->id);
        $this->assertSame(1, Notify::unreadCount($c->id));

        // And C clearing their own leaves nothing behind for A.
        Notify::markAllRead($c->id);
        $this->assertSame(0, Notify::unreadCount($c->id));
        $this->assertSame(0, Notify::unreadCount($a->id));
    }
}
