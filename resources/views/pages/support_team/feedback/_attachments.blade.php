{{-- Attachment gallery for a feedback item (Workstream CW).
     Expects $item with attachments loaded. Images render as
     thumbnails that open full-size; other files as named chips. --}}
@if($item->attachments && $item->attachments->count())
    <div class="d-flex flex-wrap" style="gap:10px">
        @foreach($item->attachments as $att)
            @if($att->isImage())
                <a href="{{ $att->url() }}" target="_blank" rel="noopener" title="{{ $att->original_name }} — click for full size">
                    <img class="fb-attach-img" src="{{ $att->url() }}" alt="{{ $att->original_name }}">
                </a>
            @else
                <a href="{{ $att->url() }}" target="_blank" rel="noopener" class="btn btn-light btn-sm">
                    <i class="icon-file-pdf mr-1"></i> {{ \Illuminate\Support\Str::limit($att->original_name, 28) }}
                </a>
            @endif
        @endforeach
    </div>
@endif
