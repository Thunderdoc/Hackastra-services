@extends('layouts.master')
@section('page_title', 'Marking Scheme — ' . $ex->name)
@section('content')
    {{-- Loop 108: rebuilt on the shared list chrome (Loop 28). Was a
         legacy card with panel-options chrome and a plain table.
         Content is unchanged: same explainer, same per-class rows,
         same Configure links and Back action. The table carries
         l108-stack so every column stays visible on phone widths. --}}
    <div class="pat-list">
        <div class="pat-toolbar">
            <div class="pat-toolbar-text">
                <div class="pat-toolbar-sub">Term {{ $ex->term }} &middot; {{ $ex->year }} — one scheme per class, defined per subject.</div>
            </div>
            <div class="pat-toolbar-actions">
                <a href="{{ route('exams.index') }}" class="btn btn-light"><i class="icon-arrow-left5 mr-1"></i> Back to Exams</a>
            </div>
        </div>

        <div class="card pat-card">
            <div class="card-body">
                <div class="alert alert-info border-0">
                    <span>Every school marks differently. Here you define, <strong>per subject</strong>, what a subject's
                    100 marks are made of — e.g. Theory 60 + Internal 30 + Viva 10, or Theory 70 + Practical 30.
                    Subjects you leave alone keep the legacy scheme (CA 20 + CA 20 + Exam 60).
                    Set a scheme <strong>before</strong> marks are entered for that subject — a scheme cannot be
                    swapped underneath marks that already exist.</span>
                </div>

                <div class="table-responsive">
                    <table class="table l108-stack">
                        <thead>
                        <tr>
                            <th>S/N</th>
                            <th>Class</th>
                            <th>Subjects</th>
                            <th>Custom Schemes Set</th>
                            <th>Action</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($rows as $r)
                            <tr>
                                <td data-label="S/N">{{ $loop->iteration }}</td>
                                <td data-label="Class">{{ $r['my_class']->name }}</td>
                                <td data-label="Subjects">{{ $r['subjects'] }}</td>
                                <td data-label="Custom Schemes Set">
                                    @if($r['configured'] > 0)
                                        <span class="badge badge-primary">{{ $r['configured'] }} of {{ $r['subjects'] }}</span>
                                    @else
                                        <span class="badge badge-secondary">All legacy</span>
                                    @endif
                                </td>
                                <td data-label="Action">
                                    <a href="{{ route('exams.scheme.edit', [$ex->id, $r['my_class']->id]) }}" class="btn btn-primary btn-sm">
                                        Configure <i class="icon-arrow-right5 ml-1"></i>
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

@endsection
