@extends('layouts.master')
@section('page_title', 'Graduated Students')
@section('content')
    {{-- Batch 3: class picker + ONE table on the shared list_page pattern
         (Loop 28), replacing the 11-tab stack of per-class tables. The
         table and every row action (profile, edit,
         reset password, not-graduated, marksheet, delete) are unchanged;
         filtering is server-side via ?class_id= on students.graduated. --}}
    @component('partials.patterns.list_page')
        @slot('title')
            <i class="icon-users mr-2"></i> Graduated Students
        @endslot
        @slot('subtitle')
            Students who have graduated. Pick a class to filter the list.
        @endslot

        @slot('filters')
            <form method="get" action="{{ route('students.graduated') }}" class="pat-filter-form">
                <div class="pat-filter-field">
                    <label for="class_id">Class:</label>
                    <select id="class_id" name="class_id" class="form-control select">
                        <option value="">All Classes</option>
                        @foreach($my_classes as $c)
                            <option {{ ($selected_class == $c->id) ? 'selected' : '' }} value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="pat-filter-action">
                    <button type="submit" class="btn btn-primary">Filter <i class="icon-paperplane ml-2"></i></button>
                </div>
            </form>
        @endslot

        @if($students->count())
            <div class="table-responsive">
            <table class="table">
                <thead>
                <tr>
                    <th>S/N</th>
                    <th>Photo</th>
                    <th>Name</th>
                    <th>ADM_No</th>
                    <th>Section</th>
                    <th>Grad Year</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                @foreach($students as $s)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>@if(Qs::hasRealPhoto($s->user->photo))<img class="rounded-circle" style="height: 40px; width: 40px;" src="{{ $s->user->photo }}" alt="photo">@else<span class="hk-ini" style="width:40px;height:40px;font-size:.8rem" aria-hidden="true">{{ Qs::initials($s->user->name) }}</span>@endif</td>
                        <td>{{ $s->user->name }}</td>
                        <td>{{ $s->adm_no }}</td>
                        <td>{{ $s->my_class->name.' '.$s->section->name }}</td>
                        <td>{{ $s->grad_date }}</td>
                        <td class="text-center">
                            <div class="list-icons">
                                <div class="dropdown">
                                    <a href="#" class="list-icons-item" data-toggle="dropdown">
                                        <i class="icon-menu9"></i>
                                    </a>

                                    <div class="dropdown-menu dropdown-menu-left">
                                        <a href="{{ route('students.show', Qs::hash($s->id)) }}" class="dropdown-item"><i class="icon-eye"></i> View Profile</a>
                                        @if(Qs::userIsTeamSAWrite())
                                            <a href="{{ route('students.edit', Qs::hash($s->id)) }}" class="dropdown-item"><i class="icon-pencil"></i> Edit</a>
                                            <form method="post" action="{{ route('st.reset_pass', Qs::hash($s->user->id)) }}" class="m-0" data-confirm="Reset this student's password to the default? They will need the default password to sign in again." data-confirm-title="Reset password" data-confirm-action="Reset password">@csrf<button type="submit" class="dropdown-item"><i class="icon-lock"></i> Reset password</button></form>

                                            {{--Not Graduated--}}
                                            <a id="{{ Qs::hash($s->id) }}" href="#" onclick="$('form#ng-'+this.id).submit();" class="dropdown-item"><i class="icon-stairs-down"></i> Not Graduated</a>
                                            <form method="post" id="ng-{{ Qs::hash($s->id) }}" action="{{ route('st.not_graduated', Qs::hash($s->id)) }}" class="hidden">@csrf @method('put')</form>
                                        @endif

                                        {{-- Marksheet action hidden for teachers (owner ruling 2026-10-22) — the route refuses them too. --}}
                                        @if(!Qs::userIsTeacher())
                                        <a target="_blank" href="{{ route('marks.year_selector', Qs::hash($s->user->id)) }}" class="dropdown-item"><i class="icon-check"></i> Marksheet</a>
                                        @endif

                                        {{--Delete--}}
                                        @if(Qs::userIsTeamSAWrite())
                                            <a id="{{ Qs::hash($s->user->id) }}" onclick="confirmDelete(this.id)" href="#" class="dropdown-item"><i class="icon-trash"></i> Delete</a>
                                            <form method="post" id="item-delete-{{ Qs::hash($s->user->id) }}" action="{{ route('students.destroy', Qs::hash($s->user->id)) }}" class="hidden">@csrf @method('delete')</form>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        @else
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-users',
                'message' => $selected_class
                    ? 'No graduated students found for the selected class yet.'
                    : 'No graduated students yet. Students appear here after promotion with the Graduated option.',
            ])
        @endif
    @endcomponent
@endsection
