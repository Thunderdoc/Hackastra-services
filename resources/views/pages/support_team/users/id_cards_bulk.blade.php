@extends('layouts.master')
@section('page_title', 'Staff ID Cards')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Staff ID Cards
@endsection
@section('content')
    {{-- Item 14: staff bulk ID cards (teamSA). Pick a role (or all
         staff), preview who will be printed, download the PDF —
         front + back per member on CR80 pages. --}}
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">Staff ID Cards</h5>
        </div>
        <div class="card-body">
            <form method="get" action="{{ route('staff.id_cards.bulk') }}" class="form-inline mb-3">
                <label for="role" class="mr-2">Role</label>
                <select name="role" id="role" class="form-control mr-2" onchange="this.form.submit()">
                    <option value="">All staff</option>
                    @foreach($roles as $r)
                        @if($r !== 'student')
                            <option value="{{ $r }}" {{ $role === $r ? 'selected' : '' }}>{{ ucfirst($r) }}</option>
                        @endif
                    @endforeach
                </select>
                <a href="{{ route('staff.id_cards.bulk.pdf', array_filter(['role' => $role])) }}" class="btn btn-primary"><i class="icon-printer mr-2"></i> Download PDF ({{ $staff->count() }})</a>
            </form>

            @if($staff->isEmpty())
                @include('partials.patterns.empty_state', ['icon' => 'icon-users', 'message' => 'No staff found for this selection.'])
            @else
                <div class="table-responsive">
                    <table class="table table-sm hk-std hk-std-760">
                        <colgroup><col style="width:34%"><col style="width:22%"><col style="width:20%"><col style="width:24%"></colgroup>
                        <thead>
                            <tr><th>Name</th><th>Role</th><th>Emp ID</th><th></th></tr>
                        </thead>
                        <tbody>
                            @foreach($staff as $u)
                                <tr>
                                    <td data-label="Name">{{ $u->name }}</td>
                                    <td data-label="Role">{{ \App\Support\IdCardFactory::ROLE_META[$u->user_type]['designation'] ?? ucfirst($u->user_type) }}</td>
                                    <td data-label="Emp ID" class="hk-nw">{{ $u->code }}</td>
                                    <td data-label=""><a href="{{ route('staff.id_card', $u->id) }}" class="btn btn-light btn-sm">View Card</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
