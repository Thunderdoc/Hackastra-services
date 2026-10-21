{{-- Batch 1B: group headers in the teacher sidebar's design language.
     Item order, routes, icons and active states are unchanged. --}}
<li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">Examinations</div><i class="icon-menu" title="Examinations"></i></li>

{{--Marksheet--}}
<li class="nav-item">
    <a href="{{ route('marks.year_selector', Qs::hash(Auth::user()->id)) }}" class="nav-link {{ in_array(Route::currentRouteName(), ['marks.show', 'marks.year_selector']) ? 'active' : '' }}"><i class="icon-book"></i> Marksheet</a>
</li>

{{--Admit Card--}}
<li class="nav-item">
    <a href="{{ route('admit_cards.my') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['admit_cards.my', 'admit_cards.my_card']) ? 'active' : '' }}"><i class="icon-profile"></i> Admit Card</a>
</li>

<li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">Attendance</div><i class="icon-menu" title="Attendance"></i></li>

{{--My Attendance--}}
<li class="nav-item">
    <a href="{{ route('attendance.my') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['attendance.my']) ? 'active' : '' }}"><i class="icon-calendar3"></i> My Attendance</a>
</li>

<li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">My School</div><i class="icon-menu" title="My School"></i></li>

{{--My Timetable--}}
<li class="nav-item">
    <a href="{{ route('tt.mine') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['tt.mine']) ? 'active' : '' }}"><i class="icon-table2"></i> My Timetable</a>
</li>

{{--My ID Card--}}
<li class="nav-item">
    <a href="{{ route('id_card.mine') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['id_card.mine']) ? 'active' : '' }}"><i class="icon-vcard"></i> My ID Card</a>
</li>

{{-- Circulars lives once in the shared sidebar (partials/menu) — Batch 6 (D13) removed the duplicate that used to sit here. --}}
