<form method="post" action="{{ route('students.promote', [$fc, $fs, $tc, $ts]) }}">
    @csrf
    {{-- Owner ask 2026-10-25: name search + set-all on the promotion
         list. Both controls carry NO name attribute, so they never
         submit — the per-student p-{id} selects below are the only
         submitted values. Set-all applies to every row, including
         rows hidden by the search filter. --}}
    <div class="d-flex flex-wrap align-items-end mb-3">
        <div class="mr-2 mb-2" style="flex:1 1 220px">
            <label for="promo-search">Search student</label>
            <input type="search" id="promo-search" class="form-control" placeholder="Search by name&hellip;" autocomplete="off">
        </div>
        <div class="mr-2 mb-2" style="flex:0 1 200px">
            <label for="promo-set-all">Set all to</label>
            <select id="promo-set-all" class="form-control">
                <option value="">Set all to&hellip;</option>
                <option value="P">Promote</option>
                <option value="D">Don't Promote</option>
                <option value="G">Graduated</option>
            </select>
        </div>
        <div class="mb-2 text-muted"><span id="promo-count"></span></div>
    </div>
    {{-- Loop 108: l108-stack so the per-student decision rows stay
         fully visible on phone widths (form + selects unchanged). --}}
    <table id="promo-table" class="table table-striped l108-stack">
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
<script>
(function(){
    var $rows = $('#promo-table tbody tr');
    var total = $rows.length;
    var $count = $('#promo-count');
    function renderCount(){ $count.text('Showing ' + $rows.filter(':visible').length + ' of ' + total + ' students'); }
    $('#promo-search').on('keydown', function(e){ if (e.key === 'Enter') e.preventDefault(); });
    $('#promo-search').on('input', function(){
        var q = (this.value || '').toLowerCase().trim();
        $rows.each(function(){
            var name = $(this).find('td[data-label="Name"]').text().toLowerCase();
            $(this).toggle(!q || name.indexOf(q) !== -1);
        });
        renderCount();
    });
    $('#promo-set-all').on('change', function(){
        var v = this.value;
        if (!v) return;
        $rows.find('select[name^="p-"]').val(v).trigger('change');
    });
    renderCount();
})();
</script>