{{-- Loop 114: preview rail rebuilt to the owner's reference #1 —
     a "Preview" head, the circular as the selected audience sees
     it (send tile, title + New pill, published date, message),
     the meta grid (Audience / Priority / Valid From / Valid Until)
     synced live from the form by the script in _form, and the
     "Tips for a Better Circular" panel. Loop 75's school-name head
     and LIVE PREVIEW tag are gone; nothing here hard-codes the
     school — dates and labels come from the record/form. --}}
@php
    $pvDate = isset($circular) && $circular->created_at ? $circular->created_at->format('d M Y') : now()->format('d M Y');
    $pvTitle = old('title', isset($circular) ? $circular->title : '');
    $pvBody = \Illuminate\Support\Str::limit(strip_tags(old('body', isset($circular) ? $circular->body : '')), 220);
    $pvPriority = old('priority', isset($circular) ? $circular->priority : 'normal');
    $pvPriorityLabel = $priorities[$pvPriority] ?? ucfirst($pvPriority);
    $pvAudienceLabel = $audiences[old('audience', isset($circular) ? $circular->audience : 'all')] ?? 'Everyone';
    $pvFrom = old('starts_at', isset($circular) && $circular->starts_at ? $circular->starts_at->format('Y-m-d') : '');
    $pvUntil = old('expires_at', isset($circular) && $circular->expires_at ? $circular->expires_at->format('Y-m-d') : '');
    $pvFromLabel = $pvFrom ? \Carbon\Carbon::parse($pvFrom)->format('d M Y') : 'Immediate';
    $pvUntilLabel = $pvUntil ? \Carbon\Carbon::parse($pvUntil)->format('d M Y') : 'Until unpublished';
@endphp
<aside class="l75-circ-side">
    <div class="card l75-circ-panel">
        <div class="l114-pv-head">
            <span class="l114-pv-eye"><i class="icon-eye"></i></span>
            <div>
                <div class="l114-pv-title">Preview</div>
                <div class="l114-pv-sub">This is how your circular will appear to the selected audience.</div>
            </div>
        </div>
        <div class="l114-pv-card">
            <div class="circ-item circ-pri-{{ $pvPriority }}" id="pv_item">
                <div class="l114-pv-top">
                    <span class="l114-pv-tile"><i class="icon-paperplane"></i></span>
                    <div class="l114-pv-main">
                        <div class="l114-pv-titlerow">
                            <h5 class="circ-title{{ $pvTitle ? '' : ' l75-pv-placeholder' }}" id="pv_title">{{ $pvTitle ?: 'Circular Title' }}</h5>
                            <span class="l114-pv-new">New</span>
                        </div>
                        <div class="l114-pv-date">Published on {{ $pvDate }}</div>
                    </div>
                </div>
                <p class="circ-excerpt{{ $pvBody ? '' : ' l75-pv-placeholder' }}" id="pv_body">{{ $pvBody ?: 'Your circular message will appear here. This is a preview of how the circular will look to the selected audience.' }}</p>
                <div class="l114-pv-meta">
                    <div class="l114-pv-meta-item"><span class="l114-pv-meta-ic"><i class="icon-users"></i></span><span class="l114-pv-meta-txt"><span class="l114-pv-meta-lbl">Audience</span><span class="l114-pv-meta-val" id="pv_aud">{{ $pvAudienceLabel }}</span></span></div>
                    <div class="l114-pv-meta-item"><span class="l114-pv-meta-ic"><i class="icon-flag3"></i></span><span class="l114-pv-meta-txt"><span class="l114-pv-meta-lbl">Priority</span><span class="l114-pv-meta-val" id="pv_pri">{{ $pvPriorityLabel }}</span></span></div>
                    <div class="l114-pv-meta-item"><span class="l114-pv-meta-ic"><i class="icon-calendar3"></i></span><span class="l114-pv-meta-txt"><span class="l114-pv-meta-lbl">Valid From</span><span class="l114-pv-meta-val" id="pv_from">{{ $pvFromLabel }}</span></span></div>
                    <div class="l114-pv-meta-item"><span class="l114-pv-meta-ic"><i class="icon-calendar3"></i></span><span class="l114-pv-meta-txt"><span class="l114-pv-meta-lbl">Valid Until</span><span class="l114-pv-meta-val" id="pv_until">{{ $pvUntilLabel }}</span></span></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card l114-pv-tips">
        <div class="l114-pv-tips-head"><span class="l114-pv-tips-ic"><i class="icon-info22"></i></span> Tips for a Better Circular</div>
        <ul class="l114-pv-tips-list">
            <li>Use a clear and concise title.</li>
            <li>Include all necessary details in the message.</li>
            <li>Choose the right audience to avoid unnecessary notifications.</li>
            <li>Attach relevant documents if needed.</li>
            <li>Set a validity period if the information is time-sensitive.</li>
        </ul>
    </div>
</aside>
