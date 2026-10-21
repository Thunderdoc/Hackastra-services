{{--Marksheet--}}
<li class="nav-item">
    <a href="{{ route('marks.year_selector', Qs::hash(Auth::user()->id)) }}" class="nav-link {{ in_array(Route::currentRouteName(), ['marks.show', 'marks.year_selector', 'pins.enter']) ? 'active' : '' }}"><i class="icon-book"></i> Marksheet</a>
</li>

{{--Admit Card--}}
<li class="nav-item">
    <a href="{{ route('admit_cards.my') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['admit_cards.my', 'admit_cards.my_card']) ? 'active' : '' }}"><i class="icon-profile"></i> Admit Card</a>
</li>

{{--My Attendance--}}
<li class="nav-item">
    <a href="{{ route('attendance.my') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['attendance.my']) ? 'active' : '' }}"><i class="icon-calendar3"></i> My Attendance</a>
</li>
