@extends('layouts.master')
@section('page_title', 'Issue Certificate')
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
                    <small id="adm_no_lookup_msg" class="form-text text-muted">Type an admission number and press Find — the student below is selected automatically. You can also type the number straight into the student list's search.</small>
                </div>
                <div class="form-group">
                    <label for="student_record_id">Student <span class="text-danger">*</span></label>
                    <select name="student_record_id" id="student_record_id" class="form-control @error('student_record_id') is-invalid @enderror" required>
                        <option value="">Choose a student…</option>
                        @foreach($students as $st)
                            <option value="{{ $st->id }}" data-adm-no="{{ $st->adm_no }}" {{ (old('student_record_id', $preselected->id ?? '') == $st->id) ? 'selected' : '' }}>{{ $st->user->name }} ({{ $st->adm_no }}) — {{ optional($st->my_class)->name }} {{ optional($st->section)->name }}</option>
                        @endforeach
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
    <script>
        (function ($) {
            function findByAdmNo() {
                var typed = $.trim($('#adm_no_lookup').val()).toUpperCase();
                var $msg = $('#adm_no_lookup_msg');
                if (!typed) {
                    $msg.text('Type an admission number first.').removeClass('text-success').addClass('text-danger');
                    return;
                }
                var $opt = $('#student_record_id option').filter(function () {
                    return (($(this).data('adm-no') || '') + '').toUpperCase() === typed;
                });
                if ($opt.length) {
                    $('#student_record_id').val($opt.val()).trigger('change');
                    $msg.text('Found: ' + $opt.text()).removeClass('text-danger').addClass('text-success');
                } else {
                    $msg.text('No student with admission no. ' + typed + '.').removeClass('text-success').addClass('text-danger');
                }
            }
            $('#adm_no_lookup_btn').on('click', findByAdmNo);
            $('#adm_no_lookup').on('keydown', function (e) {
                if (e.which === 13) { e.preventDefault(); findByAdmNo(); }
            });
        })(jQuery);
    </script>
@endsection
