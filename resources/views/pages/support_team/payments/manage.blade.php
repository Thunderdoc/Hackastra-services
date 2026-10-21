@extends('layouts.master')
@section('page_title', 'Student Payments')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            @if($selected)
                <div>
                    <button type="button" class="btn btn-light btn-sm" onclick="window.print()"><i class="icon-printer mr-1"></i> Print</button>
                    <a href="{{ route('payments.export', $my_class_id) }}" class="btn btn-primary btn-sm"><i class="icon-download mr-1"></i> Export</a>
                </div>
            @endif
            {!! Qs::getPanelOptions() !!}
        </div>

        <div class="card-body">
            {{-- Workstream BT: compact inline picker row (label, select,
                 Submit together) — was a centred column floating in a
                 near-empty card, same cure as the payments index. --}}
            <form method="post" action="{{ route('payments.select_class') }}">
                @csrf
                <div class="bt-picker">
                    <div class="bt-picker-field">
                        <label for="my_class_id" class="col-form-label font-weight-bold">Class:</label>
                        <select required id="my_class_id" name="my_class_id" class="form-control select">
                            <option value="">Select Class</option>
                            @foreach($my_classes as $c)
                                <option {{ ($selected && $my_class_id == $c->id) ? 'selected' : '' }} value="{{ $c->id }}">{{ $c->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="bt-picker-btn">
                        <button type="submit" class="btn btn-primary">Submit <i class="icon-paperplane ml-2"></i></button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    @if($selected)
        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
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
                                    <a href="#" class=" btn btn-danger" data-toggle="dropdown"> Manage Payments <i class="icon-arrow-down5"></i>
                                    </a>
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
                </div>
            </div>
        </div>
    @endif
@endsection
