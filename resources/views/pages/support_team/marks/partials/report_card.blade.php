{{--
    Term report card — adapter (redesign 2026-11-06).

    The document itself lives in ONE shared partial,
    marks/partials/report_card_document, which also renders the
    annual card — the term card, the annual card and every surface
    that prints them (screen, browser print, PDF, section bulk) can
    never drift apart again. This adapter only names the mode; all
    data is the App\Support\ReportCard::build assembly, unchanged.

    Expects: $card, $sr, $ex, $exr, $my_class, $class_type, $s, $ct;
    optional $skills, $rc_show_traits (print: also render the
    traits annex).
--}}
@include('pages.support_team.marks.partials.report_card_document', ['rcd_mode' => 'term'])
