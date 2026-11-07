@extends('layouts.master')
@section('page_title', 'School Licence')
@section('content')
    {{-- Round 5 Slice 4 (P14): the SA-maintained licence record
         for this school. Plan name exactly as sold — there are
         deliberately no price fields, and nothing gates on this
         record. The school Admin sees it read-only. --}}
  <div class="l106 l106-licence">
    @component('partials.patterns.form_page', [
        'form_action' => route('platform_licence.update'),
        'back' => route('dashboard'),
        'submit_label' => 'Save Licence Record',
    ])
        @slot('subtitle')
            The licence record for this school, exactly as sold. The school Admin sees this read-only on their dashboard.
        @endslot

        <div class="pat-form-section">
            <div class="pat-form-section-title"><span class="pat-sec-icon"><i class="icon-certificate"></i></span> Licence</div>
            <div class="pat-fields">
                <div class="pat-field">
                    <label for="plan_name">Plan name <span class="text-danger">*</span></label>
                    <input type="text" name="plan_name" id="plan_name" class="form-control" maxlength="100" value="{{ old('plan_name', $licence->plan_name ?? '') }}" required>
                    @error('plan_name')<span class="text-danger">{{ $message }}</span>@enderror
                </div>
                <div class="pat-field">
                    <label for="status">Status <span class="text-danger">*</span></label>
                    <select name="status" id="status" class="form-control">
                        @foreach($statuses as $status)
                            <option value="{{ $status }}" {{ old('status', $licence->status ?? '') === $status ? 'selected' : '' }}>{{ \App\Models\SchoolLicence::statusLabel($status) }}</option>
                        @endforeach
                    </select>
                    @error('status')<span class="text-danger">{{ $message }}</span>@enderror
                </div>
                <div class="pat-field">
                    <label for="starts_on">Starts on</label>
                    <input type="date" name="starts_on" id="starts_on" class="form-control" value="{{ old('starts_on', isset($licence) && $licence->starts_on ? $licence->starts_on->format('Y-m-d') : '') }}">
                    @error('starts_on')<span class="text-danger">{{ $message }}</span>@enderror
                </div>
                <div class="pat-field">
                    <label for="renews_on">Renews on</label>
                    <input type="date" name="renews_on" id="renews_on" class="form-control" value="{{ old('renews_on', isset($licence) && $licence->renews_on ? $licence->renews_on->format('Y-m-d') : '') }}">
                    @error('renews_on')<span class="text-danger">{{ $message }}</span>@enderror
                </div>
                <div class="pat-field pat-field-wide">
                    <label for="notes">Notes</label>
                    <textarea name="notes" id="notes" class="form-control" rows="3" maxlength="2000">{{ old('notes', $licence->notes ?? '') }}</textarea>
                    @error('notes')<span class="text-danger">{{ $message }}</span>@enderror
                </div>
            </div>
        </div>
    @endcomponent
  </div>{{-- /.l106-licence --}}
@endsection
