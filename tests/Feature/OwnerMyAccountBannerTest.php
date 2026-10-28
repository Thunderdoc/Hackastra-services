<?php

namespace Tests\Feature;

use App\Http\Controllers\MyAccountController;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Parent fix 2026-10-28 (flagged by agent F round 9): MyAccount
 * bannerStats/bannerChips used direct subjects only, so an
 * override-only teacher showed 0 subjects / 0 students on their
 * own profile page — the same display-undercount family as the
 * HomeController F2 dashboard fix. Both methods now merge
 * override subjects (same pattern as teacherHomeData).
 *
 * Anchors: Ritu Sharma (teacher20@teacher.com, id 44) holds ONLY
 * overrides — English 67 in Class 11 (my_class_id 12) and English
 * 79 in Class 12 (my_class_id 13). Class 11 = 360 students,
 * Class 12 = 520, so her banner must read Subjects 2, Classes 2,
 * Students 880 — the dashboard F2 numbers.
 */
class OwnerMyAccountBannerTest extends TestCase
{
    use DatabaseTransactions;

    protected function bannerStats(User $me): array
    {
        $m = new \ReflectionMethod(MyAccountController::class, 'bannerStats');
        $m->setAccessible(true);
        return $m->invoke(app(MyAccountController::class), $me);
    }

    protected function bannerChips(User $me): array
    {
        $m = new \ReflectionMethod(MyAccountController::class, 'bannerChips');
        $m->setAccessible(true);
        return $m->invoke(app(MyAccountController::class), $me);
    }

    protected function stat(array $stats, string $label)
    {
        foreach ($stats as $s) {
            if ($s['label'] === $label) {
                return $s['value'];
            }
        }
        $this->fail("No banner stat labelled {$label}");
    }

    public function test_override_only_teacher_banner_counts_override_subjects()
    {
        $ritu = User::where('email', 'teacher20@teacher.com')->firstOrFail();
        $stats = $this->bannerStats($ritu);

        $this->assertSame(2, $this->stat($stats, 'Subjects'));
        $this->assertSame(2, $this->stat($stats, 'Classes'));
        $this->assertSame(880, $this->stat($stats, 'Students'));
        $this->assertContains('English Language', $this->bannerChips($ritu));
    }

    public function test_teacher_with_no_assignments_shows_zeros()
    {
        $teacher = User::factory()->create(['user_type' => 'teacher']);
        $stats = $this->bannerStats($teacher);

        $this->assertSame(0, $this->stat($stats, 'Subjects'));
        $this->assertSame(0, $this->stat($stats, 'Classes'));
        $this->assertSame(0, $this->stat($stats, 'Students'));
        $this->assertSame([], $this->bannerChips($teacher));
    }
}
