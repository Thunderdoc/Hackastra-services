<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\MyClass;
use App\Models\Section;
use App\Support\TeacherScope;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Agent F round 16 — section-leader swap scope flip.
 *
 * Follow-through on the round-8 memo removal, which proved grant/drop
 * of OVERRIDES follows immediately (TeacherScope::classIds reads fresh
 * every call; the old static $classIdsByUser memo is gone). This round
 * proves the same immediacy for the OTHER scope source that changes
 * under an admin's hand: sections.teacher_id (source 2 — leading a
 * section assigns the whole class).
 *
 * Fixture isolation matters: both probe teachers are fresh (no
 * subjects, no overrides, no other led sections), and the probe class
 * is fresh (no seeded subject can name either teacher). So teacher A
 * qualifies ONLY via leadership of the probe section, and teacher B
 * qualifies for nothing — before the swap, classIds(A) is exactly
 * [class] and classIds(B) is exactly []. After the swap through the
 * real admin update path, the two lists must exchange with NO reset
 * step, NO re-login, and the gated students-list page must flip
 * with them (A 200→403, B 403→200). A third test swaps back to
 * prove the flip is bidirectional, not a one-way artifact.
 *
 * Route mechanics (learned in rounds 2/15, stated so the next author
 * does not re-derive them): sections.update is a resource route
 * whose controller resolves raw OR hashed ids (Qs::resolveId), so
 * the route param is the RAW section id; but SectionUpdate decodes
 * the body teacher_id from its hash (getValidatorInstance), so the
 * body value must be Qs::hash($teacher->id). The update itself is
 * teamSAWrite (admin only).
 */
class OwnerAllotmentRound16Test extends TestCase
{
    use DatabaseTransactions;

    protected function makeTeacher(string $code, string $email): User
    {
        return User::create([
            'name' => 'Probe Teacher R16',
            'username' => $code,
            'email' => $email,
            'password' => 'secret',
            'code' => $code,
            'user_type' => 'teacher',
        ]);
    }

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    /** Fresh class + fresh section led by $leader; returns [$class, $section]. */
    protected function leaderFixture(User $leader): array
    {
        $class = MyClass::create(['name' => 'ProbeLeaderClass R16']);
        $section = Section::create([
            'name' => 'A',
            'my_class_id' => $class->id,
            'teacher_id' => $leader->id,
        ]);

        return [$class, $section];
    }

    protected function swapLeader(Section $section, User $newLeader): void
    {
        $this->actingAs($this->admin())
            ->put(route('sections.update', $section->id), [
                'name' => $section->name,
                'teacher_id' => Qs::hash($newLeader->id),
            ])
            ->assertRedirect(route('sections.index'));
        $this->assertEquals($newLeader->id, (int) $section->fresh()->teacher_id);
    }

    public function test_leader_swap_flips_class_ids_immediately()
    {
        $a = $this->makeTeacher('PROBE-R16-A', 'probe-r16-a@example.com');
        $b = $this->makeTeacher('PROBE-R16-B', 'probe-r16-b@example.com');
        [$class, $section] = $this->leaderFixture($a);

        // Isolation: A qualifies ONLY via leadership; B for nothing.
        $this->assertSame([$class->id], TeacherScope::classIds($a));
        $this->assertSame([], TeacherScope::classIds($b));

        $this->swapLeader($section, $b);

        // No reset step, no re-login: the very next reads flip.
        $this->assertSame([], TeacherScope::classIds($a));
        $this->assertSame([$class->id], TeacherScope::classIds($b));
    }

    public function test_leader_swap_flips_students_list_gate()
    {
        $a = $this->makeTeacher('PROBE-R16-C', 'probe-r16-c@example.com');
        $b = $this->makeTeacher('PROBE-R16-D', 'probe-r16-d@example.com');
        [$class, $section] = $this->leaderFixture($a);

        // Gate before the swap: leader admitted, stranger refused.
        $this->actingAs($a)->get(route('students.list', [$class->id]))->assertOk();
        $this->actingAs($b)->get(route('students.list', [$class->id]))->assertForbidden();

        $this->swapLeader($section, $b);

        // Gate after the swap, same process, same assertions: flipped.
        $this->actingAs($a)->get(route('students.list', [$class->id]))->assertForbidden();
        $this->actingAs($b)->get(route('students.list', [$class->id]))->assertOk();
    }

    public function test_leader_swap_back_restores_scope()
    {
        $a = $this->makeTeacher('PROBE-R16-E', 'probe-r16-e@example.com');
        $b = $this->makeTeacher('PROBE-R16-F', 'probe-r16-f@example.com');
        [$class, $section] = $this->leaderFixture($a);

        $this->swapLeader($section, $b);
        $this->assertSame([], TeacherScope::classIds($a));
        $this->assertSame([$class->id], TeacherScope::classIds($b));

        // Swap back through the same real path: scope returns, not stuck.
        $this->swapLeader($section, $a);
        $this->assertSame([$class->id], TeacherScope::classIds($a));
        $this->assertSame([], TeacherScope::classIds($b));
        $this->actingAs($a)->get(route('students.list', [$class->id]))->assertOk();
        $this->actingAs($b)->get(route('students.list', [$class->id]))->assertForbidden();
    }
}
