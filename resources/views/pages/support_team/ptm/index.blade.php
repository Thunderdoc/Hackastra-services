@extends('layouts.master')
@section('page_title', 'PTM Scheduling')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h5 class="card-title">Parent-Teacher Meeting Slots</h5>
            <div class="header-elements">
                @if($can_create)
                    <a href="{{ route('ptm.create') }}" class="btn btn-primary btn-sm">New Slot</a>
                @endif
            </div>
        </div>
        <div class="card-body">
            @if(session('flash_success'))
                <div class="alert alert-success border-0">{{ session('flash_success') }}</div>
            @endif
            @if(session('flash_danger'))
                <div class="alert alert-danger border-0">{{ session('flash_danger') }}</div>
            @endif

            @if($slots->count() === 0)
                @include('partials.patterns.empty_state', ['icon' => 'icon-calendar3', 'message' => 'No PTM slots yet.'])
            @else
                <div class="table-responsive">
                    <table class="table hk-std hk-std-880">
                        <colgroup><col style="width:12%"><col style="width:13%"><col style="width:20%"><col style="width:11%"><col style="width:12%"><col style="width:17%"><col style="width:15%"></colgroup>
                        <thead><tr><th>Date</th><th>Time</th><th>Teacher</th><th>Class</th><th>Section</th><th>Status</th><th>Action</th></tr></thead>
                        <tbody>
                        @foreach($slots as $slot)
                            <tr>
                                <td class="hk-nw">{{ $slot->date ? $slot->date->format('d M Y') : '—' }}</td>
                                <td class="hk-nw">{{ substr($slot->start_time, 0, 5) }} – {{ substr($slot->end_time, 0, 5) }}</td>
                                <td>{{ $slot->teacher->name ?? '—' }}</td>
                                <td>{{ $slot->my_class->name ?? '—' }}</td>
                                <td>{{ $slot->section->name ?? 'All sections' }}</td>
                                <td>
                                    @if($slot->booking)
                                        @if($staff_view)
                                            <span class="badge badge-success">Booked</span>
                                            <div class="text-muted">{{ $slot->booking->parent->name ?? '—' }} ({{ $slot->booking->student->name ?? '—' }})</div>
                                        @elseif($slot->booking->parent_id == auth()->id())
                                            <span class="badge badge-success">Booked by you</span>
                                            <div class="text-muted">{{ $slot->booking->student->name ?? '—' }}</div>
                                        @else
                                            <span class="badge badge-success">Booked</span>
                                        @endif
                                    @elseif($slot->hasEnded())
                                        <span class="badge badge-secondary">Ended</span>
                                    @else
                                        <span class="badge badge-info">Available</span>
                                    @endif
                                </td>
                                <td>
                                    @if(!$slot->booking && !$slot->hasEnded() && $is_parent && $my_children->count() > 0)
                                        <form method="POST" action="{{ route('ptm.book', $slot->id) }}">
                                            @csrf
                                            <select name="student_user_id" class="form-control form-control-sm @error('student_user_id') is-invalid @enderror" required>
                                                <option value="">Select child</option>
                                                @foreach($my_children as $child)
                                                    <option value="{{ $child->user_id }}">{{ $child->user->name ?? $child->user_id }} ({{ $child->my_class->name ?? '' }})</option>
                                                @endforeach
                                            </select>@include('partials.errors.field', ['field' => 'student_user_id'])
                                            <button type="submit" class="btn btn-primary btn-sm mt-1">Book</button>
                                        </form>
                                    @elseif($slot->booking && ($slot->booking->parent_id == auth()->id() || $staff_view))
                                        <form method="POST" action="{{ route('ptm.cancel', $slot->booking->id) }}" data-confirm="Cancel this booking? The slot will become available again." data-confirm-title="Cancel Booking" data-confirm-action="Cancel Booking">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-light btn-sm">Cancel Booking</button>
                                        </form>
                                    @else
                                        —
                                    @endif
                                    @if($can_create && !$slot->booking)
                                        <form method="POST" action="{{ route('ptm.destroy', $slot->id) }}" data-confirm="Delete this slot? This cannot be undone." data-confirm-title="Delete Slot" data-confirm-action="Delete" data-confirm-danger class="mt-1">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-light btn-sm text-danger">Delete Slot</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                {{ $slots->links() }}
            @endif
        </div>
    </div>
@endsection
