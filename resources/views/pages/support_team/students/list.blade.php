@extends('layouts.master')
@section('page_title', 'Student Information - '.$my_class->name)
@section('content')

    {{-- Loop 31 (Fix 4): print-only document header/footer — hidden on
         screen (Loop 31 CSS), shown by window.print() so the student
         list prints as a professional school document. --}}
    <div class="print-doc-head">
        <img src="{{ Qs::getLogo() }}" alt="">
        <div>
            <h1>{{ Qs::getSystemName() }}</h1>
            <p>Student Information &mdash; {{ $my_class->name }} &middot; Session: {{ Qs::getCurrentSession() }} &middot; Total Students: {{ $students->count() }}</p>
        </div>
    </div>

    {{-- Batch 3B: rebuilt on the shared list_page pattern (Loop 28).
         The legacy DataTables stack (five tables across section tabs,
         Copy/Excel/PDF/Visibility chrome duplicating the app Export)
         is replaced by ONE server-filtered table: the section picker
         and search are a GET filter (StudentRecordController@listByClass
         reads ?section_id= / ?q= additively). The print contract is
         unchanged — print-doc-head/foot above/below, print-hide on the
         photo/action/checkbox columns, print-nowrap on S/N + ADM_No —
         and the bulk-delete bar + script below are the Workstream AA
         originals, with the confirmation flowing through the styled
         modal helper (Workstream AY) exactly as on master. --}}
    @component('partials.patterns.list_page')
        @slot('title')
            <i class="icon-users mr-2"></i> Students &mdash; {{ $my_class->name }}
        @endslot
        @slot('subtitle')
            {{ $students->count() }} {{ $students->count() == 1 ? 'student' : 'students' }} &middot; Session {{ Qs::getCurrentSession() }}@if($selected_section || $q !== '') &middot; Showing {{ $listed->count() }}@endif
        @endslot
        @slot('action')
            <button type="button" class="btn btn-light btn-sm" onclick="window.print()"><i class="icon-printer mr-1"></i> Print</button>
            <a href="{{ route('students.export', $selected_section ? [$my_class->id, $selected_section->id] : $my_class->id) }}" class="btn btn-primary btn-sm"><i class="icon-download mr-1"></i> Export{{ $selected_section ? ' Section' : '' }}</a>
            @if(Qs::userIsTeamSA())
                <a href="{{ route('students.import') }}" class="btn btn-success btn-sm"><i class="icon-upload mr-1"></i> Import CSV</a>
            @endif
        @endslot
        @slot('filters')
            <form method="get" action="{{ route('students.list', $my_class->id) }}" class="pat-filter-form">
                <div class="pat-filter-field">
                    <label for="section_id">Section:</label>
                    <select id="section_id" name="section_id" class="form-control">
                        <option value="">All Sections</option>
                        @foreach($sections as $s)
                            <option {{ $selected_section && $selected_section->id == $s->id ? 'selected' : '' }} value="{{ $s->id }}">{{ $my_class->name.' '.$s->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-field">
                    <label for="q">Search:</label>
                    <input id="q" name="q" type="text" class="form-control" value="{{ $q }}" placeholder="Name, ADM No or email">
                </div>
                <div class="pat-filter-action">
                    <button type="submit" class="btn btn-primary">Filter</button>
                    @if($selected_section || $q !== '')
                        <a href="{{ route('students.list', $my_class->id) }}" class="btn btn-light ml-2">Clear</a>
                    @endif
                </div>
            </form>
        @endslot

        @if(Qs::userIsTeamSA())
            {{-- Bulk delete (Workstream AA). The form deliberately does
                 NOT wrap the table: every row already contains its own
                 (hidden) delete form, and nested forms are invalid HTML.
                 The checkboxes carry no name — the script section collects
                 the checked ids and appends them to this form as
                 student_ids[] on submit, after the styled confirmation
                 modal (Workstream AY) approves. --}}
            <form id="bulk-delete-form" class="print-hide" method="post" action="{{ route('students.bulk_delete') }}">
                @csrf
                <div class="d-flex align-items-center mb-2">
                    <span id="bulk-selected-count" class="mr-2 text-muted">0 selected</span>
                    <button type="submit" id="bulk-delete-btn" class="btn btn-danger btn-sm" disabled><i class="icon-trash mr-1"></i> Delete Selected</button>
                </div>
            </form>
        @endif

        <div id="all-students">
            @if($listed->isEmpty())
                @include('partials.patterns.empty_state', [
                    'icon' => 'icon-users',
                    'message' => ($selected_section || $q !== '')
                        ? 'No students match this filter. Clear the filter to see the full '.$my_class->name.' roster.'
                        : 'No students in '.$my_class->name.' yet.',
                ])
            @else
                <table class="table">
                    <thead>
                    <tr>
                        @if(Qs::userIsTeamSA())
                            <th class="print-hide"><input type="checkbox" id="bulk-check-all" title="Select all"></th>
                        @endif
                        <th class="print-nowrap">S/N</th>
                        <th class="print-hide">Photo</th>
                        <th>Name</th>
                        <th>ADM_No</th>
                        <th>Section</th>
                        <th>Email</th>
                        <th class="print-hide">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($listed as $s)
                        <tr>
                            @if(Qs::userIsTeamSA())
                                <td class="print-hide"><input type="checkbox" class="bulk-check" value="{{ $s->id }}"></td>
                            @endif
                            <td class="print-nowrap">{{ $loop->iteration }}</td>
                            <td class="print-hide"><img class="rounded-circle" style="height: 40px; width: 40px;" src="{{ $s->user->photo }}" alt="photo"></td>
                            <td>{{ $s->user->name }}</td>
                            <td class="print-nowrap">{{ $s->adm_no }}</td>
                            <td>{{ $my_class->name.' '.$s->section->name }}</td>
                            <td>{{ $s->user->email }}</td>
                            <td class="text-center print-hide">
                                <div class="list-icons">
                                    <div class="dropdown">
                                        <a href="#" class="list-icons-item" data-toggle="dropdown">
                                            <i class="icon-menu9"></i>
                                        </a>

                                        <div class="dropdown-menu dropdown-menu-left">
                                            <a href="{{ route('students.show', Qs::hash($s->id)) }}" class="dropdown-item"><i class="icon-eye"></i> View Profile</a>
                                            @if(Qs::userIsTeamSA())
                                                <a href="{{ route('students.edit', Qs::hash($s->id)) }}" class="dropdown-item"><i class="icon-pencil"></i> Edit</a>
                                                <form method="post" action="{{ route('st.reset_pass', Qs::hash($s->user->id)) }}" class="m-0" data-confirm="Reset this student's password to the default? They will need the default password to sign in again." data-confirm-title="Reset password" data-confirm-action="Reset password">@csrf<button type="submit" class="dropdown-item"><i class="icon-lock"></i> Reset password</button></form>
                                            @endif
                                            <a target="_blank" href="{{ route('marks.year_selector', Qs::hash($s->user->id)) }}" class="dropdown-item"><i class="icon-check"></i> Marksheet</a>
                                            @if(Qs::userIsTeamAccount())
                                                <a href="{{ route('payments.invoice', Qs::hash($s->user->id)) }}" class="dropdown-item"><i class="icon-coins"></i> Payments</a>
                                            @endif
                                            @if(Qs::userIsTeamSA())
                                                <a target="_blank" href="{{ route('students.id_card', Qs::hash($s->id)) }}" class="dropdown-item"><i class="icon-vcard"></i> ID Card</a>
                                            @endif

                                            {{--Delete--}}
                                            @if(Qs::userIsTeamSA())
                                                <a id="{{ Qs::hash($s->user->id) }}" onclick="confirmDelete(this.id)" href="#" class="dropdown-item"><i class="icon-trash"></i> Delete</a>
                                                <form method="post" id="item-delete-{{ Qs::hash($s->user->id) }}" action="{{ route('students.destroy', Qs::hash($s->user->id)) }}" class="hidden">@csrf @method('delete')</form>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endcomponent

    <div class="print-doc-foot">
        <span>Printed on {{ now()->format('d M Y, g:i A') }} &middot; {{ Qs::getSystemName() }}</span>
        <span>Student Information &mdash; {{ $my_class->name }}</span>
    </div>

    {{--Student List Ends--}}

@endsection

@if(Qs::userIsTeamSA())
@section('scripts')
<script>
        (function () {
            function bulkTable() {
                try {
                    var $t = $('#all-students table.datatable-button-html5-columns');
                    return ($.fn.DataTable && $.fn.DataTable.isDataTable($t)) ? $t.DataTable() : null;
                } catch (e) { return null; }
            }
            function checkedBoxes() {
                var dt = bulkTable();
                return dt ? dt.$('input.bulk-check:checked') : $('#all-students input.bulk-check:checked');
            }
            function allBoxes() {
                var dt = bulkTable();
                return dt ? dt.$('input.bulk-check') : $('#all-students input.bulk-check');
            }
            function refreshCount() {
                var n = checkedBoxes().length;
                $('#bulk-selected-count').text(n + ' selected');
                $('#bulk-delete-btn').prop('disabled', n === 0);
            }
            $(document).on('change', '#all-students input.bulk-check', refreshCount);
            $(document).on('change', '#bulk-check-all', function () {
                allBoxes().prop('checked', this.checked);
                refreshCount();
            });
            $(document).on('draw.dt', '#all-students table.datatable-button-html5-columns', refreshCount);
            $('#bulk-delete-form').on('submit', function (e) {
                var form = this;
                var ids = [];
                checkedBoxes().each(function () { ids.push(this.value); });
                if (!ids.length) { e.preventDefault(); return; }
                if (form.dataset.hxcConfirmed !== '1') {
                    e.preventDefault();
                    window.hackstraConfirm('Delete ' + ids.length + ' selected student(s)? Their login accounts will be removed too. This cannot be undone.', { title: 'Delete Students', action: 'Delete', danger: true }).then(function (ok) {
                        if (!ok) { return; }
                        form.dataset.hxcConfirmed = '1';
                        if (form.requestSubmit) { form.requestSubmit(); }
                        else {
                            $(form).find('input[type=hidden][data-bulk-id]').remove();
                            ids.forEach(function (id) {
                                $('<input>').attr({ type: 'hidden', name: 'student_ids[]', value: id, 'data-bulk-id': '1' }).appendTo(form);
                            });
                            form.submit();
                        }
                    });
                    return;
                }
                delete form.dataset.hxcConfirmed;
                $(form).find('input[type=hidden][data-bulk-id]').remove();
                ids.forEach(function (id) {
                    $('<input>').attr({ type: 'hidden', name: 'student_ids[]', value: id, 'data-bulk-id': '1' }).appendTo(form);
                });
            });
            refreshCount();
        })();
    </script>
@endsection
@endif
