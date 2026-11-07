<?php

namespace Tests\Feature;

use App\Models\AiQuestionSuggestion;
use App\Models\AiUsage;
use App\Models\AuditLog;
use App\Models\QuestionBankQuestion;
use App\Models\QuestionPaper;
use App\Models\QuestionPaperQuestion;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * F4 — Question-Paper Generator (design §8, items 1–20).
 * Teacher = teacher@hackstra.app (id 4), who teaches English
 * (subject 79) in Class 12 (my_class 13). Users resolved by email.
 */
class OwnerQuestionPaperTest extends TestCase
{
    use DatabaseTransactions;

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->first();
    }

    protected function otherTeacher()
    {
        return User::where('email', 'teacher1@teacher.com')->first();
    }

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->first();
    }

    protected function principal()
    {
        return User::where('email', 'principal@hackstra.app')->first();
    }

    protected function superAdmin()
    {
        return User::where('email', 'superadmin@hackstra.app')->first();
    }

    protected function student()
    {
        return User::where('email', 'student@hackstra.app')->first();
    }

    protected function parentUser()
    {
        return User::where('email', 'parent@hackstra.app')->first();
    }

    protected function bankMcq($teacher, array $overrides = [])
    {
        return QuestionBankQuestion::create(array_merge([
            'teacher_id' => $teacher->id,
            'my_class_id' => 13,
            'subject_id' => 79,
            'section_id' => null,
            'question' => 'Bank MCQ ' . uniqid(),
            'options' => ['Alpha', 'Beta', 'Gamma', 'Delta'],
            'correct_index' => 1,
            'marks' => 1,
            'question_type' => 'mcq',
            'chapter' => null,
            'difficulty' => null,
            'model_answer' => null,
        ], $overrides));
    }

    protected function bankTyped($teacher, string $type, array $overrides = [])
    {
        $base = [
            'teacher_id' => $teacher->id,
            'my_class_id' => 13,
            'subject_id' => 79,
            'section_id' => null,
            'question' => ucfirst($type) . ' bank question ' . uniqid(),
            'marks' => 3,
            'question_type' => $type,
            'chapter' => null,
            'difficulty' => null,
        ];
        if ($type === 'mcq') {
            $base += ['options' => ['Alpha', 'Beta', 'Gamma', 'Delta'], 'correct_index' => 0, 'model_answer' => null];
        } else {
            $base += ['options' => null, 'correct_index' => null, 'model_answer' => 'Model answer ' . uniqid()];
        }

        return QuestionBankQuestion::create(array_merge($base, $overrides));
    }

    protected function seedBank($teacher, int $mcq = 12)
    {
        $rows = [];
        for ($i = 0; $i < $mcq; $i++) {
            $rows[] = $this->bankMcq($teacher, ['question' => "Seed MCQ number {$i}"]);
        }

        return $rows;
    }

    protected function specPayload(array $overrides = [], array $sectionA = [])
    {
        return array_merge([
            'title' => 'Unit Test Paper',
            'my_class_id' => 13,
            'subject_id' => 79,
            'section_id' => null,
            'exam_id' => null,
            'exam_label' => null,
            'duration_minutes' => 60,
            'seed' => 'seed-fixed-1',
            'sections' => [
                'A' => array_merge([
                    'key' => 'A', 'label' => 'Objective', 'type' => 'mcq',
                    'count' => 5, 'marks_each' => 1,
                ], $sectionA),
            ],
        ], $overrides);
    }

    protected function storePaper(array $overrides = [], array $sectionA = [])
    {
        $response = $this->actingAs($this->teacher())
            ->post(route('question_papers.store'), $this->specPayload($overrides, $sectionA));
        $paper = QuestionPaper::where('teacher_id', $this->teacher()->id)->latest('id')->first();
        $response->assertRedirect(route('question_papers.show', $paper->id));

        return $paper;
    }

    protected function setAiSetting(string $type, string $value)
    {
        DB::table('settings')->where('type', $type)->update(['description' => $value]);
    }

    protected function enableAiWithKey()
    {
        $this->setAiSetting('ai_enabled', '1');
        $this->setAiSetting('ai_api_key', 'test-key-123');
        config(['ai.mock' => false]);
    }

    protected function fakeGemini($payload)
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => is_string($payload) ? $payload : json_encode($payload)]]]]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 20],
            ], 200),
        ]);
    }

    /* ---------------- Slice 0: bank extension ---------------- */

    public function test_teacher_banks_short_and_long_questions()
    {
        $base = ['my_class_id' => 13, 'subject_id' => 79, 'section_id' => null, 'marks' => 3];

        $this->actingAs($this->teacher())->post(route('question_bank.store'), $base + [
            'question' => 'Explain photosynthesis briefly.',
            'question_type' => 'short',
            'model_answer' => 'Plants convert light into food energy.',
            'chapter' => 'Light',
            'difficulty' => 'easy',
        ])->assertRedirect(route('question_bank.index'));
        $this->assertDatabaseHas('question_bank_questions', [
            'question' => 'Explain photosynthesis briefly.',
            'question_type' => 'short',
            'model_answer' => 'Plants convert light into food energy.',
            'chapter' => 'Light',
            'difficulty' => 'easy',
            'options' => null,
            'correct_index' => null,
        ]);

        $this->actingAs($this->teacher())->post(route('question_bank.store'), $base + [
            'question' => 'Discuss the causes of the revolt.',
            'question_type' => 'long',
            'model_answer' => 'A long model answer.',
            'marks' => 5,
        ])->assertRedirect(route('question_bank.index'));
        $this->assertDatabaseHas('question_bank_questions', [
            'question' => 'Discuss the causes of the revolt.',
            'question_type' => 'long',
        ]);
    }

    public function test_short_question_without_model_answer_fails()
    {
        $this->actingAs($this->teacher())->post(route('question_bank.store'), [
            'my_class_id' => 13, 'subject_id' => 79, 'section_id' => null, 'marks' => 3,
            'question' => 'A short question with no answer.',
            'question_type' => 'short',
        ])->assertSessionHasErrors('model_answer');
        $this->assertDatabaseMissing('question_bank_questions', ['question' => 'A short question with no answer.']);
    }

    public function test_picker_returns_all_types_for_owner_only()
    {
        $mine = $this->bankTyped($this->teacher(), 'short', ['question' => 'Picker short mine']);
        $this->bankTyped($this->otherTeacher(), 'short', ['question' => 'Picker short theirs']);

        $response = $this->actingAs($this->teacher())
            ->getJson(route('question_bank.picker', ['class_id' => 13, 'subject_id' => 79]));
        $response->assertOk();
        $ids = array_column($response->json(), 'id');
        $this->assertContains($mine->id, $ids);
        $this->assertCount(1, $ids);
    }

    /* ---------------- Mode A: assembly ---------------- */

    public function test_exact_assembly_sums_and_counts()
    {
        $this->seedBank($this->teacher(), 12);
        $paper = $this->storePaper();

        $this->assertSame(5, $paper->questions()->count());
        $this->assertSame(5, $paper->total_marks);
        $this->assertSame(5, (int) $paper->questions()->sum('marks'));
        $bankIds = $paper->questions()->pluck('bank_question_id')->all();
        $this->assertSame($bankIds, array_unique($bankIds));
        $this->assertSame('draft', $paper->status);
    }

    public function test_determinism_same_seed_same_paper_different_seed_differs()
    {
        $this->seedBank($this->teacher(), 15);
        $p1 = $this->storePaper();
        $p2 = $this->storePaper();
        $order = fn ($p) => QuestionPaperQuestion::where('question_paper_id', $p->id)
            ->orderBy('section_key')->orderBy('position')->pluck('bank_question_id')->all();
        $this->assertSame($order($p1), $order($p2));

        $p3 = $this->storePaper(['seed' => 'seed-other-9'], ['count' => 8]);
        $p1b = $this->storePaper([], ['count' => 8]);
        $this->assertNotSame($order($p1b), $order($p3));
        $this->assertSame($p3->total_marks, $p1b->total_marks);
    }

    public function test_variable_marks_total_exact_via_dp_repair()
    {
        foreach ([2, 2, 3, 3, 5] as $i => $m) {
            $this->bankMcq($this->teacher(), ['question' => "Var marks {$i}", 'marks' => $m]);
        }
        $paper = $this->storePaper([], ['count' => 3, 'marks_each' => null, 'marks_total' => 10]);
        $this->assertSame(3, $paper->questions()->count());
        $this->assertSame(10, $paper->total_marks);
    }

    public function test_uniform_marks_each_is_strict()
    {
        $this->bankMcq($this->teacher(), ['marks' => 2]);
        $this->bankMcq($this->teacher(), ['marks' => 2]);
        $response = $this->actingAs($this->teacher())
            ->post(route('question_papers.store'), $this->specPayload([], ['count' => 2, 'marks_each' => 1]));
        $response->assertSessionHas('flash_danger');
        $this->assertSame(0, QuestionPaper::count());
    }

    public function test_shortfall_writes_nothing_and_names_the_gap()
    {
        $this->seedBank($this->teacher(), 2);
        $response = $this->actingAs($this->teacher())
            ->post(route('question_papers.store'), $this->specPayload());
        $response->assertSessionHas('flash_danger');
        $this->assertStringContainsString('Short by 3 questions / 3 marks', session('flash_danger'));
        $this->assertSame(0, QuestionPaper::count());
        $this->assertSame(0, QuestionPaperQuestion::count());
    }

    public function test_partial_draft_saved_but_cannot_be_finalised()
    {
        $this->seedBank($this->teacher(), 2);
        $paper = $this->storePaper(['create_partial' => 1]);
        $this->assertTrue($paper->hasShortfall());
        $this->assertSame(2, $paper->questions()->count());

        $this->actingAs($this->teacher())
            ->post(route('question_papers.finalise', $paper->id))
            ->assertSessionHas('flash_danger');
        $this->assertSame('draft', $paper->fresh()->status);
    }

    public function test_chapter_filter_and_difficulty_mix_honoured()
    {
        for ($i = 0; $i < 4; $i++) {
            $this->bankMcq($this->teacher(), ['question' => "Light Q{$i}", 'chapter' => 'Light', 'difficulty' => 'easy']);
            $this->bankMcq($this->teacher(), ['question' => "Sound Q{$i}", 'chapter' => 'Sound', 'difficulty' => 'hard']);
        }
        $paper = $this->storePaper([], [
            'count' => 3, 'chapters' => ['Light'],
        ]);
        foreach ($paper->questions as $q) {
            $this->assertSame('Light', $q->chapter);
        }

        $paper2 = $this->storePaper(['seed' => 'mix-seed'], [
            'count' => 4,
            'difficulty_mix' => ['easy' => 50, 'hard' => 50],
        ]);
        $diffs = $paper2->questions->pluck('difficulty')->all();
        $this->assertSame(2, count(array_filter($diffs, fn ($d) => $d === 'easy')));
        $this->assertSame(2, count(array_filter($diffs, fn ($d) => $d === 'hard')));
    }

    public function test_preview_reports_unrated_and_saves_nothing()
    {
        $this->seedBank($this->teacher(), 6); // all unrated
        $response = $this->actingAs($this->teacher())
            ->postJson(route('question_papers.preview'), $this->specPayload([], [
                'difficulty_mix' => ['easy' => 100],
            ]));
        $response->assertOk();
        $note = $response->json('sections.A.difficulty_note');
        $this->assertNotNull($note);
        $this->assertStringContainsString('unrated', $note);
        $this->assertSame(0, QuestionPaper::count());
    }

    public function test_section_pinning_and_foreign_teacher_rows_never_selected()
    {
        // Teacher 4 teaches subject 79 in section 76 (override);
        // the row pinned to section 64 (another teacher's section)
        // must never be selected for a section-76 paper.
        $this->bankMcq($this->teacher(), ['question' => 'Pinned to another section', 'section_id' => 64]);
        $whole = $this->bankMcq($this->teacher(), ['question' => 'Whole class row']);
        $foreign = $this->bankMcq($this->otherTeacher(), ['question' => 'Foreign teacher row']);

        $paper = $this->storePaper(['section_id' => 76], ['count' => 1]);
        $ids = $paper->questions()->pluck('bank_question_id')->all();
        $this->assertSame([$whole->id], $ids);
        $this->assertNotContains($foreign->id, $ids);
    }

    public function test_teacher_cannot_generate_for_subject_they_do_not_teach()
    {
        $response = $this->actingAs($this->teacher())
            ->post(route('question_papers.store'), $this->specPayload(['my_class_id' => 1, 'subject_id' => 2]));
        $response->assertSessionHasErrors('subject_id');
        $this->assertSame(0, QuestionPaper::count());
    }

    /* ---------------- Duplicates across papers ---------------- */

    public function test_cross_paper_duplicates_excluded_and_reported()
    {
        $this->seedBank($this->teacher(), 10);
        $p1 = $this->storePaper(['exam_id' => 1], ['count' => 4]);
        $this->actingAs($this->teacher())->post(route('question_papers.finalise', $p1->id));
        $usedIds = $p1->questions()->pluck('bank_question_id')->all();

        $preview = $this->actingAs($this->teacher())
            ->postJson(route('question_papers.preview'), $this->specPayload(['exam_id' => 1, 'seed' => 'another-seed'], ['count' => 4]));
        $preview->assertOk();
        $this->assertGreaterThan(0, $preview->json('excluded_total'));

        $p2 = $this->storePaper(['exam_id' => 1, 'seed' => 'another-seed'], ['count' => 4]);
        $overlap = array_intersect($usedIds, $p2->questions()->pluck('bank_question_id')->all());
        $this->assertSame([], array_values($overlap));
    }

    public function test_repeat_override_included_and_audited()
    {
        $this->seedBank($this->teacher(), 6);
        $p1 = $this->storePaper(['exam_id' => 1], ['count' => 4]);
        $this->actingAs($this->teacher())->post(route('question_papers.finalise', $p1->id));

        $p2 = $this->storePaper(['exam_id' => 1, 'allow_repeat_questions' => 1], ['count' => 4]);
        $overlap = array_intersect(
            $p1->questions()->pluck('bank_question_id')->all(),
            $p2->questions()->pluck('bank_question_id')->all()
        );
        $this->assertNotEmpty($overlap);
        $log = AuditLog::where('action', 'question_paper.created')
            ->where('subject_id', $p2->id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('repeats used:', $log->description);
    }

    public function test_text_hash_twin_is_excluded()
    {
        $original = $this->bankMcq($this->teacher(), ['question' => 'What is the Capital of France?']);
        $this->seedBank($this->teacher(), 8);
        $p1 = $this->storePaper(['exam_id' => 1], ['count' => 9]);
        $this->actingAs($this->teacher())->post(route('question_papers.finalise', $p1->id));
        $this->assertContains($original->id, $p1->questions()->pluck('bank_question_id')->all());

        $twin = $this->bankMcq($this->teacher(), ['question' => 'what is the capital of france']);
        $this->bankMcq($this->teacher(), ['question' => 'A fresh unrelated question one']);
        $this->bankMcq($this->teacher(), ['question' => 'A fresh unrelated question two']);
        $preview = $this->actingAs($this->teacher())
            ->postJson(route('question_papers.preview'), $this->specPayload(['exam_id' => 1, 'seed' => 'twin-seed'], ['count' => 1]));
        $preview->assertOk();
        // The twin is counted among the excluded duplicates even
        // though its bank id never appeared in any paper.
        $this->assertGreaterThanOrEqual(10, $preview->json('sections.A.excluded_duplicates'));
        $p2 = $this->storePaper(['exam_id' => 1, 'seed' => 'twin-seed'], ['count' => 1]);
        $this->assertNotContains($twin->id, $p2->questions()->pluck('bank_question_id')->all());
    }

    public function test_snapshot_immutable_after_bank_edit_and_delete()
    {
        $q1 = $this->bankMcq($this->teacher(), ['question' => 'Immutable original text']);
        $this->seedBank($this->teacher(), 6);
        $paper = $this->storePaper([], ['count' => 7]);
        $this->actingAs($this->teacher())->post(route('question_papers.finalise', $paper->id));

        $q1->update(['question' => 'Edited after finalise']);
        $someOther = QuestionBankQuestion::where('teacher_id', $this->teacher()->id)
            ->where('id', '!=', $q1->id)->first();
        $someOther->delete();

        $this->actingAs($this->teacher())
            ->get(route('question_papers.show', $paper->id))
            ->assertOk()
            ->assertSee('Immutable original text')
            ->assertDontSee('Edited after finalise');
        $this->assertSame(7, $paper->questions()->count());
    }

    /* ---------------- Mode B: AI gate + queue ---------------- */

    public function test_ai_off_refuses_and_saves_nothing()
    {
        $response = $this->actingAs($this->teacher())->post(route('question_papers.ai_generate'), [
            'my_class_id' => 13, 'subject_id' => 79, 'question_type' => 'mcq', 'count' => 3, 'marks' => 1,
        ]);
        $response->assertSessionHas('flash_danger');
        $this->assertStringContainsString('switched off', session('flash_danger'));
        $this->assertSame(0, AiQuestionSuggestion::count());
    }

    public function test_ai_enabled_without_key_is_honest_no_key_state()
    {
        $this->setAiSetting('ai_enabled', '1'); // key stays ''
        $this->actingAs($this->teacher())
            ->get(route('question_papers.create'))
            ->assertOk()
            ->assertSee('no API key is configured yet');

        $response = $this->actingAs($this->teacher())->post(route('question_papers.ai_generate'), [
            'my_class_id' => 13, 'subject_id' => 79, 'question_type' => 'mcq', 'count' => 3, 'marks' => 1,
        ]);
        $response->assertSessionHas('flash_danger');
        $this->assertStringContainsString('no API key is configured yet', session('flash_danger'));
        $this->assertSame(0, AiQuestionSuggestion::count());
    }

    public function test_ai_fake_success_creates_pending_suggestions()
    {
        $this->enableAiWithKey();
        $this->fakeGemini([
            ['question' => 'AI Q one?', 'options' => ['A1', 'B1', 'C1', 'D1'], 'correct_index' => 2, 'model_answer' => null, 'marks' => 1, 'difficulty' => 'easy'],
            ['question' => 'AI Q two?', 'options' => ['A2', 'B2', 'C2', 'D2'], 'correct_index' => 0, 'model_answer' => null, 'marks' => 1, 'difficulty' => 'hard'],
        ]);
        $response = $this->actingAs($this->teacher())->post(route('question_papers.ai_generate'), [
            'my_class_id' => 13, 'subject_id' => 79, 'question_type' => 'mcq', 'count' => 2, 'marks' => 1, 'chapter' => 'Light',
        ]);
        $response->assertRedirect(route('question_papers.ai_index'));
        $this->assertSame(2, AiQuestionSuggestion::where('status', 'pending')->count());
        $s = AiQuestionSuggestion::first();
        $this->assertSame('gemini-2.5-flash', $s->ai_model);
        $this->assertSame('Light', $s->chapter);
    }

    public function test_ai_partial_json_saves_only_valid_and_reports_counts()
    {
        $this->enableAiWithKey();
        $this->fakeGemini([
            ['question' => 'Valid one?', 'options' => ['A', 'B'], 'correct_index' => 1, 'marks' => 1],
            ['question' => 'Broken one?', 'options' => ['Only'], 'correct_index' => 0, 'marks' => 1],
            ['question' => 'Valid two?', 'options' => ['A', 'B', 'C'], 'correct_index' => 0, 'marks' => 1],
        ]);
        $this->actingAs($this->teacher())->post(route('question_papers.ai_generate'), [
            'my_class_id' => 13, 'subject_id' => 79, 'question_type' => 'mcq', 'count' => 3, 'marks' => 1,
        ])->assertSessionHas('flash_success');
        $this->assertStringContainsString('2 of 3', session('flash_success'));
        $this->assertSame(2, AiQuestionSuggestion::count());
    }

    public function test_ai_all_invalid_saves_nothing()
    {
        $this->enableAiWithKey();
        $this->fakeGemini([
            ['question' => '', 'options' => [], 'correct_index' => 0, 'marks' => 1],
            ['question' => 'No model answer short', 'options' => null, 'correct_index' => null, 'model_answer' => '', 'marks' => 1],
        ]);
        $this->actingAs($this->teacher())->post(route('question_papers.ai_generate'), [
            'my_class_id' => 13, 'subject_id' => 79, 'question_type' => 'mcq', 'count' => 2, 'marks' => 1,
        ])->assertSessionHas('flash_danger');
        $this->assertSame(0, AiQuestionSuggestion::count());
    }

    public function test_ai_transport_failure_saves_nothing_and_meters_error()
    {
        $this->enableAiWithKey();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response('nope', 500)]);
        $this->actingAs($this->teacher())->post(route('question_papers.ai_generate'), [
            'my_class_id' => 13, 'subject_id' => 79, 'question_type' => 'mcq', 'count' => 2, 'marks' => 1,
        ])->assertSessionHas('flash_danger');
        $this->assertStringContainsString('AI is unavailable right now', session('flash_danger'));
        $this->assertSame(0, AiQuestionSuggestion::count());
        $this->assertDatabaseHas('ai_usage', [
            'user_id' => $this->teacher()->id, 'task' => 'question_generate', 'status' => 'error',
        ]);
    }

    public function test_ai_daily_cap_refuses_with_cap_message()
    {
        $this->enableAiWithKey();
        for ($i = 0; $i < 50; $i++) {
            AiUsage::create(['user_id' => $this->teacher()->id, 'task' => 'circular_draft', 'status' => 'ok', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($this->teacher())->post(route('question_papers.ai_generate'), [
            'my_class_id' => 13, 'subject_id' => 79, 'question_type' => 'mcq', 'count' => 2, 'marks' => 1,
        ])->assertSessionHas('flash_danger');
        $this->assertStringContainsString("reached today's AI limit", session('flash_danger'));
        $this->assertSame(0, AiQuestionSuggestion::count());
    }

    protected function makeSuggestion(array $overrides = [])
    {
        return AiQuestionSuggestion::create(array_merge([
            'teacher_id' => $this->teacher()->id,
            'my_class_id' => 13,
            'subject_id' => 79,
            'section_id' => null,
            'question_paper_id' => null,
            'question_type' => 'mcq',
            'question' => 'Pending suggestion question?',
            'options' => ['One', 'Two', 'Three', 'Four'],
            'correct_index' => 0,
            'model_answer' => null,
            'marks' => 1,
            'status' => 'pending',
            'ai_model' => 'gemini-2.5-flash',
        ], $overrides));
    }

    public function test_pending_suggestion_cannot_enter_a_paper()
    {
        $suggestion = $this->makeSuggestion(['question' => 'Sneaky pending text ZZZ']);
        $this->seedBank($this->teacher(), 8);

        // Crafted request: a suggestion id smuggled into store.
        $this->actingAs($this->teacher())->post(route('question_papers.store'),
            $this->specPayload(['suggestion_id' => $suggestion->id, 'ai_question_suggestion_id' => $suggestion->id]));
        $paper = QuestionPaper::latest('id')->first();
        $this->assertNotNull($paper);
        foreach ($paper->questions as $q) {
            $this->assertStringNotContainsString('Sneaky pending text ZZZ', $q->question);
        }
        $this->assertSame('pending', $suggestion->fresh()->status);
    }

    public function test_approve_to_paper_bank_and_both_and_reject_terminal()
    {
        $this->seedBank($this->teacher(), 6);
        $draft = $this->storePaper([], ['count' => 3]);

        // (a) to paper
        $s1 = $this->makeSuggestion(['question' => 'Approved into paper?']);
        $this->actingAs($this->teacher())->post(route('question_papers.ai_approve', $s1->id), [
            'target' => 'paper', 'question_paper_id' => $draft->id,
        ])->assertSessionHas('flash_success');
        $this->assertSame('approved', $s1->fresh()->status);
        $row = QuestionPaperQuestion::where('question_paper_id', $draft->id)->where('source', 'ai')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->bank_question_id);
        $this->assertTrue((bool) $draft->fresh()->spec['ai_assisted']);

        // final paper refuses
        $final = $this->storePaper(['seed' => 'final-seed'], ['count' => 3]);
        $this->actingAs($this->teacher())->post(route('question_papers.finalise', $final->id));
        $s2 = $this->makeSuggestion(['question' => 'Into final paper?']);
        $this->actingAs($this->teacher())->post(route('question_papers.ai_approve', $s2->id), [
            'target' => 'paper', 'question_paper_id' => $final->id,
        ])->assertSessionHas('flash_danger');
        $this->assertSame('pending', $s2->fresh()->status);

        // (b) to bank
        $s3 = $this->makeSuggestion(['question' => 'Approved into bank?']);
        $this->actingAs($this->teacher())->post(route('question_papers.ai_approve', $s3->id), [
            'target' => 'bank',
        ])->assertSessionHas('flash_success');
        $this->assertNotNull($s3->fresh()->bank_question_id);
        $this->assertDatabaseHas('question_bank_questions', [
            'id' => $s3->fresh()->bank_question_id, 'question' => 'Approved into bank?',
        ]);

        // bad marks: bank save refused, stays pending
        $s4 = $this->makeSuggestion(['question' => 'Bad marks suggestion?', 'marks' => 0]);
        $this->actingAs($this->teacher())->post(route('question_papers.ai_approve', $s4->id), [
            'target' => 'bank',
        ])->assertSessionHas('flash_danger');
        $this->assertSame('pending', $s4->fresh()->status);

        // reject is terminal
        $s5 = $this->makeSuggestion(['question' => 'Reject me?']);
        $this->actingAs($this->teacher())->post(route('question_papers.ai_reject', $s5->id));
        $this->assertSame('rejected', $s5->fresh()->status);
        $this->actingAs($this->teacher())->post(route('question_papers.ai_approve', $s5->id), ['target' => 'bank']);
        $this->assertSame('rejected', $s5->fresh()->status);
        $this->assertDatabaseMissing('question_bank_questions', ['question' => 'Reject me?']);
    }

    public function test_foreign_teacher_cannot_touch_suggestions()
    {
        $s = $this->makeSuggestion();
        $this->actingAs($this->otherTeacher())
            ->post(route('question_papers.ai_approve', $s->id), ['target' => 'bank'])
            ->assertForbidden();
        $this->actingAs($this->admin())
            ->get(route('question_papers.ai_index'))
            ->assertForbidden();
    }

    /* ---------------- Output + gates ---------------- */

    protected function makeRichPaper()
    {
        $this->bankMcq($this->teacher(), [
            'question' => 'Capital of France?', 'options' => ['London', 'ParisCorrectOption', 'Berlin', 'Madrid'],
            'correct_index' => 1, 'marks' => 1,
        ]);
        $this->bankTyped($this->teacher(), 'short', [
            'question' => 'Explain rain briefly.', 'model_answer' => 'MODELANSWER-SECRET-XYZ', 'marks' => 3,
        ]);
        $payload = $this->specPayload(['exam_label' => 'Unit Test 9'], []);
        $payload['sections'] = [
            'A' => ['key' => 'A', 'label' => 'Objective', 'type' => 'mcq', 'count' => 1, 'marks_each' => 1],
            'B' => ['key' => 'B', 'label' => 'Short Answer', 'type' => 'short', 'count' => 1, 'marks_each' => 3],
        ];
        $this->actingAs($this->teacher())->post(route('question_papers.store'), $payload);

        return QuestionPaper::latest('id')->first();
    }

    public function test_paper_pdf_and_answer_leak_pin()
    {
        $paper = $this->makeRichPaper();

        $pdf = $this->actingAs($this->teacher())->get(route('question_papers.pdf', $paper->id));
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        // The print host renders the SAME stripped partial: the
        // model answer must not appear anywhere in the paper.
        $print = $this->actingAs($this->teacher())->get(route('question_papers.print', $paper->id));
        $print->assertOk()
            ->assertSee('Hackstra Academy')
            ->assertSee('Unit Test 9')
            ->assertSee('Section A')
            ->assertSee('[1]')
            ->assertDontSee('MODELANSWER-SECRET-XYZ')
            ->assertDontSee('ANSWER KEY');

        $key = $this->actingAs($this->teacher())->get(route('question_papers.answer_key', $paper->id));
        $key->assertOk();
        $this->assertStringStartsWith('%PDF', $key->getContent());
    }

    public function test_answer_key_document_contains_answers()
    {
        $paper = $this->makeRichPaper();
        $doc = [
            'paper' => $paper,
            'sections' => [[
                'key' => 'A', 'label' => 'Objective', 'type' => 'mcq', 'heading_marks' => '1 marks',
                'questions' => [[
                    'question' => 'Capital of France?', 'options' => ['London', 'ParisCorrectOption', 'Berlin', 'Madrid'],
                    'marks' => 1, 'question_type' => 'mcq', 'correct_index' => 1, 'model_answer' => null,
                ]],
            ], [
                'key' => 'B', 'label' => 'Short Answer', 'type' => 'short', 'heading_marks' => '3 marks',
                'questions' => [[
                    'question' => 'Explain rain briefly.', 'options' => null,
                    'marks' => 3, 'question_type' => 'short', 'correct_index' => null, 'model_answer' => 'MODELANSWER-SECRET-XYZ',
                ]],
            ]],
            'school_name' => 'Hackstra Academy', 'logo' => null, 'address' => null, 'phone' => null, 'email' => null,
            'exam_name' => 'Unit Test 9', 'class_name' => 'Class 12', 'section_name' => null,
            'subject_name' => 'English Language', 'total_marks' => 4, 'duration_minutes' => 60,
            'exam_date' => null, 'instructions' => '', 'answer_space_lines' => 0,
        ];
        $html = view('pages.support_team.question_papers.partials.answer_key_document', ['doc' => $doc])->render();
        $this->assertStringContainsString('ANSWER KEY — TEACHER COPY', $html);
        $this->assertStringContainsString('B (ParisCorrectOption)', $html);
        $this->assertStringContainsString('MODELANSWER-SECRET-XYZ', $html);
    }

    public function test_document_gates_foreign_student_parent()
    {
        $paper = $this->makeRichPaper();
        foreach (['question_papers.pdf', 'question_papers.answer_key'] as $routeName) {
            $this->actingAs($this->otherTeacher())->get(route($routeName, $paper->id))->assertForbidden();
            $this->actingAs($this->student())->get(route($routeName, $paper->id))->assertForbidden();
            $this->actingAs($this->parentUser())->get(route($routeName, $paper->id))->assertForbidden();
            $this->actingAs($this->admin())->get(route($routeName, $paper->id))->assertOk();
        }
    }

    public function test_role_matrix()
    {
        $this->seedBank($this->teacher(), 6);
        $paper = $this->storePaper();

        // Admin: read-only.
        $this->actingAs($this->admin())->get(route('question_papers.index'))->assertOk();
        $this->actingAs($this->admin())->get(route('question_papers.show', $paper->id))->assertOk();
        $this->actingAs($this->admin())->get(route('question_papers.create'))->assertForbidden();
        $this->actingAs($this->admin())->post(route('question_papers.store'), $this->specPayload())->assertForbidden();
        $this->actingAs($this->admin())->delete(route('question_papers.destroy', $paper->id))->assertForbidden();
        $this->actingAs($this->admin())->post(route('question_papers.ai_generate'), [
            'my_class_id' => 13, 'subject_id' => 79, 'question_type' => 'mcq', 'count' => 1, 'marks' => 1,
        ])->assertForbidden();

        // Principal: 403 in v1.
        $this->actingAs($this->principal())->get(route('question_papers.index'))->assertForbidden();
        $this->actingAs($this->principal())->get(route('question_papers.show', $paper->id))->assertForbidden();

        // Super admin: not PlatformOnly-listed, so the platform
        // middleware bounces SA away (302) — no surface at all.
        // Student / parent: 403.
        $this->actingAs($this->superAdmin())->get(route('question_papers.index'))->assertStatus(302);
        $this->actingAs($this->student())->get(route('question_papers.index'))->assertForbidden();
        $this->actingAs($this->parentUser())->get(route('question_papers.index'))->assertForbidden();

        // Foreign teacher cannot read another teacher's paper.
        $this->actingAs($this->otherTeacher())->get(route('question_papers.show', $paper->id))->assertForbidden();
    }

    public function test_save_pdf_files_bytes_and_delete_removes_them()
    {
        $this->seedBank($this->teacher(), 6);
        $paper = $this->storePaper();
        $suggestion = $this->makeSuggestion(['question_paper_id' => $paper->id]);

        $this->actingAs($this->teacher())
            ->post(route('question_papers.save_pdf', $paper->id))
            ->assertSessionHas('flash_success');
        $paper->refresh();
        $this->assertNotNull($paper->pdf_path);
        $this->assertTrue(Storage::disk('local')->exists($paper->pdf_path));
        $this->assertTrue(Storage::disk('local')->exists($paper->answer_key_pdf_path));
        $paperPath = $paper->pdf_path;
        $keyPath = $paper->answer_key_pdf_path;

        // Filed download serves bytes.
        $this->actingAs($this->teacher())->get(route('question_papers.pdf', $paper->id))->assertOk();

        $this->actingAs($this->teacher())->delete(route('question_papers.destroy', $paper->id));
        $this->assertFalse(Storage::disk('local')->exists($paperPath));
        $this->assertFalse(Storage::disk('local')->exists($keyPath));
        $this->assertSame(0, QuestionPaperQuestion::where('question_paper_id', $paper->id)->count());
        $this->assertNull($suggestion->fresh()->question_paper_id);
    }

    public function test_routes_use_paper_param_never_id()
    {
        $this->assertSame(url('question-papers/123'), route('question_papers.show', 123));
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            $name = $route->getName();
            if ($name && str_starts_with($name, 'question_papers.')) {
                $this->assertStringNotContainsString('{id}', $route->uri(), $name . ' must not use {id}');
            }
        }
    }

    public function test_regenerate_changes_draw_and_final_is_frozen()
    {
        $this->seedBank($this->teacher(), 12);
        $paper = $this->storePaper([], ['count' => 4]);
        $before = $paper->questions()->pluck('bank_question_id')->all();
        $this->actingAs($this->teacher())->post(route('question_papers.regenerate', $paper->id));
        $paper->refresh();
        $this->assertNotSame($paper->seed, 'seed-fixed-1');
        $this->assertSame(4, $paper->questions()->count());

        $this->actingAs($this->teacher())->post(route('question_papers.finalise', $paper->id));
        $seedAfterFinal = $paper->fresh()->seed;
        $this->actingAs($this->teacher())
            ->post(route('question_papers.regenerate', $paper->id))
            ->assertSessionHas('flash_danger');
        $this->assertSame($seedAfterFinal, $paper->fresh()->seed);
    }
}
