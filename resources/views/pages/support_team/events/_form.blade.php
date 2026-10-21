<div class="form-group">
    <label for="title">Title</label>
    <input id="title" type="text" name="title" class="form-control" required maxlength="255" value="{{ old('title', $event->title ?? '') }}">
</div>
<div class="form-row">
    <div class="form-group col-md-4">
        <label for="type">Type</label>
        <select id="type" name="type" class="form-control" required>
            @foreach($types as $value => $label)
                <option value="{{ $value }}" {{ old('type', $event->type ?? 'event') === $value ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="form-group col-md-4">
        <label for="starts_at">Starts</label>
        <input id="starts_at" type="date" name="starts_at" class="form-control" required value="{{ old('starts_at', isset($event) ? $event->starts_at->format('Y-m-d') : '') }}">
    </div>
    <div class="form-group col-md-4">
        <label for="ends_at">Ends (optional)</label>
        <input id="ends_at" type="date" name="ends_at" class="form-control" value="{{ old('ends_at', isset($event) && $event->ends_at ? $event->ends_at->format('Y-m-d') : '') }}">
    </div>
</div>
<div class="form-group">
    <label for="description">Description (optional)</label>
    <textarea id="description" name="description" class="form-control" rows="3" maxlength="2000">{{ old('description', $event->description ?? '') }}</textarea>
</div>
<div class="form-group form-check">
    <input type="hidden" name="all_day" value="0">
    <input id="all_day" type="checkbox" name="all_day" value="1" class="form-check-input" {{ old('all_day', $event->all_day ?? true) ? 'checked' : '' }}>
    <label class="form-check-label" for="all_day">All day event</label>
</div>
