{{-- Batch 1B: group headers in the teacher sidebar's design language.
     Item order, routes, icons and active states are unchanged. --}}
<li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">Children</div><i class="icon-menu" title="Children"></i></li>

{{--My Children--}}
<li class="nav-item">
    <a href="{{ route('my_children') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['my_children']) ? 'active' : '' }}"><i class="icon-users4"></i> My Children</a>
</li>

<li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">Examinations</div><i class="icon-menu" title="Examinations"></i></li>

{{--Admit Cards--}}
<li class="nav-item">
    <a href="{{ route('admit_cards.my') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['admit_cards.my', 'admit_cards.my_card']) ? 'active' : '' }}"><i class="icon-profile"></i> Admit Cards</a>
</li>

<li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">Attendance</div><i class="icon-menu" title="Attendance"></i></li>

{{--Children Attendance--}}
<li class="nav-item">
    <a href="{{ route('attendance.my') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['attendance.my']) ? 'active' : '' }}"><i class="icon-calendar3"></i> Attendance</a>
</li>

<li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">Fees</div><i class="icon-menu" title="Fees"></i></li>

{{--Fees--}}
<li class="nav-item">
    <a href="{{ route('my_fees') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['my_fees', 'my_fees.show']) ? 'active' : '' }}"><i class="icon-coins"></i> Fees</a>
</li>

<li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">Academics</div><i class="icon-menu" title="Academics"></i></li>

{{--My Timetable--}}
<li class="nav-item">
    <a href="{{ route('tt.mine') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['tt.mine']) ? 'active' : '' }}"><i class="icon-table2"></i> My Timetable</a>
</li>

{{--ID Cards--}}
<li class="nav-item">
    <a href="{{ route('id_card.mine') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['id_card.mine']) ? 'active' : '' }}"><i class="icon-vcard"></i> ID Cards</a>
</li>

{{-- Circulars lives once in the shared sidebar (partials/menu) — Batch 6 (D13) removed the duplicate that used to sit here. --}}