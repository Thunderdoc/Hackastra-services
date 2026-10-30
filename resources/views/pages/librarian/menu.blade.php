{{--Books / Library (restored in the Library module loop — BookController
     was an empty scaffold in Loop 2, so the dead "#" item was removed;
     it now points at the real catalogue and loans screens.)--}}
{{-- Batch 1B: group header in the teacher sidebar's design language --}}
<li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">Library</div><i class="icon-menu" title="Library"></i></li>
<li class="nav-item">
    <a href="{{ route('books.index') }}" class="nav-link {{ (Route::is('books.*') || Route::is('book_requests.*')) ? 'active' : '' }}"><i class="icon-books"></i> <span>Books</span></a>
</li>
{{-- Report Builder link removed (owner loophole review,
     2026-10-30): the builder is gated to teacher / principal /
     admin, so this link landed the librarian on a 403 — a button
     for something that cannot happen for the viewer. --}}
<li class="nav-item">
    <a href="{{ route('library_reservations.index') }}" class="nav-link {{ (Route::is('library_reservations.*')) ? 'active' : '' }}"><i class="icon-books"></i> <span>Book Reservations</span></a>
</li>
