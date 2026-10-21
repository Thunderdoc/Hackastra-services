@extends('layouts.master')
@section('page_title', 'Student Promotion')
@section('content')
    {{-- Batch 3: rebuilt on the shared list_page pattern (Loop 28). The
         selector keeps its POST contract (students.promote_selector) and
         the promote list below keeps its own form, endpoints and
         per-student p-{id} selects — only the page shell changed. --}}
    @component('partials.patterns.list_page')
        @slot('title')
            <i class="icon-stairs-up mr-2"></i> Student Promotion
        @endslot
        @slot('subtitle')
            Student promotion from <span class="text-danger">{{ $old_year }}</span> to <span class="text-success">{{ $new_year }}</span> session. Choose the from and to class/section to load the students.
        @endslot

        @slot('filters')
            @include('pages.support_team.students.promotion.selector')
        @endslot

        @if($selected)
            <h6 class="font-weight-bold mb-3">Promote Students From <span class="text-teal">{{ $my_classes->where('id', $fc)->first()->name.' '.$sections->where('id', $fs)->first()->name }}</span> TO <span class="text-purple">{{ $my_classes->where('id', $tc)->first()->name.' '.$sections->where('id', $ts)->first()->name }}</span></h6>
            @include('pages.support_team.students.promotion.promote')
        @else
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-stairs-up',
                'message' => 'No class selected yet. Pick the from and to class/section above, then choose Manage Promotion to list the students.',
            ])
        @endif
    @endcomponent
@endsection
