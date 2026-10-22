@extends('layouts.master')
@section('page_title', 'Manage Promotions')
@section('content')
    {{-- Loop 108: rebuilt on the shared list chrome (Loop 28). Was two
         legacy cards (one with panel-options chrome). Behaviour
         unchanged: same Reset All button + AJAX flow, same per-row
         Reset forms and confirm dialogs (scripts section untouched).
         Status now renders as the app's badge pills instead of bare
         coloured text, and the table carries l108-stack so every
         column stays visible on phone widths. --}}
    <div class="pat-list">
        <div class="pat-toolbar">
            <div class="pat-toolbar-text">
                <div class="pat-toolbar-sub">Students who were promoted from <span class="text-danger">{{ $old_year }}</span> to <span class="text-success">{{ $new_year }}</span> session.</div>
            </div>
            <div class="pat-toolbar-actions">
                <button id="promotion-reset-all" class="btn btn-danger">Reset All Promotions for the Session</button>
            </div>
        </div>

        <div class="card pat-card">
            <div class="card-body">
                <div class="table-responsive">
                <table id="promotions-list" class="table l108-stack">
                    <thead>
                    <tr>
                        <th>S/N</th>
                        <th>Photo</th>
                        <th>Name</th>
                        <th>From Class</th>
                        <th>To Class</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($promotions->sortBy('fc.name')->sortBy('student.name') as $p)
                        <tr>
                            <td data-label="S/N">{{ $loop->iteration }}</td>
                            <td data-label="Photo">@if(Qs::hasRealPhoto($p->student->photo))<img class="rounded-circle" style="height: 40px; width: 40px;" src="{{ $p->student->photo }}" alt="photo">@else<span class="hk-ini" style="width:40px;height:40px;font-size:.8rem" aria-hidden="true">{{ Qs::initials($p->student->name) }}</span>@endif</td>
                            <td data-label="Name">{{ $p->student->name }}</td>
                            <td data-label="From Class">{{ $p->fc->name.' '.$p->fs->name }}</td>
                            <td data-label="To Class">{{ $p->tc->name.' '.$p->ts->name }}</td>
                            @if($p->status === 'P')
                                <td data-label="Status"><span class="badge badge-success">Promoted</span></td>
                            @elseif($p->status === 'D')
                                <td data-label="Status"><span class="badge badge-danger">Not Promoted</span></td>
                            @else
                                <td data-label="Status"><span class="badge badge-primary">Graduated</span></td>
                            @endif
                            <td class="text-center" data-label="Action">
                                <button data-id="{{ $p->id }}" class="btn btn-danger promotion-reset">Reset</button>
                                <form id="promotion-reset-{{ $p->id }}" method="post" action="{{ route('students.promotion_reset', $p->id) }}">@csrf @method('DELETE')</form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>

@endsection

@section('scripts')
    <script>
        /* Single Reset */
        $('.promotion-reset').on('click', function () {
            let pid = $(this).data('id');
            window.hackstraConfirm('Are you sure you want to proceed?', { title: 'Reset Promotion', action: 'Reset', danger: true }).then(function (ok) {
                if (ok) {
                    $('form#promotion-reset-'+pid).submit();
                }
            });
            return false;
        });

        /* Reset All Promotions */
        $('#promotion-reset-all').on('click', function () {
            window.hackstraConfirm('Are you sure you want to proceed? All promotions for this session will be reset.', { title: 'Reset All Promotions', action: 'Reset All', danger: true }).then(function (ok) {
                if (!ok) { return; }
                $.ajax({
                    url:"{{ route('students.promotion_reset_all') }}",
                    type:'DELETE',
                    data:{ '_token' : $('#csrf-token').attr('content') },
                    success:function (resp) {
                        $('table#promotions-list > tbody').fadeOut().remove();
                        flash({msg : resp.msg, type : 'success'});
                    }
                })
            });
            return false;
        })
    </script>
@endsection
