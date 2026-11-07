{{--
    Annual / cumulative report card — adapter (redesign 2026-11-06).

    The document itself lives in ONE shared partial,
    marks/partials/report_card_document, which also renders the
    term card — the annual card, the term card and every surface
    that prints them (screen, browser print, PDF) can never drift
    apart again. This adapter only names the mode; all data is the
    App\Support\ReportCard::buildAnnual assembly, unchanged (a term
    cell is the term's stored total; the annual score is the mean of
    the term totals present — a missed term is excluded, never
    averaged in as 0).

    Expects: $card, $sr, $year, $my_class, $class_type, $s, $ct.
--}}
@include('pages.support_team.marks.partials.report_card_document', ['rcd_mode' => 'annual'])
