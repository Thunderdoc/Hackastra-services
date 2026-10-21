@extends('layouts.master')
@section('page_title', 'Manage TimeTable Record')
@section('content')

    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title font-weight-bold">{{ $ttr->name.' ('.$my_class->name.')'.' '.$ttr->year }}</h6>
            {!! Qs::getPanelOptions() !!}
        </div>

        <div class="card-body">
            <ul class="nav nav-tabs nav-tabs-highlight">
                <li class="nav-item"><a href="#manage-ts" class="nav-link active" data-toggle="tab">Manage Time Slots</a></li>
                <li class="nav-item"><a href="#add-sub" class="nav-link" data-toggle="tab">Add Subject</a></li>
                <li class="nav-item"><a href="#edit-subs" class="nav-link " data-toggle="tab">Edit Subjects</a></li>
                <li class="nav-item"><a target="_blank" href="{{ route('ttr.show', $ttr->id) }}" class="nav-link" >View Timetable</a></li>
            </ul>

            <div class="tab-content">
                {{--Add Time Slots--}}
                @include('pages.support_team.timetables.time_slots.index')
                {{--Add Subject--}}
                @include('pages.support_team.timetables.subjects.add')
                {{--Edit Subject--}}
                @include('pages.support_team.timetables.subjects.edit')
            </div>
        </div>
    </div>

    {{--TimeTable Manage Ends--}}

@endsection

@section('scripts')
<script>
    // Batch 11: the Edit Subjects tab uses ONE shared edit form in a modal;
    // each row's Edit button carries the record's values in data-*
    // attributes and the form is pointed at that record's update route.
    // Field names / method / endpoint are exactly the legacy per-card form's.
    $(document).on('click', '.ttx-edit-btn', function () {
        var $btn = $(this);
        var $form = $('#ttx-edit-form');
        $form.attr('action', $btn.data('update'));
        $form.find('[name="day"]').val($btn.data('day'));
        $form.find('[name="exam_date"]').val($btn.data('exam'));
        $form.find('[name="subject_id"]').val($btn.data('subject'));
        $form.find('[name="ts_id"]').val($btn.data('ts'));
        $('#ttx-edit-modal').modal('show');
    });
</script>
@endsection
