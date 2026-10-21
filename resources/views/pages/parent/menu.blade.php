{{--My Children--}}
<li class="nav-item">
    <a href="{{ route('my_children') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['my_children']) ? 'active' : '' }}"><i class="icon-users4"></i> My Children</a>
</li>

{{--Admit Cards--}}
<li class="nav-item">
    <a href="{{ route('admit_cards.my') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['admit_cards.my', 'admit_cards.my_card']) ? 'active' : '' }}"><i class="icon-profile"></i> Admit Cards</a>
</li>

{{--Children Attendance--}}
<li class="nav-item">
    <a href="{{ route('attendance.my') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['attendance.my']) ? 'active' : '' }}"><i class="icon-calendar3"></i> Attendance</a>
</li>

{{--Fees--}}
<li class="nav-item">
    <a href="{{ route('my_fees') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['my_fees', 'my_fees.show']) ? 'active' : '' }}"><i class="icon-coins"></i> Fees</a>
</li>

{{--Circulars--}}
<li class="nav-item">
    <a href="{{ route('circulars.index') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['circulars.index', 'circulars.show']) ? 'active' : '' }}"><i class="icon-megaphone"></i> Circulars</a>
</li>