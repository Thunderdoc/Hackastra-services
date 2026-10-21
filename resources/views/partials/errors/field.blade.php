{{--
    Loop 9 · UX Fix 4 (S5: errors at the field, in plain words).
    Usage: @include('partials.errors.field', ['field' => 'amount'])
    Renders the field's validation message directly under its input.
    Pair it with the is-invalid marker on the input itself:
    class="form-control @error('amount') is-invalid @enderror"
    Styling: .field-error in public/assets/css/qs.css (Loop 9 section).
--}}
@error($field)
    <span class="field-error" role="alert">{{ $message }}</span>
@enderror
