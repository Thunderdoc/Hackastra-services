@if($errors->any())
    <div class="alert alert-danger border-0">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
@endif
<div class="form-group">
    <label>Name</label>
    <input type="text" name="name" value="{{ old('name', $item->name ?? '') }}" class="form-control" required>
</div>
<div class="form-group">
    <label>SKU / Item code (optional)</label>
    <input type="text" name="sku" value="{{ old('sku', $item->sku ?? '') }}" class="form-control">
</div>
<div class="form-group">
    <label>Category</label>
    <select name="category" class="form-control" required>
        @foreach($categories as $cat)
            <option value="{{ $cat }}" {{ old('category', $item->category ?? '') === $cat ? 'selected' : '' }}>{{ ucfirst($cat) }}</option>
        @endforeach
    </select>
</div>
<div class="form-group">
    <label>Unit (e.g. pcs, sets, packets)</label>
    <input type="text" name="unit" value="{{ old('unit', $item->unit ?? '') }}" class="form-control" required>
</div>
<div class="form-group">
    <label>Low-stock threshold</label>
    <input type="number" name="low_stock_threshold" value="{{ old('low_stock_threshold', $item->low_stock_threshold ?? 0) }}" class="form-control" min="0" required>
    <span class="form-text text-muted">The Low Stock badge appears when computed stock falls to or below this number.</span>
</div>
