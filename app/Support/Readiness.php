<?php

namespace App\Support;

use App\Helpers\Qs;
use App\Models\Exam;
use App\Models\ExamDate;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Models\TimeTableRecord;
use App\User;

/**
 * School Readiness (program Phase 8): is this school actually set
 * up to run? Each check reads live data and reports ok / warn /
 * fail with the reason and where to fix it — the same honesty rule
 * as everywhere else in the program: a missing logo is a warning,
 * a clashing timetable is a failure, and nothing is scored from
 * invented data.
 */
class Readiness
{
    public static function checks(): array
    {
        $checks = [];
        $session = Qs::getCurrentSession();
        $year = $session;

        $add = function ($status, $label, $detail, $route = null) use (&$checks) {
            $checks[] = ['status' => $status, 'label' => $label, 'detail' => $detail, 'route' => $route];
        };

        /* Basics */
        $add($session ? 'ok' : 'fail', 'Current session set',
            $session ? 'Session ' . $session . ' is active.' : 'No current session — set it in Settings.',
            'settings');
        $name = Qs::getSetting('system_name');
        $add($name ? 'ok' : 'fail', 'School identity',
            $name ? $name . ' · ' . (Qs::getSetting('address') ?: 'address missing') : 'School name is not set.',
            'settings');

        /* Structure */
        $classCount = MyClass::count();
        $sectionCount = Section::count();
        $add($classCount > 0 ? 'ok' : 'fail', 'Classes & sections',
            $classCount . ' classes, ' . $sectionCount . ' sections.', 'classes.index');
        $students = StudentRecord::where('session', $session)->count();
        $add($students > 0 ? 'ok' : 'warn', 'Students enrolled',
            $students . ' student records in the current session.', 'students.index');

        /* Academics */
        $subjects = Subject::count();
        $configured = Subject::whereNotNull('periods_per_week')->count();
        $add($subjects === 0 ? 'fail' : ($configured === $subjects ? 'ok' : 'warn'),
            'Subject requirements configured',
            $configured . ' of ' . $subjects . ' class-subjects have a weekly-period requirement.',
            'subjects.index');
        $teachers = User::where('user_type', 'teacher')->count();
        $overloaded = 0;
        $report = Staffing::report();
        foreach ($report['teachers'] as $row) {
            if ($row['overloaded']) {
                $overloaded++;
            }
        }
        $add($teachers === 0 ? 'fail' : ($overloaded ? 'warn' : 'ok'), 'Teacher load within caps',
            $teachers . ' teachers · ' . $overloaded . ' over the weekly cap of ' . Staffing::maxPeriodsPerWeek() . '.',
            'planning.staffing');

        /* Timetables */
        $records = TimeTableRecord::whereNull('exam_id')->where('year', $year)->get();
        $classesWithTt = $records->pluck('my_class_id')->unique()->count();
        $add($classCount > 0 && $classesWithTt === $classCount ? 'ok' : 'warn', 'Timetables for every class',
            $classesWithTt . ' of ' . $classCount . ' classes have a timetable this session.', 'tt.index');
        $ttErrors = count(TimetableValidator::globalErrors($year));
        $add($ttErrors === 0 ? 'ok' : 'fail', 'Timetables clash-free',
            $ttErrors === 0 ? 'No teacher double-bookings or collisions in this session\'s timetables.'
                : $ttErrors . ' blocking error(s) across this session\'s timetables — open a timetable\'s Validate page.',
            'tt.index');

        /* Exams & documents */
        $exams = Exam::where('year', $year)->count();
        $sittings = $exams ? ExamDate::whereIn('exam_id', Exam::where('year', $year)->pluck('id'))->count() : 0;
        $add($exams === 0 ? 'warn' : ($sittings > 0 ? 'ok' : 'warn'), 'Exams & date sheets',
            $exams . ' exam(s) this session, ' . $sittings . ' date-sheet sitting(s).', 'exam_dates.manage');
        $stamp = Qs::getSetting('school_stamp');
        $sig = Qs::getSetting('principal_signature');
        $add($stamp && $sig ? 'ok' : 'warn', 'Report-card stamp & signature',
            ($stamp ? 'Stamp uploaded. ' : 'No stamp uploaded. ') . ($sig ? 'Principal signature uploaded.' : 'No principal signature uploaded.'),
            'settings');

        /* Money — ledger integrity (Phase 6): every balance must
           recompute from its payment and collections. */
        $ledger = LedgerCheck::scan();
        $add($ledger['consistent'] ? 'ok' : 'fail', 'Fee ledger consistent',
            $ledger['consistent']
                ? $ledger['records'] . ' records reconcile — ₹' . number_format($ledger['total_paid']) . ' collected, ₹' . number_format($ledger['total_balance']) . ' outstanding, no drift.'
                : collect($ledger['issues'])->map(function ($i) { return $i['label'] . ' ×' . $i['count']; })->implode('; ') . '.',
            'payments.index');

        /* Money */
        $gateway = Qs::getSetting('gateway_enabled');
        $add('ok', 'Fee collection mode',
            $gateway ? 'Online payments enabled — parents pay into the school\'s own Razorpay account.' : 'Manual recording mode — online gateway is off; payments are recorded by the school office.',
            'settings');

        $ok = count(array_filter($checks, function ($c) { return $c['status'] === 'ok'; }));

        return ['checks' => $checks, 'score' => $checks ? (int) round($ok * 100 / count($checks)) : 0, 'ok' => $ok, 'total' => count($checks)];
    }
}
