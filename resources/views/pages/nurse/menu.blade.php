{{-- Nurse navigation (owner order 2026-11-06): the clinic runs on
     its own login — Health Records, nothing else. Rendered from the
     sidebar for every user whose user_type is nurse; the pages
     themselves are nurse-gated in HealthRecordController. --}}
<li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">Clinic</div><i class="icon-menu" title="Clinic"></i></li>
<li class="nav-item">
    <a href="{{ route('dashboard') }}" class="nav-link {{ in_array(request()->path(), ['/', 'home', 'dashboard']) ? 'active' : '' }}"><i class="icon-home4"></i> <span>Dashboard</span></a>
</li>
<li class="nav-item">
    <a href="{{ route('health_records.index') }}" class="nav-link {{ (Route::is('health_records.*')) ? 'active' : '' }}"><i class="icon-heart6"></i> <span>Health Records</span></a>
</li>
<li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">Account</div><i class="icon-menu" title="Account"></i></li>
<li class="nav-item">
    <a href="{{ route('my_account') }}" class="nav-link {{ (Route::is('my_account')) ? 'active' : '' }}"><i class="icon-user"></i> <span>My Account</span></a>
</li>
