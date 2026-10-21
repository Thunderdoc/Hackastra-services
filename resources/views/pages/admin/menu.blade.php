{{-- Admin menu (Workstream AB): the school Admin runs the whole school, so
     the items that used to be linked only from the Super Admin menu —
     Settings, Library, Pins — live here too. The route gates agree:
     settings is teamSA, books/book_requests are teamLibrary (SA + admin +
     librarian), pins are teamSA. The Audit Log stays Super Admin-only
     (platform oversight) and is deliberately not linked here. --}}

{{-- Batch 1B: group header in the teacher sidebar's design language --}}
<li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">School Setup</div><i class="icon-menu" title="School Setup"></i></li>

{{--Manage Settings--}}
<li class="nav-item">
    <a href="{{ route('settings') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['settings',]) ? 'active' : '' }}"><i class="icon-gear"></i> <span>Settings</span></a>
</li>

{{--Library--}}
<li class="nav-item">
    <a href="{{ route('books.index') }}" class="nav-link {{ (Route::is('books.*') || Route::is('book_requests.*')) ? 'active' : '' }}"><i class="icon-books"></i> <span>Library</span></a>
</li>

{{--Pins--}}
<li class="nav-item nav-item-submenu {{ in_array(Route::currentRouteName(), ['pins.create', 'pins.index']) ? 'nav-item-expanded nav-item-open' : '' }} ">
    <a href="#" class="nav-link"><i class="icon-lock2"></i> <span> Pins</span></a>

    <ul class="nav nav-group-sub" data-submenu-title="Manage Pins">
        {{--Generate Pins--}}
            <li class="nav-item">
                <a href="{{ route('pins.create') }}"
                   class="nav-link {{ (Route::is('pins.create')) ? 'active' : '' }}">Generate Pins</a>
            </li>

        {{--    Valid/Invalid Pins  --}}
        <li class="nav-item">
            <a href="{{ route('pins.index') }}"
               class="nav-link {{ (Route::is('pins.index')) ? 'active' : '' }}">View Pins</a>
        </li>
    </ul>
</li>
