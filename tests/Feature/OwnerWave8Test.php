<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Circular;
use App\Models\Exam;
use App\Models\Mark;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Regression tests for the 2026-10-25 owner wave 8 (small polish):
 * the marks exam picker is filtered by class, and a class-scoped
 * exam can never take marks for another class — the picker used
 * to list every session exam, and selector() firstOrCreated mark
 * rows for whatever pair was picked (silent wrong data on report
 * cards). Also locks the circulars show() answer: drafts 404 for
 * non-SA, published + visible circulars render.
 */
class OwnerWave8Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->first();
    }

    protected function teacher()
    {
        return User::find(4);
    }

    protected function scopedExam()
    {
        // Class 6's own exam (my_class_id 7); term 1 of the current
        // session, so the (term, year, my_class_id) unique holds.
        return Exam::create([
            'name' => 'Scoped Test Exam',
            'term' => 1,
            'year' => Qs::getCurrentSession(),
            'my_class_id' => 7,
        ]);
    }

    public function test_scoped_exam_rejects_other_class_marks()
    {
        $exam = $this->scopedExam();

        $this->actingAs($this->admin())
            ->post(route('marks.selector'), [
                'exam_id' => $exam->id,
                'my_class_id' => 1,
                'section_id' => 1,
                'subject_id' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHas('pop_error');

        $this->assertSame(0, Mark::where('exam_id', $exam->id)
            ->where('my_class_id', 1)->count());
    }

    public function test_global_exam_selector_still_works()
    {
        $this->actingAs($this->admin())
            ->post(route('marks.selector'), [
                'exam_id' => 1,
                'my_class_id' => 1,
                'section_id' => 1,
                'subject_id' => 1,
            ])
            ->assertRedirect(route('marks.manage', [1, 1, 1, 1]));
    }

    public function test_manage_rejects_scoped_exam_for_other_class()
    {
        $exam = $this->scopedExam();

        $this->actingAs($this->admin())
            ->get(route('marks.manage', [$exam->id, 1, 1, 1]))
            ->assertStatus(404);
    }

    public function test_circular_show_is_live_only()
    {
        $adminId = $this->admin()->id;

        $draft = Circular::create([
            'title' => 'Draft Test Circular',
            'body' => 'Not published yet.',
            'audience' => 'teachers',
            'priority' => 'normal',
            'published_by' => $adminId,
            'is_published' => false,
        ]);

        $live = Circular::create([
            'title' => 'Live Test Circular',
            'body' => 'Published for teachers.',
            'audience' => 'teachers',
            'priority' => 'normal',
            'published_by' => $adminId,
            'is_published' => true,
        ]);

        $this->actingAs($this->teacher())
            ->get(route('circulars.show', $draft->id))
            ->assertStatus(404);

        $this->actingAs($this->teacher())
            ->get(route('circulars.show', $live->id))
            ->assertStatus(200);
    }
}
