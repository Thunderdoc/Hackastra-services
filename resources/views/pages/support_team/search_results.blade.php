@extends('layouts.master')
@section('page_title', 'Search')
{{-- Beauty Batch 3 (audit B12): this page carries its own designed
     hero ("Results for …"), so the shared header band is hidden —
     the same hide_page_header opt-out Ask Hackstra / My Account
     use — leaving exactly one title surface. --}}
@section('hide_page_header', true)
@section('content')
    <div class="dashboard-hero mb-4">
        <div>
            <p class="dashboard-eyebrow mb-1">Global search</p>
            <h2 class="mb-1">Results for &ldquo;{{ $term }}&rdquo;</h2>
            <p class="mb-0 dashboard-muted">Students, staff, classes and payments you are allowed to see.</p>
        </div>
        <a href="{{ route('dashboard') }}" class="btn dashboard-primary-action">Back to dashboard</a>
    </div>

    @if(strlen($term) < 2)
        <div class="dashboard-panel">@include('partials.patterns.empty_state', ['icon' => 'icon-search4', 'message' => 'Type at least 2 characters to search.', 'compact' => true])</div>
    @else
        <section class="dashboard-panel mb-4">
            <div class="dashboard-panel-heading"><div><h5 class="mb-1">Students</h5></div></div>
            <div class="table-responsive"><table class="table hk-std hk-std-560 mb-0">{{-- Inside-Matter Job 2 (2026-11-08): hk-std standard — colgroup widths sum to 100%. --}}<colgroup><col style="width:44%"><col style="width:22%"><col style="width:18%"><col style="width:16%"></colgroup>
                <thead><tr><th>Student</th><th>Class</th><th>Section</th><th></th></tr></thead>
                <tbody>
                @forelse($students as $student)
                    <tr>
                        <td><strong>{{ $student->name }}</strong><small>{{ $student->email }}</small></td>
                        <td>{{ optional(optional($student->student_record)->my_class)->name ?: '—' }}</td>
                        <td>{{ optional(optional($student->student_record)->section)->name ?: '—' }}</td>
                        <td>@if($student->student_record)<a href="{{ route('students.show', \App\Helpers\Qs::hash($student->student_record->id)) }}" class="dashboard-view-all">View</a>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="4">@include('partials.patterns.empty_state', ['icon' => 'icon-search4', 'message' => 'No students match.', 'compact' => true])</td></tr>
                @endforelse
                </tbody></table></div>
        </section>

        <section class="dashboard-panel mb-4">
            <div class="dashboard-panel-heading"><div><h5 class="mb-1">Staff</h5></div></div>
            <div class="table-responsive"><table class="table hk-std hk-std-560 mb-0">{{-- Inside-Matter Job 2 (2026-11-08): hk-std standard — colgroup widths sum to 100%. --}}<colgroup><col style="width:34%"><col style="width:24%"><col style="width:42%"></colgroup>
                <thead><tr><th>Name</th><th>Role</th><th>Email</th></tr></thead>
                <tbody>
                @forelse($staff as $member)
                    <tr><td><strong>{{ $member->name }}</strong></td><td>{{ ucwords(str_replace('_', ' ', $member->user_type)) }}</td><td>{{ $member->email }}</td></tr>
                @empty
                    <tr><td colspan="3">@include('partials.patterns.empty_state', ['icon' => 'icon-search4', 'message' => 'No staff match.', 'compact' => true])</td></tr>
                @endforelse
                </tbody></table></div>
        </section>

        <div class="row">
            <div class="col-xl-6">
                <section class="dashboard-panel mb-4">
                    <div class="dashboard-panel-heading"><div><h5 class="mb-1">Classes</h5></div></div>
                    <div class="table-responsive"><table class="table hk-std hk-std-560 mb-0">{{-- Inside-Matter Job 2 (2026-11-08): hk-std standard — colgroup widths sum to 100%. --}}<colgroup><col style="width:76%"><col style="width:24%"></colgroup>
                        <tbody>
                        @forelse($classes as $class)
                            <tr><td><strong>{{ $class->name }}</strong></td><td><a href="{{ route('students.list', $class->id) }}" class="dashboard-view-all">Students</a></td></tr>
                        @empty
                            <tr><td>@include('partials.patterns.empty_state', ['icon' => 'icon-search4', 'message' => 'No classes match.', 'compact' => true])</td></tr>
                        @endforelse
                        </tbody></table></div>
                </section>
            </div>
            <div class="col-xl-6">
                <section class="dashboard-panel mb-4">
                    <div class="dashboard-panel-heading"><div><h5 class="mb-1">Payments</h5></div></div>
                    <div class="table-responsive"><table class="table hk-std hk-std-560 mb-0">{{-- Inside-Matter Job 2 (2026-11-08): hk-std standard — colgroup widths sum to 100%. --}}<colgroup><col style="width:72%"><col style="width:28%"></colgroup>
                        <tbody>
                        @forelse($payments as $payment)
                            <tr><td><strong>{{ $payment->title }}</strong><small>{{ $payment->ref_no }}</small></td><td class="hk-nw">{{ \App\Helpers\Money::inr($payment->amount) }}</td></tr>
                        @empty
                            <tr><td>@include('partials.patterns.empty_state', ['icon' => 'icon-search4', 'message' => 'No payments match.', 'compact' => true])</td></tr>
                        @endforelse
                        </tbody></table></div>
                </section>
            </div>
        </div>
    @endif
@endsection
