<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\StudentRecord;
use Illuminate\Http\Request;

/**
 * Homework tracker — Wave 1 feature 3. Read-only aggregation over the
 * existing assignment tables; this feature writes nothing and creates
 * no model, migration, or submission change.
 *
 * Real columns relied on (read from the models, not assumed):
 * - assignments: teacher_id, my_class_id, section_id (nullable: null
 *   means the whole class), subject_id, title, due_at (NOT NULL,
 *   datetime cast), allow_late, max_marks.
 * - assignment_submissions: assignment_id, student_id (a users id —
 *   the same convention marks.student_id uses, NOT a
 *   student_records id), submitted_at (NOT NULL, datetime cast),
 *   marks_awarded (nullable decimal), feedback (nullable text),
 *   graded_by, graded_at.
 *
 * Graded flag: there is no boolean graded column. A submission counts
 * as graded when it carries a grading signal — marks_awarded non-null
 * OR feedback non-null and non-empty. (The model's own isGraded()
 * checks marks_awarded only; the tracker counts either signal, per
 * the feature brief. On the seeded data both rules give the same
 * counts: assignment 2 has 2 graded rows under either rule.)
 *
 * Roster: the same pattern PeriodAttendanceController::roster uses —
 * StudentRecord where my_class_id matches, section_id matches only
 * when the assignment sets one, grad = 0, ordered by id (admission
 * order). Submitted students are matched to the roster by user_id,
 * because submissions store a users id.
 *
 * Access (controller-level; this route carries no team middleware):
 * - teacher: only their own assignments; opening a foreign
 *   assignment's detail aborts 403.
 * - admin: all assignments.
 * - anyone else (including Super Admin, which this route name does
 *   not open to the platform side): 403.
 */
class HomeworkTrackerController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $isTeacher = Qs::userIsTeacher();
        $isAdmin = Qs::userIsAdmin();

        if (!$isTeacher && !$isAdmin) {
            abort(403);
        }

        $query = Assignment::with(['teacher', 'my_class', 'section', 'subject'])
            ->orderBy('due_at', 'desc');

        if ($isTeacher) {
            $query->where('teacher_id', $user->id);
        }

        $assignments = $query->get()->map(function ($assignment) {
            return $this->attachTrackerStats($assignment);
        });

        $selected = null;
        $assignmentId = $request->query('assignment_id');
        if ($assignmentId !== null && $assignmentId !== '') {
            $candidate = Assignment::with(['teacher', 'my_class', 'section', 'subject'])
                ->find((int) $assignmentId);
            if (!$candidate) {
                abort(404);
            }
            if ($isTeacher && (int) $candidate->teacher_id !== (int) $user->id) {
                abort(403);
            }
            $selected = $this->attachTrackerStats($candidate, true);
        }

        return view('pages.support_team.homework_tracker.index', [
            'assignments' => $assignments,
            'selected' => $selected,
            'is_teacher' => $isTeacher,
        ]);
    }

    /**
     * Attach submitted / pending / graded counts to one assignment,
     * and — when detail is requested — the list of roster students
     * who have not submitted yet.
     */
    protected function attachTrackerStats(Assignment $assignment, bool $withPending = false): Assignment
    {
        $roster = $this->roster((int) $assignment->my_class_id, $assignment->section_id);

        $submissions = $assignment->submissions()
            ->select('student_id', 'marks_awarded', 'feedback')
            ->get();

        $submittedUserIds = $submissions->pluck('student_id')->unique()->values();
        $rosterUserIds = $roster->pluck('user_id');

        $submittedCount = $rosterUserIds->intersect($submittedUserIds)->count();
        $gradedCount = $submissions->filter(function ($submission) {
            return $submission->marks_awarded !== null
                || ($submission->feedback !== null && trim((string) $submission->feedback) !== '');
        })->count();

        $assignment->setAttribute('roster_count', $roster->count());
        $assignment->setAttribute('submitted_count', $submittedCount);
        $assignment->setAttribute('pending_count', $roster->count() - $submittedCount);
        $assignment->setAttribute('graded_count', $gradedCount);

        if ($withPending) {
            $pending = $roster->filter(function ($record) use ($submittedUserIds) {
                return !$submittedUserIds->contains($record->user_id);
            })->values();
            $assignment->setAttribute('pending_students', $pending);
        }

        return $assignment;
    }

    /**
     * Class/section roster, mirroring PeriodAttendanceController::roster:
     * non-graduated students of the class, narrowed to the section
     * only when one is given, in admission (id) order.
     */
    protected function roster(int $classId, $sectionId = null)
    {
        return StudentRecord::with('user', 'section')
            ->where('my_class_id', $classId)
            ->when($sectionId, function ($q) use ($sectionId) {
                $q->where('section_id', $sectionId);
            })
            ->where('grad', 0)
            ->orderBy('id')
            ->get();
    }
}
