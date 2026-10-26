{{-- Batch 3: selector rebuilt as a pattern filter form (Loop 28). Same
     contract: POST students.promote_selector with fc/fs/tc/ts, and the
     class selects drive the section selects via the global
     getClassSections() AJAX helper ('#fs' / '#ts' targets). --}}
<form method="post" action="{{ route('students.promote_selector') }}" class="pat-filter-form">
    @csrf
    <div class="pat-filter-field">
        <label for="fc">From Class:</label>
        <select required onchange="getClassSections(this.value, '#fs')" id="fc" name="fc" class="form-control select">
            <option value="">Select Class</option>
            @foreach($my_classes as $c)
                <option {{ ($selected && $fc == $c->id) ? 'selected' : '' }} value="{{ $c->id }}">{{ $c->name }}</option>
            @endforeach
        </select>
    </div>

    <div class="pat-filter-field">
        <label for="fs">From Section:</label>
        <select required id="fs" name="fs" data-placeholder="Select Class First" class="form-control select">
            @if($selected && $fs)
                <option value="{{ $fs }}">{{ $sections->where('id', $fs)->first()->name }}</option>
            @endif
        </select>
    </div>

    <div class="pat-filter-field">
        <label for="tc">To Class:</label>
        <select required onchange="promotionToClass(this.value)" id="tc" name="tc" class="form-control select">
            <option value="">Select Class</option>
            @foreach($my_classes as $c)
                <option {{ ($selected && $tc == $c->id) ? 'selected' : '' }} value="{{ $c->id }}">{{ $c->name }}</option>
            @endforeach
            <option {{ ($selected && $tc === 'G') ? 'selected' : '' }} value="G">Graduate — no next class</option>
        </select>
    </div>

    <div class="pat-filter-field">
        <label for="ts">To Section:</label>
        <select required id="ts" name="ts" data-placeholder="Select Class First" class="form-control select">
            @if($selected && $ts)
                <option value="{{ $ts }}">{{ $sections->where('id', $ts)->first()->name }}</option>
            @endif
        </select>
    </div>

    <div class="pat-filter-action">
        <button type="submit" class="btn btn-primary">Manage Promotion <i class="icon-paperplane ml-2"></i></button>
    </div>
</form>
<script>
    // Graduating mode (batch 2026-10-25): graduates have no
    // destination class or section — skip the section AJAX and
    // leave To Section empty; the server pairs graduates with
    // their own section.
    function promotionToClass(v) {
        var ts = document.getElementById('ts');
        if (!ts) { return; }
        if (v === 'G') {
            jQuery('#ts').val('').trigger('change.select2');
            ts.removeAttribute('required');
            return;
        }
        ts.setAttribute('required', 'required');
        getClassSections(v, '#ts');
    }
</script>
