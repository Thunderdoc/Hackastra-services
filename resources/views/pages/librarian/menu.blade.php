{{--Books / Library (restored in the Library module loop — BookController
     was an empty scaffold in Loop 2, so the dead "#" item was removed;
     it now points at the real catalogue and loans screens.)--}}
<li class="nav-item">
    <a href="{{ route('books.index') }}" class="nav-link {{ (Route::is('books.*') || Route::is('book_requests.*')) ? 'active' : '' }}"><i class="icon-books"></i> <span>Books</span></a>
</li>
