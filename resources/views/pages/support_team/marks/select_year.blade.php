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
    @component('partials.patterns.form_page', [
        'form_action' => route('marks.year_select', $student_id),
        'submit_label' => 'Submit',
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
