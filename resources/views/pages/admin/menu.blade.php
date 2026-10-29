{{-- Admin menu (Workstream AB): the school Admin runs the whole school, so
     the items that used to be linked only from the Super Admin menu —
     Settings — live here too. (Owner order 2026-10-25: the Library
     item was removed from the Admin panel completely — the Librarian
     runs the library, the Admin does not need it; see
     pages/librarian/menu.blade.php for the Librarian's Books link.)
     The route gates agree:
     settings is teamSA, books/book_requests are teamLibrary (SA +
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

{{--Audit Log (school trail — Workstream CM)--}}
<li class="nav-item">
    <a href="{{ route('audit_logs.index') }}" class="nav-link {{ (Route::is('audit_logs.index')) ? 'active' : '' }}"><i class="icon-history"></i> <span>Audit Log</span></a>
</li>
{{-- Overnight menus (2026-10-29, item 7): Reports & PDF Builder
     is out of the ADMIN menu only — teacher and principal keep
     their builder links, and the server gate is teacher+principal
     only. Link-only removal; the builder itself is untouched. --}}
<li class="nav-item">
    <a href="{{ route('analytics.index') }}" class="nav-link {{ (Route::is('analytics.*')) ? 'active' : '' }}"><i class="icon-stats-bars"></i> <span>Analytics Dashboard</span></a>
</li>
<li class="nav-item">
    <a href="{{ route('substitutions.index') }}" class="nav-link {{ (Route::is('substitutions.*')) ? 'active' : '' }}"><i class="icon-users"></i> <span>Substitutions</span></a>
</li>
{{-- Overnight menus (2026-10-29, item 16): Parent Messages is out
     of the admin menu (link only; the controller and routes stay). --}}
<li class="nav-item">
    <a href="{{ route('ptm.index') }}" class="nav-link {{ (Route::is('ptm.*')) ? 'active' : '' }}"><i class="icon-calendar3"></i> <span>PTM Meetings</span></a>
</li>
<li class="nav-item">
    <a href="{{ route('library_reservations.index') }}" class="nav-link {{ (Route::is('library_reservations.*')) ? 'active' : '' }}"><i class="icon-books"></i> <span>Library Reservations</span></a>
</li>
<li class="nav-item">
    <a href="{{ route('health_records.index') }}" class="nav-link {{ (Route::is('health_records.*')) ? 'active' : '' }}"><i class="icon-users"></i> <span>Health Records</span></a>
</li>
{{-- Overnight menus (2026-10-29, item 16): Discipline Records,
     Documents Vault, Alumni Directory, Elective Choices and Role
     Permissions are out of the admin menu (link only; every
     controller, route and view stays, so each restores by
     re-adding its line). --}}
