{{--
    Student Attendance Report — official document (owner,
    2026-10-23). One student's own report: summary, per-subject
    breakdown and the register, from studentReportData() — the
    same assembly the screen and CSV use. Identity from Settings
    only; rendered by the dompdf wrapper (student_report_pdf).
--}}
@php
    $srPdf = $for_pdf ?? false;
    $srLogo = $srPdf ? ($school['logo_file'] ?? null) : ($school['logo'] ?? null);
    $studentUser = $student->user;
@endphp
<style>
    .srd-doc{position:relative;background:#fff;color:#172536;font-size:12.5px;padding:4px 2px;font-family:'Segoe UI',Helvetica,Arial,sans-serif;}
    .srd-doc table{border-collapse:collapse;}
    .srd-head{width:100%;border-bottom:2.5px solid #10263b;padding-bottom:8px;}
    .srd-school{color:#10263b;font-size:23px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;line-height:1.15;}
    .srd-addr{color:#44546a;font-size:12px;font-style:italic;margin-top:3px;}
    .srd-contact{color:#44546a;font-size:11.5px;margin-top:2px;}
    .srd-logo-tile{display:block;width:60px;height:60px;line-height:60px;text-align:center;background:#10263b;color:#fff;font-size:19px;font-weight:800;border-radius:8px;}
    .srd-logo-img{max-height:62px;max-width:100px;}
    .srd-title{text-align:center;margin:10px 0 2px;color:#10263b;font-size:19px;font-weight:800;}
    .srd-subtitle{text-align:center;color:#44546a;font-size:12.5px;margin-bottom:10px;}
    .srd-meta{width:100%;margin-bottom:10px;}
    .srd-meta td{background:#eef2f7;padding:7px 12px;border-radius:6px;}
    .srd-meta-label{color:#718096;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;}
    .srd-meta-value{color:#10263b;font-size:12.5px;font-weight:700;}
    .srd-table{width:100%;margin-bottom:12px;}
    .srd-table th{background:#10263b;color:#fff;font-size:11px;text-align:left;padding:6px 8px;}
    .srd-table td{border-bottom:1px solid #dbe3ec;padding:5px 8px;font-size:12px;}
    .srd-kpis{width:100%;margin-bottom:12px;}
    .srd-kpis td{width:20%;padding:0 4px;vertical-align:top;}
    .srd-kpis td:first-child{padding-left:0;}
    .srd-kpis td:last-child{padding-right:0;}
    .srd-kpi{background:#eef2f7;border-radius:8px;padding:8px 11px;}
    .srd-kpi-num{font-size:20px;font-weight:800;color:#10263b;line-height:1.1;}
    .srd-kpi-label{font-size:10.5px;font-weight:700;color:#172536;}
    .srd-sect{color:#10263b;font-size:13.5px;font-weight:800;margin:12px 0 6px;}
    .srd-foot{margin-top:14px;color:#718096;font-size:10.5px;text-align:center;}
    .srd-pill{display:inline-block;padding:1px 8px;border-radius:20px;font-size:10.5px;font-weight:700;}
    .srd-p-present{background:#e6f6ec;color:#1e7d43;}
    .srd-p-absent{background:#fdecec;color:#b3261e;}
    .srd-p-late{background:#fdf3e0;color:#9a6a00;}
</style>
<div class="srd-doc">
    <table class="srd-head">
        <tr>
            <td style="width:72px;vertical-align:middle">
                @if($srLogo)
                    <img class="srd-logo-img" src="{{ $srLogo }}" alt="">
                @else
                    <span class="srd-logo-tile">{{ $school['initials'] }}</span>
                @endif
            </td>
            <td style="vertical-align:middle">
                <div class="srd-school">{{ $school['name'] }}</div>
                @if($school['address'])<div class="srd-addr">{{ $school['address'] }}</div>@endif
                <div class="srd-contact">{{ $school['phone'] }}@if($school['email']) · {{ $school['email'] }}@endif</div>
            </td>
        </tr>
    </table>

    <div class="srd-title">Student Attendance Report</div>
    <div class="srd-subtitle">{{ $range_label }} · Session {{ $school['session'] }}</div>

    <table class="srd-meta">
        <tr>
            <td><div class="srd-meta-label">Student</div><div class="srd-meta-value">{{ $studentUser->name }}</div></td>
            <td><div class="srd-meta-label">Admission No</div><div class="srd-meta-value">{{ $student->adm_no }}</div></td>
            <td><div class="srd-meta-label">Class</div><div class="srd-meta-value">{{ optional($student->my_class)->name }}</div></td>
            <td><div class="srd-meta-label">Section</div><div class="srd-meta-value">{{ optional($student->section)->name }}</div></td>
        </tr>
    </table>

    <table class="srd-kpis">
        <tr>
            <td><div class="srd-kpi"><div class="srd-kpi-num">{{ $counts['present'] }}</div><div class="srd-kpi-label">Present</div></div></td>
            <td><div class="srd-kpi"><div class="srd-kpi-num">{{ $counts['absent'] }}</div><div class="srd-kpi-label">Absent</div></div></td>
            <td><div class="srd-kpi"><div class="srd-kpi-num">{{ $counts['late'] }}</div><div class="srd-kpi-label">Late</div></div></td>
            <td><div class="srd-kpi"><div class="srd-kpi-num">{{ $counts['marked'] }}</div><div class="srd-kpi-label">Periods Marked</div></div></td>
            <td><div class="srd-kpi"><div class="srd-kpi-num">{{ $percent === null ? '—' : $percent . '%' }}</div><div class="srd-kpi-label">Attendance · {{ $status_label }}</div></div></td>
        </tr>
    </table>

    <div class="srd-sect">Subject Breakdown</div>
    <table class="srd-table">
        <thead><tr><th>Subject</th><th>Present</th><th>Absent</th><th>Late</th><th>Periods</th><th>Attendance %</th></tr></thead>
        <tbody>
        @forelse($subjects as $row)
            <tr>
                <td>{{ optional($row['subject'])->name ?: '—' }}</td>
                <td>{{ $row['present'] }}</td><td>{{ $row['absent'] }}</td><td>{{ $row['late'] }}</td><td>{{ $row['marked'] }}</td>
                <td>{{ $row['percent'] === null ? '—' : $row['percent'] . '%' }}</td>
            </tr>
        @empty
            <tr><td colspan="6" style="text-align:center;color:#718096">No periods marked in this period.</td></tr>
        @endforelse
        </tbody>
    </table>

    <div class="srd-sect">Register — Latest Entries @if($entries_total > $entries->count())(showing {{ $entries->count() }} of {{ $entries_total }})@endif</div>
    <table class="srd-table">
        <thead><tr><th>Date</th><th>Subject</th><th>Status</th></tr></thead>
        <tbody>
        @forelse($entries as $entry)
            @php $session = $entry->session; @endphp
            <tr>
                <td>{{ $session && $session->date ? \Illuminate\Support\Carbon::parse($session->date)->format('d M Y') : '—' }}</td>
                <td>{{ optional(optional($session)->subject)->name ?: '—' }}</td>
                <td><span class="srd-pill srd-p-{{ $entry->status }}">{{ ucfirst($entry->status) }}</span></td>
            </tr>
        @empty
            <tr><td colspan="3" style="text-align:center;color:#718096">No register entries in this period.</td></tr>
        @endforelse
        </tbody>
    </table>

    <div class="srd-foot">Generated by Hackstra · {{ now()->format('d M Y, h:i A') }}</div>
</div>
