{{-- Batch 1B: group headers in the teacher sidebar's design language.
     Item order, routes, icons and active states are unchanged. --}}
<li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">Children</div><i class="icon-menu" title="Children"></i></li>

{{--My Children--}}
<li class="nav-item">
    <a href="{{ route('my_children') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['my_children']) ? 'active' : '' }}" title="My Children"><i class="icon-users4"></i> <span>My Children</span></a>
</li>

<li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">Examinations</div><i class="icon-menu" title="Examinations"></i></li>

{{--Admit Cards--}}
<li class="nav-item">
    <a href="{{ route('admit_cards.my') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['admit_cards.my', 'admit_cards.my_card']) ? 'active' : '' }}" title="Admit Cards"><i class="icon-profile"></i> <span>Admit Cards</span></a>
</li>

{{--Exam Date Sheet (owner-ordered module 2026-10-22): children's classes--}}
<li class="nav-item">
    <a href="{{ route('exam_dates.index') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['exam_dates.index', 'exam_dates.show', 'exam_dates.print', 'exam_dates.pdf']) ? 'active' : '' }}" title="Exam Date Sheet"><i class="icon-calendar3"></i> <span>Exam Date Sheet</span></a>
</li>

<li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">Attendance</div><i class="icon-menu" title="Attendance"></i></li>

{{--Children Attendance--}}
<li class="nav-item">
    <a href="{{ route('attendance.my') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['attendance.my']) ? 'active' : '' }}" title="Attendance"><i class="icon-calendar3"></i> <span>Attendance</span></a>
</li>

<li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">Fees</div><i class="icon-menu" title="Fees"></i></li>

{{--Fees--}}
<li class="nav-item">
    <a href="{{ route('my_fees') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['my_fees', 'my_fees.show']) ? 'active' : '' }}" title="Fees"><i class="icon-coins"></i> <span>Fees</span></a>
</li>

<li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">Academics</div><i class="icon-menu" title="Academics"></i></li>

{{--My Timetable--}}
<li class="nav-item">
    <a href="{{ route('tt.mine') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['tt.mine']) ? 'active' : '' }}" title="My Timetable"><i class="icon-table2"></i> <span>My Timetable</span></a>
</li>

{{--ID Cards--}}
<li class="nav-item">
    <a href="{{ route('id_card.mine') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['id_card.mine']) ? 'active' : '' }}" title="ID Cards"><i class="icon-vcard"></i> <span>ID Cards</span></a>
</li>

{{--Assignments — Workstream CS2 (LMS, owner-ordered module): new
     item only; read-only status + marks for their children. --}}
<li class="nav-item">
    <a href="{{ route('assignments.index') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['assignments.*']) ? 'active' : '' }}" title="Assignments"><i class="icon-file-text2"></i> <span>Assignments</span></a>
</li>

{{-- Circulars lives once in the shared sidebar (partials/menu) — Batch 6 (D13) removed the duplicate that used to sit here. --}}