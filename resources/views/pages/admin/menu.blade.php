{{-- Admin menu (Workstream AB): the school Admin runs the whole school, so
     the items that used to be linked only from the Super Admin menu —
     Settings, Library — live here too. The route gates agree:
     settings is teamSA, books/book_requests are teamLibrary (SA + admin +
     librarian). Workstream CM (owner boundary): the school Admin also
     reads the school's audit trail — the audit_logs route is teamSA
     and the controller scopes the Admin to non-Super-Admin entries —
     so the Audit Log is linked here too.
     (Workstream BH: the Pins item was removed with the PIN system.) --}}

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

{{--Audit Log (school trail — Workstream CM)--}}
<li class="nav-item">
    <a href="{{ route('audit_logs.index') }}" class="nav-link {{ (Route::is('audit_logs.index')) ? 'active' : '' }}"><i class="icon-history"></i> <span>Audit Log</span></a>
</li>
