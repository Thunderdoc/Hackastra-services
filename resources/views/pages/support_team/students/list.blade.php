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

    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Students List</h6>
            <div>
                <button type="button" class="btn btn-light btn-sm" onclick="window.print()"><i class="icon-printer mr-1"></i> Print</button>
                <a href="{{ route('students.export', $my_class->id) }}" class="btn btn-primary btn-sm"><i class="icon-download mr-1"></i> Export</a>
                @if(Qs::userIsTeamSA())
                    <a href="{{ route('students.import') }}" class="btn btn-success btn-sm"><i class="icon-upload mr-1"></i> Import CSV</a>
                @endif
            </div>
            {!! Qs::getPanelOptions() !!}
        </div>

        <div class="card-body">
            <ul class="nav nav-tabs nav-tabs-highlight print-hide">
                <li class="nav-item"><a href="#all-students" class="nav-link active" data-toggle="tab">All {{ $my_class->name }} Students</a></li>
                <li class="nav-item dropdown">
                    <a href="#" class="nav-link dropdown-toggle" data-toggle="dropdown">Sections</a>
                    <div class="dropdown-menu dropdown-menu-right">
                        @foreach($sections as $s)
                            <a href="#s{{ $s->id }}" class="dropdown-item" data-toggle="tab">{{ $my_class->name.' '.$s->name }}</a>
                        @endforeach
                    </div>
                </li>
            </ul>

            <div class="tab-content">
                <div class="tab-pane fade show active" id="all-students">
                    @if(Qs::userIsTeamSA())
                        {{-- Bulk delete (Workstream AA). The form deliberately does
                             NOT wrap the table: every row already contains its own
                             (hidden) delete form, and nested forms are invalid HTML.
                             The checkboxes carry no name — the script section collects
                             the checked ids across all DataTables pages and appends
                             them to this form as student_ids[] on submit. --}}
                        <form id="bulk-delete-form" class="print-hide" method="post" action="{{ route('students.bulk_delete') }}">
                            @csrf
                            <div class="d-flex align-items-center mb-2">
                                <span id="bulk-selected-count" class="mr-2 text-muted">0 selected</span>
                                <button type="submit" id="bulk-delete-btn" class="btn btn-danger btn-sm" disabled><i class="icon-trash mr-1"></i> Delete Selected</button>
                            </div>
                        </form>
                    @endif
                    <table class="table datatable-button-html5-columns">
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
                        @foreach($students as $s)
                            <tr>
                                @if(Qs::userIsTeamSA())
                                    <td class="print-hide"><input type="checkbox" class="bulk-check" value="{{ $s->id }}"></td>
                                @endif
                                <td>{{ $loop->iteration }}</td>
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
                                                    <a href="{{ route('st.reset_pass', Qs::hash($s->user->id)) }}" class="dropdown-item"><i class="icon-lock"></i> Reset password</a>
                                                @endif
                                                <a target="_blank" href="{{ route('marks.year_selector', Qs::hash($s->user->id)) }}" class="dropdown-item"><i class="icon-check"></i> Marksheet</a>

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
                </div>

                @foreach($sections as $se)
                    <div class="tab-pane fade" id="s{{$se->id}}">
                        <div class="text-right mb-2 print-hide">
                            <a href="{{ route('students.export', [$my_class->id, $se->id]) }}" class="btn btn-light btn-sm"><i class="icon-download mr-1"></i> Export Section</a>
                        </div>
                        <table class="table datatable-button-html5-columns">
                            <thead>
                            <tr>
                                <th class="print-nowrap">S/N</th>
                                <th class="print-hide">Photo</th>
                                <th>Name</th>
                                <th>ADM_No</th>
                                <th>Email</th>
                                <th class="print-hide">Action</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($students->where('section_id', $se->id) as $sr)
                                <tr>
                                    <td>{{ $loop->iteration }}</td>
                                    <td class="print-hide"><img class="rounded-circle" style="height: 40px; width: 40px;" src="{{ $sr->user->photo }}" alt="photo"></td>
                                    <td>{{ $sr->user->name }}</td>
                                    <td class="print-nowrap">{{ $sr->adm_no }}</td>
                                    <td>{{ $sr->user->email }}</td>
                                    <td class="text-center print-hide">
                                        <div class="list-icons">
                                            <div class="dropdown">
                                                <a href="#" class="list-icons-item" data-toggle="dropdown">
                                                    <i class="icon-menu9"></i>
                                                </a>

                                                <div class="dropdown-menu dropdown-menu-right">
                                                    <a href="{{ route('students.show', Qs::hash($sr->id)) }}" class="dropdown-item"><i class="icon-eye"></i> View Info</a>
                                                    @if(Qs::userIsTeamSA())
                                                        <a href="{{ route('students.edit', Qs::hash($sr->id)) }}" class="dropdown-item"><i class="icon-pencil"></i> Edit</a>
                                                        <a href="{{ route('st.reset_pass', Qs::hash($sr->user->id)) }}" class="dropdown-item"><i class="icon-lock"></i> Reset password</a>
                                                    @endif
                                                    <a target="_blank" href="{{ route('marks.year_selector', Qs::hash($sr->user->id)) }}" class="dropdown-item"><i class="icon-check"></i> Marksheet</a>

                                                    {{--Delete--}}
                                                    @if(Qs::userIsTeamSA())
                                                        <a id="{{ Qs::hash($sr->user->id) }}" onclick="confirmDelete(this.id)" href="#" class="dropdown-item"><i class="icon-trash"></i> Delete</a>
                                                        <form method="post" id="item-delete-{{ Qs::hash($sr->user->id) }}" action="{{ route('students.destroy', Qs::hash($sr->user->id)) }}" class="hidden">@csrf @method('delete')</form>
                                                    @endif

                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach

                            </tbody>
                        </table>
                    </div>
                @endforeach

            </div>
        </div>
    </div>

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
