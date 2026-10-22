@extends('layouts.master')
@section('page_title', 'Student Payments')
@section('content')

    {{-- Workstream CK: rebuilt on the shared list_page pattern
         (Loop 28), the same chrome as Students / Attendance. The
         class picker is the pattern filter row (same POST to
         payments.select_class, same field name), Print / Export are
         the toolbar actions (same routes), and the per-student
         Payments dropdown keeps its exact menu (All Payments + one
         invoice link per year) on the labelled button standard.
         Workstream BT's compact-picker cure is preserved inside the
         filter row. --}}
    @component('partials.patterns.list_page')
        @slot('subtitle')
            Pick a class, then open a student&rsquo;s payment records to take a fee or print a receipt.
        @endslot
        @if($selected)
            @slot('action')
                <button type="button" class="btn btn-light btn-sm" onclick="window.print()"><i class="icon-printer mr-1"></i> Print</button>
                <a href="{{ route('payments.export', $my_class_id) }}" class="btn btn-primary btn-sm"><i class="icon-download mr-1"></i> Export</a>
            @endslot
        @endif
        @slot('filters')
            <form method="post" action="{{ route('payments.select_class') }}" class="pat-filter-form">
                @csrf
                <div class="pat-filter-field">
                    <label for="my_class_id">Class:</label>
                    <select required id="my_class_id" name="my_class_id" class="form-control select">
                        <option value="">Select Class</option>
                        @foreach($my_classes as $c)
                            <option {{ ($selected && $my_class_id == $c->id) ? 'selected' : '' }} value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-action">
                    <button type="submit" class="btn btn-primary">Submit <i class="icon-paperplane ml-2"></i></button>
                </div>
            </form>
        @endslot

        @if($selected)
            @if($students->isEmpty())
                @include('partials.patterns.empty_state', [
                    'icon' => 'icon-users',
                    'message' => 'No active students in this class yet.',
                ])
            @else
                <table class="table">
                    <thead>
                    <tr>
                        <th>S/N</th>
                        <th>Photo</th>
                        <th>Name</th>
                        <th>ADM_No</th>
                        <th>Payments</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($students as $s)
                        <tr>
                            <td>{{ $loop->iteration }}</td>
                            <td><img class="rounded-circle" style="height: 40px; width: 40px;" src="{{ $s->user->photo }}" alt="photo"></td>
                            <td>{{ $s->user->name }}</td>
                            <td>{{ $s->adm_no }}</td>
                            <td>
                                <div class="dropdown">
                                    <button type="button" class="btn btn-sm btn-primary dropdown-toggle" data-toggle="dropdown">Manage Payments</button>
                                    <div class="dropdown-menu dropdown-menu-left">
                                        <a href="{{ route('payments.invoice', [Qs::hash($s->user_id)]) }}" class="dropdown-item">All Payments</a>
                                        @foreach(Pay::getYears($s->user_id) as $py)
                                        @if($py)
                                            <a href="{{ route('payments.invoice', [Qs::hash($s->user_id), $py]) }}" class="dropdown-item">{{ $py }}</a>
                                        @endif
                                        @endforeach
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        @else
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-cash2',
                'message' => 'Pick a class above to see its students and their payments.',
            ])
        @endif
    @endcomponent
@endsection
