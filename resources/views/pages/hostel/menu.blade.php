{{--Hostel Warden navigation (overnight menus 2026-10-29): the hostel
    runs on its own login — dorms, rooms and allocations, nothing
    else. Rendered from the sidebar for every user whose user_type
    is hostel; the pages themselves are warden-gated in
    HostelController. --}}
<li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">Hostel</div><i class="icon-menu" title="Hostel"></i></li>
<li class="nav-item">
    <a href="{{ route('hostel.dorms.index') }}" class="nav-link {{ (Route::is('hostel.dorms.*')) ? 'active' : '' }}"><i class="icon-home9"></i> <span>Dorms</span></a>
</li>
<li class="nav-item">
    <a href="{{ route('hostel.rooms.index') }}" class="nav-link {{ (Route::is('hostel.rooms.*')) ? 'active' : '' }}"><i class="icon-home9"></i> <span>Rooms</span></a>
</li>
<li class="nav-item">
    <a href="{{ route('hostel.allocations.index') }}" class="nav-link {{ (Route::is('hostel.allocations.*')) ? 'active' : '' }}"><i class="icon-users4"></i> <span>Allocations</span></a>
</li>
<li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">Account</div><i class="icon-menu" title="Account"></i></li>
<li class="nav-item">
    <a href="{{ route('my_account') }}" class="nav-link {{ (Route::is('my_account')) ? 'active' : '' }}"><i class="icon-user"></i> <span>My Account</span></a>
</li>
