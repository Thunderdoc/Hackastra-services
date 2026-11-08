@extends('layouts.master')
@section('page_title', 'Issue Certificate')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Certificates &nbsp;/&nbsp; <a href="{{ route('certificates.index') }}">Certificates</a> &nbsp;/&nbsp; {!! $__env->yieldContent('page_title') !!}
@endsection
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Issue Certificate</h6>
            {!! Qs::getPanelOptions() !!}
        </div>
        <div class="card-body">
            <p class="text-muted">The serial is minted by the system (BON/TC + year + sequence) and the student's details are snapshotted — the certificate never changes afterwards.</p>
            <form method="POST" action="{{ route('certificates.store') }}">
                @csrf
                <div class="form-group">
                    <label for="adm_no_lookup">Find by Admission No.</label>
                    <div class="input-group">
                        <input type="text" id="adm_no_lookup" class="form-control" placeholder="e.g. HA/PN/2026/91300" value="{{ request('adm_no') }}">
                        <div class="input-group-append">
                            <button type="button" id="adm_no_lookup_btn" class="btn btn-light">Find</button>
                        </div>
                    </div>
                    <small id="adm_no_lookup_msg" class="form-text text-muted">Type an admission number and press Find — the class, section and student below are selected automatically.</small>
                </div>
                <div class="form-group">
                    <label>Class</label>
                    <select class="form-control hk-cascade-class">
                        <option value="">Choose a class…</option>
                        @foreach($classes as $hkClass)
                            <option value="{{ $hkClass->id }}">{{ $hkClass->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label>Section</label>
                    <select class="form-control hk-cascade-section">
                        <option value="">Choose a class first</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="student_record_id">Student <span class="text-danger">*</span></label>
                    <select name="student_record_id" id="student_record_id" class="form-control hk-cascade-student @error('student_record_id') is-invalid @enderror" required data-value-key="id"
                            @if($preselected) data-preselect-class="{{ $preselected->my_class_id }}" data-preselect-section="{{ $preselected->section_id }}" data-preselect-value="{{ $preselected->id }}" data-preselect-label="{{ optional($preselected->user)->name }} ({{ $preselected->adm_no }})" @endif>
                        <option value="">Choose a student…</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="type">Type <span class="text-danger">*</span></label>
                    <select name="type" id="type" class="form-control @error('type') is-invalid @enderror" required>
                        <option value="bonafide" {{ old('type', $type) === 'bonafide' ? 'selected' : '' }}>Bonafide Certificate</option>
                        <option value="transfer" {{ old('type', $type) === 'transfer' ? 'selected' : '' }}>Transfer Certificate</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="reason">Reason <span class="text-muted">(required for Transfer)</span></label>
                    <textarea name="reason" id="reason" rows="2" maxlength="1000" class="form-control @error('reason') is-invalid @enderror" placeholder="e.g. Parent transferred to another city">{{ old('reason') }}</textarea>
                </div>
                <button type="submit" class="btn btn-success">Issue &amp; Print</button>
                <a href="{{ route('certificates.index') }}" class="btn btn-light">Back to Register</a>
            </form>
        </div>
    </div>
@endsection

@section('scripts')
    @include('partials.student_cascade_js')
    <script>
        (function ($) {
            function findByAdmNo() {
                var typed = $.trim($('#adm_no_lookup').val());
                var $msg = $('#adm_no_lookup_msg');
                if (!typed) {
                    $msg.text('Type an admission number first.').removeClass('text-success').addClass('text-danger');
                    return;
                }
                $.getJSON(@json(route('get_student_by_adm_no')), { adm_no: typed }, function (record) {
                    if (record && record.id) {
                        window.hkCascadeSetStudent(record, function (ok) {
                            if (ok) {
                                $msg.text('Found: ' + record.name + ' (' + record.adm_no + ')').removeClass('text-danger').addClass('text-success');
                            }
                        });
                    } else {
                        $msg.text('No student with admission no. ' + typed.toUpperCase() + '.').removeClass('text-success').addClass('text-danger');
                    }
                });
            }
            $('#adm_no_lookup_btn').on('click', findByAdmNo);
            $('#adm_no_lookup').on('keydown', function (e) {
                if (e.which === 13) { e.preventDefault(); findByAdmNo(); }
            });
        })(jQuery);
    </script>
@endsection
