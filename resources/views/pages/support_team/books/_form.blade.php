<div class="form-group">
    <label for="name">Title</label>
    <input id="name" type="text" name="name" class="form-control" required maxlength="100" value="{{ old('name', $book->name ?? '') }}">
</div>
<div class="form-row">
    <div class="form-group col-md-6">
        <label for="author">Author (optional)</label>
        <input id="author" type="text" name="author" class="form-control" maxlength="255" value="{{ old('author', $book->author ?? '') }}">
    </div>
    <div class="form-group col-md-3">
        <label for="book_type">Type</label>
        <select id="book_type" name="book_type" class="form-control">
            <option value="">—</option>
            @foreach($types as $value => $label)
                <option value="{{ $value }}" {{ old('book_type', $book->book_type ?? '') === $value ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="form-group col-md-3">
        <label for="total_copies">Total copies</label>
        <input id="total_copies" type="number" name="total_copies" class="form-control" min="0" step="1" value="{{ old('total_copies', $book->total_copies ?? 1) }}">
    </div>
</div>
<div class="form-row">
    <div class="form-group col-md-4">
        <label for="my_class_id">Class (optional)</label>
        <select id="my_class_id" name="my_class_id" class="form-control">
            <option value="">General / all classes</option>
            @foreach($classes as $class)
                <option value="{{ $class->id }}" {{ (string) old('my_class_id', $book->my_class_id ?? '') === (string) $class->id ? 'selected' : '' }}>{{ $class->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="form-group col-md-4">
        <label for="location">Shelf / location (optional)</label>
        <input id="location" type="text" name="location" class="form-control" maxlength="255" value="{{ old('location', $book->location ?? '') }}">
    </div>
    <div class="form-group col-md-4">
        <label for="url">Online copy URL (optional)</label>
        <input id="url" type="url" name="url" class="form-control" maxlength="255" value="{{ old('url', $book->url ?? '') }}">
    </div>
</div>
<div class="form-group">
    <label for="description">Description (optional)</label>
    <textarea id="description" name="description" class="form-control" rows="2" maxlength="255">{{ old('description', $book->description ?? '') }}</textarea>
</div>
