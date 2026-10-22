@extends('layouts.master')
@section('page_title', 'My Children')
@section('content')
    {{-- Loop 108: rebuilt on the shared list_page pattern (Loop 28).
         Was a plain legacy card whose row action was a bare
         icon-menu9 glyph dropdown (the pattern Workstream BW
         replaced app-wide — it rendered as a stray "≡"). Now the
         labelled Actions dropdown button; the two items inside are
         unchanged. The table carries l108-stack so every column
         stays visible on phone widths. Header label "ADM_No"
         corrected to "Adm No". --}}
    @component('partials.patterns.list_page')
        @slot('title')
            <i class="icon-users mr-2"></i> My Children
        @endslot
        @slot('subtitle')
            Your children linked to this account.
        @endslot

        @if($students->count())
            <table class="table l108-stack">
                <thead>
                <tr>
                    <th>S/N</th>
                    <th>Photo</th>
                    <th>Name</th>
                    <th>Adm No</th>
                    <th>Class / Section</th>
                    <th>Email</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                @foreach($students as $s)
                    <tr>
                        <td data-label="S/N">{{ $loop->iteration }}</td>
                        <td data-label="Photo">@if(Qs::hasRealPhoto($s->user->photo))<img class="rounded-circle" style="height: 40px; width: 40px;" src="{{ $s->user->photo }}" alt="photo">@else<span class="hk-ini" style="width:40px;height:40px;font-size:.8rem" aria-hidden="true">{{ Qs::initials($s->user->name) }}</span>@endif</td>
                        <td data-label="Name">{{ $s->user->name }}</td>
                        <td data-label="Adm No">{{ $s->adm_no }}</td>
                        <td data-label="Class / Section">{{ $s->my_class->name.' '.$s->section->name }}</td>
                        <td data-label="Email">{{ $s->user->email }}</td>
                        <td class="text-center" data-label="Action">
                            <div class="dropdown">
                                <button type="button" class="btn btn-sm btn-primary dropdown-toggle" data-toggle="dropdown">Actions</button>
                                <div class="dropdown-menu dropdown-menu-right">
                                    <a href="{{ route('students.show', Qs::hash($s->id)) }}" class="dropdown-item"><i class="icon-eye"></i> View Profile</a>
                                    <a target="_blank" href="{{ route('marks.year_selector', Qs::hash($s->user->id)) }}" class="dropdown-item"><i class="icon-check"></i> Marksheet</a>
                                </div>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @else
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-users',
                'message' => 'No children are linked to your account yet. Please contact the school office so they can link your child to your account.',
            ])
        @endif
    @endcomponent

@endsection
