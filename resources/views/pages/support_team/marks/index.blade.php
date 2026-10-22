@extends('layouts.master')
@section('page_title', 'Manage Exam Marks')
@section('content')
    {{-- Workstream BW: shell rebuilt — the page is only the marks
         picker, so it now renders as one pat-form-card holding the
         (also rebuilt) selector form. The legacy card header that
         repeated the page title is gone; the common header carries
         the title. --}}
    <div class="card pat-form-card">
        @include('pages.support_team.marks.selector')
    </div>
@endsection
