<?php

namespace App\Http\Controllers\SupportTeam;

use App\Http\Controllers\Controller;
use App\Http\Requests\Book\BookSave;
use App\Models\Book;
use App\Models\MyClass;
use App\Support\Audit;
use Illuminate\Http\Request;

/**
 * Library catalogue management (UI/UX sweep — Library module).
 * Access: Super Admin and Librarian (teamLibrary middleware);
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

    /**
     * Round 5 Slice 3 (P12) — the catalogue's ONE filtered query.
     * index() paginates it and export() streams it, so the CSV is
     * always exactly the set on screen (q / type / class_id /
     * availability / sort all honoured by both).
     */
    protected function filteredQuery(Request $request)
    {
        $type = $request->input('type');
        $classId = $request->input('class_id');
        $availability = $request->input('availability');
        $sort = $request->input('sort', 'title_asc');

        return Book::with('myClass')
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
            });
    }

    public function index(Request $request)
    {
        $type = $request->input('type');
        $classId = $request->input('class_id');
        $availability = $request->input('availability');
        $sort = $request->input('sort', 'title_asc');

        $books = $this->filteredQuery($request)
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

    /**
     * P12: CSV export of the SAME filtered set index() shows —
     * the columns mirror the index table's own (Book, Type,
     * Class, Location, Copies, Status); pagination is ignored.
     * Gated by the constructor's teamLibrary middleware, exactly
     * like the catalogue page itself.
     */
    public function export(Request $request)
    {
        $rows = $this->filteredQuery($request)->get()->map(function ($book) {
            $available = $book->availableCopies();
            if ((int) $book->issued_copies > 0 && $available > 0) {
                $status = 'Partially Issued';
            } elseif ($available > 0) {
                $status = 'Available';
            } else {
                $status = 'All Issued';
            }

            return [
                $book->name,
                $book->author,
                $book->book_type,
                optional($book->myClass)->name,
                $book->location,
                (int) $book->total_copies,
                (int) $book->issued_copies,
                $available,
                $status,
            ];
        });

        return \App\Support\CsvExport::download(
            'library-books-' . now()->format('Ymd-Hi') . '.csv',
            ['Title', 'Author', 'Type', 'Class', 'Location', 'Total Copies', 'Issued Copies', 'Available Copies', 'Status'],
            $rows
        );
    }

    /**
     * Round 5 Slice 3 (P10) — CSV export of the SELECTED books
     * only: the P12 export column set, filtered to the posted ids
     * inside the catalogue's own filtered query (the constructor's
     * teamLibrary gate is the scope — the same gate as the index
     * and the filtered export). There is deliberately NO bulk
     * delete: catalogue rows carry loans.
     */
    public function exportSelected(Request $request)
    {
        $raw = $request->input('ids', []);
        $ids = is_array($raw) ? collect($raw)
            ->filter(function ($id) { return is_numeric($id); })
            ->map(function ($id) { return (int) $id; })
            ->filter(function ($id) { return $id > 0; })
            ->unique()->values()->all() : [];

        if (!$ids) {
            return back()->with('flash_danger', 'Nothing selected — tick at least one book first.');
        }

        $books = $this->filteredQuery($request)->whereIn('id', $ids)->get();
        if ($books->isEmpty()) {
            return back()->with('flash_danger', 'None of the selected books are in the catalogue.');
        }

        Audit::log('book.bulk_exported', Book::class,
            'Exported ' . $books->count() . ' selected book(s) to CSV.');

        $rows = $books->map(function ($book) {
            $available = $book->availableCopies();
            if ((int) $book->issued_copies > 0 && $available > 0) {
                $status = 'Partially Issued';
            } elseif ($available > 0) {
                $status = 'Available';
            } else {
                $status = 'All Issued';
            }

            return [
                $book->name,
                $book->author,
                $book->book_type,
                optional($book->myClass)->name,
                $book->location,
                (int) $book->total_copies,
                (int) $book->issued_copies,
                $available,
                $status,
            ];
        });

        return \App\Support\CsvExport::download(
            'library-books-selected-' . now()->format('Ymd-Hi') . '.csv',
            ['Title', 'Author', 'Type', 'Class', 'Location', 'Total Copies', 'Issued Copies', 'Available Copies', 'Status'],
            $rows
        );
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
