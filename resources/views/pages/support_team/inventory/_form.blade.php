<div class="form-group">
    <label>Name</label>
    <input type="text" name="name" value="{{ old('name', $item->name ?? '') }}" class="form-control @error('name') is-invalid @enderror" required>@include('partials.errors.field', ['field' => 'name'])
</div>
<div class="form-group">
    <label>SKU / Item code (optional)</label>
    <input type="text" name="sku" value="{{ old('sku', $item->sku ?? '') }}" class="form-control @error('sku') is-invalid @enderror">@include('partials.errors.field', ['field' => 'sku'])
</div>
<div class="form-group">
    <label>Category</label>
    <select name="category" class="form-control @error('category') is-invalid @enderror" required>
        @foreach($categories as $cat)
            <option value="{{ $cat }}" {{ old('category', $item->category ?? '') === $cat ? 'selected' : '' }}>{{ ucfirst($cat) }}</option>
        @endforeach
    </select>@include('partials.errors.field', ['field' => 'category'])
</div>
<div class="form-group">
    <label>Unit (e.g. pcs, sets, packets)</label>
    <input type="text" name="unit" value="{{ old('unit', $item->unit ?? '') }}" class="form-control @error('unit') is-invalid @enderror" required>@include('partials.errors.field', ['field' => 'unit'])
</div>
<div class="form-group">
    <label>Low-stock threshold</label>
    <input type="number" name="low_stock_threshold" value="{{ old('low_stock_threshold', $item->low_stock_threshold ?? 0) }}" class="form-control @error('low_stock_threshold') is-invalid @enderror" min="0" required>@include('partials.errors.field', ['field' => 'low_stock_threshold'])
    <span class="form-text text-muted">The Low Stock badge appears when computed stock falls to or below this number.</span>
</div>
