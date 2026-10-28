<html>
<head>
    <title>Bulk ID Cards - {{ $my_class->name }}@if($section) {{ $section->name }}@endif</title>
    {{-- Wave 2 feature 4: whole-class/section ID card sheet (dompdf).
         Card content mirrors the single-student card partial field for
         field (school header, photo-or-initials tile, name,
         class/section, admission no, session footer) with self-contained
         dompdf-safe table CSS — the partial's flex/aspect-ratio rules
         from qs.css do not render reliably in dompdf, so the styles are
         replicated here rather than linked. There is deliberately NO QR
         code on these cards: no QR library exists in this app, and no
         fake QR image is ever printed. --}}
    <style>
        @page { size: A4; margin: 10mm 10mm 10mm 10mm; }
        html { background: #fff; }
        body { margin: 0; font-family: "Segoe UI", Arial, sans-serif; color: #1e293b; }
        .bdc-sheet { width: 100%; }
        .bdc-sheet-break { page-break-after: always; }
        .bdc-sheet-last { page-break-after: auto; }
        .bdc-grid { width: 100%; border-collapse: separate; border-spacing: 4mm 4mm; }
        .bdc-grid td { width: 50%; vertical-align: top; padding: 0; }
        .bdc-card { border: 1px solid #94a3b8; border-radius: 3mm; height: 54mm; overflow: hidden; page-break-inside: avoid; }
        .bdc-head { background: #0f2a4a; color: #ffffff; padding: 2.5mm 3mm; }
        .bdc-school { font-size: 11pt; font-weight: bold; }
        .bdc-head-sub { font-size: 7.5pt; letter-spacing: 1px; text-transform: uppercase; }
        /* ID-card fix: the body table had no width and no fixed layout,
           so dompdf's auto layout shrank the photo column below its
           22mm and the initials overflowed into the name ("JSJayson").
           Fixed layout + 100% width make the 22mm column real, and the
           initials live in their own block sized exactly to the tile. */
        .bdc-body { width: 100%; table-layout: fixed; }
        .bdc-photo { vertical-align: top; padding: 3mm 0 3mm 3mm; }
        .bdc-photo img { width: 22mm; height: 26mm; }
        .bdc-initials { width: 22mm; height: 26mm; line-height: 26mm; background: #e2e8f0; color: #0f2a4a; font-size: 16pt; font-weight: bold; text-align: center; }
        /* The outer grid's `.bdc-grid td { width: 50% }` matches EVERY
           descendant td (specificity 0,1,1) — including these body cells
           — so a plain `.bdc-photo` width lost to it and both columns
           split 50/50. This 0,2,1 rule wins the photo column back.
           It MUST be a percent: dompdf's fixed-layout code records only
           percent widths (Cellmap.php: absolute widths resolve from a
           frame_min of 0, so mm/pt widths are silently dropped) —
           24% of the ~93mm card ≈ 22mm. */
        .bdc-body td.bdc-photo { width: 24%; }
        .bdc-info { padding-left: 3mm; vertical-align: top; }
        .bdc-name { font-size: 11pt; font-weight: bold; margin-bottom: 1.5mm; }
        .bdc-row { font-size: 8.5pt; margin-bottom: 1mm; }
        .bdc-label { color: #64748b; }
        .bdc-foot { border-top: 1px solid #e2e8f0; color: #64748b; font-size: 7.5pt; padding: 1.5mm 3mm; text-align: center; }
    </style>
</head>
<body>
    @foreach($students->chunk(8) as $page)
        <div class="bdc-sheet {{ $loop->last ? 'bdc-sheet-last' : 'bdc-sheet-break' }}">
            <table class="bdc-grid">
                @foreach($page->chunk(2) as $row)
                    <tr>
                        @foreach($row as $sr)
                            <td>
                                <div class="bdc-card">
                                    <div class="bdc-head">
                                        <div class="bdc-school">{{ $school }}</div>
                                        <div class="bdc-head-sub">Student Identity Card</div>
                                    </div>
                                    <table class="bdc-body" cellpadding="0" cellspacing="0"><tr>
                                        <td class="bdc-photo">@if(Qs::hasRealPhoto(optional($sr->user)->photo) && !empty($sr->pdf_photo))<img src="{{ $sr->pdf_photo }}" alt="">@else<div class="bdc-initials">{{ Qs::initials(optional($sr->user)->name) }}</div>@endif</td>
                                        <td class="bdc-info">
                                            <div class="bdc-name">{{ optional($sr->user)->name }}</div>
                                            <div class="bdc-row"><span class="bdc-label">Class:</span> {{ $sr->my_class->name }}@if($sr->section) &middot; {{ $sr->section->name }}@endif</div>
                                            <div class="bdc-row"><span class="bdc-label">Adm No:</span> {{ $sr->adm_no ?: '—' }}</div>
                                        </td>
                                    </tr></table>
                                    <div class="bdc-foot">Valid for session <strong>{{ $session }}</strong></div>
                                </div>
                            </td>
                        @endforeach
                        @if($row->count() < 2)
                            <td></td>
                        @endif
                    </tr>
                @endforeach
            </table>
        </div>
    @endforeach
</body>
</html>
