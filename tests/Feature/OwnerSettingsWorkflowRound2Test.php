<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Book;
use App\Models\BookRequest;
use App\Models\LibraryFine;
use App\Models\MyClass;
use App\Models\RagChunk;
use App\Models\RagDocument;
use App\Models\Setting;
use App\Models\Subject;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\Support\LibraryFines;
use App\Support\SectionTeacher;
use App\Support\Staffing;
use App\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Agent G round 2 — settings-to-consumer workflows beyond branding
 * (round 1 covered branding). Patterns: P16 (settings mass-write,
 * now whitelisted), P6 (role vs middleware), P4 (label promises more
 * than the control does — the day-cap finding below).
 *
 * All tests run on a snapshot DB (DatabaseTransactions); nothing here
 * touches the shared DB's real settings.
 */
class OwnerSettingsWorkflowRound2Test extends TestCase
{
    use DatabaseTransactions;

    private function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    private function librarian()
    {
        return User::where('email', 'librarian@hackstra.app')->firstOrFail();
    }

    private function setting(string $type): ?string
    {
        $row = Setting::where('type', $type)->first();

        return $row ? $row->description : null;
    }

    private function basePayload(): array
    {
        return [
            'system_name' => $this->setting('system_name') ?? 'Hackstra Academy',
            'current_session' => $this->setting('current_session') ?? '2026-2027',
            'address' => $this->setting('address') ?? 'Madurai, Tamil Nadu, India',
        ];
    }

    private function putSettings(array $overrides = [])
    {
        return $this->actingAs($this->admin())
            ->from(route('settings'))
            ->put(route('settings.update'), array_merge($this->basePayload(), $overrides));
    }

    // ---------- Report-card consumers: term_begins + next-term fee ----------

    public function test_report_card_renders_new_term_begins_and_prep_fee()
    {
        // Prep's class_type code is PN, so the Prep report card reads
        // next_term_fees_pn (seed 25000). Both values go through the
        // REAL settings save, not a direct DB write.
        $this->putSettings([
            'term_begins' => '3/4/2026',
            'next_term_fees_pn' => '77777',
        ])->assertRedirect(route('settings'));
        $this->assertSame('3/4/2026', $this->setting('term_begins'));
        $this->assertSame('77777', $this->setting('next_term_fees_pn'));

        $hash = Qs::hash(57); // Aarav Sharma, Prep, student user id 57
        $res = $this->actingAs($this->admin())->get("/marks/print/{$hash}/1/2026-2027");
        $res->assertOk();
        $content = $res->getContent();
        // strtotime('3/4/2026') is m/d/Y: Wednesday 4th March 2026.
        $this->assertStringContainsString('Wednesday, 4th March, 2026', $content);
        $this->assertStringContainsString('77777', $content);
        // The OLD seeded term_begins ('1/6/2026' -> 6th January) is gone.
        $this->assertStringNotContainsString('Tuesday, 6th January, 2026', $content);
    }

    public function test_term_ends_still_dead_no_consumer_renders_it()
    {
        // Code-read (grep, whole app + views): term_ends appears ONLY
        // in the settings whitelist, the settings form, and this test.
        // Zero readers. The save below proves it persists yet renders
        // nowhere on the report card that renders its sibling key.
        $this->putSettings(['term_ends' => 'G2-TERM-ENDS-DISTINCT'])
            ->assertRedirect(route('settings'));
        $this->assertSame('G2-TERM-ENDS-DISTINCT', $this->setting('term_ends'));
        $html = $this->actingAs($this->admin())->get(route('settings'))->assertOk()->getContent();
        $this->assertStringContainsString('G2-TERM-ENDS-DISTINCT', $html);

        $hash = Qs::hash(57);
        $res = $this->actingAs($this->admin())->get("/marks/print/{$hash}/1/2026-2027");
        $res->assertOk();
        $this->assertStringNotContainsString('G2-TERM-ENDS-DISTINCT', $res->getContent());
    }

    // ---------- Teaching-load settings: Staffing vs generator ----------

    public function test_teaching_load_defaults_before_any_save()
    {
        // Neither key exists in the seeded settings table, so the
        // documented defaults apply: 8/day, 40/week, fine ₹10/day.
        $this->assertNull($this->setting('teacher_max_periods_day'));
        $this->assertNull($this->setting('library_fine_per_day'));
        $this->assertSame(8, Staffing::maxPeriodsPerDay());
        $this->assertSame(40, Staffing::maxPeriodsPerWeek());
        $this->assertSame(10, LibraryFines::dailyRate());
    }

    public function test_day_cap_save_flows_into_staffing_report()
    {
        $this->putSettings(['teacher_max_periods_day' => 3, 'teacher_max_periods_week' => 15])
            ->assertRedirect(route('settings'));
        $this->assertSame(3, Staffing::maxPeriodsPerDay());
        $this->assertSame(15, Staffing::maxPeriodsPerWeek());
        $report = Staffing::report();
        $this->assertSame(3, $report['totals']['cap_day']);
        $this->assertSame(15, $report['totals']['cap_week']);
    }

    public function test_settings_day_cap_does_not_bind_the_generator()
    {
        // FINDING (P4 shape, label vs control): the settings label
        // "Max Periods per Teacher — Day" reads as a school-wide
        // teaching-load cap, but the generator never reads it — its
        // cap comes only from the per-run form field
        // max_teacher_periods_per_day (TimeTableController:735).
        // Proven: settings day cap = 1, generator run WITHOUT the
        // form cap still packs a teacher 2+ times in one day.
        $this->putSettings(['teacher_max_periods_day' => 1])
            ->assertRedirect(route('settings'));
        $this->assertSame(1, Staffing::maxPeriodsPerDay());

        $class = MyClass::create(['name' => 'G2 Cap Class']);
        $t1 = User::create(['name' => 'G2 Cap Teacher One', 'email' => 'g2cap-t1@test.local', 'password' => bcrypt('teacher'), 'user_type' => 'teacher', 'code' => 'G2CAPT1']);
        $t2 = User::create(['name' => 'G2 Cap Teacher Two', 'email' => 'g2cap-t2@test.local', 'password' => bcrypt('teacher'), 'user_type' => 'teacher', 'code' => 'G2CAPT2']);
        Subject::create(['name' => 'G2 Cap Subject A', 'slug' => 'g2cap-a', 'my_class_id' => $class->id, 'teacher_id' => $t1->id, 'periods_per_week' => 6]);
        Subject::create(['name' => 'G2 Cap Subject B', 'slug' => 'g2cap-b', 'my_class_id' => $class->id, 'teacher_id' => $t2->id, 'periods_per_week' => 6]);

        $response = $this->actingAs($this->admin())
            ->post(route('tt.generate'), [
                'start_time' => '09:00',
                'period_minutes' => 40,
                'periods_per_day' => 8,
                'lunch_after' => 4,
                'lunch_minutes' => 45,
                'break_after' => 2,
                'break_minutes' => 15,
                'days' => 5,
                'scope' => 'one',
                'class_id' => $class->id,
                'mode' => 'replace',
                // Deliberately NO max_teacher_periods_per_day: the
                // settings day cap of 1 must NOT bind if the control
                // is truly per-run only.
            ]);
        $response->assertRedirect(route('tt.index'));
        $record = TimeTableRecord::where('my_class_id', $class->id)->whereNull('exam_id')->firstOrFail();
        $cells = TimeTable::where('ttr_id', $record->id)->get();
        $this->assertSame(12, $cells->count());
        $perTeacherDay = [];
        foreach ($cells as $cell) {
            $teacherId = SectionTeacher::teacherIdFor(null, $cell->subject);
            $key = $teacherId . ':' . $cell->day_num;
            $perTeacherDay[$key] = ($perTeacherDay[$key] ?? 0) + 1;
        }
        $this->assertGreaterThan(1, max($perTeacherDay));
    }

    // ---------- Library fine rate: settings -> late-return fine ----------

    public function test_library_fine_rate_flows_into_late_return_fine()
    {
        $this->putSettings(['library_fine_per_day' => 25])
            ->assertRedirect(route('settings'));
        $this->assertSame(25, LibraryFines::dailyRate());

        $book = Book::create([
            'name' => 'G2 Fine Book', 'author' => 'G2', 'book_type' => 'Book',
            'total_copies' => 2, 'issued_copies' => 1,
        ]);
        $daysLate = 4;
        $loan = BookRequest::create([
            'book_id' => $book->id, 'user_id' => 57,
            'start_date' => Carbon::today()->subDays(10)->format('Y-m-d'),
            'end_date' => Carbon::today()->subDays($daysLate)->format('Y-m-d'),
            'returned' => 0, 'status' => BookRequest::STATUS_ISSUED,
        ]);
        $this->actingAs($this->librarian())->from(route('book_requests.index'))
            ->patch(route('book_requests.return', Qs::hash($loan->id)))
            ->assertRedirect(route('book_requests.index'));
        $this->assertEquals(1, (int) $loan->fresh()->returned);
        $fine = LibraryFine::where('book_request_id', $loan->id)->firstOrFail();
        $this->assertSame(100, (int) $fine->amount); // 4 days x ₹25
        $this->assertSame($daysLate, (int) $fine->days_overdue);
        // Student borrower: the fine mirrors into the fee ledger.
        $this->assertNotNull($fine->payment_record_id);
    }

    public function test_library_fines_index_shows_new_rate()
    {
        $this->putSettings(['library_fine_per_day' => 33])
            ->assertRedirect(route('settings'));
        $res = $this->actingAs($this->librarian())->get(route('library_fines.index'));
        $res->assertOk();
        $this->assertSame(33, $res->viewData('rate'));
    }

    // ---------- Knowledge Base live store path (r1 micro-item) ----------

    public function test_knowledge_store_creates_doc_and_mock_index_chunks()
    {
        // Default config has no ai_api_key pasted, so EmbeddingClient
        // picks the deterministic mock transport (no network call).
        // Store therefore runs the full rebuild in-process: the row
        // exists AND its chunks exist with a mock-* embedding model.
        $this->assertEmpty($this->setting('ai_api_key'));
        $this->actingAs($this->admin())->from(route('settings'))
            ->post(route('settings.knowledge.store'), [
                'title' => 'G2 Policy Doc',
                'body' => 'G2 policy text about library hours and late returns.',
                'is_public' => false,
            ])->assertRedirect(route('settings'))
            ->assertSessionHas('flash_success');
        $flash = session('flash_success');
        $this->assertStringContainsString('Knowledge document saved.', $flash);
        $this->assertStringContainsString('Knowledge index rebuilt:', $flash);

        $doc = RagDocument::where('title', 'G2 Policy Doc')->firstOrFail();
        $this->assertSame('policy_text', $doc->source_type);
        $chunks = RagChunk::where('rag_document_id', $doc->id)->get();
        $this->assertGreaterThanOrEqual(1, $chunks->count());
        foreach ($chunks as $chunk) {
            $this->assertStringStartsWith('mock-hash-', (string) $chunk->embedding_model);
        }
    }

    public function test_knowledge_store_validation_refuses_empty_body()
    {
        $this->actingAs($this->admin())->from(route('settings'))
            ->post(route('settings.knowledge.store'), [
                'title' => 'G2 Empty Doc',
                'body' => '',
            ])->assertSessionHasErrors('body');
        $this->assertDatabaseMissing('rag_documents', ['title' => 'G2 Empty Doc']);
    }
}
