{{-- Workstream BW: fields moved onto the pattern grid
     (pat-fields/pat-field). Same names, ids, old()/$book bindings,
     maxlengths and options — only the layout language changed
     (was Bootstrap form-group/form-row columns). --}}
<div class="pat-fields">
    <div class="pat-field pat-field-wide">
        <label for="name">Title</label>
        <input id="name" type="text" name="name" class="form-control @error('name') is-invalid @enderror" required maxlength="100" value="{{ old('name', $book->name ?? '') }}">@include('partials.errors.field', ['field' => 'name'])
    </div>
    <div class="pat-field pat-field-span2">
        <label for="author">Author (optional)</label>
        <input id="author" type="text" name="author" class="form-control @error('author') is-invalid @enderror" maxlength="255" value="{{ old('author', $book->author ?? '') }}">@include('partials.errors.field', ['field' => 'author'])
    </div>
    <div class="pat-field">
        <label for="book_type">Type</label>
        <select id="book_type" name="book_type" class="form-control @error('book_type') is-invalid @enderror">
            <option value="">—</option>
            @foreach($types as $value => $label)
                <option value="{{ $value }}" {{ old('book_type', $book->book_type ?? '') === $value ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>@include('partials.errors.field', ['field' => 'book_type'])
    </div>
    <div class="pat-field">
        <label for="total_copies">Total copies</label>
        <input id="total_copies" type="number" name="total_copies" class="form-control @error('total_copies') is-invalid @enderror" min="0" step="1" value="{{ old('total_copies', $book->total_copies ?? 1) }}">@include('partials.errors.field', ['field' => 'total_copies'])
    </div>
    <div class="pat-field">
        <label for="my_class_id">Class (optional)</label>
        <select id="my_class_id" name="my_class_id" class="form-control @error('my_class_id') is-invalid @enderror">
            <option value="">General / all classes</option>
            @foreach($classes as $class)
                <option value="{{ $class->id }}" {{ (string) old('my_class_id', $book->my_class_id ?? '') === (string) $class->id ? 'selected' : '' }}>{{ $class->name }}</option>
            @endforeach
        </select>@include('partials.errors.field', ['field' => 'my_class_id'])
    </div>
    <div class="pat-field">
        <label for="location">Shelf / location (optional)</label>
        <input id="location" type="text" name="location" class="form-control @error('location') is-invalid @enderror" maxlength="255" value="{{ old('location', $book->location ?? '') }}">@include('partials.errors.field', ['field' => 'location'])
    </div>
    <div class="pat-field pat-field-span2">
        <label for="url">Online copy URL (optional)</label>
        <input id="url" type="url" name="url" class="form-control @error('url') is-invalid @enderror" maxlength="255" value="{{ old('url', $book->url ?? '') }}">@include('partials.errors.field', ['field' => 'url'])
    </div>
    <div class="pat-field pat-field-wide">
        <label for="description">Description (optional)</label>
        <textarea id="description" name="description" class="form-control @error('description') is-invalid @enderror" rows="2" maxlength="255">{{ old('description', $book->description ?? '') }}</textarea>@include('partials.errors.field', ['field' => 'description'])
    </div>
</div>
