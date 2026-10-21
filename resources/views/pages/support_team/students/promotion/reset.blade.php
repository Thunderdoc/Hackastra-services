@extends('layouts.master')
@section('page_title', 'Manage Promotions')
@section('content')
    {{-- Batch 3: rebuilt on the shared list_page pattern (Loop 28). The
         promotions table (id promotions-list), the per-row Reset forms
         and the Reset All AJAX flow in the scripts section are
         unchanged — only the page shell changed. --}}
    @component('partials.patterns.list_page')
        @slot('title')
            <i class="icon-stairs-up mr-2"></i> Manage Promotions
        @endslot
        @slot('subtitle')
            Students who were promoted from <span class="text-danger">{{ $old_year }}</span> to <span class="text-success">{{ $new_year }}</span> session.
        @endslot
        @slot('action')
            <button id="promotion-reset-all" class="btn btn-danger">Reset All Promotions for the Session</button>
        @endslot

        @if($promotions->count())
            <table id="promotions-list" class="table datatable-button-html5-columns">
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
                        <td>{{ $loop->iteration }}</td>
                        <td><img class="rounded-circle" style="height: 40px; width: 40px;" src="{{ $p->student->photo }}" alt="photo"></td>
                        <td>{{ $p->student->name }}</td>
                        <td>{{ $p->fc->name.' '.$p->fs->name }}</td>
                        <td>{{ $p->tc->name.' '.$p->ts->name }}</td>
                        @if($p->status === 'P')
                            <td><span class="text-success">Promoted</span></td>
                        @elseif($p->status === 'D')
                            <td><span class="text-danger">Not Promoted</span></td>
                        @else
                            <td><span class="text-primary">Graduated</span></td>
                        @endif
                        <td class="text-center">
                            <button data-id="{{ $p->id }}" class="btn btn-danger promotion-reset">Reset</button>
                            <form id="promotion-reset-{{ $p->id }}" method="post" action="{{ route('students.promotion_reset', $p->id) }}">@csrf @method('DELETE')</form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @else
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-stairs-up',
                'message' => 'No promotions recorded for this session yet. Promotions appear here after a promotion run.',
            ])
        @endif
    @endcomponent
@endsection

@section('scripts')
    <script>
        /* Single Reset */
        $('.promotion-reset').on('click', function () {
            let pid = $(this).data('id');
            if (confirm('Are You Sure you want to proceed?')){
                $('form#promotion-reset-'+pid).submit();
            }
            return false;
        });

        /* Reset All Promotions */
        $('#promotion-reset-all').on('click', function () {
            if (confirm('Are You Sure you want to proceed?')){
                $.ajax({
                    url:"{{ route('students.promotion_reset_all') }}",
                    type:'DELETE',
                    data:{ '_token' : $('#csrf-token').attr('content') },
                    success:function (resp) {
                        $('table#promotions-list > tbody').fadeOut().remove();
                        flash({msg : resp.msg, type : 'success'});
                    }
                })
            }
            return false;
        })
    </script>
@endsection
