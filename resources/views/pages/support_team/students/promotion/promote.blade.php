<form method="post" action="{{ route('students.promote', [$fc, $fs, $tc, $ts]) }}">
    @csrf
    {{-- Loop 108: l108-stack so the per-student decision rows stay
         fully visible on phone widths (form + selects unchanged). --}}
    <table class="table table-striped l108-stack">
        <thead>
        <tr>
            <th>#</th>
            <th>Photo</th>
            <th>Name</th>
            <th>Current Session</th>
            <th>Action</th>
        </tr>
        </thead>
        <tbody>
        @foreach($students->sortBy('user.name') as $sr)
            <tr>
                <td data-label="#">{{ $loop->iteration }}</td>
                <td data-label="Photo">@if(Qs::hasRealPhoto($sr->user->photo))<img class="rounded-circle" style="height: 30px; width: 30px;" src="{{ $sr->user->photo }}" alt="img">@else<span class="hk-ini" style="width:30px;height:30px;font-size:.7rem" aria-hidden="true">{{ Qs::initials($sr->user->name) }}</span>@endif</td>
                <td data-label="Name">{{ $sr->user->name }}</td>
                <td data-label="Current Session">{{ $sr->session }}</td>
                <td data-label="Action">
                    <select class="form-control select" name="p-{{$sr->id}}" id="p-{{$sr->id}}">
                        <option value="P">Promote</option>
                        <option value="D">Don't Promote</option>
                        <option value="G">Graduated</option>
                    </select>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    {{-- Workstream BW: submit moved from centred to bottom-right
         (pattern action placement). Form, table and per-student
         selects unchanged. --}}
    <div class="text-right mt-3">
        <button class="btn btn-success"><i class="icon-stairs-up mr-2"></i> Promote Students</button>
    </div>
</form>