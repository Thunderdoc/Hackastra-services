{{--
    F4 answer key document (design §5.2) — a SEPARATE document,
    always: never a section of the paper PDF, never combined.
    The band labels it TEACHER COPY (no watermark transforms).
    Expects: $doc built WITH answers (documentData(..., true)).
--}}
<style>
    .qk-doc{font-family:'DejaVu Sans',sans-serif;color:#172536;font-size:13px;line-height:1.5;background:#fff;padding:18px}
    .qk-doc .qk-centre{text-align:center}
    .qk-doc .qk-logo{max-height:64px;max-width:220px}
    .qk-doc .qk-school{font-size:20px;font-weight:700;margin:4px 0 0}
    .qk-doc .qk-addr{font-size:11.5px;color:#44546a;margin:2px 0 0}
    .qk-doc .qk-band{background:#7f1d1d;color:#fff;text-align:center;font-weight:700;font-size:15px;letter-spacing:1px;padding:8px 6px;margin:14px 0 10px}
    .qk-doc table.qk-meta{width:100%;border-collapse:collapse;margin-bottom:10px}
    .qk-doc table.qk-meta td{border:1px solid #bfcfdf;padding:5px 8px;font-size:12.5px}
    .qk-doc table.qk-meta td.qk-meta-k{background:#edf2f8;font-weight:700;width:18%}
    .qk-doc .qk-section-band{background:#edf2f8;border:1px solid #bfcfdf;font-weight:700;padding:6px 8px;margin:16px 0 8px;font-size:13.5px}
    .qk-doc .qk-line{margin:4px 0 4px 8px;font-size:12.5px}
    .qk-doc .qk-model{margin:2px 0 8px 34px;font-size:12.5px}
    .qk-doc .qk-foot{border-top:1px solid #bfcfdf;margin-top:18px;padding-top:6px;font-size:11px;color:#44546a;text-align:center}
</style>
<div class="qk-doc">
    <div class="qk-centre">
        @if(!empty($doc['logo']))
            <img class="qk-logo" src="{{ $doc['logo'] }}" alt="">
        @endif
        <p class="qk-school">{{ $doc['school_name'] }}</p>
        @php
            $qkContact = array_filter([$doc['address'] ?? null, $doc['phone'] ?? null, $doc['email'] ?? null]);
        @endphp
        @if($qkContact)
            <p class="qk-addr">{{ implode(' · ', $qkContact) }}</p>
        @endif
    </div>

    <div class="qk-band">ANSWER KEY — TEACHER COPY &middot; {{ $doc['exam_name'] }}</div>

    <table class="qk-meta">
        <tr>
            <td class="qk-meta-k">Class</td>
            <td>{{ $doc['class_name'] }}{{ $doc['section_name'] ? ' · Section ' . $doc['section_name'] : ' · All sections' }}</td>
            <td class="qk-meta-k">Subject</td>
            <td>{{ $doc['subject_name'] }}</td>
        </tr>
        <tr>
            <td class="qk-meta-k">Max. Marks</td>
            <td colspan="3">{{ $doc['total_marks'] }}</td>
        </tr>
    </table>

    @foreach($doc['sections'] as $section)
        <div class="qk-section-band">Section {{ $section['key'] }} — {{ $section['label'] }} ({{ $section['heading_marks'] }})</div>
        @foreach($section['questions'] as $i => $q)
            @if($q['question_type'] === 'mcq')
                @php
                    $qkLetter = isset($q['correct_index']) && isset($q['options'][$q['correct_index']])
                        ? chr(65 + (int) $q['correct_index']) : '?';
                    $qkText = isset($q['correct_index']) ? ($q['options'][$q['correct_index']] ?? '') : '';
                @endphp
                <p class="qk-line"><strong>Q{{ $i + 1 }} — {{ $qkLetter }} ({{ $qkText }})</strong> [{{ $q['marks'] }}]</p>
            @else
                <p class="qk-line"><strong>Q{{ $i + 1 }}</strong> [{{ $q['marks'] }}]</p>
                <p class="qk-model">{{ $q['model_answer'] }}</p>
            @endif
        @endforeach
    @endforeach

    <div class="qk-foot">{{ $doc['school_name'] }} · {{ $doc['paper']->title }} · TEACHER COPY</div>
</div>
