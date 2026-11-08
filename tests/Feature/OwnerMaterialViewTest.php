<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\StudentDocument;
use App\Models\StudentRecord;
use App\Models\StudyMaterial;
use App\Models\Subject;
use App\Models\Substitution;
use App\Models\TimeTable;
use App\Support\TeacherScope;
use App\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * R3 LMS Phase 1 (Item 5) — inline PDF/image viewing beside the
 * untouched downloads, plus the rebuilt LMS table markers.
 *
 * Users are resolved by EMAIL / record queries, never hardcoded
 * ids. Seed anchors: teacher@hackstra.app owns Prep English
 * (class 1, subject 1); student@hackstra.app is class 1 section 1
 * with parent parent@hackstra.app.
 */
class OwnerMaterialViewTest extends TestCase
{
    use DatabaseTransactions;

    protected $pdfContent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdfContent = "%PDF-1.4 view probe\n" . str_repeat('0123456789', 60);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    protected function userByEmail(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    protected function ownerTeacher(): User
    {
        return $this->userByEmail('teacher@hackstra.app');
    }

    /** A teacher who can teach class 1 but does not own the fixture. */
    protected function classTeacher(): User
    {
        $owner = $this->ownerTeacher();
        return User::where('user_type', 'teacher')->get()
            ->first(function ($t) use ($owner) {
                return $t->id !== $owner->id && TeacherScope::canTeachClass($t, 1);
            });
    }

    /** A teacher with no class-1 scope at all. */
    protected function otherTeacher(): User
    {
        $owner = $this->ownerTeacher();
        return User::where('user_type', 'teacher')->get()
            ->first(function ($t) use ($owner) {
                return $t->id !== $owner->id && !TeacherScope::canTeachClass($t, 1);
            });
    }

    protected function studentInSection(int $sectionId): User
    {
        $sr = StudentRecord::where('my_class_id', 1)->where('section_id', $sectionId)
            ->where('grad', 0)->firstOrFail();
        return User::findOrFail($sr->user_id);
    }

    protected function otherClassStudent(): User
    {
        $sr = StudentRecord::where('my_class_id', '!=', 1)->where('grad', 0)->firstOrFail();
        return User::findOrFail($sr->user_id);
    }

    protected function makeMaterial(array $overrides = []): StudyMaterial
    {
        // Only the default path is auto-written; a caller-supplied
        // file_path is written by the caller (or deliberately NOT
        // written, for the missing-file case).
        $path = $overrides['file_path'] ?? 'materials/probe-' . uniqid() . '.pdf';
        if (!array_key_exists('file_path', $overrides)) {
            Storage::disk('public')->put($path, $this->pdfContent);
        }
        return StudyMaterial::create(array_merge([
            'teacher_id' => $this->ownerTeacher()->id,
            'my_class_id' => 1,
            'section_id' => null,
            'subject_id' => 1,
            'title' => 'View Probe Material',
            'description' => 'probe',
            'file_path' => $path,
            'file_hash' => md5($this->pdfContent . uniqid()),
            'link_url' => null,
        ], $overrides));
    }

    /* ---------------- Material view: headers + range ---------------- */

    public function test_material_view_serves_pdf_inline_with_range_support()
    {
        Storage::fake('public');
        $material = $this->makeMaterial();

        $response = $this->actingAs($this->ownerTeacher())->get(route('materials.view', $material->id));
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('bytes', $response->headers->get('Accept-Ranges'));
    }

    public function test_material_view_answers_range_request_with_206_and_exact_bytes()
    {
        Storage::fake('public');
        $material = $this->makeMaterial();

        $response = $this->actingAs($this->ownerTeacher())->call(
            'GET', route('materials.view', $material->id), [], [], [], ['HTTP_RANGE' => 'bytes=0-99']
        );
        $response->assertStatus(206);
        $this->assertSame('bytes 0-99/' . strlen($this->pdfContent), $response->headers->get('Content-Range'));
        ob_start();
        $response->baseResponse->sendContent();
        $bytes = ob_get_clean();
        $this->assertSame(100, strlen($bytes));
        $this->assertSame(substr($this->pdfContent, 0, 100), $bytes);
    }

    public function test_material_download_is_still_an_attachment()
    {
        Storage::fake('public');
        $material = $this->makeMaterial();

        $response = $this->actingAs($this->ownerTeacher())->get(route('materials.download', $material->id));
        $response->assertOk();
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
    }

    /* ---------------- Material view: gate parity ---------------- */

    public function test_material_view_gate_matches_download_per_persona()
    {
        Storage::fake('public');
        $wholeClass = $this->makeMaterial();
        $sectionOne = $this->makeMaterial(['section_id' => 1]);

        $personas = [
            'owning teacher' => [$this->ownerTeacher(), $wholeClass, 200],
            'class teacher' => [$this->classTeacher(), $wholeClass, 200],
            'other-class teacher' => [$this->otherTeacher(), $wholeClass, 403],
            'student in class+section' => [$this->userByEmail('student@hackstra.app'), $sectionOne, 200],
            'student wrong section' => [$this->studentInSection(2), $sectionOne, 403],
            'student other class' => [$this->otherClassStudent(), $wholeClass, 403],
            'parent of enrolled' => [$this->userByEmail('parent@hackstra.app'), $wholeClass, 200],
            'admin' => [$this->userByEmail('admin@hackstra.app'), $wholeClass, 200],
        ];

        foreach ($personas as $label => [$user, $material, $expected]) {
            $view = $this->actingAs($user)->get(route('materials.view', $material->id));
            $this->assertSame($expected, $view->getStatusCode(), "view status for {$label}");
            $download = $this->actingAs($user)->get(route('materials.download', $material->id));
            $this->assertSame($expected, $download->getStatusCode(), "download status for {$label}");
        }

        // Super Admin: the portal guard redirects SA to the
        // dashboard for school-portal routes in this harness — for
        // download exactly as for view (verified pre-existing
        // behaviour on materials.index/download). The pin is
        // parity: view must never differ from download for SA.
        $sa = $this->userByEmail('superadmin@hackstra.app');
        $saView = $this->actingAs($sa)->get(route('materials.view', $wholeClass->id));
        $saDownload = $this->actingAs($sa)->get(route('materials.download', $wholeClass->id));
        $this->assertSame($saDownload->getStatusCode(), $saView->getStatusCode(), 'SA view/download parity');
    }

    public function test_material_view_parent_of_other_class_is_refused()
    {
        Storage::fake('public');
        $subject2 = Subject::where('my_class_id', 2)->firstOrFail();
        $material = $this->makeMaterial(['my_class_id' => 2, 'subject_id' => $subject2->id]);

        $this->actingAs($this->userByEmail('parent@hackstra.app'))
            ->get(route('materials.view', $material->id))->assertForbidden();
        $this->actingAs($this->userByEmail('parent@hackstra.app'))
            ->get(route('materials.download', $material->id))->assertForbidden();
    }

    public function test_material_view_guest_is_sent_to_login()
    {
        Storage::fake('public');
        $material = $this->makeMaterial();
        $this->get(route('materials.view', $material->id))->assertRedirect(route('login'));
    }

    /* ---------------- Substitute matrix (frozen time) ---------------- */

    protected function makeSubstitution(User $substitute, int $subjectId, string $status, Carbon $date): Substitution
    {
        $tt = TimeTable::where('subject_id', $subjectId)->with('tt_record')->get()
            ->first(function ($t) { return optional($t->tt_record)->my_class_id === 1; });
        $this->assertNotNull($tt, 'timetable cell needed for substitution fixture');
        return Substitution::create([
            'time_table_id' => $tt->id,
            'date' => $date->toDateString(),
            'absent_teacher_id' => $this->ownerTeacher()->id,
            'substitute_teacher_id' => $substitute->id,
            'created_by' => $this->userByEmail('admin@hackstra.app')->id,
            'reason' => 'View probe cover',
            'status' => $status,
        ]);
    }

    public function test_material_view_substitute_matrix()
    {
        Storage::fake('public');
        Carbon::setTestNow(Carbon::today()->setTime(10, 0));
        $material = $this->makeMaterial();
        $sub = $this->otherTeacher();

        // Confirmed today, same class+subject -> 200.
        $this->makeSubstitution($sub, 1, 'confirmed', Carbon::today());
        $this->actingAs($sub)->get(route('materials.view', $material->id))->assertOk();
        Substitution::query()->delete();

        // Confirmed tomorrow only -> 403 today.
        $this->makeSubstitution($sub, 1, 'confirmed', Carbon::tomorrow());
        $this->actingAs($sub)->get(route('materials.view', $material->id))->assertForbidden();
        Substitution::query()->delete();

        // Pending today -> 403.
        $this->makeSubstitution($sub, 1, 'pending', Carbon::today());
        $this->actingAs($sub)->get(route('materials.view', $material->id))->assertForbidden();
        Substitution::query()->delete();

        // Confirmed today, different subject -> 403.
        $this->makeSubstitution($sub, 2, 'confirmed', Carbon::today());
        $this->actingAs($sub)->get(route('materials.view', $material->id))->assertForbidden();
    }

    /* ---------------- Non-viewable / link / missing / prefix ---------------- */

    public function test_material_view_refuses_docx_but_download_still_works()
    {
        Storage::fake('public');
        $path = 'materials/probe-doc.docx';
        Storage::disk('public')->put($path, 'docx probe bytes');
        $material = $this->makeMaterial(['file_path' => $path]);

        $this->actingAs($this->ownerTeacher())->get(route('materials.view', $material->id))
            ->assertRedirect()->assertSessionHas('flash_danger');
        $this->actingAs($this->ownerTeacher())->get(route('materials.download', $material->id))->assertOk();
    }

    public function test_material_view_refuses_link_only_and_missing_and_outside_prefix()
    {
        Storage::fake('public');
        $link = $this->makeMaterial(['file_path' => null, 'link_url' => 'https://example.com/notes']);
        $this->actingAs($this->ownerTeacher())->get(route('materials.view', $link->id))
            ->assertRedirect()->assertSessionHas('flash_danger');

        $missing = $this->makeMaterial(['file_path' => 'materials/never-written.pdf']);
        $this->actingAs($this->ownerTeacher())->get(route('materials.view', $missing->id))
            ->assertRedirect()->assertSessionHas('flash_danger');

        Storage::disk('public')->put('other/evil.pdf', $this->pdfContent);
        $outside = $this->makeMaterial(['file_path' => 'other/evil.pdf']);
        $this->actingAs($this->ownerTeacher())->get(route('materials.view', $outside->id))
            ->assertRedirect()->assertSessionHas('flash_danger');
    }

    public function test_material_view_serves_images_inline()
    {
        Storage::fake('public');
        $path = 'materials/probe-img.png';
        Storage::disk('public')->put($path, 'png probe bytes');
        $material = $this->makeMaterial(['file_path' => $path]);

        $response = $this->actingAs($this->ownerTeacher())->get(route('materials.view', $material->id));
        $response->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
    }

    /* ---------------- Viewer page + index blades ---------------- */

    public function test_viewer_page_embeds_the_view_route_and_keeps_download()
    {
        Storage::fake('public');
        $material = $this->makeMaterial();

        $response = $this->actingAs($this->ownerTeacher())->get(route('materials.viewer', $material->id));
        $response->assertOk();
        $response->assertSee('src="' . route('materials.view', $material->id) . '"', false);
        $response->assertSee(route('materials.download', $material->id), false);
        $response->assertSee('Download');
    }

    public function test_materials_index_shows_read_only_on_viewable_file_rows()
    {
        Storage::fake('public');
        $pdf = $this->makeMaterial(['title' => 'Index PDF Probe']);
        $path = 'materials/index-doc.docx';
        Storage::disk('public')->put($path, 'docx bytes');
        $docx = $this->makeMaterial(['title' => 'Index DOCX Probe', 'file_path' => $path]);
        $link = $this->makeMaterial(['title' => 'Index Link Probe', 'file_path' => null, 'link_url' => 'https://example.com/x']);

        $response = $this->actingAs($this->ownerTeacher())->get(route('materials.index'));
        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString(route('materials.viewer', $pdf->id), $html);
        $this->assertStringContainsString('Read', $html);
        $this->assertStringNotContainsString(route('materials.viewer', $docx->id), $html);
        $this->assertStringNotContainsString(route('materials.viewer', $link->id), $html);
        // Download stays first-class on file rows; no raw storage URLs.
        $this->assertStringContainsString(route('materials.download', $pdf->id), $html);
        $this->assertStringNotContainsString('/storage/materials/', $html);
        // Table-isation markers (P8). Re-expressed 2026-11-08
        // (Inside-Matter Job 2): the private lms-table skin was
        // retired for the house hk-std standard with a colgroup;
        // the phone stack (l108-stack) contract is unchanged.
        $this->assertStringContainsString('hk-std hk-std-880', $html);
        $this->assertStringContainsString('<colgroup>', $html);
        $this->assertStringContainsString('l108-stack', $html);
        $this->assertStringContainsString('data-label="Title"', $html);
        $this->assertStringContainsString('data-label="Actions"', $html);
    }

    /* ---------------- Assignments: question + submission views ---------------- */

    protected function makeAssignmentWithFiles(): array
    {
        Storage::disk('public')->put('assignments/q-probe.pdf', $this->pdfContent);
        $assignment = Assignment::create([
            'teacher_id' => $this->ownerTeacher()->id,
            'my_class_id' => 1,
            'section_id' => null,
            'subject_id' => 1,
            'title' => 'View Probe Assignment',
            'description' => 'probe',
            'attachment_path' => 'assignments/q-probe.pdf',
            'max_marks' => 10,
            'due_at' => now()->addDays(2),
            'allow_late' => false,
        ]);
        Storage::disk('local')->put('submissions/' . $assignment->id . '/s-probe.pdf', $this->pdfContent);
        $submission = AssignmentSubmission::create([
            'assignment_id' => $assignment->id,
            'student_id' => $this->userByEmail('student@hackstra.app')->id,
            'attachment_path' => 'submissions/' . $assignment->id . '/s-probe.pdf',
            'text_answer' => null,
            'submitted_at' => now(),
            'is_late' => false,
        ]);
        return [$assignment, $submission];
    }

    public function test_assignment_question_view_gates()
    {
        Storage::fake('public');
        Storage::fake('local');
        [$assignment] = $this->makeAssignmentWithFiles();

        $ok = $this->actingAs($this->userByEmail('student@hackstra.app'))->get(route('assignments.view', $assignment->id));
        $ok->assertOk();
        $this->assertStringContainsString('inline', (string) $ok->headers->get('Content-Disposition'));

        $this->actingAs($this->otherClassStudent())->get(route('assignments.view', $assignment->id))->assertForbidden();
        $this->actingAs($this->ownerTeacher())->get(route('assignments.view', $assignment->id))->assertOk();
        // A class teacher who does not own the assignment cannot view it.
        $this->actingAs($this->classTeacher())->get(route('assignments.view', $assignment->id))->assertForbidden();
    }

    public function test_assignment_submission_view_gates()
    {
        Storage::fake('public');
        Storage::fake('local');
        [$assignment, $submission] = $this->makeAssignmentWithFiles();
        $url = route('assignments.view', [$assignment->id, 'submission' => $submission->id]);

        $this->actingAs($this->userByEmail('student@hackstra.app'))->get($url)->assertOk();
        $this->actingAs($this->otherClassStudent())->get($url)->assertForbidden();
        $this->actingAs($this->studentInSection(2))->get($url)->assertForbidden();
        $this->actingAs($this->ownerTeacher())->get($url)->assertOk();
        $this->actingAs($this->classTeacher())->get($url)->assertForbidden();
        $this->actingAs($this->userByEmail('parent@hackstra.app'))->get($url)->assertOk();
        // SA: portal-guard parity with download (see material matrix).
        $sa = $this->userByEmail('superadmin@hackstra.app');
        $saView = $this->actingAs($sa)->get($url);
        $saDownload = $this->actingAs($sa)->get(route('assignments.download', [$assignment->id, 'submission' => $submission->id]));
        $this->assertSame($saDownload->getStatusCode(), $saView->getStatusCode(), 'SA submission view/download parity');
    }

    public function test_assignment_show_blades_offer_read_beside_download()
    {
        Storage::fake('public');
        Storage::fake('local');
        [$assignment, $submission] = $this->makeAssignmentWithFiles();

        $teacherView = $this->actingAs($this->ownerTeacher())->get(route('assignments.show', $assignment->id));
        $teacherView->assertOk();
        $teacherView->assertSee(route('assignments.view', [$assignment->id, 'submission' => $submission->id]), false);
        $teacherView->assertSee(route('assignments.download', [$assignment->id, 'submission' => $submission->id]), false);
        $this->assertStringNotContainsString('/storage/', $teacherView->getContent());

        $studentView = $this->actingAs($this->userByEmail('student@hackstra.app'))->get(route('assignments.show', $assignment->id));
        $studentView->assertOk();
        $studentView->assertSee(route('assignments.view', $assignment->id), false);
        $studentView->assertSee(route('assignments.download', $assignment->id), false);
    }

    /* ---------------- Documents: view ---------------- */

    public function test_document_view_serves_pdf_inline_and_keeps_download()
    {
        Storage::fake('public');
        $path = 'documents/vault-probe.pdf';
        Storage::disk('public')->put($path, $this->pdfContent);
        $sr = StudentRecord::where('user_id', $this->userByEmail('student@hackstra.app')->id)->firstOrFail();
        $doc = StudentDocument::create([
            'student_record_id' => $sr->id,
            'uploaded_by' => $this->userByEmail('admin@hackstra.app')->id,
            'title' => 'Vault View Probe',
            'original_name' => 'vault-probe.pdf',
            'stored_path' => $path,
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($this->pdfContent),
        ]);

        $response = $this->actingAs($this->userByEmail('student@hackstra.app'))->get(route('documents.view', $doc->id));
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));

        $this->actingAs($this->otherTeacher())->get(route('documents.view', $doc->id))->assertForbidden();

        $index = $this->actingAs($this->userByEmail('admin@hackstra.app'))->get(route('documents.index'));
        $index->assertOk();
        $index->assertSee(route('documents.view', $doc->id), false);
        $index->assertSee(route('documents.download', $doc->id), false);
        $index->assertSee('l108-stack', false);
        $this->assertStringNotContainsString('/storage/documents/', $index->getContent());
    }

    /* ---------------- Rebuilt-page table markers ---------------- */

    // Renamed + re-expressed 2026-11-08 (Inside-Matter Job 2): the
    // quizzes list retired its private lms-table skin for the house
    // hk-std standard (hk-std-1080 + colgroup); the phone stack
    // (l108-stack) contract is unchanged.
    public function test_quizzes_index_uses_the_hk_std_table_standard()
    {
        $response = $this->actingAs($this->ownerTeacher())->get(route('quizzes.index'));
        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('hk-std hk-std-1080', $html);
        $this->assertStringContainsString('<colgroup>', $html);
        $this->assertStringContainsString('l108-stack', $html);
        $this->assertStringNotContainsString('alert alert-success', $html, 'duplicate inline flash alerts must stay removed');
    }

    public function test_quizzes_index_renders_status_chips_and_filters()
    {
        $teacher = $this->ownerTeacher();
        \App\Models\Quiz::create([
            'teacher_id' => $teacher->id, 'my_class_id' => 1, 'section_id' => null,
            'subject_id' => 1, 'title' => 'Chip Probe Published', 'status' => 'published',
        ]);
        \App\Models\Quiz::create([
            'teacher_id' => $teacher->id, 'my_class_id' => 1, 'section_id' => null,
            'subject_id' => 1, 'title' => 'Chip Probe Draft', 'status' => 'draft',
        ]);

        $response = $this->actingAs($teacher)->get(route('quizzes.index'));
        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('lms-chip', $html);
        $this->assertStringContainsString('Published', $html);
        $this->assertStringContainsString('Draft', $html);
        $this->assertStringContainsString('data-label="Status"', $html);

        // Status filter narrows the listing server-side.
        $draftOnly = $this->actingAs($teacher)->get(route('quizzes.index', ['status' => 'draft']));
        $draftOnly->assertOk();
        $this->assertStringContainsString('Chip Probe Draft', $draftOnly->getContent());
        $this->assertStringNotContainsString('Chip Probe Published', $draftOnly->getContent());

        // Student result column header appears only for students.
        $student = $this->actingAs($this->userByEmail('student@hackstra.app'))->get(route('quizzes.index'));
        $student->assertOk();
        $this->assertStringContainsString('Your Result', $student->getContent());
    }

    public function test_assignments_index_is_one_stacked_table_without_grid()
    {
        $response = $this->actingAs($this->ownerTeacher())->get(route('assignments.index'));
        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('l108-stack', $html);
        $this->assertStringContainsString('data-label="Submissions"', $html);
        $this->assertStringNotContainsString('lms82-grid', $html);
        $this->assertStringNotContainsString('data-lms82-view', $html);
    }

    public function test_lesson_plans_index_renders_progress_as_a_table()
    {
        // The seed carries no lesson plans; create one so both
        // tables on the page render.
        \App\Models\LessonPlan::create([
            'teacher_id' => $this->ownerTeacher()->id,
            'my_class_id' => 1,
            'section_id' => null,
            'subject_id' => 1,
            'week_start' => '2026-11-02',
            'topic' => 'View Probe Topic',
            'notes' => 'probe',
        ]);
        $response = $this->actingAs($this->userByEmail('admin@hackstra.app'))->get(route('lesson_plans.index'));
        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('l108-stack', $html);
        $this->assertStringContainsString('data-label="Progress"', $html);
        $this->assertStringContainsString('progress-bar', $html);
    }
}
