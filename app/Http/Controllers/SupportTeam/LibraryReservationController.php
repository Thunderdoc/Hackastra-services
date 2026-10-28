<?php

namespace App\Http\Controllers\SupportTeam;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\LibraryReservation;
use App\Models\StudentRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Library Search + Reservation (2026-10-28). Controller-guarded:
 * the routes carry no team middleware (students and parents must
 * reach them), so every action gates on Auth::user()->user_type.
 * Students reserve for themselves; parents reserve only for their
 * own child (student_records.my_parent_id must equal the parent's
 * user id); librarians and admins see every reservation, may
 * cancel any, and may mark a reserved book collected (staff-only:
 * collection is the librarian handing the book over, never a
 * self-service action). Reservations are their own
 * table — BookRequest (issued/returned only) and the issue/return
 * flow are never touched, and no Book counter is changed here:
 * reservability is total_copies > issued_copies, so a cancellation
 * automatically makes the book reservable again.
 */
class LibraryReservationController extends Controller
{
    private const ALLOWED_TYPES = ['student', 'parent', 'admin', 'librarian'];

    private function user()
    {
        return Auth::user();
    }

    private function denyUnlessAllowed()
    {
        $user = $this->user();
        if (!$user || !in_array($user->user_type, self::ALLOWED_TYPES, true)) {
            abort(403);
        }
        return $user;
    }

    private function isStaff($user): bool
    {
        return in_array($user->user_type, ['admin', 'librarian'], true);
    }

    public function index(Request $request)
    {
        $user = $this->denyUnlessAllowed();
        $q = trim((string) $request->query('q', ''));

        $books = Book::query()
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('name', 'like', "%{$q}%")
                        ->orWhere('author', 'like', "%{$q}%");
                });
            })
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        $reservationsQuery = LibraryReservation::with(['book', 'user', 'studentRecord.user'])
            ->orderByDesc('created_at');
        if (!$this->isStaff($user)) {
            $reservationsQuery->where('user_id', $user->id);
        }
        $reservations = $reservationsQuery->paginate(15, ['*'], 'res_page')
            ->withQueryString();

        // Books this user already holds an active reservation for,
        // so the row shows "Reserved" instead of a second Reserve button.
        $myReservedBookIds = LibraryReservation::active()
            ->where('user_id', $user->id)
            ->pluck('book_id')
            ->all();

        // A parent's children, for the "reserve for" choice on each row.
        $children = [];
        if ($user->user_type === 'parent') {
            $children = StudentRecord::with('user')
                ->where('my_parent_id', $user->id)
                ->orderBy('id')
                ->get();
        }

        // The student's own record, so a student reservation is
        // always linked to themselves and never to a supplied id.
        $ownRecordId = null;
        if ($user->user_type === 'student') {
            $ownRecordId = optional(StudentRecord::where('user_id', $user->id)->first())->id;
        }

        return view('pages.support_team.library_reservations.index', [
            'books' => $books,
            'reservations' => $reservations,
            'q' => $q,
            'myReservedBookIds' => $myReservedBookIds,
            'children' => $children,
            'ownRecordId' => $ownRecordId,
            'canReserve' => in_array($user->user_type, ['student', 'parent'], true),
            'isStaff' => $this->isStaff($user),
        ]);
    }

    public function store(Request $request)
    {
        $user = $this->denyUnlessAllowed();
        if (!in_array($user->user_type, ['student', 'parent'], true)) {
            abort(403);
        }

        $request->validate([
            'book_id' => 'required|integer|exists:books,id',
            'student_record_id' => 'nullable|integer|exists:student_records,id',
        ]);

        $book = Book::findOrFail($request->input('book_id'));

        // The child a parent reserves for must actually be theirs.
        $studentRecordId = null;
        if ($user->user_type === 'parent') {
            $childId = $request->input('student_record_id');
            if (!$childId) {
                return back()->withInput()
                    ->with('flash_danger', 'Choose which of your children this reservation is for.');
            }
            $child = StudentRecord::findOrFail($childId);
            if ((int) $child->my_parent_id !== (int) $user->id) {
                abort(403);
            }
            $studentRecordId = $child->id;
        } else {
            $studentRecordId = optional(StudentRecord::where('user_id', $user->id)->first())->id;
        }

        if ($book->availableCopies() < 1) {
            return back()->withInput()
                ->with('flash_danger', 'No copies of "' . $book->name . '" are available to reserve right now.');
        }

        $duplicate = LibraryReservation::active()
            ->where('user_id', $user->id)
            ->where('book_id', $book->id)
            ->exists();
        if ($duplicate) {
            return back()->withInput()
                ->with('flash_danger', 'You already have an active reservation for "' . $book->name . '".');
        }

        LibraryReservation::create([
            'book_id' => $book->id,
            'user_id' => $user->id,
            'student_record_id' => $studentRecordId,
            'status' => LibraryReservation::STATUS_RESERVED,
        ]);

        return redirect()->route('library_reservations.index')
            ->with('flash_success', 'Book reserved: "' . $book->name . '".');
    }

    public function cancel(LibraryReservation $reservation)
    {
        $user = $this->denyUnlessAllowed();

        if (!$this->isStaff($user) && (int) $reservation->user_id !== (int) $user->id) {
            abort(403);
        }

        if (!$reservation->isActive()) {
            return back()->with('flash_danger', 'Only an active reservation can be cancelled — this one is already ' . $reservation->status . '.');
        }

        $reservation->update(['status' => LibraryReservation::STATUS_CANCELLED]);

        // No counters to repair: availability is derived from
        // total_copies > issued_copies, so the book is reservable
        // again the moment this row stops being active.
        return redirect()->route('library_reservations.index')
            ->with('flash_success', 'Reservation cancelled.');
    }

    /** Staff-only: a reserved book handed over is collected. */
    public function collect(LibraryReservation $reservation)
    {
        $user = $this->denyUnlessAllowed();

        if (!$this->isStaff($user)) {
            abort(403);
        }

        if (!$reservation->isActive()) {
            return back()->with('flash_danger', 'Only a reserved book can be marked collected — this one is already ' . $reservation->status . '.');
        }

        $reservation->update(['status' => LibraryReservation::STATUS_COLLECTED]);

        return redirect()->route('library_reservations.index')
            ->with('flash_success', 'Reservation marked collected.');
    }
}
