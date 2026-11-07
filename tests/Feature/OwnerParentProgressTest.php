<?php

namespace Tests\Feature;

use App\Helpers\Money;
use App\Helpers\Qs;
use App\Models\AssessmentPeriod;
use App\Models\AttendanceSession;
use App\Models\AttendanceSessionEntry;
use App\Models\Exam;
use App\Models\ExamRecord;
use App\Models\ExamSubjectComponent;
use App\Models\Grade;
use App\Models\Mark;
use App\Models\MyClass;
use App\Models\PaymentRecord;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Repositories\MarkRepo;
use App\Support\FeeDefaulter;
use App\Support\MarkScheme;
use App\Support\ReportCard;
use App\Support\StudentProgress;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * F2 — Parent Progress (design §6, T1–T21).
 *
 * Fixtures resolve Kabir Anand's children through my_parent_id
 * (design §1.1 — never hardcoded record ids beyond resolving the
 * parent by email) and users by email. Seeded anchors relied on:
 * exam 1 (Term 1 Examination, period Term 1, unpublished) with
 * marks for both children at scheme max 100 per subject; Aarav
 * owes ₹0, Lakshmi owes (record 2 pattern) — both facts are
 * asserted from FeeDefaulter itself, not hardcoded amounts,
 * except where the design sanctions the anchor.
 */
class OwnerParentProgressTest extends TestCase
{
    use DatabaseTransactions;

    protected function parent(): User
    {
        return User::where('email', 'parent@hackstra.app')->firstOrFail();
    }

    protected function userByEmail(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    /** Kabir's children, oldest record first (Aarav, then Lakshmi). */
    protected function children()
    {
        return StudentRecord::with(['user', 'my_class', 'section'])
            ->where('my_parent_id', $this->parent()->id)
            ->orderBy('id')->get();
    }

    protected function schoolSession(): string
    {
        return Qs::getCurrentSession();
    }

    protected function publishExam1(): Exam
    {
        $exam = Exam::findOrFail(1);
        $exam->update(['results_published' => true]);
        return $exam->fresh();
    }

    protected function makeExam(array $attrs = []): Exam
    {
        return Exam::create(array_merge([
            'name' => 'F2 Test Exam',
            'term' => 2,
            'year' => $this->schoolSession(),
            'results_published' => true,
            'my_class_id' => 0,
            'period_id' => null,
        ], $attrs));
    }

    protected function makeMark(Exam $exam, StudentRecord $child, int $subjectId, $tex, ?int $gradeId = null): Mark
    {
        $texCol = 'tex' . $exam->term;
        return Mark::create([
            'student_id' => $child->user_id,
            'subject_id' => $subjectId,
            'my_class_id' => $child->my_class_id,
            'section_id' => $child->section_id,
            'exam_id' => $exam->id,
            'year' => $this->schoolSession(),
            $texCol => $tex,
            'grade_id' => $gradeId,
        ]);
    }

    protected function classSubjects(StudentRecord $child)
    {
        return Subject::where('my_class_id', $child->my_class_id)->orderBy('id')->get();
    }

    protected function progressFor($records)
    {
        return StudentProgress::forChildren(collect($records), $this->schoolSession());
    }

    protected function makeParentWithChild(string $tag): array
    {
        $parent = User::create([
            'name' => 'F2 Parent ' . $tag,
            'email' => 'f2-parent-' . $tag . '@hackstra.app',
            'password' => bcrypt('password'),
            'user_type' => 'parent',
            'code' => 'F2P' . $tag,
        ]);
        $studentUser = User::create([
            'name' => 'F2 Child ' . $tag,
            'email' => 'f2-child-' . $tag . '@hackstra.app',
            'password' => bcrypt('password'),
            'user_type' => 'student',
            'code' => 'F2C' . $tag,
        ]);
        $class = MyClass::findOrFail(1);
        $record = StudentRecord::create([
            'user_id' => $studentUser->id,
            'my_class_id' => $class->id,
            'section_id' => 1,
            'my_parent_id' => $parent->id,
            'adm_no' => 'F2-' . $tag,
            'session' => $this->schoolSession(),
            'grad' => 0,
        ]);
        $record->load(['user', 'my_class', 'section']);
        return [$parent, $record];
    }

    /* ---------------- Scoping / gates (T1–T4) ---------------- */

    public function test_t1_parent_sees_own_children_and_own_figures_only()
    {
        $this->publishExam1();
        [$aarav, $lakshmi] = $this->children()->all();

        $res = $this->actingAs($this->parent())->get(route('my_progress'));
        $res->assertOk();
        $res->assertSee($aarav->user->name);
        $res->assertSee($lakshmi->user->name); // switcher lists both
        $res->assertSee('68.8%'); // Aarav's published Term 1 figure

        $foreign = StudentRecord::whereNotIn('id', $this->children()->pluck('id')->all())
            ->whereHas('user')->firstOrFail();
        $res->assertDontSee($foreign->user->name);
        // Lakshmi is fee-blocked; her Term 1 figure must not leak
        // onto Aarav's page either.
        $res->assertDontSee('64.2%');
    }

    public function test_t2_foreign_child_param_falls_back_to_own_first_child()
    {
        $this->publishExam1();
        [$foreignParent, $foreignChild] = $this->makeParentWithChild('t2');
        $exam = Exam::findOrFail(1);
        $subject = $this->classSubjects($foreignChild)->first();
        $this->makeMark($exam, $foreignChild, $subject->id, 97);

        $res = $this->actingAs($this->parent())
            ->get(route('my_progress', ['child' => $foreignChild->id]));
        $res->assertOk();
        $res->assertSee($this->children()->first()->user->name);
        $res->assertDontSee($foreignChild->user->name);
        $res->assertDontSee('97.0%');
    }

    public function test_t3_non_parents_are_redirected_to_login()
    {
        $this->get(route('my_progress'))->assertRedirect(route('login'));

        $student = User::where('user_type', 'student')->firstOrFail();
        $this->actingAs($student)->get(route('my_progress'))->assertRedirect(route('login'));

        $teacher = $this->userByEmail('teacher@hackstra.app');
        $this->actingAs($teacher)->get(route('my_progress'))->assertRedirect(route('login'));

        $admin = $this->userByEmail('admin@hackstra.app');
        $this->actingAs($admin)->get(route('my_progress'))->assertRedirect(route('login'));
    }

    public function test_t4_parent_home_carries_progress_strip_and_link()
    {
        $this->publishExam1();
        [$aarav, $lakshmi] = $this->children()->all();

        $res = $this->actingAs($this->parent())->get(route('dashboard'));
        $res->assertOk();
        $res->assertSee('my_progress?child=' . $aarav->id, false);
        $res->assertSee('Latest result');
        $res->assertSee('68.8%');
        $res->assertSee('Results are held until fees are cleared'); // Lakshmi strip
        $res->assertSee('>Progress<', false);

        $admin = $this->userByEmail('admin@hackstra.app');
        $this->actingAs($admin)->get(route('dashboard'))->assertDontSee('f2-strip', false);
    }

    /* ---------------- Published gating (T5–T7) ---------------- */

    public function test_t5_unpublished_exam_leaks_nothing()
    {
        // Exam 1 is seeded unpublished, with real marks behind it.
        $res = $this->actingAs($this->parent())->get(route('my_progress'));
        $res->assertOk();
        $res->assertSee('No results have been published for ' . $this->schoolSession() . ' yet.');
        $res->assertDontSee('68.8%');
    }

    public function test_t6_publish_flip_shows_then_hides_the_point()
    {
        $exam = $this->publishExam1();
        $this->actingAs($this->parent())->get(route('my_progress'))->assertSee('68.8%');

        $exam->update(['results_published' => false]);
        $this->actingAs($this->parent())->get(route('my_progress'))
            ->assertDontSee('68.8%')
            ->assertSee('No results have been published for');
    }

    public function test_t7_publish_requested_only_behaves_as_unpublished()
    {
        if (!\Illuminate\Support\Facades\Schema::hasColumn('exams', 'publish_requested_at')) {
            $this->markTestSkipped('exams.publish_requested_at not present in this tree');
        }
        $exam = Exam::findOrFail(1);
        $exam->update([
            'results_published' => false,
            'publish_requested_at' => now(),
            'publish_requested_by' => $this->userByEmail('admin@hackstra.app')->id,
        ]);

        $this->actingAs($this->parent())->get(route('my_progress'))
            ->assertDontSee('68.8%')
            ->assertSee('No results have been published for');
    }

    /* ---------------- Maths pins (T8–T12) ---------------- */

    public function test_t8_legacy_scheme_period_pct_equals_exam_record_ave()
    {
        [$aarav] = $this->children()->all();
        $subjects = $this->classSubjects($aarav);
        $exam = $this->makeExam(['name' => 'F2 T8 Exam', 'term' => 2]);
        $this->makeMark($exam, $aarav, $subjects[0]->id, 80);
        $this->makeMark($exam, $aarav, $subjects[1]->id, 60);
        $record = ExamRecord::create([
            'exam_id' => $exam->id, 'student_id' => $aarav->user_id,
            'my_class_id' => $aarav->my_class_id, 'section_id' => $aarav->section_id,
            'year' => $this->schoolSession(), 'total' => 140, 'ave' => 70.0,
        ]);

        $out = $this->progressFor([$aarav]);
        $this->assertCount(1, $out[$aarav->id]['periods']);
        $pct = $out[$aarav->id]['periods'][0]['pct'];
        $this->assertSame(70.0, $pct);
        $this->assertSame((float) $record->ave, $pct, 'legacy 100-max: F2 % must equal the printed card footer ave');

        $this->actingAs($this->parent())
            ->get(route('my_progress', ['child' => $aarav->id]))
            ->assertSee('70.0%');
    }

    public function test_t9_mixed_maxima_period_pct_is_mean_of_percentages()
    {
        [$aarav] = $this->children()->all();
        $subjects = $this->classSubjects($aarav);
        $exam = $this->makeExam(['name' => 'F2 T9 Exam', 'term' => 2]);
        ExamSubjectComponent::create(['exam_id' => $exam->id, 'subject_id' => $subjects[0]->id, 'name' => 'Theory', 'short_code' => 'TH', 'max_marks' => 80, 'sort' => 1]);
        ExamSubjectComponent::create(['exam_id' => $exam->id, 'subject_id' => $subjects[1]->id, 'name' => 'Theory', 'short_code' => 'TH', 'max_marks' => 50, 'sort' => 1]);
        $this->makeMark($exam, $aarav, $subjects[0]->id, 72); // 90.0%
        $this->makeMark($exam, $aarav, $subjects[1]->id, 40); // 80.0%

        $out = $this->progressFor([$aarav]);
        $point = $out[$aarav->id]['periods'][0];
        $this->assertSame(85.0, $point['pct'], 'mean of % (90+80)/2 — NOT the raw mean 56');
        $classTypeId = MyClass::findOrFail($aarav->my_class_id)->class_type_id;
        $expectedGrade = (new MarkRepo())->getGrade(85.0, $classTypeId);
        $this->assertSame($expectedGrade ? $expectedGrade->name : null, $point['grade']);

        $this->actingAs($this->parent())
            ->get(route('my_progress', ['child' => $aarav->id]))
            ->assertSee('85.0%');
    }

    public function test_t10_null_total_excluded_from_mean_zero_included()
    {
        [$aarav] = $this->children()->all();
        $subjects = $this->classSubjects($aarav);
        $exam = $this->makeExam(['name' => 'F2 T10 Exam', 'term' => 2]);
        $this->makeMark($exam, $aarav, $subjects[0]->id, 90);
        $this->makeMark($exam, $aarav, $subjects[1]->id, null);

        $out = $this->progressFor([$aarav]);
        $this->assertSame(90.0, $out[$aarav->id]['periods'][0]['pct'], 'NULL excluded — not 45');

        $res = $this->actingAs($this->parent())->get(route('my_progress', ['child' => $aarav->id]));
        $res->assertSee('90.0%');
        $res->assertSee('Not entered');

        $this->makeMark($exam, $aarav, $subjects[2]->id, 0);
        $out = $this->progressFor([$aarav]);
        $this->assertSame(45.0, $out[$aarav->id]['periods'][0]['pct'], 'a stored 0 is a real 0 — (90+0)/2');
    }

    public function test_t11_subject_rows_match_report_card_build()
    {
        $exam = $this->publishExam1();
        [$aarav] = $this->children()->all();

        $out = $this->progressFor([$aarav]);
        $point = $out[$aarav->id]['periods'][0];
        $byName = collect($point['subjects'])->keyBy('name');

        $subjects = $this->classSubjects($aarav);
        $marks = Mark::with('grade')->where('exam_id', $exam->id)
            ->where('student_id', $aarav->user_id)->get();
        $card = ReportCard::build($aarav, $exam, $subjects, $marks);

        $checked = 0;
        foreach ($card['rows'] as $row) {
            if ($row['mark'] === null) {
                continue;
            }
            $name = $row['subject']->name;
            $this->assertArrayHasKey($name, $byName->all());
            $this->assertSame($row['total'], $byName[$name]['total'], "total parity for {$name}");
            $this->assertSame($row['grade'], $byName[$name]['grade'], "grade parity for {$name}");
            $this->assertSame($row['remark'], $byName[$name]['remark'], "remark parity for {$name}");
            $checked++;
        }
        $this->assertGreaterThan(0, $checked);
    }

    public function test_t12_delta_is_percentage_points_between_points()
    {
        [$aarav] = $this->children()->all();
        $subjects = $this->classSubjects($aarav);
        $examA = $this->makeExam(['name' => 'F2 T12 A', 'term' => 2]);
        $this->makeMark($examA, $aarav, $subjects[0]->id, 80);
        $this->makeMark($examA, $aarav, $subjects[1]->id, 60); // 70.0
        $examB = $this->makeExam(['name' => 'F2 T12 B', 'term' => 3]);
        $this->makeMark($examB, $aarav, $subjects[0]->id, 85);
        $this->makeMark($examB, $aarav, $subjects[1]->id, 85); // 85.0

        $out = $this->progressFor([$aarav]);
        $points = $out[$aarav->id]['periods'];
        $this->assertCount(2, $points);
        $this->assertNull($points[0]['delta']);
        $this->assertSame(15.0, $points[1]['delta']);

        $this->actingAs($this->parent())
            ->get(route('my_progress', ['child' => $aarav->id]))
            ->assertSee('+15.0 pts');
    }

    /* ---------------- Periods, dual mode (T13–T15) ---------------- */

    public function test_t13_null_period_exams_label_and_order_by_term()
    {
        $this->publishExam1(); // Term 1 (period-linked)
        [$aarav] = $this->children()->all();
        $subjects = $this->classSubjects($aarav);
        $exam2 = $this->makeExam(['name' => 'F2 T13 Term2', 'term' => 2]); // period_id NULL
        $this->makeMark($exam2, $aarav, $subjects[0]->id, 75);
        $this->makeExam(['name' => 'F2 T13 Term3 Hidden', 'term' => 3, 'results_published' => false]);

        $out = $this->progressFor([$aarav]);
        $names = array_map(function ($p) { return $p['period_name']; }, $out[$aarav->id]['periods']);
        $this->assertSame(['Term 1', 'Term 2'], $names);
    }

    public function test_t14_real_mode_period_rules()
    {
        $this->publishExam1();
        [$aarav] = $this->children()->all();
        $subjects = $this->classSubjects($aarav);
        $year = $this->schoolSession();

        $semester = AssessmentPeriod::create([
            'year' => $year, 'name' => 'Semester 1', 'kind' => 'term',
            'legacy_term' => 2, 'counts_toward_annual' => true, 'sort_order' => 15, 'is_active' => true,
        ]);
        $unit = AssessmentPeriod::create([
            'year' => $year, 'name' => 'F2 Unit Period', 'kind' => 'unit',
            'feeds_period_id' => $semester->id, 'legacy_term' => 2,
            'counts_toward_annual' => false, 'sort_order' => 12, 'is_active' => true,
        ]);
        $standaloneOff = AssessmentPeriod::create([
            'year' => $year, 'name' => 'F2 Standalone Off', 'kind' => 'standalone',
            'legacy_term' => null, 'counts_toward_annual' => false, 'sort_order' => 20, 'is_active' => true,
        ]);
        $oldTerm = AssessmentPeriod::create([
            'year' => $year, 'name' => 'F2 Old Term', 'kind' => 'term',
            'legacy_term' => 3, 'counts_toward_annual' => true, 'sort_order' => 30, 'is_active' => false,
        ]);

        $semExam = $this->makeExam(['name' => 'F2 Semester Exam', 'term' => 2, 'period_id' => $semester->id]);
        $this->makeMark($semExam, $aarav, $subjects[0]->id, 88);
        $unitExam = $this->makeExam(['name' => 'F2 Unit Exam', 'term' => 2, 'period_id' => $unit->id]);
        $this->makeMark($unitExam, $aarav, $subjects[0]->id, 33);
        $saExam = $this->makeExam(['name' => 'F2 Standalone Exam', 'term' => 2, 'period_id' => $standaloneOff->id]);
        $this->makeMark($saExam, $aarav, $subjects[0]->id, 44);
        $oldExam = $this->makeExam(['name' => 'F2 Old Exam', 'term' => 3, 'period_id' => $oldTerm->id]);
        $this->makeMark($oldExam, $aarav, $subjects[0]->id, 66);

        $out = $this->progressFor([$aarav]);
        $points = $out[$aarav->id]['periods'];
        $names = array_map(function ($p) { return $p['period_name']; }, $points);
        $this->assertContains('Semester 1', $names);
        $this->assertContains('F2 Old Term', $names, 'deactivated period still shows');
        $this->assertNotContains('F2 Standalone Off', $names);
        $this->assertNotContains('F2 Unit Period', $names);
        $this->assertGreaterThan(array_search('Term 1', $names), array_search('Semester 1', $names), 'Semester 1 (sort 15) follows Term 1 (sort 10)');
        $semPoint = collect($points)->firstWhere('period_name', 'Semester 1');
        $this->assertTrue($semPoint['fed_by_unit']);

        $this->actingAs($this->parent())
            ->get(route('my_progress', ['child' => $aarav->id]))
            ->assertSee('Semester 1')
            ->assertSee('Unit-test marks are included within this period')
            ->assertDontSee('F2 Standalone Off');
    }

    public function test_t15_class_scoped_exam_of_another_class_gives_no_point()
    {
        [$aarav] = $this->children()->all();
        $subjects = $this->classSubjects($aarav);
        $otherClass = MyClass::where('id', '!=', $aarav->my_class_id)->firstOrFail();

        $scoped = $this->makeExam(['name' => 'F2 T15 Scoped', 'term' => 2, 'my_class_id' => $otherClass->id]);
        $otherChild = StudentRecord::where('my_class_id', $otherClass->id)->firstOrFail();
        $otherSubject = Subject::where('my_class_id', $otherClass->id)->firstOrFail();
        $this->makeMark($scoped, $otherChild, $otherSubject->id, 33);

        $out = $this->progressFor([$aarav]);
        $this->assertSame([], $out[$aarav->id]['periods'], 'another class\'s exam contributes nothing');

        $global = $this->makeExam(['name' => 'F2 T15 Global', 'term' => 2, 'my_class_id' => 0]);
        $this->makeMark($global, $aarav, $subjects[0]->id, 75);
        $out = $this->progressFor([$aarav]);
        $this->assertCount(1, $out[$aarav->id]['periods']);
        $this->assertSame(75.0, $out[$aarav->id]['periods'][0]['pct']);
    }

    /* ---------------- Attendance (T16–T17) ---------------- */

    protected function addAttendance(StudentRecord $child, string $date, string $status): void
    {
        $teacher = User::where('user_type', 'teacher')->firstOrFail();
        $session = AttendanceSession::create([
            'date' => $date, 'my_class_id' => $child->my_class_id,
            'section_id' => $child->section_id, 'subject_id' => 1,
            'teacher_id' => $teacher->id, 'topic' => 'F2 test register', 'status' => 'submitted',
        ]);
        AttendanceSessionEntry::create([
            'session_id' => $session->id, 'student_record_id' => $child->id, 'status' => $status,
        ]);
    }

    public function test_t16_attendance_months_pinned_and_chronological()
    {
        [$aarav] = $this->children()->all();
        $this->addAttendance($aarav, '2027-01-15', 'present');
        $this->addAttendance($aarav, '2027-01-16', 'absent');   // Jan: 1/2 = 50%
        $this->addAttendance($aarav, '2027-02-10', 'late');
        $this->addAttendance($aarav, '2027-02-11', 'absent');
        $this->addAttendance($aarav, '2027-02-12', 'absent');   // Feb: 1/3 = 33%
        $this->addAttendance($aarav, '2027-03-10', 'absent');
        $this->addAttendance($aarav, '2027-03-11', 'absent');   // Mar: 0%

        $out = $this->progressFor([$aarav]);
        $months = $out[$aarav->id]['attendance']['months'];
        $keys = array_map(function ($m) { return $m['key']; }, $months);
        $sorted = $keys;
        sort($sorted);
        $this->assertSame($sorted, $keys, 'months are chronological');
        $byKey = collect($months)->keyBy('key');
        $this->assertSame(50, $byKey['2027-01']['percent']);
        $this->assertSame(33, $byKey['2027-02']['percent']);
        $this->assertSame(0, $byKey['2027-03']['percent'], 'an all-absent month is a real 0%');

        // Session % must equal the app-wide rule over ALL entries.
        $rows = AttendanceSessionEntry::where('student_record_id', $aarav->id)
            ->select('status', DB::raw('count(*) as c'))->groupBy('status')->pluck('c', 'status');
        $marked = (int) $rows->sum();
        $expected = (int) round((((int) ($rows['present'] ?? 0) + (int) ($rows['late'] ?? 0)) / $marked) * 100);
        $this->assertSame($expected, $out[$aarav->id]['attendance']['session']['percent']);

        $this->actingAs($this->parent())
            ->get(route('attendance.my', ['student_id' => $aarav->id]))
            ->assertOk()->assertSee('January 2027');
    }

    public function test_t17_no_attendance_renders_honest_empty_state()
    {
        [$parent2, $child2] = $this->makeParentWithChild('t17');
        $res = $this->actingAs($parent2)->get(route('my_progress'));
        $res->assertOk();
        $res->assertSee('No attendance registers have been marked yet this session.');
        $res->assertSee('Attendance this session: —', false);
        $res->assertDontSee('Attendance this session: 0'); // no fake 0% for a null percent
    }

    /* ---------------- Fee gate (T18) ---------------- */

    public function test_t18_fee_blocked_child_hides_marks_not_attendance()
    {
        $this->publishExam1();
        [$aarav, $lakshmi] = $this->children()->all();
        $due = FeeDefaulter::outstandingFor($lakshmi->user_id, $this->schoolSession());
        $this->assertGreaterThan(0, $due, 'fixture: Lakshmi must owe fees');
        $this->assertSame(0, FeeDefaulter::outstandingFor($aarav->user_id, $this->schoolSession()));

        $res = $this->actingAs($this->parent())->get(route('my_progress', ['child' => $lakshmi->id]));
        $res->assertOk();
        $res->assertSee('Progress Report');
        $res->assertSee(Money::inr($due), false);
        $res->assertDontSee('64.2%'); // her published Term 1 figure stays hidden
        $res->assertSee('September 2026'); // attendance still renders in full

        $this->actingAs($this->parent())->get(route('my_progress', ['child' => $aarav->id]))
            ->assertSee('68.8%');

        PaymentRecord::where('student_id', $lakshmi->user_id)
            ->where('year', $this->schoolSession())->update(['paid' => 1]);
        $this->assertSame(0, FeeDefaulter::outstandingFor($lakshmi->user_id, $this->schoolSession()));
        $this->actingAs($this->parent())->get(route('my_progress', ['child' => $lakshmi->id]))
            ->assertSee('64.2%');
    }

    /* ---------------- Empty / single-point states (T19) ---------------- */

    public function test_t19_single_point_no_chart_and_other_empty_states()
    {
        $this->publishExam1();
        [$aarav] = $this->children()->all();

        $res = $this->actingAs($this->parent())->get(route('my_progress', ['child' => $aarav->id]));
        $res->assertOk();
        $res->assertSee('Subject-wise marks');
        $res->assertSee('The trend chart appears once two or more periods are published.');
        $res->assertDontSee('Average percentage by assessment period'); // chart markup absent

        Exam::findOrFail(1)->update(['results_published' => false]);
        $res = $this->actingAs($this->parent())->get(route('my_progress', ['child' => $aarav->id]));
        $res->assertSee('No results have been published for');
        $res->assertDontSee('Term 1 Examination'); // no unpublished exam name leaks

        [$lonelyParent] = $this->makeParentWithChild('t19');
        // Remove the child link again — a parent with no children.
        StudentRecord::where('my_parent_id', $lonelyParent->id)->delete();
        $this->actingAs($lonelyParent)->get(route('my_progress'))
            ->assertSee('No children are linked to your account yet');
    }

    /* ---------------- Performance (T20) ---------------- */

    public function test_t20_fixed_query_set_does_not_grow_with_child_count()
    {
        $this->publishExam1();
        [$aarav, $lakshmi] = $this->children()->all();
        // Clear Lakshmi's dues so BOTH children run the full marks
        // assembly — the strictest case for the fixed set.
        PaymentRecord::where('student_id', $lakshmi->user_id)
            ->where('year', $this->schoolSession())->update(['paid' => 1]);

        $fixedTables = ['marks', 'exams', 'assessment_periods', 'exam_subject_components', 'subjects', 'my_classes', 'grades', 'attendance_session_entries', 'attendance_sessions'];
        $countFixed = function ($records) use ($fixedTables) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            StudentProgress::forChildren(collect($records), $this->schoolSession());
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            return collect($queries)->filter(function ($q) use ($fixedTables) {
                foreach ($fixedTables as $table) {
                    if (stripos($q['query'], '"' . $table . '"') !== false || stripos($q['query'], $table) !== false) {
                        return true;
                    }
                }
                return false;
            })->count();
        };

        $one = $countFixed([$aarav]);
        $two = $countFixed([$aarav, $lakshmi]);
        $this->assertGreaterThan(0, $one);
        $this->assertSame($one, $two, 'marks/attendance assembly queries are fixed — only the per-child fee gate (§3.4 allowance) may grow');
    }

    /* ---------------- Unit (T21) ---------------- */

    public function test_t21_max_totals_for_exams_matches_max_total_per_pair()
    {
        // Configured pair from the seed (exam 1 carries components).
        $configured = ExamSubjectComponent::where('exam_id', 1)->firstOrFail();
        $map = MarkScheme::maxTotalsForExams([1]);
        $key = '1:' . $configured->subject_id;
        $this->assertArrayHasKey($key, $map);
        $this->assertSame(MarkScheme::maxTotal(1, $configured->subject_id), $map[$key]);

        // Legacy pair (no components anywhere for this subject).
        $legacySubjectId = 987001;
        $this->assertSame(100, MarkScheme::maxTotal(1, $legacySubjectId));
        $this->assertArrayNotHasKey('1:' . $legacySubjectId, $map, 'unconfigured pairs stay absent — caller applies legacy 100');

        // Mixed exam: one configured subject, one legacy subject.
        $exam = $this->makeExam(['name' => 'F2 T21 Mixed']);
        $subjects = Subject::orderBy('id')->take(2)->get();
        ExamSubjectComponent::create(['exam_id' => $exam->id, 'subject_id' => $subjects[0]->id, 'name' => 'Theory', 'short_code' => 'TH', 'max_marks' => 80, 'sort' => 1]);
        $map = MarkScheme::maxTotalsForExams([$exam->id]);
        $this->assertSame(80, $map[$exam->id . ':' . $subjects[0]->id]);
        $this->assertSame(MarkScheme::maxTotal($exam->id, $subjects[0]->id), $map[$exam->id . ':' . $subjects[0]->id]);
        $this->assertArrayNotHasKey($exam->id . ':' . $subjects[1]->id, $map);
        $this->assertSame(100, MarkScheme::maxTotal($exam->id, $subjects[1]->id));

        $this->assertSame([], MarkScheme::maxTotalsForExams([]));
    }
}
