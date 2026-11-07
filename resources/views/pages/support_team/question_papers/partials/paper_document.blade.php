{{--
    F4 question paper document (design §5.1) — ONE partial for the
    screen (show/print) and PDF hosts, so surfaces cannot drift.

    ANSWER SAFETY: this partial receives the STRIPPED view-model
    built by QuestionPaperController::documentData() — question
    rows carry question/options/marks/type only. correct_index and
    model_answer are never passed here; no answer can print.

    House document law: logo centred alone at the top, school name
    centred below, address + phone/email as one centred line, then
    a solid navy #10263b title band. Solid colours only, tables for
    geometry, no transforms.
    Expects: $doc (see QuestionPaperController::documentData).
--}}
<style>
    .qp-doc{font-family:'DejaVu Sans',sans-serif;color:#172536;font-size:13px;line-height:1.5;background:#fff;padding:18px}
    .qp-doc .qp-centre{text-align:center}
    .qp-doc .qp-logo{max-height:64px;max-width:220px}
    .qp-doc .qp-school{font-size:20px;font-weight:700;margin:4px 0 0}
    .qp-doc .qp-addr{font-size:11.5px;color:#44546a;margin:2px 0 0}
    .qp-doc .qp-band{background:#10263b;color:#fff;text-align:center;font-weight:700;font-size:15px;letter-spacing:1px;padding:8px 6px;margin:14px 0 10px}
    .qp-doc table.qp-meta{width:100%;border-collapse:collapse;margin-bottom:10px}
    .qp-doc table.qp-meta td{border:1px solid #bfcfdf;padding:5px 8px;font-size:12.5px}
    .qp-doc table.qp-meta td.qp-meta-k{background:#edf2f8;font-weight:700;width:18%}
    .qp-doc .qp-instructions{border:1px solid #bfcfdf;background:#edf2f8;padding:8px 10px;font-size:12.5px;margin-bottom:12px}
    .qp-doc .qp-student{font-size:12.5px;margin-bottom:12px}
    .qp-doc .qp-section-band{background:#edf2f8;border:1px solid #bfcfdf;font-weight:700;padding:6px 8px;margin:16px 0 8px;font-size:13.5px}
    .qp-doc table.qp-q{width:100%;border-collapse:collapse;margin-bottom:8px}
    .qp-doc table.qp-q td{vertical-align:top;padding:2px 4px}
    .qp-doc td.qp-marks{width:44px;text-align:right;font-weight:700}
    .qp-doc td.qp-num{width:30px;font-weight:700}
    .qp-doc table.qp-opts{width:100%;border-collapse:collapse;margin:2px 0 4px 30px;width:92%}
    .qp-doc table.qp-opts td{padding:2px 6px 2px 0;font-size:12.5px;vertical-align:top}
    .qp-doc .qp-opt-line{margin:2px 0 2px 34px;font-size:12.5px}
    .qp-doc .qp-space{border-bottom:1px solid #bfcfdf;height:22px;margin-left:34px}
    .qp-doc .qp-foot{border-top:1px solid #bfcfdf;margin-top:18px;padding-top:6px;font-size:11px;color:#44546a;text-align:center}
</style>
<div class="qp-doc">
    <div class="qp-centre">
        @if(!empty($doc['logo']))
            <img class="qp-logo" src="{{ $doc['logo'] }}" alt="">
        @endif
        <p class="qp-school">{{ $doc['school_name'] }}</p>
        @php
            $qpContact = array_filter([$doc['address'] ?? null, $doc['phone'] ?? null, $doc['email'] ?? null]);
        @endphp
        @if($qpContact)
            <p class="qp-addr">{{ implode(' · ', $qpContact) }}</p>
        @endif
    </div>

    <div class="qp-band">QUESTION PAPER &middot; {{ $doc['exam_name'] }}</div>

    <table class="qp-meta">
        <tr>
            <td class="qp-meta-k">Class</td>
            <td>{{ $doc['class_name'] }}{{ $doc['section_name'] ? ' · Section ' . $doc['section_name'] : ' · All sections' }}</td>
            <td class="qp-meta-k">Subject</td>
            <td>{{ $doc['subject_name'] }}</td>
        </tr>
        <tr>
            <td class="qp-meta-k">Date</td>
            <td>{{ $doc['exam_date'] ?: '______' }}</td>
            <td class="qp-meta-k">Time</td>
            <td>{{ $doc['duration_minutes'] ? $doc['duration_minutes'] . ' minutes' : '______' }}</td>
        </tr>
        <tr>
            <td class="qp-meta-k">Max. Marks</td>
            <td colspan="3">{{ $doc['total_marks'] }}</td>
        </tr>
    </table>

    <p class="qp-student">Name: ____________ &nbsp;&nbsp; Roll No: ________</p>

    <div class="qp-instructions"><strong>General Instructions:</strong> {{ $doc['instructions'] }}</div>

    @foreach($doc['sections'] as $section)
        <div class="qp-section-band">Section {{ $section['key'] }} — {{ $section['label'] }} ({{ $section['heading_marks'] }})</div>
        @foreach($section['questions'] as $i => $q)
            <table class="qp-q">
                <tr>
                    <td class="qp-num">Q{{ $i + 1 }}.</td>
                    <td>{{ $q['question'] }}</td>
                    <td class="qp-marks">[{{ $q['marks'] }}]</td>
                </tr>
            </table>
            @if($q['question_type'] === 'mcq' && !empty($q['options']))
                @php $qpLong = collect($q['options'])->contains(fn ($o) => mb_strlen((string) $o) > 60); @endphp
                @if($qpLong)
                    @foreach($q['options'] as $oi => $opt)
                        <p class="qp-opt-line">({{ chr(65 + $oi) }}) {{ $opt }}</p>
                    @endforeach
                @else
                    <table class="qp-opts">
                        @foreach(array_chunk($q['options'], 2) as $rowOpts)
                            <tr>
                                @foreach($rowOpts as $col => $opt)
                                    @php $oi = $loop->parent->index * 2 + $col; @endphp
                                    <td style="width:50%">({{ chr(65 + $oi) }}) {{ $opt }}</td>
                                @endforeach
                                @if(count($rowOpts) < 2)<td style="width:50%"></td>@endif
                            </tr>
                        @endforeach
                    </table>
                @endif
            @endif
            @for($line = 0; $line < (int) $doc['answer_space_lines']; $line++)
                <div class="qp-space"></div>
            @endfor
        @endforeach
    @endforeach

    <div class="qp-foot">{{ $doc['school_name'] }} · {{ $doc['paper']->title }}</div>
</div>
