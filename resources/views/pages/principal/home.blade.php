@extends('layouts.master')
@section('page_title', 'Principal Dashboard')
{{-- Header Design B (2026-11-08): homes keep their banner/hero only —
     every other role home already suppresses the generic header, so
     the principal home does the same instead of stacking the Title
     Strip above its own greeting hero. --}}
@section('hide_page_header', true)
@section('content')
    @include('partials.role_banner', ['hkRole' => 'principal', 'hkLabel' => 'Principal'])
<style>
.pr-wrap{color:#10263b}
.pr-hero{background:#10263b;color:#fff;border-radius:12px;padding:22px 24px;margin-bottom:18px}
.pr-hero h2{color:#fff;margin:0 0 4px;font-size:26px}
.pr-hero p{color:#dbe7fb;margin:0}
.pr-hero .pr-date{color:#dbe7fb;font-size:14px}
.pr-eyebrow{letter-spacing:.14em;text-transform:uppercase;font-size:11px;color:#9fc3ec;font-weight:700}
.pr-att{border-radius:12px;padding:4px 0;margin-bottom:18px}
.pr-att-item{background:#fff;border:1px solid #d7e0ec;border-left:5px solid #d64545;border-radius:10px;padding:14px 18px;margin-bottom:10px;display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap}
.pr-att-item.amber{border-left-color:#e8a317}
.pr-att-item strong{font-size:15px}
.pr-att-item p{margin:2px 0;color:#44546a;font-size:13px}
.pr-att-item .pr-meta{color:#718096;font-size:12px}
.pr-att-item a{white-space:nowrap;font-weight:700}
.pr-ok{background:#eafaf0;border:1px solid #bfe8cb;border-radius:10px;padding:14px 18px;margin-bottom:18px}
.pr-cols{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px}
/* Fit fix (Batch 7): the finance card's unbreakable rupee totals
   set the grid column's min-content (~380px), stretching BOTH cards
   past a 360px phone. Grid items may now shrink below min-content,
   ops tracks are minmax(0,1fr), and the money tiles stack full-width
   on small phones so no figure is ever cut. The totals themselves
   scale with the viewport and their tiles drop to two columns under
   1500px, so a figure never spills out of its tile at laptop widths
   either. */
.pr-cols>*{min-width:0}
@media(max-width:991px){.pr-cols{grid-template-columns:1fr}}
.pr-card{background:#fff;border:1px solid #d7e0ec;border-radius:12px;padding:20px;margin-bottom:0}
.pr-card h5{margin:0 0 4px;font-size:17px;color:#10263b}
.pr-card p,.pr-card td{color:#44546a}
.pr-card td strong,.pr-card p strong{color:#10263b}
.pr-card .pr-sub{color:#718096;font-size:13px;margin:0 0 14px}
.pr-ops{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
@media(max-width:540px){.pr-ops{grid-template-columns:repeat(2,minmax(0,1fr))}}
.pr-op{background:#f7fafc;border-radius:10px;padding:12px 14px;min-width:0}
.pr-op strong{font-size:22px;display:block}
.pr-op span{color:#44546a;font-size:12px}
.pr-table{width:100%;border-collapse:collapse;font-size:14px}
.pr-table th{text-align:left;color:#718096;font-size:11px;letter-spacing:.08em;text-transform:uppercase;padding:6px 8px;border-bottom:1px solid #d7e0ec}
.pr-table td{padding:9px 8px;border-bottom:1px solid #eef2f7}
.pr-foot{color:#718096;font-size:12px;margin-top:10px}
.pr-chip{display:inline-flex;align-items:center;gap:7px;font-size:12.3px;font-weight:700;border-radius:999px;padding:5px 11px;white-space:nowrap}
.pr-chip b{font-weight:800}
.pr-chip-dot{width:7px;height:7px;border-radius:50%;flex:0 0 auto}
.pr-chip-t0{background:rgba(37,99,235,.11);color:#1d4fd7}.pr-chip-t0 .pr-chip-dot{background:#2563eb}.pr-chip-t0 b{color:#1d4fd7}
.pr-chip-t1{background:rgba(217,119,6,.13);color:#97590f}.pr-chip-t1 .pr-chip-dot{background:#d97706}.pr-chip-t1 b{color:#97590f}
.pr-chip-t2{background:rgba(5,150,105,.12);color:#047857}.pr-chip-t2 .pr-chip-dot{background:#059669}.pr-chip-t2 b{color:#047857}
.pr-chip-t3{background:rgba(124,58,237,.11);color:#6d28d9}.pr-chip-t3 .pr-chip-dot{background:#7c3aed}.pr-chip-t3 b{color:#6d28d9}
/* Visual-coherence round (2026-11-06): the .pr-* surfaces above
   were hardcoded light, so in dark mode the attention strip and
   the cards rendered WHITE on the dark page (owner: "aadha ujla
   aadha black"). Every surface now follows the app's dark card
   tokens (#26272a / #36383c, the dashboard-stat-card surface);
   light theme keeps the look above, untouched. */
html.hk-dark .pr-wrap{color:#e7e8e9}
html.hk-dark .pr-att-item{background:#26272a;border-color:#36383c;border-left-color:#d64545}
html.hk-dark .pr-att-item.amber{border-left-color:#e8a317}
html.hk-dark .pr-att-item p{color:#c9cbce}
html.hk-dark .pr-att-item .pr-meta{color:#a5a8ad}
html.hk-dark .pr-ok{background:#123528;border-color:#1e5c40;color:#e7e8e9}
html.hk-dark .pr-card{background:#26272a;border-color:#36383c}
html.hk-dark .pr-card h5{color:#f4f4f5}
html.hk-dark .pr-card p,html.hk-dark .pr-card td{color:#c9cbce}
html.hk-dark .pr-card td strong,html.hk-dark .pr-card p strong{color:#f4f4f5}
html.hk-dark .pr-card .pr-sub{color:#a5a8ad}
html.hk-dark .pr-op{background:#2f3135}
html.hk-dark .pr-op span{color:#a5a8ad}
html.hk-dark .pr-table th{color:#a5a8ad;border-bottom-color:#36383c}
html.hk-dark .pr-table td{border-bottom-color:#36383c}
html.hk-dark .pr-foot{color:#a5a8ad}
html.hk-dark .pr-chip-t0{background:rgba(37,99,235,.22);color:#a9c6ff}html.hk-dark .pr-chip-t0 b{color:#a9c6ff}
html.hk-dark .pr-chip-t1{background:rgba(217,119,6,.22);color:#f5bc60}html.hk-dark .pr-chip-t1 b{color:#f5bc60}
html.hk-dark .pr-chip-t2{background:rgba(5,150,105,.22);color:#4ade9e}html.hk-dark .pr-chip-t2 b{color:#4ade9e}
html.hk-dark .pr-chip-t3{background:rgba(124,58,237,.24);color:#c9aefc}html.hk-dark .pr-chip-t3 b{color:#c9aefc}

/* ===== Principal home sample v2 (owner-approved 2026-11-08) =====
   The analytics presentation of the approved sample: pastel KPI
   tiles, fees donut, ranked attendance rows, averages podium and
   pastel workload bars. Screen-only surfaces; colours follow the
   site family (white cards, pastel tints, app blue). */
.pr2-pulse{display:flex;gap:9px;margin-top:14px;flex-wrap:wrap}
.pr2-pchip{display:inline-flex;align-items:center;gap:8px;font-size:12.3px;font-weight:700;border-radius:999px;padding:6px 12px;background:rgba(255,255,255,.14);color:#fff}
.pr2-pd{width:7px;height:7px;border-radius:50%;flex:0 0 auto}
.pr2-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:18px;margin-bottom:18px}
.pr2-kpis>*{min-width:0}
@media(max-width:1100px){.pr2-kpis{grid-template-columns:repeat(2,1fr)}}
@media(max-width:560px){.pr2-kpis{grid-template-columns:1fr}}
.pr2-kpi{border-radius:16px;padding:20px 21px;display:flex;gap:15px;align-items:flex-start;border:1px solid;box-shadow:0 3px 12px rgba(23,37,54,.05);min-width:0}
.pr2-kpi-ic{width:48px;height:48px;border-radius:14px;display:flex;align-items:center;justify-content:center;color:#fff;flex:0 0 auto;box-shadow:0 5px 12px rgba(20,35,60,.18)}
.pr2-kpi-lab{font-size:11px;font-weight:800;letter-spacing:.11em;text-transform:uppercase}
.pr2-kpi-num{font-size:27px;font-weight:800;line-height:1.12;margin-top:3px;white-space:nowrap;color:#14273c}
@media(max-width:1500px){.pr2-kpi-num{font-size:24px}}
.pr2-kpi-sub{font-size:12px;font-weight:600;margin-top:3px}
.pr2-kpi-blue{background:linear-gradient(135deg,#e9f0fd,#dde9fc);border-color:#d3e0f6}.pr2-kpi-blue .pr2-kpi-ic{background:#2867dc}.pr2-kpi-blue .pr2-kpi-lab{color:#1d5cd6}.pr2-kpi-blue .pr2-kpi-sub{color:#5b7ba6}
.pr2-kpi-violet{background:linear-gradient(135deg,#f2ecfe,#e9e1fd);border-color:#ddd0f8}.pr2-kpi-violet .pr2-kpi-ic{background:#7c3aed}.pr2-kpi-violet .pr2-kpi-lab{color:#6d28d9}.pr2-kpi-violet .pr2-kpi-sub{color:#7d6aad}
.pr2-kpi-green{background:linear-gradient(135deg,#e6f7ef,#d8f2e4);border-color:#c4e9d4}.pr2-kpi-green .pr2-kpi-ic{background:#059669}.pr2-kpi-green .pr2-kpi-lab{color:#047857}.pr2-kpi-green .pr2-kpi-sub{color:#4d8a72}
.pr2-kpi-amber{background:linear-gradient(135deg,#fdf4e1,#fbeed2);border-color:#f3dfb2}.pr2-kpi-amber .pr2-kpi-ic{background:#d97706}.pr2-kpi-amber .pr2-kpi-lab{color:#b45309}.pr2-kpi-amber .pr2-kpi-sub{color:#a07c3f}
html.hk-dark .pr2-kpi{border-color:#36383c;box-shadow:0 3px 12px rgba(0,0,0,.35)}
html.hk-dark .pr2-kpi-num{color:#f2f5fa}
html.hk-dark .pr2-kpi-blue{background:linear-gradient(135deg,rgba(40,103,220,.20),rgba(40,103,220,.10))}html.hk-dark .pr2-kpi-blue .pr2-kpi-lab{color:#a9c6ff}html.hk-dark .pr2-kpi-blue .pr2-kpi-sub{color:#8fa8cf}
html.hk-dark .pr2-kpi-violet{background:linear-gradient(135deg,rgba(124,58,237,.22),rgba(124,58,237,.11))}html.hk-dark .pr2-kpi-violet .pr2-kpi-lab{color:#c9aefc}html.hk-dark .pr2-kpi-violet .pr2-kpi-sub{color:#a68fd6}
html.hk-dark .pr2-kpi-green{background:linear-gradient(135deg,rgba(5,150,105,.22),rgba(5,150,105,.11))}html.hk-dark .pr2-kpi-green .pr2-kpi-lab{color:#4ade9e}html.hk-dark .pr2-kpi-green .pr2-kpi-sub{color:#79bda1}
html.hk-dark .pr2-kpi-amber{background:linear-gradient(135deg,rgba(217,119,6,.24),rgba(217,119,6,.12))}html.hk-dark .pr2-kpi-amber .pr2-kpi-lab{color:#f5bc60}html.hk-dark .pr2-kpi-amber .pr2-kpi-sub{color:#cfa763}
.pr2-row2{display:grid;grid-template-columns:5fr 7fr;gap:18px;margin-bottom:18px}
.pr2-row2>*{min-width:0}
@media(max-width:1500px){.pr2-row2{grid-template-columns:1fr 1fr}}
@media(max-width:991px){.pr2-row2{grid-template-columns:1fr}}
.pr2-card{background:#fff;border:1px solid #e3eaf2;border-radius:16px;padding:22px 24px;box-shadow:0 3px 12px rgba(23,37,54,.055);margin-bottom:18px}
.pr2-row2 .pr2-card{margin-bottom:0}
html.hk-dark .pr2-card{background:#26272a;border-color:#36383c;box-shadow:0 3px 12px rgba(0,0,0,.35)}
.pr2-card-h{display:flex;align-items:center;gap:11px;margin-bottom:18px}
.pr2-hic2{width:36px;height:36px;border-radius:11px;display:flex;align-items:center;justify-content:center;flex:0 0 auto}
.pr2-card-h h3{font-size:16.5px;font-weight:700;margin:0;color:#16283c}
html.hk-dark .pr2-card-h h3{color:#eef1f6}
.pr2-card-sub{font-size:12px;font-weight:600;margin-top:1px;color:#8296ab}
html.hk-dark .pr2-card-sub{color:#a5a8ad}
.pr2-go{margin-left:auto;font-size:12.5px;font-weight:700;color:#2867dc;display:flex;align-items:center;gap:3px;white-space:nowrap}
html.hk-dark .pr2-go{color:#8db1ff}
.pr2-fee{display:flex;flex-direction:column}
.pr2-fee-flex{display:flex;align-items:center;gap:24px;flex:1}
.pr2-ring{position:relative;width:158px;height:158px;flex:0 0 auto}
.pr2-ring svg{transform:rotate(-90deg);width:100%;height:100%;display:block}
.pr2-ring-track{stroke:#e7edf4}
.pr2-ring-arc{stroke:#3478f6}
html.hk-dark .pr2-ring-track{stroke:#3a3c40}
.pr2-ring-c{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center}
.pr2-ring-big{font-size:27px;font-weight:800;line-height:1;color:#14273c}
html.hk-dark .pr2-ring-big{color:#f2f5fa}
.pr2-ring-sm{font-size:11px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;margin-top:4px;color:#8296ab}
html.hk-dark .pr2-ring-sm{color:#a5a8ad}
.pr2-fee-rows{display:flex;flex-direction:column;gap:19px;flex:1;min-width:0}
.pr2-fee-row{display:flex;align-items:center;gap:10px}
.pr2-fd{width:10px;height:10px;border-radius:3.5px;flex:0 0 auto}
.pr2-fl{font-size:12.8px;font-weight:600;color:#5c7189}
html.hk-dark .pr2-fl{color:#c9cbce}
.pr2-fv{margin-left:auto;font-size:16.5px;font-weight:800;white-space:nowrap;color:#1c2f47}
html.hk-dark .pr2-fv{color:#eef1f6}
@media(max-width:1500px){.pr2-fv{font-size:15px}.pr2-fl{font-size:12px}}
@media(max-width:480px){.pr2-fee-flex{flex-direction:column;align-items:stretch}.pr2-ring{margin:0 auto;width:132px;height:132px}}
.pr2-fee-note{margin-top:16px;font-size:12.3px;font-weight:600;border-radius:10px;padding:9px 12px;display:flex;gap:8px;align-items:center;background:#fdf4e1;color:#97590f}
html.hk-dark .pr2-fee-note{background:rgba(217,119,6,.16);color:#f5bc60}
.pr2-fee .pr-foot{margin-top:12px}
.pr2-ca-row{display:flex;align-items:center;gap:13px;padding:7.5px 0}
.pr2-ca-row + .pr2-ca-row{border-top:1px dashed #edf1f6}
html.hk-dark .pr2-ca-row + .pr2-ca-row{border-color:#36383c}
.pr2-ca-chip{width:33px;height:33px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:11.5px;font-weight:800;color:#fff;flex:0 0 auto}
.pr2-ca-nm{width:86px;font-size:13.8px;font-weight:700;flex:0 0 auto;color:#22364e}
html.hk-dark .pr2-ca-nm{color:#dfe4ec}
.pr2-ca-bar{flex:1;height:11px;border-radius:99px;overflow:hidden;background:#edf2f7;min-width:30px}
html.hk-dark .pr2-ca-bar{background:#3a3c40}
.pr2-ca-fill{height:100%;border-radius:99px}
.pr2-ca-val{width:44px;text-align:right;font-size:14.5px;font-weight:800;flex:0 0 auto;color:#14273c}
html.hk-dark .pr2-ca-val{color:#f2f5fa}
.pr2-ca-rest{margin-top:13px;font-size:12.5px;font-weight:600;border-radius:10px;padding:9px 12px;background:#f2f6fb;color:#5c7189}
html.hk-dark .pr2-ca-rest{background:rgba(255,255,255,.04);color:#c9cbce}
@media(max-width:480px){.pr2-ca-nm{width:70px;font-size:12.5px}.pr2-ca-row{gap:9px}}
.pr2-podium{display:grid;grid-template-columns:1fr 1.14fr 1fr;gap:18px;align-items:end;margin-bottom:20px}
.pr2-podium-2{grid-template-columns:1fr 1.14fr}
.pr2-podium-1{grid-template-columns:1fr;max-width:430px;margin-left:auto;margin-right:auto}
@media(max-width:640px){.pr2-podium{grid-template-columns:1fr}}
.pr2-pod{border-radius:15px;padding:18px 18px 16px;text-align:center;position:relative;border:1px solid}
.pr2-medal{width:44px;height:44px;border-radius:50%;margin:0 auto;display:flex;align-items:center;justify-content:center;color:#fff;box-shadow:0 5px 12px rgba(20,35,60,.20)}
.pr2-pod-rk{font-size:10.5px;font-weight:800;letter-spacing:.13em;text-transform:uppercase;margin-top:10px}
.pr2-pod-cl{font-size:16.5px;font-weight:800;margin-top:2px;color:#16283c}
html.hk-dark .pr2-pod-cl{color:#f0f3f8}
.pr2-pod-av{font-size:25px;font-weight:800;line-height:1.1}
.pr2-pod-av small{font-size:12px;font-weight:700}
.pr2-p1{padding-top:24px;padding-bottom:22px;background:linear-gradient(160deg,#fdf6dd,#fbeec3);border-color:#eed88f;box-shadow:0 10px 22px rgba(190,150,20,.18)}
.pr2-p1 .pr2-medal{background:linear-gradient(145deg,#e9b93c,#c9930a)}.pr2-p1 .pr2-pod-rk{color:#97590f}.pr2-p1 .pr2-pod-av{color:#7c5410}
.pr2-p2{background:linear-gradient(160deg,#f4f7fb,#e7edf4);border-color:#cfdae6}
.pr2-p2 .pr2-medal{background:linear-gradient(145deg,#aebdd0,#8496ad)}.pr2-p2 .pr2-pod-rk{color:#5c7189}.pr2-p2 .pr2-pod-av{color:#40536c}
.pr2-p3{background:linear-gradient(160deg,#fbf0e4,#f6e3cd);border-color:#eac9a4}
.pr2-p3 .pr2-medal{background:linear-gradient(145deg,#d99a5b,#b06f33)}.pr2-p3 .pr2-pod-rk{color:#9a5a1e}.pr2-p3 .pr2-pod-av{color:#7c4a16}
html.hk-dark .pr2-p1{background:linear-gradient(160deg,rgba(217,164,6,.20),rgba(217,164,6,.09));border-color:rgba(217,164,6,.35)}html.hk-dark .pr2-p1 .pr2-pod-rk{color:#f5bc60}html.hk-dark .pr2-p1 .pr2-pod-av{color:#f7cd72}
html.hk-dark .pr2-p2{background:linear-gradient(160deg,rgba(160,178,200,.15),rgba(160,178,200,.06));border-color:rgba(160,178,200,.3)}html.hk-dark .pr2-p2 .pr2-pod-rk{color:#b9c6d8}html.hk-dark .pr2-p2 .pr2-pod-av{color:#d5dee9}
html.hk-dark .pr2-p3{background:linear-gradient(160deg,rgba(217,140,60,.18),rgba(217,140,60,.08));border-color:rgba(217,140,60,.32)}html.hk-dark .pr2-p3 .pr2-pod-rk{color:#f0b27e}html.hk-dark .pr2-p3 .pr2-pod-av{color:#f3c491}
.pr2-avg-list{display:grid;grid-template-columns:1fr 1fr;gap:0 34px}
@media(max-width:640px){.pr2-avg-list{grid-template-columns:1fr}}
.pr2-avg-row{display:flex;align-items:center;gap:11px;padding:8.5px 2px;border-top:1px dashed #edf1f6}
html.hk-dark .pr2-avg-row{border-color:#36383c}
.pr2-avg-rk{width:24px;height:24px;border-radius:8px;font-size:11.5px;font-weight:800;display:flex;align-items:center;justify-content:center;flex:0 0 auto;background:#eef3f9;color:#5c7189}
html.hk-dark .pr2-avg-rk{background:rgba(255,255,255,.06);color:#a5a8ad}
.pr2-avg-nm{font-size:13.8px;font-weight:700;width:78px;flex:0 0 auto;color:#22364e}
html.hk-dark .pr2-avg-nm{color:#dfe4ec}
.pr2-avg-bar{flex:1;height:8px;border-radius:99px;overflow:hidden;background:#edf2f7;min-width:30px}
html.hk-dark .pr2-avg-bar{background:#3a3c40}
.pr2-avg-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,#7fb0f5,#3478f6)}
.pr2-avg-vl{width:38px;text-align:right;font-size:13.5px;font-weight:800;color:#14273c}
html.hk-dark .pr2-avg-vl{color:#f2f5fa}
.pr2-avg-foot{margin-top:18px;display:flex;align-items:center;gap:9px;flex-wrap:wrap}
.pr2-avg-foot-lab{font-size:11px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;margin-right:2px;color:#8296ab}
html.hk-dark .pr2-avg-foot-lab{color:#a5a8ad}
.pr2-safe{margin-left:auto;display:inline-flex;align-items:center;gap:7px;font-size:12.3px;font-weight:700;color:#047857}
html.hk-dark .pr2-safe{color:#4ade9e}
@media(max-width:640px){.pr2-safe{margin-left:0}}
.pr2-wl-row{display:flex;align-items:center;gap:14px;padding:8.5px 0}
.pr2-wl-row + .pr2-wl-row{border-top:1px dashed #edf1f6}
html.hk-dark .pr2-wl-row + .pr2-wl-row{border-color:#36383c}
.pr2-wl-av{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12.5px;font-weight:800;flex:0 0 auto}
.pr2-wl-nm{width:128px;font-size:13.8px;font-weight:700;flex:0 0 auto;color:#22364e}
html.hk-dark .pr2-wl-nm{color:#dfe4ec}
.pr2-wl-bar{flex:1;height:13px;border-radius:99px;overflow:hidden;background:#eef2f6;min-width:30px}
html.hk-dark .pr2-wl-bar{background:#3a3c40}
.pr2-wl-fill{height:100%;border-radius:99px}
.pr2-wl-val{width:104px;text-align:right;font-size:13px;font-weight:700;flex:0 0 auto;color:#5c7189}
html.hk-dark .pr2-wl-val{color:#a5a8ad}
.pr2-wl-val b{font-size:15px;color:#14273c}
html.hk-dark .pr2-wl-val b{color:#f2f5fa}
@media(max-width:640px){.pr2-wl-nm{width:92px;font-size:12.5px}.pr2-wl-val{width:88px;font-size:12px}.pr2-wl-row{gap:10px}}
</style>

<div class="pr-wrap">
    <div class="pr-hero hk-reveal">
        <div class="pr-eyebrow">School Command Center</div>
        <p>Whole-school situation at a glance — academics, attendance, staffing and decisions.</p>
        <div class="pr-date">{{ $today->format('l, d F Y') }} &middot; Session {{ $current_session }}</div>
        {{-- Sample v2 pulse chips: each chip wires to a figure
             this page already computes — the 30-day attendance
             roll-up, the current analytics term, and the total
             approvals waiting across the four inbox counts. A
             chip whose figure is not real today does not render. --}}
        @php $prApprovalsWaiting = (int) $pr_pending_leaves_count + (int) $pr_pub_requests_count + (int) $pr_tt_requests_count + (int) ($pr_unlock_requests_count ?? 0); @endphp
        @if($pr_attendance_30d['recorded'] || $pr_term_exam || $prApprovalsWaiting > 0)
            <div class="pr2-pulse">
                @if($pr_attendance_30d['recorded'])
                    <span class="pr2-pchip"><span class="pr2-pd" style="background:#3ecf8e"></span>{{ $pr_attendance_30d['percent'] }}% attendance · last 30 days</span>
                @endif
                @if($pr_term_exam)
                    <span class="pr2-pchip"><span class="pr2-pd" style="background:#7fb0f5"></span>Term {{ $pr_term_exam->term }} in progress</span>
                @endif
                @if($prApprovalsWaiting > 0)
                    <span class="pr2-pchip"><span class="pr2-pd" style="background:#f5bc60"></span>{{ $prApprovalsWaiting }} approval{{ $prApprovalsWaiting === 1 ? '' : 's' }} waiting</span>
                @endif
            </div>
        @endif
    </div>

    @if(count($pr_attention) > 0)
        <div class="pr-att">
            @foreach($pr_attention as $item)
                <div class="pr-att-item {{ $item['tone'] === 'amber' ? 'amber' : '' }}">
                    <div>
                        <strong>{{ $item['title'] }}</strong>
                        <p>{{ $item['detail'] }}</p>
                        <div class="pr-meta">{{ $item['meta'] }}</div>
                    </div>
                    <a href="{{ $item['link'] }}">{{ $item['link_label'] }} &rarr;</a>
                </div>
            @endforeach
        </div>
    @else
        <div class="pr-ok"><strong>All clear today.</strong> No timetable clashes, uncovered substitutions, overdue approvals or incidents needing attention.</div>
    @endif

    {{-- Principal home sample v2 (owner-approved 2026-11-08,
         "principal home aisa hi banao"): the four KPIs leave the
         shared dashboard-stat-card chrome for the sample's pastel
         tiles — this page carries its own stat system again (the
         Beauty Wave 2 move onto the shared pattern is superseded
         by the owner's sample pick). Every value is a figure this
         page has always computed: enrolled students, teachers on
         roll, the school-wide last-30-days attendance roll-up and
         the year's collected fees with its collection percent. --}}
    @php
        $prFeePct = $pr_fees_total > 0 ? round($pr_fees_collected / $pr_fees_total * 100, 1) : 0.0;
        $prFeePctText = number_format($prFeePct, 1);
    @endphp
    <div class="pr2-kpis hk-reveal hk-reveal-1">
        <div class="pr2-kpi pr2-kpi-blue">
            <div class="pr2-kpi-ic"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.6"/><path d="M3.5 19.5c.6-3 2.8-4.5 5.5-4.5s4.9 1.5 5.5 4.5"/><path d="M16 5.4a3.6 3.6 0 0 1 0 6.2"/><path d="M17.8 15.3c1.6.6 2.5 1.9 2.8 4.2"/></svg></div>
            <div><div class="pr2-kpi-lab">Total Students</div><div class="pr2-kpi-num">{{ number_format($pr_students) }}</div><div class="pr2-kpi-sub">Enrolled</div></div>
        </div>
        <div class="pr2-kpi pr2-kpi-violet">
            <div class="pr2-kpi-ic"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="18" height="13" rx="2.5"/><path d="M9 7V5.5A2.5 2.5 0 0 1 11.5 3h1A2.5 2.5 0 0 1 15 5.5V7"/><path d="M3 12.5h18"/></svg></div>
            <div><div class="pr2-kpi-lab">Teaching Staff</div><div class="pr2-kpi-num">{{ number_format($pr_teachers) }}</div><div class="pr2-kpi-sub">Teachers on roll</div></div>
        </div>
        <div class="pr2-kpi pr2-kpi-green">
            <div class="pr2-kpi-ic"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="17" rx="2.5"/><path d="M8.5 12.5l2.4 2.4 4.6-5"/></svg></div>
            <div><div class="pr2-kpi-lab">Attendance</div><div class="pr2-kpi-num">{{ $pr_attendance_30d['recorded'] ? $pr_attendance_30d['percent'] . '%' : '—' }}</div><div class="pr2-kpi-sub">{{ $pr_attendance_30d['recorded'] ? 'Last 30 days, school-wide' : 'No attendance in the last 30 days' }}</div></div>
        </div>
        <div class="pr2-kpi pr2-kpi-amber">
            <div class="pr2-kpi-ic"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h13a1 1 0 0 1 1 1v2"/><path d="M3 7v11a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-8a1 1 0 0 0-1-1H5a2 2 0 0 1-2-2z"/><circle cx="16.8" cy="14.8" r="1.1" fill="currentColor" stroke="none"/></svg></div>
            <div><div class="pr2-kpi-lab">Fees Collected · {{ $today->format('Y') }}</div><div class="pr2-kpi-num">&#8377;{{ \App\Helpers\Money::indianFormat($pr_fees_collected) }}</div><div class="pr2-kpi-sub">{{ $prFeePctText }}% of this year&rsquo;s billing</div></div>
        </div>
    </div>

    <div class="pr-cols">
        <section class="pr-card">
            <h5>Today&rsquo;s School Operations</h5>
            <p class="pr-sub">Whether the school is running smoothly or needs intervention.</p>
            <div class="pr-ops">
                <div class="pr-op"><strong>{{ $pr_ops['periods'] }}</strong><span>Periods today</span></div>
                <div class="pr-op"><strong>{{ $pr_ops['registers_submitted'] }}</strong><span>Registers submitted</span></div>
                <div class="pr-op"><strong>{{ $pr_ops['registers_pending'] }}</strong><span>Registers pending</span></div>
                <div class="pr-op"><strong>{{ $pr_ops['exams_today'] }}</strong><span>Exams today</span></div>
                <div class="pr-op"><strong>{{ $pr_ops['events_today'] }}</strong><span>School events today</span></div>
                <div class="pr-op"><strong>{{ $pr_ops['substitutions_uncovered'] }}</strong><span>Uncovered periods</span></div>
            </div>
            @if(count($pr_ops['events_list']) > 0)
                <div class="pr-foot">
                    Today:
                    @foreach($pr_ops['events_list'] as $ev)
                        {{ $ev->title }}@if(!$loop->last), @endif
                    @endforeach
                </div>
            @endif
            <div class="pr-foot"><a href="{{ route('attendance.periods.index') }}">Period attendance</a> &middot; <a href="{{ route('attendance.report') }}">Monthly report</a> &middot; <a href="{{ route('tt.index') }}">Timetables</a></div>
        </section>

        <section class="pr-card">
            <h5>Approval Center</h5>
            <p class="pr-sub">Pending leave decisions and result publication requests. Emergency leaves auto-approve per policy and never wait here.</p>
            @if(count($pr_pending_leaves) > 0)
                <div class="table-responsive">
                    <table class="pr-table">
                    <thead><tr><th>Applicant</th><th>Type</th><th>Dates</th><th></th></tr></thead>
                    <tbody>
                    @foreach($pr_pending_leaves as $leave)
                        <tr>
                            <td><strong>{{ optional($leave->user)->name }}</strong></td>
                            <td>{{ ucfirst($leave->leave_type ?? 'normal') }}</td>
                            <td>{{ $leave->start_date }} &ndash; {{ $leave->end_date }}</td>
                            <td><a href="{{ route('leave.index') }}">Review</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
            @endif
            @if(count($pr_pub_requests) > 0)
                <div class="table-responsive">
                    <table class="pr-table" style="margin-top:14px;">
                    <thead><tr><th>Result publication requested</th><th>Requested</th><th></th></tr></thead>
                    <tbody>
                    @foreach($pr_pub_requests as $pubExam)
                        <tr>
                            <td><strong>{{ $pubExam->name }}</strong></td>
                            <td>{{ \Illuminate\Support\Carbon::parse($pubExam->publish_requested_at)->format('d M Y') }}</td>
                            <td><a href="{{ route('exams.publication', $pubExam->id) }}">Review &amp; publish</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
            @endif
            @if(count($pr_tt_requests) > 0)
                <div class="table-responsive">
                    <table class="pr-table" style="margin-top:14px;">
                    <thead><tr><th>Timetable approval requested</th><th>Class</th><th></th></tr></thead>
                    <tbody>
                    @foreach($pr_tt_requests as $ttReq)
                        <tr>
                            <td><strong>{{ $ttReq->name }}</strong></td>
                            <td>{{ optional($ttReq->my_class)->name ?? 'Deleted class' }}</td>
                            <td><a href="{{ route('tt.approvals') }}">Review &amp; approve</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
            @endif
            @if(isset($pr_unlock_requests) && count($pr_unlock_requests) > 0)
                <div class="table-responsive">
                    <table class="pr-table" style="margin-top:14px;">
                    <thead><tr><th>Settings unlock requested</th><th>Changes</th><th></th></tr></thead>
                    <tbody>
                    @foreach($pr_unlock_requests as $unlockReq)
                        <tr>
                            <td><strong>{{ optional($unlockReq->requester)->name ?? 'Admin' }}</strong></td>
                            <td>{{ \Illuminate\Support\Str::limit($unlockReq->changes, 90) }}</td>
                            <td><a href="{{ route('settings_unlock.index') }}">Review &amp; approve</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
            @endif
            @if(count($pr_pending_leaves) === 0 && count($pr_pub_requests) === 0 && count($pr_tt_requests) === 0 && (isset($pr_unlock_requests) ? count($pr_unlock_requests) === 0 : true))
                @include('partials.patterns.empty_state', ['icon' => 'icon-checkmark3', 'message' => 'No pending approvals.', 'compact' => true])
            @endif
            <div class="pr-foot"><a href="{{ route('leave.index') }}">Open approval queue</a></div>
        </section>
    </div>

    {{-- Principal home sample v2 (owner-approved 2026-11-08):
         the old two-up Analytics | Finance row becomes the
         sample's stack — Fees (donut) beside Class Attendance,
         then the Term averages card with its podium, then Teacher
         Workload on its own. All figures are the controller's
         existing ones; only the presentation changed. --}}
    <div class="pr2-row2">
        <section class="pr2-card pr2-fee">
            <div class="pr2-card-h">
                <div class="pr2-hic2" style="background:rgba(217,119,6,.14);color:#d97706"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h13a1 1 0 0 1 1 1v2"/><path d="M3 7v11a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-8a1 1 0 0 0-1-1H5a2 2 0 0 1-2-2z"/><circle cx="16.8" cy="14.8" r="1.1" fill="currentColor" stroke="none"/></svg></div>
                <div><h3>Fees — {{ $today->format('Y') }}</h3><div class="pr2-card-sub">Collection at a glance</div></div>
                <a class="pr2-go" href="{{ route('payments.manage') }}">Fee records <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg></a>
            </div>
            <div class="pr2-fee-flex">
                <div class="pr2-ring">
                    <svg viewBox="0 0 158 158"><circle class="pr2-ring-track" cx="79" cy="79" r="62" fill="none" stroke-width="16"/><circle class="pr2-ring-arc" cx="79" cy="79" r="62" fill="none" stroke-width="16" stroke-linecap="round" stroke-dasharray="{{ round($prFeePct / 100 * 389.6, 1) }} 389.6"/></svg>
                    <div class="pr2-ring-c"><div class="pr2-ring-big">{{ $prFeePctText }}%</div><div class="pr2-ring-sm">Collected</div></div>
                </div>
                <div class="pr2-fee-rows">
                    {{-- Money in Indian digit grouping (Money helper),
                         like the rest of the app. The labels carry
                         their (year) scope — HOTFIX H: "Fees
                         outstanding" is a current-year figure and
                         now says so, like its sibling always did. --}}
                    <div class="pr2-fee-row"><span class="pr2-fd" style="background:#3478f6"></span><span class="pr2-fl">Fees collected ({{ $today->format('Y') }})</span><span class="pr2-fv">&#8377;{{ \App\Helpers\Money::indianFormat($pr_fees_collected) }}</span></div>
                    <div class="pr2-fee-row"><span class="pr2-fd" style="background:#e8a317"></span><span class="pr2-fl">Fees outstanding ({{ $today->format('Y') }})</span><span class="pr2-fv">&#8377;{{ \App\Helpers\Money::indianFormat($pr_fees_pending) }}</span></div>
                    <div class="pr2-fee-row"><span class="pr2-fd" style="background:#b9c6d4"></span><span class="pr2-fl">Total billed ({{ $today->format('Y') }})</span><span class="pr2-fv">&#8377;{{ \App\Helpers\Money::indianFormat($pr_fees_total) }}</span></div>
                    <div class="pr2-fee-row"><span class="pr2-fd" style="background:#8496ad"></span><span class="pr2-fl">Expenses ({{ $pr_expenses_count }} records)</span><span class="pr2-fv">&#8377;{{ \App\Helpers\Money::indianFormat($pr_expenses) }}</span></div>
                </div>
            </div>
            <div class="pr2-fee-note"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 16.5l5.5-5.5 3.5 3.5L20.5 6"/><path d="M15 6h5.5v5.5"/></svg> &#8377;{{ \App\Helpers\Money::indianFormat($pr_fees_pending) }} still to collect this year.</div>
            <div class="pr-foot"><a href="{{ route('payments.manage') }}">Fee records</a> &middot; <a href="{{ route('reports.builder') }}">Reports &amp; Analytics</a></div>
        </section>

        <section class="pr2-card">
            <div class="pr2-card-h">
                <div class="pr2-hic2" style="background:rgba(5,150,105,.13);color:#059669"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="17" rx="2.5"/><path d="M8.5 12.5l2.4 2.4 4.6-5"/></svg></div>
                <div><h3>Class Attendance</h3><div class="pr2-card-sub">Last 30 days · best first</div></div>
                <a class="pr2-go" href="{{ route('attendance.report') }}">Full report <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg></a>
            </div>
            @if(count($pr_class_attendance) > 0)
                @php
                    $attRanked = collect($pr_class_attendance)->sortByDesc('percent')->values();
                    $attShown = $attRanked->take(8);
                    $attRest = $attRanked->skip(8)->values();
                    $attPalette = ['#2563eb', '#7c3aed', '#db2777', '#0891b2', '#ea580c', '#65a30d', '#0f766e', '#4f46e5'];
                    $attRestText = null;
                    if ($attRest->count() > 0) {
                        $restGroups = [];
                        foreach ($attRest as $restRow) { $restGroups[$restRow['percent']][] = $restRow['class']; }
                        krsort($restGroups);
                        $restParts = [];
                        $restIdx = 0;
                        foreach ($restGroups as $restPct => $restNames) {
                            $restCount = count($restNames);
                            if ($restCount > 2) { $restLast = array_pop($restNames); $restNameStr = implode(', ', $restNames) . ' & ' . $restLast; }
                            elseif ($restCount === 2) { $restNameStr = $restNames[0] . ' & ' . $restNames[1]; }
                            else { $restNameStr = $restNames[0]; }
                            $restParts[] = $restNameStr . ($restCount > 1 ? ' are' : ' is') . ' at ' . $restPct . '%' . ($restIdx === 0 && (int) $restPct === (int) $attShown->last()['percent'] ? ' too' : '');
                            $restIdx++;
                        }
                        $attRestText = implode(' · ', $restParts);
                        if ((int) $attRanked->min('percent') >= 75) { $attRestText .= ' — every class is in the Good band.'; }
                    }
                @endphp
                @foreach($attShown as $attRow)
                    @php
                        $attChip = \Illuminate\Support\Str::startsWith($attRow['class'], 'Class ') ? substr($attRow['class'], 6) : strtoupper(substr($attRow['class'], 0, 1));
                        $attGrad = $attRow['percent'] >= 90 ? 'linear-gradient(90deg,#3ecf8e,#059669)' : ($attRow['percent'] >= 75 ? 'linear-gradient(90deg,#8fd98a,#4caf6d)' : ($attRow['percent'] >= 50 ? 'linear-gradient(90deg,#f5c65c,#e8a317)' : 'linear-gradient(90deg,#ef7d7d,#d64545)'));
                        $attColor = $attPalette[abs(crc32($attRow['class'])) % count($attPalette)];
                    @endphp
                    <div class="pr2-ca-row">
                        <div class="pr2-ca-chip" style="background:{{ $attColor }}">{{ $attChip }}</div>
                        <div class="pr2-ca-nm">{{ $attRow['class'] }}</div>
                        <div class="pr2-ca-bar"><div class="pr2-ca-fill" style="width:{{ $attRow['percent'] }}%;background:{{ $attGrad }}"></div></div>
                        <div class="pr2-ca-val">{{ $attRow['percent'] }}%</div>
                    </div>
                @endforeach
                @if($attRestText)
                    <div class="pr2-ca-rest">{{ $attRestText }}</div>
                @endif
            @else
                @include('partials.patterns.empty_state', ['icon' => 'icon-calendar3', 'message' => 'No attendance records in the last 30 days.', 'compact' => true])
            @endif
        </section>
    </div>

    <section class="pr2-card">
        <div class="pr2-card-h">
            <div class="pr2-hic2" style="background:rgba(217,164,6,.15);color:#c9930a"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="9" r="5.5"/><path d="M8.8 13.5L7.5 21l4.5-2.4L16.5 21l-1.3-7.5"/></svg></div>
            <div><h3>{{ $pr_term_exam ? $pr_term_exam->name . ' — class averages' : 'Class averages' }}</h3><div class="pr2-card-sub">Who is leading this term</div></div>
            <a class="pr2-go" href="{{ route('marks.tabulation') }}">Tabulation <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg></a>
        </div>
        @if($pr_term_exam && count($pr_class_avgs) > 0)
            @php
                $avgRanked = collect($pr_class_avgs)->sortByDesc('avg')->values();
                $avgGroups = [];
                foreach ($avgRanked as $avgRow) {
                    $avgKey = (string) $avgRow['avg'];
                    if (!isset($avgGroups[$avgKey])) { $avgGroups[$avgKey] = ['avg' => $avgRow['avg'], 'classes' => []]; }
                    $avgGroups[$avgKey]['classes'][] = $avgRow['class'];
                }
                $avgGroups = array_values($avgGroups);
                $podGroups = array_slice($avgGroups, 0, 3);
                $listGroups = array_slice($avgGroups, 3);
                $podSlots = [];
                if (count($podGroups) > 1) { $podSlots[] = 1; }
                $podSlots[] = 0;
                if (count($podGroups) > 2) { $podSlots[] = 2; }
                $podOrdinals = ['1st', '2nd', '3rd'];
            @endphp
            <div class="pr2-podium pr2-podium-{{ count($podGroups) }}">
                @foreach($podSlots as $podSlot)
                    @php $podGroup = $podGroups[$podSlot]; @endphp
                    <div class="pr2-pod pr2-p{{ $podSlot + 1 }}">
                        <div class="pr2-medal"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="9" r="5.5"/><path d="M8.8 13.5L7.5 21l4.5-2.4L16.5 21l-1.3-7.5"/></svg></div>
                        <div class="pr2-pod-rk">{{ count($podGroup['classes']) > 1 ? 'Joint ' : '' }}{{ $podOrdinals[$podSlot] }} Place{{ $podSlot === 0 && count($podGroup['classes']) === 1 ? ' · Term Leaders' : '' }}</div>
                        <div class="pr2-pod-cl">{{ implode(' · ', $podGroup['classes']) }}</div>
                        <div class="pr2-pod-av">{{ $podGroup['avg'] }}<small> avg</small></div>
                    </div>
                @endforeach
            </div>
            @if(count($listGroups) > 0)
                <div class="pr2-avg-list">
                    @php $listSeen = 0; @endphp
                    @foreach($listGroups as $listGroup)
                        @php $listRank = count($podGroups) + 1 + $listSeen; @endphp
                        @foreach($listGroup['classes'] as $listClass)
                            <div class="pr2-avg-row"><div class="pr2-avg-rk">{{ $listRank }}</div><div class="pr2-avg-nm">{{ $listClass }}</div><div class="pr2-avg-bar"><div class="pr2-avg-fill" style="width:{{ $listGroup['avg'] }}%"></div></div><div class="pr2-avg-vl">{{ $listGroup['avg'] }}</div></div>
                        @endforeach
                        @php $listSeen += count($listGroup['classes']); @endphp
                    @endforeach
                </div>
            @endif
        @endif
        @if(count($pr_subject_avgs) > 0)
            <div class="pr2-avg-foot">
                <span class="pr2-avg-foot-lab">Top subjects by Term 1 average</span>
                @foreach($pr_subject_avgs as $subjIdx => $subjRow)
                    <span class="pr-chip pr-chip-t{{ $subjIdx % 4 }}"><span class="pr-chip-dot"></span>{{ $subjRow['subject'] }} · <b>{{ $subjRow['avg'] }}</b></span>
                @endforeach
                @if($pr_support_count === 0)
                    <span class="pr2-safe"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7 2.8v5.4c0 4.6-3 7.6-7 9.3-4-1.7-7-4.7-7-9.3V5.8L12 3z"/><path d="M9 11.8l2.2 2.2 4-4.2"/></svg> No student averaged below 50 this term</span>
                @endif
            </div>
        @endif
        <div class="pr-foot">
            Students needing academic support (Term 1 average below 50): <strong>{{ $pr_support_count }}</strong>
            &middot; Lesson plans filed: <strong>{{ $pr_lesson_plans }}</strong> (<a href="{{ route('lesson_plans.index') }}">Lesson plans</a>)
        </div>
    </section>

    <section class="pr2-card">
        <div class="pr2-card-h">
            <div class="pr2-hic2" style="background:rgba(124,58,237,.12);color:#7c3aed"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V10"/><path d="M10 20V4"/><path d="M16 20v-7"/><path d="M2 20h20"/></svg></div>
            <div><h3>Teacher Workload</h3><div class="pr2-card-sub">Registers submitted · last 30 days</div></div>
        </div>
        @if(count($pr_workload) > 0)
            @php
                $wlMax = (int) collect($pr_workload)->max('registers');
                $wlPalette = ['#2563eb', '#db2777', '#7c3aed', '#d97706', '#059669'];
                $wlTints = [['rgba(37,99,235,.13)', '#1d4fd7'], ['rgba(219,39,119,.12)', '#be185d'], ['rgba(124,58,237,.12)', '#6d28d9'], ['rgba(217,119,6,.14)', '#97590f'], ['rgba(5,150,105,.12)', '#047857']];
            @endphp
            @foreach($pr_workload as $wlIdx => $wlRow)
                @php
                    $wlParts = preg_split('/\s+/', trim($wlRow['teacher']));
                    $wlInitials = strtoupper(substr($wlParts[0], 0, 1) . (count($wlParts) > 1 ? substr($wlParts[count($wlParts) - 1], 0, 1) : ''));
                @endphp
                <div class="pr2-wl-row">
                    <div class="pr2-wl-av" style="background:{{ $wlTints[$wlIdx % 5][0] }};color:{{ $wlTints[$wlIdx % 5][1] }}">{{ $wlInitials }}</div>
                    <div class="pr2-wl-nm">{{ $wlRow['teacher'] }}</div>
                    <div class="pr2-wl-bar"><div class="pr2-wl-fill" style="width:{{ $wlMax > 0 ? (int) round($wlRow['registers'] / $wlMax * 100) : 0 }}%;background:{{ $wlPalette[$wlIdx % 5] }}"></div></div>
                    <div class="pr2-wl-val"><b>{{ $wlRow['registers'] }}</b> registers</div>
                </div>
            @endforeach
        @else
            @include('partials.patterns.empty_state', ['icon' => 'icon-file-text2', 'message' => 'No registers submitted in the last 30 days.', 'compact' => true])
        @endif
    </section>
</div>
@endsection
