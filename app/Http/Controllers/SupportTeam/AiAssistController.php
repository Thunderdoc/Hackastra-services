<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\Mark;
use App\Models\StudentRecord;
use App\Services\AiAssist;
use App\Services\AiAssistException;
use App\Support\TeacherScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * AI Assist endpoints (Workstream CX). Both actions only RETURN draft
 * text as JSON — the browser drops it into the existing form fields and
 * the human reviews, edits and saves through the normal paths. Nothing
 * here persists a circular, a mark or a comment.
 *
 * Gates mirror the surfaces they assist: circular drafting is teamSA
 * (same as CircularController's mutating actions); report comments are
 * teamSAT with the Batch S teacher-scope check, same as
 * MarkController@comment_update.
 */
class AiAssistController extends Controller
{
    public function __construct()
    {
        $this->middleware('teamSA', ['only' => ['circularDraft']]);
        $this->middleware('teamSAT', ['only' => ['reportComment']]);
    }

    /** ASSIST 1 — draft a circular's title + body from a few words. */
    public function circularDraft(Request $req)
    {
        $req->validate([
            'brief' => 'required|string|min:3|max:500',
            'tone' => 'required|in:Formal,Friendly',
            'language' => 'required|in:English,Hindi,Tamil',
        ]);

        try {
            $text = AiAssist::generate('circular_draft', [
                'brief' => $req->brief,
                'tone' => $req->tone,
                'language' => $req->language,
                'school_name' => Qs::getSystemName(),
            ], $req->user());
        } catch (AiAssistException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $draft = AiAssist::decodeJson($text);
        if ($draft === null || empty($draft['title']) || empty($draft['body'])) {
            return response()->json(['message' => 'AI returned an unreadable draft. Please try again.'], 422);
        }

        return response()->json(['title' => $draft['title'], 'body' => $draft['body']]);
    }

    /**
     * ASSIST 2 — draft a report-card remark from the student's REAL
     * computed subject totals for one exam. The numbers are assembled
     * here from the marks table (tex{term} is the subject total out of
     * 100 in both the legacy and marking-scheme pipelines); the model
     * only phrases them.
     */
    public function reportComment(Request $req)
    {
        $req->validate([
            'student_id' => 'required|integer',
            'exam_id' => 'required|integer',
            'year' => 'required|string',
        ]);

        $sr = StudentRecord::where('user_id', $req->student_id)->first();
        if (!$sr) {
            return response()->json(['message' => 'Student record not found.'], 404);
        }

        // Batch S parity: a teacher comments only on their own classes.
        if (Qs::userIsTeacher() && !TeacherScope::canTeachClass(Auth::user(), $sr->my_class_id)) {
            return response()->json(['message' => 'You can only manage marks for your assigned classes.'], 403);
        }

        $exam = Exam::find($req->exam_id);
        if (!$exam) {
            return response()->json(['message' => 'Exam not found.'], 404);
        }

        $tex = 'tex' . $exam->term;
        $subjects = [];
        $marks = Mark::with('subject')
            ->where('student_id', $req->student_id)
            ->where('exam_id', $req->exam_id)
            ->where('year', $req->year)
            ->get();

        foreach ($marks as $mk) {
            if ($mk->$tex === null || !$mk->subject) {
                continue;
            }
            $subjects[] = ['name' => $mk->subject->name, 'score' => (float) $mk->$tex];
        }

        if (count($subjects) < 1) {
            return response()->json(['message' => 'No marks found for this student in that exam yet — enter marks first, then generate the comment.'], 422);
        }

        $sorted = collect($subjects)->sortByDesc('score')->values();
        $context = [
            'student_name' => $sr->user->name,
            'class_name' => $sr->my_class->name,
            'exam_name' => $exam->name,
            'subjects' => $subjects,
            'average' => round($sorted->avg('score'), 1),
            'strongest' => $sorted->first()['name'],
            'weakest' => $sorted->last()['name'],
        ];

        try {
            $comment = AiAssist::generate('report_comment', $context, $req->user());
        } catch (AiAssistException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['comment' => $comment, 'based_on' => $context]);
    }
}
