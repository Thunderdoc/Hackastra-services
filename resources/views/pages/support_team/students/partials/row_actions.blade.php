{{-- Loop 83: the student row action menu, shared by the list table
     and the grid cards on the Student Information page. Items and
     permission gates are the page's long-standing set — View Profile
     for everyone who can open the list, Edit / Reset password /
     Delete for teamSAWrite, Marksheet for all, Payments for team
     accounts, ID Card for teamSA. --}}
<div class="list-icons">
    <div class="dropdown">
        <a href="#" class="list-icons-item" data-toggle="dropdown" aria-label="Actions for {{ $s->user->name }}">
            <i class="icon-more2"></i>
        </a>

        <div class="dropdown-menu dropdown-menu-left">
            <a href="{{ route('students.show', Qs::hash($s->id)) }}" class="dropdown-item"><i class="icon-eye"></i> View Profile</a>
            @if(Qs::userIsTeamSAWrite())
                <a href="{{ route('students.edit', Qs::hash($s->id)) }}" class="dropdown-item"><i class="icon-pencil"></i> Edit</a>
                <form method="post" action="{{ route('st.reset_pass', Qs::hash($s->user->id)) }}" class="m-0" data-confirm="Reset this student's password to the default? They will need the default password to sign in again." data-confirm-title="Reset password" data-confirm-action="Reset password">@csrf<button type="submit" class="dropdown-item"><i class="icon-lock"></i> Reset password</button></form>
            @endif
            {{-- Marksheet action hidden for teachers (owner ruling 2026-10-22) — the route refuses them too. --}}
            @if(!Qs::userIsTeacher())
            <a target="_blank" href="{{ route('marks.year_selector', Qs::hash($s->user->id)) }}" class="dropdown-item"><i class="icon-check"></i> Marksheet</a>
            @endif
            @if(Qs::userIsTeamAccount())
                <a href="{{ route('payments.invoice', Qs::hash($s->user->id)) }}" class="dropdown-item"><i class="icon-coins"></i> Payments</a>
            @endif
            @if(Qs::userIsTeamSA())
                <a target="_blank" href="{{ route('students.id_card', Qs::hash($s->id)) }}" class="dropdown-item"><i class="icon-vcard"></i> ID Card</a>
            @endif

            {{--Delete--}}
            @if(Qs::userIsTeamSAWrite())
                <a id="{{ Qs::hash($s->user->id) }}" onclick="confirmDelete(this.id)" href="#" class="dropdown-item"><i class="icon-trash"></i> Delete</a>
                <form method="post" id="item-delete-{{ Qs::hash($s->user->id) }}" action="{{ route('students.destroy', Qs::hash($s->user->id)) }}" class="hidden">@csrf @method('delete')</form>
            @endif
        </div>
    </div>
</div>
