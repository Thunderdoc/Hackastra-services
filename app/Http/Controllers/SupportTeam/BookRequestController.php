<?php

namespace App\Http\Controllers\SupportTeam;

use App\Http\Controllers\Controller;
use App\Http\Requests\BookRequest\BookRequestCreate;
use App\Models\Book;
use App\Models\BookRequest;
use App\Support\LibraryFines;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Library loans (book requests): issue a book to a borrower and record
 * its return. Access: Super Admin and Librarian (teamLibrary).
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

        /* Library fine (owner, 2026-10-23): a late return imposes
           the fine automatically — days overdue x the Settings rate
           — and for a student it lands in the main fee account via
           LibraryFines, so it shows in the student's fees, balance
           and defaulters until paid or waived. The librarian does
           not calculate anything; the system does, and reports it
           in this flash so nothing is silent. */
        $message = 'Book marked as returned.';
        if ($loan->end_date) {
            $daysLate = (int) Carbon::parse($loan->end_date)->startOfDay()
                ->diffInDays(Carbon::today(), false);
            if ($daysLate > 0) {
                $amount = $daysLate * LibraryFines::dailyRate();
                $bookTitle = optional($loan->book)->name ?: 'Book';
                $fine = LibraryFines::impose($loan, $amount, $daysLate,
                    'Late return — ' . $bookTitle . ' (' . $daysLate . ' day' . ($daysLate === 1 ? '' : 's') . ' overdue)',
                    Auth::id());
                $borrower = optional($loan->user)->name ?: 'the borrower';
                $message .= ' Library fine of ₹' . number_format($amount) . ' imposed on ' . $borrower
                    . ' (' . $daysLate . ' day' . ($daysLate === 1 ? '' : 's') . ' overdue)'
                    . ($fine->payment_record_id ? ' — added to their fee account.' : '.');
            }
        }

        return back()->with('flash_success', $message);
    }
}
