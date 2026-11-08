@extends('layouts.master')
@section('page_title', 'Select Exam Year')
@section('content')
    {{-- Workstream BW: rebuilt on the shared form_page pattern.
         Was a narrow centred column (col-md-6 offset-md-3) inside
         a legacy card whose header repeated the page title —
         dropped. Shell only: same POST to marks.year_select, same
         year select and bindings. The select's select-lg sizing
         class is dropped so the field matches the app-wide input
         height (pattern consistency). --}}
    @if(!empty($latest_result))
        <div class="card sd-latest-result">
            <span class="sd-lr-ic"><i class="icon-book2"></i></span>
            <div class="sd-lr-copy">
                <strong>{{ $latest_result['exam'] }}</strong>
                <span>{{ $latest_result['term'] }} · {{ $latest_result['year'] }} · {{ $latest_result['subjects'] }} subjects · Overall <b>{{ $latest_result['percent'] }}%</b> — results published</span>
            </div>
            <a class="btn btn-primary" href="{{ route('marks.show', [$student_id, $latest_result['year']]) }}">View Marksheet</a>
        </div>
    @endif
    @component('partials.patterns.form_page', [
        'form_action' => route('marks.year_select', $student_id),
        'submit_label' => 'Submit',
        'form_compact' => true,
    ])
        <div class="pat-form-section">
            <div class="pat-fields">
                <div class="pat-field">
                    <label for="year">Select Exam Year:</label>
                    <select required id="year" name="year" data-placeholder="Select Exam Year" class="form-control select">
                        @foreach($years as $y)
                            <option value="{{ $y->year }}">{{ $y->year }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>
    @endcomponent
@endsection
