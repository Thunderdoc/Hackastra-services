{{--
    Embedded stylesheet for the payslip PDF document (Loop 89) —
    the Loop 71 document tokens (navy #10263b, hairline #b9c6d4,
    shaded heads #eef2f7) restated for dompdf: table layout only,
    no flex/grid. Included once per PDF wrapper view.
--}}
<style>
    @page { margin: 42px 46px; }
    body { font-family: "DejaVu Sans", Arial, Helvetica, sans-serif; color: #172536; font-size: 12.5px; }
    .psdoc { position: relative; }
    .psdoc-watermark { position: absolute; top: 300px; left: 0; width: 100%; text-align: center; font-size: 120px; font-weight: 800; letter-spacing: .04em; color: #10263b; opacity: .055; }
    .psdoc-watermark-img { position: absolute; top: 260px; left: 50%; margin-left: -170px; width: 340px; opacity: .10; }
    .psdoc-head { width: 100%; border-collapse: collapse; border-bottom: 2px solid #e4eaf1; margin-bottom: 14px; }
    .psdoc-head td { vertical-align: top; padding-bottom: 12px; }
    .psdoc-head-side { text-align: right; width: 200px; }
    .psdoc-brand { border-collapse: collapse; }
    .psdoc-brand td { padding-bottom: 0; vertical-align: middle; }
    .psdoc-brand-tile { width: 56px; }
    .psdoc-mono { display: inline-block; width: 48px; height: 48px; background: #10263b; color: #ffffff; font-size: 17px; font-weight: 800; text-align: center; line-height: 48px; border-radius: 8px; }
    .psdoc-logo { max-height: 48px; }
    .psdoc-school { color: #10263b; font-weight: 700; font-size: 16.5px; margin-bottom: 3px; }
    .psdoc-muted { color: #718096; }
    .psdoc-title { color: #10263b; letter-spacing: .12em; font-weight: 700; font-size: 14px; margin-bottom: 3px; }
    .psdoc-stamp { display: inline-block; border: 2px solid; border-radius: 6px; padding: 2px 12px; font-weight: 700; letter-spacing: .14em; font-size: 10.5px; margin-top: 6px; }
    .psdoc-stamp-paid { color: #1f9d57; border-color: #1f9d57; }
    .psdoc-stamp-pending { color: #c07f1f; border-color: #e8a13a; }
    .psdoc-staff { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    .psdoc-staff td { width: 50%; padding: 3px 0; vertical-align: top; }
    .psdoc-staff span { color: #718096; display: inline-block; width: 92px; }
    .psdoc-staff strong { color: #172536; font-weight: 700; }
    .psdoc-table { width: 100%; border-collapse: collapse; }
    .psdoc-table th, .psdoc-table td { padding: 7px 10px; border-bottom: 1px solid #edf1f5; text-align: left; }
    .psdoc-table th { background: #eef2f7; color: #10263b; }
    .psdoc-th-main { width: 62%; }
    .psdoc-th-amt { text-align: right !important; }
    .psdoc-amt { text-align: right; }
    .psdoc-subtotal td { font-weight: 700; background: #fbfcfe; }
    .psdoc-net { width: 100%; border-collapse: collapse; background: #eef2f7; border: 1px solid #b9c6d4; margin-top: 14px; }
    .psdoc-net td { padding: 12px 16px; vertical-align: middle; }
    .psdoc-net-main span { color: #44546a; font-size: 9.5px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; display: block; }
    .psdoc-net-main strong { color: #10263b; font-size: 19px; }
    .psdoc-net-words { color: #44546a; font-style: italic; text-align: right; }
    .psdoc-remarks { color: #718096; margin: 10px 0 0; }
    .psdoc-foot { width: 100%; border-collapse: collapse; border-top: 1px solid #edf1f5; margin-top: 14px; }
    .psdoc-foot td { color: #9aa7b5; font-size: 9.5px; padding-top: 8px; vertical-align: top; }
    .psdoc-foot-right { text-align: right; }
    .psdoc-genline { text-align: center; color: #718096; font-size: 8.5px; letter-spacing: .16em; text-transform: uppercase; margin-top: 12px; }
    .psdoc-page { page-break-after: always; }
    .psdoc-page-last { page-break-after: auto; }
</style>
