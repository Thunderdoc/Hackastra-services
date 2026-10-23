<?php

namespace App\Http\Controllers\SupportTeam;

use App\Http\Controllers\Controller;
use App\Models\LibraryFine;
use App\Support\LibraryFines;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Library fines (owner, 2026-10-23): the list of fines the library
 * has imposed, with their fee-account status. Students' fines are
 * mirrored into the main fee ledger (LibraryFines service) — this
 * page is where staff see them and waive a pending one (waiving
 * also removes the unpaid fee entry; a paid fine is history).
 * Access mirrors the loans: teamLibrary, writes teamLibraryWrite.
 */
class LibraryFineController extends Controller
{
    public function __construct()
    {
        $this->middleware('teamLibrary');
        $this->middleware('teamLibraryWrite', ['only' => ['waive']]);
    }

    public function index(Request $request)
    {
        $status = $request->query('status');
        $query = LibraryFine::with(['user', 'book_request.book'])->orderByDesc('id');
        if (in_array($status, ['pending', 'paid', 'waived'], true)) {
            $query->where('status', $status);
        }
        $d['fines'] = $query->paginate(20)->withQueryString();
        $d['status'] = $status;
        $d['counts'] = [
            'pending' => LibraryFine::where('status', 'pending')->count(),
            'paid' => LibraryFine::where('status', 'paid')->count(),
            'waived' => LibraryFine::where('status', 'waived')->count(),
            'pending_amount' => (int) LibraryFine::where('status', 'pending')->sum('amount'),
        ];
        $d['rate'] = LibraryFines::dailyRate();

        return view('pages.support_team.library_fines.index', $d);
    }

    public function waive($id)
    {
        $fine = LibraryFine::findOrFail($id);
        if (!LibraryFines::waive($fine, Auth::id())) {
            return back()->with('flash_danger', 'Only a pending fine can be waived — this one is already ' . $fine->status . '.');
        }

        return back()->with('flash_success', 'Fine waived — the unpaid fee entry was removed from the student\'s account.');
    }
}
