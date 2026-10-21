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
        $this->middleware('teamSA', ['only' => ['destroy']]);
    }

    public function index(Request $request)
    {
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
            ->orderBy('name')
            ->paginate(15);

        $d['books'] = $books;
        $d['q'] = $request->input('q');

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
