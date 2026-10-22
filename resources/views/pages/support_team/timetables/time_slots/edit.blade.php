@extends('layouts.master')
@section('page_title', 'Edit Time Slot')
@section('content')
    {{-- Workstream BW: rebuilt on the shared form_page pattern.
         Was the legacy label-column card (col-md-6 island, label
         col + three narrow select cols per time). Shell only: same
         plain PUT to ts.update with the hidden ttr_id, same six
         selects and $tms bindings; each Start/End time stays one
         compound field (hour/minute/meridian selects in a row)
         inside a full-width pattern field. Cancel returns to the
         record's Manage page, where this editor is reached from. --}}
    @component('partials.patterns.form_page', [
        'form_action' => route('ts.update', $tms->id),
        'form_method' => 'PUT',
        'back' => route('ttr.manage', $tms->ttr_id),
        'submit_label' => 'Submit form',
    ])
        <div class="pat-form-section">
            <input name="ttr_id" value="{{ $tms->ttr_id }}" type="hidden">
            <div class="pat-fields">
                <div class="pat-field pat-field-wide">
                    <label>Start Time <span class="text-danger">*</span></label>
                    <div class="row">
                        <div class="col-4">
                            <select data-placeholder="Hour" required class="select-search form-control" name="hour_from" id="hour_from">
                                <option value=""></option>
                                @for($t=1; $t<=12; $t++)
                                    <option {{ $tms->hour_from == $t ? 'selected' : '' }} value="{{ $t }}">{{ $t}}</option>
                                @endfor
                            </select>
                        </div>
                        <div class="col-4">
                            <select data-placeholder="Minute" required class="select-search form-control" name="min_from" id="min_from">
                                <option value=""></option>
                                <option value="00">00</option>
                                <option value="05">05</option>
                                @for($t=10; $t<=55; $t+=5)
                                    <option {{ $tms->min_from == $t ? 'selected' : '' }} value="{{ $t }}">{{ $t}}</option>
                                @endfor
                            </select>
                        </div>
                        <div class="col-4">
                            <select data-placeholder="Meridian" required class="select form-control" name="meridian_from" id="meridian_from">
                                <option value=""></option>
                                <option {{ $tms->meridian_from == 'AM' ? 'selected' : '' }} value="AM">AM</option>
                                <option {{ $tms->meridian_from == 'PM' ? 'selected' : '' }} value="PM">PM</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="pat-field pat-field-wide">
                    <label>End Time <span class="text-danger">*</span></label>
                    <div class="row">
                        <div class="col-4">
                            <select data-placeholder="Hour" required class="select-search form-control" name="hour_to" id="hour_to">
                                <option value=""></option>
                                @for($t=1; $t<=12; $t++)
                                    <option {{ $tms->hour_to == $t ? 'selected' : '' }} value="{{ $t }}">{{ $t}}</option>
                                @endfor
                            </select>
                        </div>
                        <div class="col-4">
                            <select data-placeholder="Minute" required class="select-search form-control" name="min_to" id="min_to">
                                <option value=""></option>
                                <option value="00">00</option>
                                <option value="05">05</option>
                                @for($t=10; $t<=55; $t+=5)
                                    <option {{ $tms->min_to == $t ? 'selected' : '' }} value="{{ $t }}">{{ $t}}</option>
                                @endfor
                            </select>
                        </div>
                        <div class="col-4">
                            <select data-placeholder="Meridian" required class="select form-control" name="meridian_to" id="meridian_to">
                                <option value=""></option>
                                <option {{ $tms->meridian_to == 'AM' ? 'selected' : '' }} value="AM">AM</option>
                                <option {{ $tms->meridian_to == 'PM' ? 'selected' : '' }} value="PM">PM</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endcomponent
@endsection
