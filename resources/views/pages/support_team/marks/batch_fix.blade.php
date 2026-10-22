@extends('layouts.master')
@section('page_title', 'Fix Mark Errors')
@section('content')
    {{-- Workstream BW: rebuilt on the shared form_page pattern.
         Was the legacy col-md-4 form-groups row inside a card
         whose header carried a second title ("Marks Correction").
         Shell only: same .ajax-update PUT to marks.batch_update,
         same three selects, bindings and the getClassSections()
         cascade; the existing danger "Fix Errors" action is kept
         verbatim via the actions slot. --}}
    @component('partials.patterns.form_page', [
        'form_action' => route('marks.batch_update'),
        'form_method' => 'PUT',
        'form_class' => 'ajax-update',
    ])
        <div class="pat-form-section">
            <div class="pat-fields">
                <div class="pat-field">
                    <label for="exam_id">Exam:</label>
                    <select required id="exam_id" name="exam_id" data-placeholder="Select Exam" class="form-control select">
                        @foreach($exams as $ex)
                            <option {{ $selected && $exam_id == $ex->id ? 'selected' : '' }} value="{{ $ex->id }}">{{ $ex->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-field">
                    <label for="my_class_id">Class:</label>
                    <select required onchange="getClassSections(this.value)" id="my_class_id" name="my_class_id" class="form-control select">
                        <option value="">Select Class</option>
                        @foreach($my_classes as $c)
                            <option {{ ($selected && $my_class_id == $c->id) ? 'selected' : '' }} value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-field">
                    <label for="section_id">Section:</label>
                    <select required id="section_id" name="section_id" data-placeholder="Select Class First" class="form-control select">
                        @if($selected)
                            @foreach($sections->where('my_class_id', $my_class_id) as $s)
                                <option {{ $section_id == $s->id ? 'selected' : '' }} value="{{ $s->id }}">{{ $s->name }}</option>
                            @endforeach
                        @endif
                    </select>
                </div>
            </div>
        </div>

        @slot('actions')
            <button type="submit" class="btn btn-danger">Fix Errors <i class="icon-wrench2 ml-2"></i></button>
        @endslot
    @endcomponent
@endsection
