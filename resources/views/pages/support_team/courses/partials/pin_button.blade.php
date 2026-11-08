{{-- Pin / unpin toggle for one course item (teacher manager).
     Amber-filled when pinned, outline when not — the sample's
     contract. Expects: $kind, $item. --}}
<form method="POST" action="{{ route('courses.pin') }}" class="course-pin-form">
    @csrf
    <input type="hidden" name="kind" value="{{ $kind }}">
    <input type="hidden" name="id" value="{{ $item->id }}">
    <button type="submit" class="btn btn-sm {{ $item->is_pinned ? 'btn-warning' : 'btn-light' }}" title="{{ $item->is_pinned ? 'Unpin' : 'Pin to top' }}">
        <i class="icon-pin"></i>
    </button>
</form>
