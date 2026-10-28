{{-- Batch 1B: group headers in the teacher sidebar's design language.
     Item order, routes, icons and active states are unchanged. --}}
<li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">Examinations</div><i class="icon-menu" title="Examinations"></i></li>

{{--Marksheet--}}
<li class="nav-item">
    <a href="{{ route('marks.year_selector', Qs::hash(Auth::user()->id)) }}" class="nav-link {{ in_array(Route::currentRouteName(), ['marks.show', 'marks.year_selector']) ? 'active' : '' }}" title="Marksheet"><i class="icon-book"></i> <span>Marksheet</span></a>
</li>

{{--Admit Card--}}
<li class="nav-item">
    <a href="{{ route('admit_cards.my') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['admit_cards.my', 'admit_cards.my_card']) ? 'active' : '' }}" title="Admit Card"><i class="icon-profile"></i> <span>Admit Card</span></a>
</li>

{{--Exam Date Sheet (owner-ordered module 2026-10-22): own class only--}}
<li class="nav-item">
    <a href="{{ route('exam_dates.index') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['exam_dates.index', 'exam_dates.show', 'exam_dates.print', 'exam_dates.pdf']) ? 'active' : '' }}" title="Exam Date Sheet"><i class="icon-calendar3"></i> <span>Exam Date Sheet</span></a>
</li>

<li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">Attendance</div><i class="icon-menu" title="Attendance"></i></li>

{{--My Attendance--}}
<li class="nav-item">
    <a href="{{ route('attendance.my') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['attendance.my']) ? 'active' : '' }}" title="My Attendance"><i class="icon-calendar3"></i> <span>My Attendance</span></a>
</li>

<li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">My School</div><i class="icon-menu" title="My School"></i></li>

{{--My Timetable--}}
<li class="nav-item">
    <a href="{{ route('tt.mine') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['tt.mine']) ? 'active' : '' }}" title="My Timetable"><i class="icon-table2"></i> <span>My Timetable</span></a>
</li>

{{--My ID Card--}}
<li class="nav-item">
    <a href="{{ route('id_card.mine') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['id_card.mine']) ? 'active' : '' }}" title="My ID Card"><i class="icon-vcard"></i> <span>My ID Card</span></a>
</li>

{{-- Workstream CS2 (LMS, owner-ordered module): new items only —
     nothing existing moved, renamed or removed. --}}
<li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">Learning</div><i class="icon-menu" title="Learning"></i></li>

{{--My Assignments--}}
<li class="nav-item">
    <a href="{{ route('assignments.index') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['assignments.*']) ? 'active' : '' }}" title="My Assignments"><i class="icon-file-text2"></i> <span>My Assignments</span></a>
</li>

{{--Study Materials--}}
<li class="nav-item">
    <a href="{{ route('materials.index') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['materials.*']) ? 'active' : '' }}" title="Study Materials"><i class="icon-book"></i> <span>Study Materials</span></a>
</li>

{{-- Circulars lives once in the shared sidebar (partials/menu) — Batch 6 (D13) removed the duplicate that used to sit here. --}}

{{-- Student portal (2026-10-23): own fees, own library, school
     events — new items only, appended after every existing item;
     nothing above was moved, renamed or removed. --}}
<li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">Fees</div><i class="icon-menu" title="Fees"></i></li>

{{--My Fees--}}
<li class="nav-item">
    <a href="{{ route('student.fees') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['student.fees', 'student.fees.receipt']) ? 'active' : '' }}" title="My Fees"><i class="icon-coins"></i> <span>My Fees</span></a>
</li>

<li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">Library &amp; Events</div><i class="icon-menu" title="Library & Events"></i></li>

{{--My Library--}}
<li class="nav-item">
    <a href="{{ route('student.library') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['student.library']) ? 'active' : '' }}" title="My Library"><i class="icon-book"></i> <span>My Library</span></a>
</li>

{{--School Events--}}
<li class="nav-item">
    <a href="{{ route('student.events') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['student.events']) ? 'active' : '' }}" title="School Events"><i class="icon-calendar3"></i> <span>School Events</span></a>
</li>

{{-- Wave 3: new items only, appended after every existing item. --}}
<li class="nav-item">
    <a href="{{ route('library_reservations.index') }}" class="nav-link {{ (Route::is('library_reservations.*')) ? 'active' : '' }}" title="Book Search & Reserve"><i class="icon-books"></i> <span>Book Search &amp; Reserve</span></a>
</li>
<li class="nav-item">
    <a href="{{ route('documents.index') }}" class="nav-link {{ (Route::is('documents.*')) ? 'active' : '' }}" title="My Documents"><i class="icon-book"></i> <span>My Documents</span></a>
</li>
<li class="nav-item">
    <a href="{{ route('electives.index') }}" class="nav-link {{ (Route::is('electives.*')) ? 'active' : '' }}" title="Elective Choices"><i class="icon-book"></i> <span>Elective Choices</span></a>
</li>

{{-- Student leave (owner order 2026-10-28): students apply, their
     class teacher approves. Appended last, nothing moved. --}}
<li class="nav-item">
    <a href="{{ route('leave.my') }}" class="nav-link {{ (Route::is('leave.my') || Route::is('leave.store')) ? 'active' : '' }}" title="My Leave"><i class="icon-calendar2"></i> <span>My Leave</span></a>
</li>
