@extends('layouts.master')
@section('page_title', 'Annual / Cumulative Report')
@section('page_breadcrumb')
    <a href="{{ route('marks.show', [Qs::hash($student_id), $year]) }}">&larr; Back to Student</a> <span class="crumb-sep">&rsaquo;</span> Examinations <span class="crumb-sep">&rsaquo;</span> Results <span class="crumb-sep">&rsaquo;</span> Annual/Cumulative Report
@endsection
@section('page_actions')
    {{-- Print + PDF — same visibility rule the page has always used:
         only users the print gate admits (teamSA, the student, or
         their parent) see the document actions. --}}
    @if(Qs::userIsTeamSA() || Auth::id() == $student_id || Qs::userIsMyChild($student_id, Auth::id()))
        <a target="_blank" href="{{ route('marks.print_annual', [Qs::hash($student_id), $year]) }}" class="btn btn-light btn-sm"><i class="icon-printer mr-1"></i> Print Report</a>
        <a href="{{ route('marks.pdf_annual', [Qs::hash($student_id), $year]) }}" class="btn btn-primary btn-sm ml-2"><i class="icon-file-pdf mr-1"></i> Download PDF</a>
    @endif
@endsection
@section('content')

    <p class="ar85-subtitle">Academic performance summary for the selected academic session.</p>

    {{-- Student identity card (Loop 85). Photo rule (Workstream BR):
         the real uploaded photo when one exists, else an initials
         tile — the stock silhouette is never rendered. Metadata
         shows only fields the record actually carries. --}}
    @php
        $ar85Initials = '';
        foreach (preg_split('/\s+/', trim((string) $sr->user->name)) as $ar85Word) {
            if ($ar85Word !== '') {
                $ar85Initials .= strtoupper(substr($ar85Word, 0, 1));
                if (strlen($ar85Initials) >= 2) { break; }
            }
        }
        $ar85SectionName = $sr->section ? $sr->section->name : ($my_class->section->first() ? $my_class->section->first()->name : null);
    @endphp
    <div class="card ar85-id-card">
        <div class="card-body">
            <div class="ar85-id-head">
                @if(Qs::hasRealPhoto($sr->user->photo))
                    <img class="ar85-avatar" src="{{ $sr->user->photo }}" alt="">
                @else
                    <span class="ar85-avatar ar85-avatar-tile" aria-hidden="true">{{ $ar85Initials ?: 'S' }}</span>
                @endif
                <div class="ar85-id-name">
                    <h5>{{ $sr->user->name }}</h5>
                    <div class="ar85-id-class">
                        {{ $my_class->name }}@if($ar85SectionName) &mdash; {{ $ar85SectionName }}@endif
                        <span class="ar85-status {{ $sr->grad ? 'ar85-status-grad' : 'ar85-status-active' }}">{{ $sr->grad ? 'Graduated' : 'Active' }}</span>
                    </div>
                </div>
            </div>
            <div class="ar85-meta">
                <div class="ar85-meta-item">
                    <i class="icon-vcard"></i>
                    <div><small>Admission No.</small><strong>{{ $sr->adm_no ?: '–' }}</strong></div>
                </div>
                @if($sr->user->dob)
                    <div class="ar85-meta-item">
                        <i class="icon-calendar3"></i>
                        <div><small>Date of Birth</small><strong>{{ date('d M Y', strtotime($sr->user->dob)) }}</strong></div>
                    </div>
                @endif
                @if($sr->user->gender)
                    <div class="ar85-meta-item">
                        <i class="icon-user"></i>
                        <div><small>Gender</small><strong>{{ ucfirst($sr->user->gender) }}</strong></div>
                    </div>
                @endif
                <div class="ar85-meta-item">
                    <i class="icon-book"></i>
                    <div><small>Class</small><strong>{{ $my_class->name }}</strong></div>
                </div>
                <div class="ar85-meta-item">
                    <i class="icon-users4"></i>
                    <div><small>Section</small><strong>{{ $ar85SectionName ?: '–' }}</strong></div>
                </div>
                <div class="ar85-meta-item">
                    <i class="icon-calendar3"></i>
                    <div><small>Academic Session</small><strong>{{ $year }}</strong></div>
                </div>
            </div>
        </div>
    </div>

    @if(($card['footer']['total'] ?? null) === null)
        <div class="alert alert-info border-0 ar85-nomarks">No marks have been recorded for this student in {{ $year }} yet — the report sheet below fills in as term results are entered.</div>
    @endif

    {{-- The official document, on screen: the SAME partial the
         printed page and the PDF render
         (marks/partials/annual_report_card), fed by the same
         App\Support\ReportCard::buildAnnual assembly — the figures
         on this screen are the figures on paper, by construction. --}}
    <div class="card ar85-paper">
        <div class="card-body ar85-screen">
            @include('pages.support_team.marks.partials.annual_report_card')
        </div>
    </div>

@endsection
