<?php

namespace App\Http\Controllers\SupportTeam;

use App\Http\Controllers\Controller;
use App\Http\Requests\BookRequest\BookRequestCreate;
use App\Models\Book;
use App\Models\BookRequest;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Library loans (book requests): issue a book to a borrower and record
 * its return. Access: Super Admin, Admin and Librarian (teamLibrary).
 * Issuing/returning keeps Book.issued_copies in step, in a transaction.
 */
class BookRequestController extends Controller
{
    public function __construct()
    {
        $this->middleware('teamLibrary');
        // Workstream CM: loan writes (issue/return) — Admin + Librarian.
        $this->middleware('teamLibraryWrite', ['only' => ['create', 'store', 'markReturned']]);
    }

    public function index(Request $request)
    {
        $status = $request->input('status');

        $loans = BookRequest::with(['book', 'user'])
            ->when($status === BookRequest::STATUS_ISSUED, function ($query) {
                $query->active();
            })
            ->when($status === BookRequest::STATUS_RETURNED, function ($query) {
                $query->returned();
            })
            ->orderByDesc('created_at')
            ->paginate(15);

        $d['loans'] = $loans;
        $d['status'] = $status;

        return view('pages.support_team.book_requests.index', $d);
    }

    public function create()
    {
        $d['books'] = Book::orderBy('name')->get();
        $d['borrowers'] = User::whereIn('user_type', ['student', 'teacher', 'parent', 'accountant', 'librarian', 'admin'])
            ->orderBy('name')
            ->get(['id', 'name', 'user_type']);

        return view('pages.support_team.book_requests.create', $d);
    }

    public function store(BookRequestCreate $request)
    {
        $data = $request->validated();

        $book = Book::findOrFail($data['book_id']);

        if ($book->availableCopies() < 1) {
            return back()->withInput()
                ->with('flash_danger', 'No copies of "' . $book->name . '" are available to issue right now.');
        }

        DB::transaction(function () use ($book, $data) {
            BookRequest::create([
                'book_id' => $data['book_id'],
                'user_id' => $data['user_id'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'returned' => 0,
                'status' => BookRequest::STATUS_ISSUED,
            ]);

            $book->increment('issued_copies');
        });

        return redirect()->route('book_requests.index')
            ->with('flash_success', 'Book issued.');
    }

    /** Record that a borrowed copy has been brought back. */
    public function markReturned($id)
    {
        $loan = BookRequest::findOrFail($id);

        if ($loan->returned) {
            return back()->with('flash_success', 'This loan was already marked as returned.');
        }

        DB::transaction(function () use ($loan) {
            $loan->update([
                'returned' => 1,
                'status' => BookRequest::STATUS_RETURNED,
            ]);

            $book = $loan->book;
            if ($book && (int) $book->issued_copies > 0) {
                $book->decrement('issued_copies');
            }
        });

        return back()->with('flash_success', 'Book marked as returned.');
    }
}
