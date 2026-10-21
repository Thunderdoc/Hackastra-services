<div class="card ttx-inner-card">
    <div class="card-header header-elements-inline">
        <h6 class="font-weight-bold card-title">Time Slots - {{ $ttr->name }}</h6>
    </div>

    <div class="card-body">
        <div class="l7-table-scroll">
        <table id="time_slots_table" class="table">
            <thead>
            <tr>
                <th style="width:56px">S/N</th>
                <th>Time From</th>
                <th>Time To</th>
                <th>Slot</th>
                <th style="width:170px">Action</th>
            </tr>
            </thead>
            <tbody>
            @forelse($time_slots as $tms)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $tms->time_from }}</td>
                    <td>{{ $tms->time_to }}</td>
                    <td>{{ $tms->full }}</td>
                    <td>
                        <div class="ttx-actions">
                            {{--Edit--}}
                            <a href="{{ route('ts.edit', $tms->id) }}" class="btn btn-light btn-sm"><i class="icon-pencil mr-1"></i> Edit</a>

                            {{--Delete--}}
                            @if(Qs::userIsTeamSA())
                                <a id="{{ $tms->id }}" onclick="confirmDelete(this.id)" href="#" class="btn btn-light btn-sm text-danger"><i class="icon-trash mr-1"></i> Delete</a>
                                <form method="post" id="item-delete-{{ $tms->id }}" action="{{ route('ts.destroy', $tms->id) }}" class="hidden">@csrf @method('delete')</form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted" style="padding:20px 12px">No time slots yet. Add one above.</td></tr>
            @endforelse

            </tbody>
        </table>
        </div>
    </div>

</div>
