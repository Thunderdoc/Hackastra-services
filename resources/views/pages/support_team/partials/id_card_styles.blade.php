{{-- ID card design styles (item 14). Included ONCE by every host
     view (screen + PDF). Geometry is CR80 vertical in points
     (153 x 242.6) so the same markup prints at true card size in
     the browser and in dompdf.
     Renderer discipline (fix round 2026-11-01, proven by rendered
     PDF pages): dompdf paints NOTHING for a background whose
     value chain reaches a linear-gradient, and its media type
     is screen so no media query can shield the PDF. So: these
     base rules carry SOLID colours only; the gradients live in
     id_card_screen_fx, which only the on-screen hosts include.
     The tile grid / identity strip / signature row are TABLES
     (dompdf mislays floated percentage grids), and initials are
     absolutely positioned inside their circles (line-height
     centring clips in dompdf). --}}
<style>
    .idc2{width:153pt;height:242.6pt;background:#fff;border:0.75pt solid #d7e0ec;border-radius:7pt;overflow:hidden;position:relative;font-family:'DejaVu Sans',Arial,sans-serif;color:#172536;-webkit-print-color-adjust:exact;print-color-adjust:exact}
    .idc2-band{height:29pt;padding:5pt 8pt 0;position:relative;color:#fff}
    .idc2-band .idc2-logo,.idc2-bhead .idc2-logo{float:left;width:15pt;height:15pt;border-radius:4pt;background:rgba(255,255,255,.16);text-align:center;margin-right:5pt}
    .idc2-band .idc2-logo img,.idc2-bhead .idc2-logo img{width:15pt;height:15pt;border-radius:4pt}
    .idc2-band .idc2-mono,.idc2-bhead .idc2-mono{font-size:8pt;font-weight:bold;line-height:15pt;color:#fff}
    .idc2-sch{font-size:8.5pt;font-weight:bold;line-height:1.15}
    .idc2-schid{font-size:4.6pt;letter-spacing:0.22em;color:rgba(255,255,255,.75);margin-top:1pt}
    .idc2-photospacer{height:31pt}
    .idc2-photowrap{position:absolute;top:26pt;left:0;right:0;text-align:center}
    .idc2-photobox{position:relative;display:block;width:41pt;margin:0 auto}
    .idc2-photo{display:inline-block;width:37pt;height:37pt;border-radius:50%;border:1.6pt solid #e9c766;background:#5b7a99;text-align:center;position:relative}
    .idc2-photo img{width:37pt;height:37pt;border-radius:50%}
    .idc2-ini{position:absolute;left:0;right:0;top:10.5pt;line-height:14pt;font-size:13pt;font-weight:bold;color:#fff;text-align:center}
    .idc2-stampf{position:absolute;left:-15pt;bottom:-8pt;width:26pt;height:26pt;transform:rotate(-12deg);opacity:.94}
    .idc2-seal{position:absolute;right:-5pt;bottom:0;width:15pt;height:15pt;border-radius:50%;background:#e9c766;border:0.75pt solid #c1922a;color:#5f430c;text-align:center}
    .idc2-seal .idc2-seal-a{display:block;font-size:4.4pt;font-weight:bold;line-height:1;margin-top:3.4pt}
    .idc2-seal .idc2-seal-b{display:block;font-size:1.9pt;font-weight:bold;letter-spacing:0.08em;line-height:1;margin-top:0.8pt}
    .idc2-name{text-align:center;font-size:10.5pt;font-weight:bold;color:#10263b;margin-top:0.5pt;padding:0 6pt;line-height:1.2}
    .idc2-micro{text-align:center;font-size:3.4pt;letter-spacing:0.26em;color:#b3c2d0;font-weight:bold;margin-top:1pt;padding:0 8pt}
    .idc2-pillrow{text-align:center;margin-top:1pt}
    .idc2-pill{display:inline-block;font-size:5.4pt;font-weight:bold;letter-spacing:0.14em;padding:2pt 7pt;border-radius:99pt;border:0.6pt solid}
    .idc2-tiles{margin:1.5pt 8pt 0}
    .idc2-tgrid{width:100%;border-collapse:separate;border-spacing:0}
    .idc2-tdl{width:50%;padding:0 2pt 1.6pt 0;vertical-align:top}
    .idc2-tdr{width:50%;padding:0 0 1.6pt 2pt;vertical-align:top}
    .idc2-tdw{padding:0 0 1.6pt;vertical-align:top}
    .idc2-tilebox{background:#eef3f8;border-radius:4.5pt;padding:2.2pt 4.5pt;min-height:14pt}
    .idc2-fl{display:block;font-size:4.4pt;font-weight:bold;letter-spacing:0.13em;color:#8aa0b4;text-transform:uppercase;line-height:1.06}
    .idc2-fv{display:block;font-size:7.3pt;font-weight:bold;color:#172536;margin-top:1pt;line-height:1.08}
    .idc2-mono{font-family:'DejaVu Sans Mono',monospace;font-size:6pt;letter-spacing:0}
    .idc2-clear{clear:both}
    .idc2-valid{text-align:center;font-size:6.4pt;color:#5b6b7f;margin-top:0.5pt}
    .idc2-schoolpanel{position:relative;margin:5pt 8pt 0;border:0.75pt solid #d9e4ef;border-radius:5pt;min-height:26pt;padding:8pt 6pt 4pt}
    .idc2-sp-lab{position:absolute;top:3pt;left:6pt;font-size:4.4pt;letter-spacing:0.16em;color:#8aa0b4;font-weight:bold}
    .idc2-sp-addr{font-size:6.8pt;color:#34506b;line-height:1.45}
    .idc2-prop{margin:4pt 8pt 0;text-align:center;font-size:4.4pt;letter-spacing:0.17em;color:#b3c2d0;font-weight:bold}
    .idc2-accent{position:absolute;left:0;right:0;bottom:0;height:3.2pt}
    .idc2-bhead{height:18pt;padding:4pt 8pt 0;color:#fff;position:relative}
    .idc2-bhead .idc2-sch{font-size:7.5pt}
    .idc2-istrip{margin:3pt 8pt 0;background:#eef3f8;border-radius:5pt;padding:2pt 6pt}
    .idc2-mini{float:left;width:17pt;height:17pt;border-radius:50%;color:#fff;position:relative;text-align:center;margin-right:5pt}
    .idc2-minii{position:absolute;left:0;right:0;top:4.6pt;line-height:8pt;font-size:6.5pt;font-weight:bold;text-align:center}
    .idc2-pill2{float:right;margin-top:3pt}
    .idc2-in{float:left;width:80pt;padding-top:1pt}
    .idc2-in b{display:block;font-size:7.6pt;color:#10263b;line-height:1.2}
    .idc2-in small{display:block;font-size:5.8pt;color:#718096;margin-top:1pt}
    .idc2-pill2{display:inline-block;font-size:4.8pt;font-weight:bold;letter-spacing:0.12em;padding:2pt 5pt;border-radius:99pt;border:0.6pt solid}
    .idc2-bsec{margin:3pt 8pt 0}
    .idc2-bt{font-size:4.6pt;font-weight:bold;letter-spacing:0.15em;color:#8aa0b4;margin-bottom:1pt}
    .idc2-bl{font-size:6.7pt;color:#34506b;line-height:1.3}
    .idc2-bl .idc2-k{color:#718096;display:inline-block;width:34pt}
    .idc2-bl b{color:#172536}
    .idc2-addr{font-size:6.7pt;color:#34506b;line-height:1.3}
    .idc2-barcode{text-align:center;margin-top:2.5pt}
    .idc2-barcode img{height:11pt}
    .idc2-bcode{font-family:'DejaVu Sans Mono',monospace;font-size:5.6pt;letter-spacing:0.22em;color:#34506b;margin-top:0}
    .idc2-office{margin:2.5pt 8pt 0;border:0.9pt dashed #c3d5e8;border-radius:6pt;padding:2pt 6pt}
    .idc2-ot{font-size:4.6pt;font-weight:bold;letter-spacing:0.13em;color:#8aa0b4}
    .idc2-ob{font-size:5.8pt;color:#34506b;line-height:1.24;margin-top:1pt}
    .idc2-found{font-size:5pt;color:#a3b4c4}
    .idc2-sigrow{margin:2.5pt 8pt 0}
    .idc2-sigtable{width:100%;border-collapse:collapse}
    .idc2-std-l{vertical-align:top}
    .idc2-std-r{vertical-align:top;text-align:right}
    .idc2-sig{width:62pt}
    .idc2-signimg{height:13pt;max-width:56pt}
    .idc2-blank{border-bottom:0.9pt solid #b9cbdd;height:9pt}
    .idc2-sl{font-size:4.8pt;color:#718096;letter-spacing:0.08em;margin-top:1pt}
    .idc2-stamp{height:30pt;width:30pt}

    .idc2-student .idc2-band,.idc2-student .idc2-bhead{background:#0b2a4c}
    .idc2-student .idc2-accent{background:#17507f}
    .idc2-student .idc2-pill,.idc2-student .idc2-pill2{background:#e8f1fc;color:#1663b8;border-color:#c3d8f2}
    .idc2-student .idc2-photo{background:#17507f}
    .idc2-student .idc2-mini{background:#17507f}
    .idc2-teacher .idc2-band,.idc2-teacher .idc2-bhead{background:#074f3c}
    .idc2-teacher .idc2-accent{background:#0e7a5f}
    .idc2-teacher .idc2-pill,.idc2-teacher .idc2-pill2{background:#e6f5ef;color:#0e7a5f;border-color:#bfe6d6}
    .idc2-teacher .idc2-photo{background:#0e7a5f}
    .idc2-teacher .idc2-mini{background:#0e7a5f}
    .idc2-principal .idc2-band,.idc2-principal .idc2-bhead{background:#3d1c73}
    .idc2-principal .idc2-accent{background:#5b2ea6}
    .idc2-principal .idc2-pill,.idc2-principal .idc2-pill2{background:#f0eafd;color:#5b2ea6;border-color:#d9c8f5}
    .idc2-principal .idc2-photo{background:#5b2ea6}
    .idc2-principal .idc2-mini{background:#5b2ea6}
    .idc2-admin .idc2-band,.idc2-admin .idc2-bhead{background:#5f430c}
    .idc2-admin .idc2-accent{background:#8a6414}
    .idc2-admin .idc2-pill,.idc2-admin .idc2-pill2{background:#faf1da;color:#8a6414;border-color:#ecd9a8}
    .idc2-admin .idc2-photo{background:#8a6414}
    .idc2-admin .idc2-mini{background:#8a6414}
    .idc2-accountant .idc2-band,.idc2-accountant .idc2-bhead{background:#83351a}
    .idc2-accountant .idc2-accent{background:#b4502a}
    .idc2-accountant .idc2-pill,.idc2-accountant .idc2-pill2{background:#fbece4;color:#b4502a;border-color:#f3cbb6}
    .idc2-accountant .idc2-photo{background:#b4502a}
    .idc2-accountant .idc2-mini{background:#b4502a}
    .idc2-librarian .idc2-band,.idc2-librarian .idc2-bhead{background:#155e75}
    .idc2-librarian .idc2-accent{background:#0e7490}
    .idc2-librarian .idc2-pill,.idc2-librarian .idc2-pill2{background:#e0f2f7;color:#0e7490;border-color:#bfe3ee}
    .idc2-librarian .idc2-photo{background:#0e7490}
    .idc2-librarian .idc2-mini{background:#0e7490}

    /* Staff fronts carry up to five tile rows PLUS the SCHOOL
       panel + property line (students carry four rows + one
       validity line), and the frozen mockups were drawn ~8%
       taller than true CR80 — so staff fronts run a compact
       measure of the same design (fix round 2026-11-01, proven
       by rendered PDF pages: exactly one page per face). */
    .idc2-staff .idc2-photowrap{top:22pt}
    .idc2-staff .idc2-photospacer{height:17pt}
    .idc2-staff .idc2-photo{width:28pt;height:28pt}
    .idc2-staff .idc2-photo img{width:28pt;height:28pt}
    .idc2-staff .idc2-ini{top:7.5pt;font-size:10.5pt}
    .idc2-staff .idc2-seal{width:12.5pt;height:12.5pt}
    .idc2-staff .idc2-seal .idc2-seal-a{margin-top:2.4pt}
    .idc2-staff .idc2-stampf{left:-11pt;bottom:-6pt;width:19pt;height:19pt}
    .idc2-staff .idc2-name{font-size:9.8pt;margin-top:0}
    .idc2-staff .idc2-micro{margin-top:0.5pt;font-size:3.1pt;letter-spacing:0.12em}
    .idc2-staff .idc2-pillrow{margin-top:0.5pt}
    .idc2-staff .idc2-tiles{margin-top:1pt}
    .idc2-staff .idc2-tdl,.idc2-staff .idc2-tdr,.idc2-staff .idc2-tdw{padding-bottom:1pt}
    .idc2-staff .idc2-tilebox{padding-top:1.8pt;padding-bottom:1.8pt;min-height:12.5pt}
    .idc2-staff .idc2-fl{font-size:4.2pt;line-height:1.02}
    .idc2-staff .idc2-fv{font-size:6.4pt;line-height:1.04}
    .idc2-staff .idc2-mono{font-size:5.8pt}
    .idc2-staff .idc2-schoolpanel{margin-top:2.5pt;padding:8pt 6pt 2pt}
    .idc2-staff .idc2-sp-addr{font-size:6.1pt;line-height:1.2}
    .idc2-staff .idc2-prop{font-size:4pt;letter-spacing:0.08em;margin-top:2pt}

    /* Parity pass (owner correction 2026-11-01): the frozen final9 design
       carries a gold trim line under the header band on both faces and
       right-aligned values on the wide tiles. Band heights shrink by the
       border width so the card geometry does not move. */
    .idc2-band{border-bottom:2pt solid #c9a227;height:27pt}
    {{-- Round 2: the back head's two lines (school name 7.5pt/1.15 +
         the DETAILS line 4.6pt + its 1pt margin + 4pt top padding)
         need ~19pt of content height, but this rule held it at 16pt —
         the DETAILS line rendered half-clipped at the gold trim on
         every card back, screen and PDF. 20pt fits both lines; the
         4pt growth comes out of the back body's slack (its content
         ends well above the bottom accent). --}}
    .idc2-bhead{border-bottom:2pt solid #c9a227;height:20pt}
    .idc2-widebox .idc2-fl{display:inline}
    .idc2-widebox .idc2-fv{float:right;margin-top:0;text-align:right}
.idc2-micro{font-size:3pt;letter-spacing:0.05em}
    .idc2-staff .idc2-micro{font-size:2.9pt;letter-spacing:0.06em}
    /* The back stamp stays in flow at 20pt and the identity-strip name
       gets a wider, one-line measure: together with the spacing trims
       above, the tallest back keeps real slack in bulk documents. */
    .idc2-stamp{float:right;height:20pt;width:20pt}
    .idc2-sig{float:left}
    .idc2-in{width:76pt}
    .idc2-mini{width:15pt;height:15pt;margin-right:3pt}
    .idc2-pill2{font-size:4.4pt;padding:1.5pt 3pt;letter-spacing:0.08em}
    .idc2-in-long b{font-size:6.2pt}
    .idc2-pill2{padding:1.5pt 4pt}
    .idc2-in b{font-size:7pt}
    /* The uploaded signature (13pt) is 4pt taller than the blank it
       replaces; the row above gives the 4pt back so the tallest back
       keeps its exact pre-signature geometry. */
    .idc2-sigrow{margin-top:0.5pt}
    .idc2-signimg{height:11pt}
    /* Back slack: the tallest back (two-line name in the identity
       strip) sat within a point of the card height, so any later
       addition (the uploaded stamp/signature) pushed its last row to
       a third page in bulk documents. Four points come back out of
       the back's section/barcode/office spacing for good. */
    .idc2-bsec{margin-top:2pt}
    .idc2-barcode{margin-top:2pt}
    .idc2-office{margin-top:1.5pt}

    /* Parity fix round 2 (owner correction 2026-11-06, measured
       against the frozen final9 render + fresh app renders):
       (1) BAND PATTERN — final9's bands carry a tone-on-tone
       guilloche that dompdf cannot paint from CSS; it now rides
       as a raster <img> (IdCardFactory::bandPattern) sized to the
       band box — a 32pt asset on the front band and a matching
       20pt top-slice on the back band, so no clipping is needed
       and the back band's sub-line keeps its natural overflow.
       (2) STAMP SIZE BUG — the base .idc2-photo img rule (0,1,1)
       silently beat .idc2-stampf (0,1,0), so the front stamp
       rendered at PHOTO size (37pt student / 28pt staff) and sat
       on the initials. The restated img.idc2-stampf rules below
       (0,2,1 / 0,3,1) restore a real stamp size: 29pt student
       (0.78x the size it wrongly rendered at), 22pt staff, moved
       to the final9 rim position (centre at -0.44d / +0.30d of
       the medallion centre) so the initials stay fully readable.
       (3) MEDALLION — final9's medallion is a third of the card
       width (88 of ~263 units); production was a quarter (40.2pt
       of 153). Student photo grows to 47pt + a 2pt gold ring
       (51pt outer, 33%); staff nudges to 32pt + 1.9pt (35.8pt
       outer) inside its compact measure. The student front pays
       for its taller medallion out of tile/name spacing below,
       point for point, so bulk page counts cannot move. */
    .idc2-bandpat{position:absolute;top:0;left:0;width:153pt;height:32pt}
    .idc2-bandpat-head{height:20pt}
    .idc2-photowrap{top:23pt}
    .idc2-photospacer{height:40pt}
    .idc2-photobox{width:51pt}
    .idc2-photo{width:47pt;height:47pt;border-width:2pt}
    .idc2-photo img{width:47pt;height:47pt}
    .idc2-ini{top:15.5pt;font-size:14.5pt;line-height:15.5pt}
    .idc2-photo img.idc2-stampf{width:29pt;height:29pt;left:-13.5pt;bottom:-6pt}
    .idc2-tiles{margin-top:1pt}
    .idc2-tdl{padding-bottom:1.05pt}
    .idc2-tdr{padding-bottom:1.05pt}
    .idc2-tdw{padding-bottom:1.05pt}
    .idc2-tilebox{padding-top:1.75pt;padding-bottom:1.75pt}
    .idc2-name{margin-top:0}
    .idc2-micro{margin-top:0.5pt}
    .idc2-pillrow{margin-top:0.5pt}
    .idc2-valid{margin-top:0}
    .idc2-staff .idc2-photowrap{top:21pt}
    .idc2-staff .idc2-photospacer{height:23pt}
    .idc2-staff .idc2-photobox{width:37pt}
    .idc2-staff .idc2-photo{width:32pt;height:32pt;border-width:1.9pt}
    .idc2-staff .idc2-photo img{width:32pt;height:32pt}
    .idc2-staff .idc2-ini{top:9pt;font-size:11.5pt;line-height:12.5pt}
    .idc2-staff .idc2-photo img.idc2-stampf{width:22pt;height:22pt;left:-11pt;bottom:-6pt}
    .idc2-staff .idc2-schoolpanel{margin-top:2pt}
</style>
