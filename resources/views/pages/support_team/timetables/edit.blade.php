@extends('layouts.master')
@section('page_title', 'Edit TimeTable Record')
@section('content')
    {{-- Workstream BW: rebuilt on the shared form_page pattern.
         Was the legacy label-column card (fields in a col-md-8
         island) with the title repeated in the card header —
         dropped. Shell only: same plain PUT to ttr.update (Loop 112: post-save lands on the index),
         same fields and bindings. --}}
    @component('partials.patterns.form_page', [
        'form_action' => route('ttr.update', $ttr->id),
        'form_method' => 'PUT',
        'back' => route('tt.index'),
        'submit_label' => 'Submit form',
    ])
        <div class="pat-form-section">
            <div class="pat-fields">
                <div class="pat-field">
                    <label for="name">Name <span class="text-danger">*</span></label>
                    <input id="name" name="name" value="{{ $ttr->name }}" required type="text" class="form-control" placeholder="Name of TimeTable">
                </div>
                <div class="pat-field">
                    <label for="my_class_id">Class <span class="text-danger">*</span></label>
                    <select required data-placeholder="Select Class" class="form-control select" name="my_class_id" id="my_class_id" onchange="getClassSections(this.value, '#section_id')">
                        @foreach($my_classes as $mc)
                            <option {{ $ttr->my_class_id == $mc->id ? 'selected' : '' }} value="{{ $mc->id }}">{{ $mc->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-field">
                    <label for="section_id">Section</label>
                    <select class="form-control select" name="section_id" id="section_id">
                        <option value="">Whole class (all sections)</option>
                        @foreach($sections as $sec)
                            <option {{ $ttr->section_id == $sec->id ? 'selected' : '' }} value="{{ $sec->id }}">{{ $sec->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-field">
                    <label for="exam_id">Type (Class or Exam)</label>
                    <select class="select form-control" name="exam_id" id="exam_id">
                        <option value="">Class Timetable</option>
                        @foreach($exams as $ex)
                            <option {{ $ttr->exam_id == $ex->id ? 'selected' : '' }} value="{{ $ex->id }}">{{ $ex->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>
    @endcomponent
@endsection
