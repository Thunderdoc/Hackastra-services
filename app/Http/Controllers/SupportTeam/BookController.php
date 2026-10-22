<?php

namespace App\Http\Controllers\SupportTeam;

use App\Http\Controllers\Controller;
use App\Http\Requests\Book\BookSave;
use App\Models\Book;
use App\Models\MyClass;
use Illuminate\Http\Request;

/**
 * Library catalogue management (UI/UX sweep — Library module).
 * Access: Super Admin, Admin and Librarian (teamLibrary middleware);
 * deleting a catalogue record stays Super Admin only, mirroring the
 * School Events module's gating.
 */
class BookController extends Controller
{
    public function __construct()
    {
        $this->middleware('teamLibrary');
        // Workstream CM: book writes (incl. delete, previously
        // teamSA) belong to the school — Admin + Librarian.
        $this->middleware('teamLibraryWrite', ['only' => ['create', 'store', 'edit', 'update', 'destroy']]);
    }

    public function index(Request $request)
    {
        $type = $request->input('type');
        $classId = $request->input('class_id');
        $availability = $request->input('availability');
        $sort = $request->input('sort', 'title_asc');

        $books = Book::with('myClass')
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->input('q');
                $query->where(function ($sub) use ($q) {
                    $sub->where('name', 'like', "%{$q}%")
                        ->orWhere('author', 'like', "%{$q}%")
                        ->orWhere('book_type', 'like', "%{$q}%")
                        ->orWhere('location', 'like', "%{$q}%");
                });
            })
            ->when($type, function ($query) use ($type) {
                $query->where('book_type', $type);
            })
            ->when($classId, function ($query) use ($classId) {
                $query->where('my_class_id', $classId);
            })
            // Availability mirrors the status pill on each row exactly:
            // Available = nothing out and at least one copy owned,
            // Partially Issued = some out, some still on the shelf,
            // All Issued = no copy left on the shelf.
            ->when($availability === 'available', function ($query) {
                $query->whereRaw('COALESCE(issued_copies, 0) = 0')
                    ->whereRaw('COALESCE(total_copies, 0) > 0');
            })
            ->when($availability === 'partial', function ($query) {
                $query->whereRaw('COALESCE(issued_copies, 0) > 0')
                    ->whereRaw('COALESCE(total_copies, 0) - COALESCE(issued_copies, 0) > 0');
            })
            ->when($availability === 'issued', function ($query) {
                $query->whereRaw('COALESCE(total_copies, 0) - COALESCE(issued_copies, 0) <= 0');
            })
            ->when($sort === 'title_desc', function ($query) {
                $query->orderByDesc('name');
            })
            ->when($sort === 'newest', function ($query) {
                $query->orderByDesc('created_at')->orderByDesc('id');
            })
            ->when($sort !== 'title_desc' && $sort !== 'newest', function ($query) {
                $query->orderBy('name');
            })
            ->paginate(15)
            ->withQueryString();

        // Headline numbers for the WHOLE catalogue (never the filtered
        // subset): titles owned, physical copies, copies out on loan
        // (the issued_copies counter BookRequestController keeps in step
        // with book_requests) and copies on the shelf.
        $agg = Book::query()->selectRaw(
            'COUNT(*) as titles, '
            . 'COALESCE(SUM(COALESCE(total_copies, 0)), 0) as copies, '
            . 'COALESCE(SUM(COALESCE(issued_copies, 0)), 0) as issued, '
            . 'COALESCE(SUM(CASE WHEN COALESCE(total_copies, 0) - COALESCE(issued_copies, 0) > 0 '
            . 'THEN COALESCE(total_copies, 0) - COALESCE(issued_copies, 0) ELSE 0 END), 0) as available'
        )->first();

        $d['books'] = $books;
        $d['q'] = $request->input('q');
        $d['type'] = $type;
        $d['class_id'] = $classId;
        $d['availability'] = $availability;
        $d['sort'] = in_array($sort, ['title_asc', 'title_desc', 'newest'], true) ? $sort : 'title_asc';
        $d['types'] = Book::types();
        $d['classes'] = MyClass::orderBy('name')->get(['id', 'name']);
        $d['stats'] = [
            'titles' => (int) $agg->titles,
            'copies' => (int) $agg->copies,
            'issued' => (int) $agg->issued,
            'available' => (int) $agg->available,
        ];

        return view('pages.support_team.books.index', $d);
    }

    public function create()
    {
        $d['types'] = Book::types();
        $d['classes'] = MyClass::orderBy('name')->get(['id', 'name']);

        return view('pages.support_team.books.create', $d);
    }

    public function store(BookSave $request)
    {
        $data = $request->validated();
        $data['issued_copies'] = 0;
        Book::create($data);

        return redirect()->route('books.index')->with('flash_success', 'Book added to the catalogue.');
    }

    public function edit($id)
    {
        $d['book'] = Book::findOrFail($id);
        $d['types'] = Book::types();
        $d['classes'] = MyClass::orderBy('name')->get(['id', 'name']);

        return view('pages.support_team.books.edit', $d);
    }

    public function update(BookSave $request, $id)
    {
        $book = Book::findOrFail($id);
        $book->update($request->validated());

        // Never let issued copies exceed the copies the library owns.
        if ((int) $book->issued_copies > (int) $book->total_copies) {
            $book->issued_copies = (int) $book->total_copies;
            $book->save();
        }

        return redirect()->route('books.index')->with('flash_success', 'Book updated.');
    }

    public function destroy($id)
    {
        $book = Book::findOrFail($id);

        if ($book->activeRequests()->exists()) {
            return back()->with('flash_danger', 'This book still has copies out on loan. It can be deleted once every copy is returned.');
        }

        $book->delete();

        return back()->with('flash_success', __('msg.del_ok'));
    }
}
