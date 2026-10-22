{{-- Loop 75: styles for the single-circular document, shared by
     the browser print view and the dompdf download. Plain CSS
     only (no flex-gap/grid) so dompdf renders it faithfully. --}}
<style>
    .cdoc { color: #172536; font-family: "Segoe UI", Arial, sans-serif; margin: 0 auto; max-width: 760px; padding: 8px 4px; }
    .cdoc-head { border-bottom: 3px solid #3478f6; padding-bottom: 14px; }
    .cdoc-head table { border-collapse: collapse; width: 100%; }
    .cdoc-logo { height: 52px; }
    .cdoc-mono { background: #3478f6; border-radius: 10px; color: #fff; display: inline-block; font-size: 20px; font-weight: 700; height: 52px; line-height: 52px; text-align: center; width: 52px; }
    .cdoc-school { font-size: 22px; font-weight: 700; margin: 0; }
    .cdoc-addr { color: #5f7185; font-size: 12px; margin: 2px 0 0; }
    .cdoc-label { color: #2867dc; font-size: 11px; font-weight: 700; letter-spacing: 3px; margin: 22px 0 4px; text-transform: uppercase; }
    .cdoc-title { font-size: 24px; font-weight: 700; line-height: 1.3; margin: 0 0 12px; }
    .cdoc-meta { border: 1px solid #e4eaf1; border-radius: 8px; margin: 0 0 20px; padding: 6px 14px; }
    .cdoc-meta table { border-collapse: collapse; width: 100%; }
    .cdoc-meta td { border-bottom: 1px solid #eef2f6; font-size: 13px; padding: 7px 4px; vertical-align: top; }
    .cdoc-meta tr:last-child td { border-bottom: 0; }
    .cdoc-meta .k { color: #718096; width: 150px; }
    .cdoc-meta .v { font-weight: 600; }
    .cdoc-body { font-size: 14.5px; line-height: 1.7; }
    .cdoc-foot { border-top: 1px solid #e4eaf1; color: #718096; font-size: 11.5px; margin-top: 28px; padding-top: 10px; }
    .cdoc-foot table { border-collapse: collapse; width: 100%; }
    .cdoc-foot .r { text-align: right; }
</style>
