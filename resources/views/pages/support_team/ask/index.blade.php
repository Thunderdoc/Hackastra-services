@extends('layouts.master')
@section('page_title', 'Ask Hackstra')
{{-- Loop 88: this page renders its own designed header (breadcrumb +
     icon tile + title + subtitle + live status pill + role action),
     so the layout's generic title bar is suppressed — the title
     would otherwise print twice. --}}
@section('hide_page_header', true)
@section('content')

    {{-- Ask Hackstra (Workstream DH) — RAG chat over the school's own
         documents. The service retrieves only what this user's role is
         allowed to see and cites its sources; when nothing permitted
         covers the question it says so instead of improvising.
         Loop 88 (design completion): the disabled state is a full,
         role-correct workspace — not a lone alert. The status pill and
         the Settings action reflect the REAL ai_enabled setting and
         the REAL settings gate: only the school Admin can open the
         school Settings page (the Super Admin is platform-only and
         never reaches this page; teachers/students/parents get a
         Back to Dashboard action instead of a settings control). --}}
    @php
        $isAdmin = Qs::userIsAdmin();
        $schoolEmail = trim((string) Qs::getSetting('system_email'));
        $suggestions = [
            'When is the Annual Sports Day?',
            'What does the uniform policy say?',
            'What are the school office hours?',
            'How do I apply for leave?',
        ];
    @endphp

    <div class="l88-ask">
        {{-- Designed page header --}}
        <div class="l88-head">
            <div class="l88-head-main">
                <div class="l88-crumb">
                    <a href="{{ route('dashboard') }}">Dashboard</a>
                    <span class="l88-crumb-sep">/</span> Communication
                    <span class="l88-crumb-sep">/</span> <span class="l88-crumb-here">Ask Hackstra</span>
                </div>
                <div class="l88-title-row">
                    <span class="l88-title-tile"><i class="icon-bubbles4"></i></span>
                    <div>
                        <h1 class="l88-title">Ask Hackstra</h1>
                        <p class="l88-subtitle">Your AI assistant for quick answers from your school's own circulars and policies.</p>
                    </div>
                </div>
            </div>
            <div class="l88-head-actions">
                @if($enabled)
                    <span class="l88-status l88-status-on"><span class="l88-dot"></span> Switched on</span>
                @else
                    <span class="l88-status l88-status-off"><span class="l88-dot"></span> Currently unavailable</span>
                @endif
                @if($isAdmin)
                    <a href="{{ route('settings') }}" class="btn btn-light l88-head-btn"><i class="icon-cog mr-1"></i> Open AI Settings</a>
                @else
                    <a href="{{ route('dashboard') }}" class="btn btn-light l88-head-btn"><i class="icon-arrow-left13 mr-1"></i> Back to Dashboard</a>
                @endif
            </div>
        </div>

        @if(!$enabled)
            {{-- ============ DISABLED STATE ============ --}}
            <div class="card l88-hero">
                <div class="card-body">
                    <div class="l88-hero-inner">
                        <div class="l88-hero-visual" aria-hidden="true">
                            <span class="l88-hero-tile"><i class="icon-bubbles4"></i></span>
                            <span class="l88-hero-badge"><i class="icon-power2"></i></span>
                            <span class="l88-hero-chip l88-chip-a"><i class="icon-megaphone"></i> Circulars</span>
                            <span class="l88-hero-chip l88-chip-b"><i class="icon-book"></i> Policies</span>
                        </div>
                        <div class="l88-hero-main">
                            <h3 class="l88-hero-title">Ask Hackstra is currently switched off</h3>
                            <p class="l88-hero-text">
                                When it is on, Ask Hackstra answers questions from your school's own circulars and
                                policies — sports day dates, uniform rules, office procedures — and names the document
                                every answer came from. It has not been switched on for this school yet, so there is
                                nothing to ask right now.
                            </p>
                            @if($isAdmin)
                                <p class="l88-hero-text">You can switch it on, connect the AI provider and manage the knowledge base in <strong>Settings &rarr; AI Assistant</strong>.</p>
                                <div class="l88-hero-actions">
                                    <a href="{{ route('settings') }}" class="btn btn-primary"><i class="icon-cog mr-1"></i> Open AI Settings</a>
                                    <a href="{{ route('dashboard') }}" class="btn btn-light">Back to Dashboard</a>
                                </div>
                            @else
                                <p class="l88-hero-text">A school administrator can switch it on in Settings. If you need help meanwhile, the options below will get you to a person.</p>
                                <div class="l88-hero-actions">
                                    <a href="{{ route('dashboard') }}" class="btn btn-primary"><i class="icon-arrow-left13 mr-1"></i> Back to Dashboard</a>
                                    <a href="{{ route('feedback.create') }}" class="btn btn-light">Give Feedback</a>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <div class="row l88-cards">
                <div class="col-lg-4 col-md-6">
                    <div class="card l88-info-card">
                        <div class="card-body">
                            <span class="l88-info-icon l88-tone-blue"><i class="icon-info3"></i></span>
                            <h5 class="l88-info-title">What is Ask Hackstra?</h5>
                            <p class="l88-info-text">The school's AI assistant. It answers questions using only the school's own published circulars and knowledge-base policies, and it only ever uses documents your role is allowed to see. It cannot see marks, fees or personal records.</p>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4 col-md-6">
                    <div class="card l88-info-card">
                        <div class="card-body">
                            <span class="l88-info-icon l88-tone-green"><i class="icon-power2"></i></span>
                            <h5 class="l88-info-title">Why is it switched off?</h5>
                            <p class="l88-info-text">Ask Hackstra is optional, and it has not been enabled for this school yet. Until it is switched on — with an AI provider connected — the assistant stays off for everyone.</p>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4 col-md-6">
                    <div class="card l88-info-card">
                        <div class="card-body">
                            <span class="l88-info-icon l88-tone-violet"><i class="icon-shield-check"></i></span>
                            <h5 class="l88-info-title">Who can enable it?</h5>
                            <p class="l88-info-text">A school administrator, in Settings &rarr; AI Assistant. They switch it on, connect the AI provider and choose which policies go into the knowledge base.</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card l88-help">
                <div class="card-body">
                    <div class="l88-help-head">
                        <span class="l88-info-icon l88-tone-amber"><i class="icon-lifebuoy"></i></span>
                        <div>
                            <h5 class="l88-help-title">Need help in the meantime?</h5>
                            <p class="l88-help-sub">You can still get support through the following options:</p>
                        </div>
                    </div>
                    <div class="l88-help-grid">
                        <a class="l88-help-tile" href="{{ route('feedback.create') }}">
                            <span class="l88-help-tile-icon l88-tone-blue"><i class="icon-comment-discussion"></i></span>
                            <span class="l88-help-tile-main">
                                <span class="l88-help-tile-title">Give Feedback</span>
                                <span class="l88-help-tile-sub">Report issues or suggestions to the school admin</span>
                            </span>
                            <i class="icon-arrow-right13 l88-help-tile-arrow"></i>
                        </a>
                        @if($schoolEmail !== '')
                            <a class="l88-help-tile" href="mailto:{{ $schoolEmail }}">
                                <span class="l88-help-tile-icon l88-tone-violet"><i class="icon-mail5"></i></span>
                                <span class="l88-help-tile-main">
                                    <span class="l88-help-tile-title">Contact Admin</span>
                                    <span class="l88-help-tile-sub">Email the school office at {{ $schoolEmail }}</span>
                                </span>
                                <i class="icon-arrow-right13 l88-help-tile-arrow"></i>
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        @else
            {{-- ============ ENABLED STATE (Loop 75 chat, completed in Loop 88) ============ --}}
            <div class="card l75-ask">
                <div class="card-body">
                    <p class="ask-lead">
                        Ask about school circulars and policies — sports day dates, uniform rules, office procedures.
                        Answers come only from the school's own documents, and the sources are always named.
                        Ask Hackstra cannot see marks, fees or personal records.
                    </p>

                    @if($visible_chunks === 0)
                        <div class="alert alert-warning border-0">
                            Nothing has been indexed for your role yet, so there is nothing to answer from.
                            @if(Qs::userIsTeamSA())
                                Publish a circular, or add a policy in <a href="{{ route('settings') }}">Settings → Knowledge Base</a>, then rebuild the knowledge index.
                            @else
                                Once the school office publishes circulars or policies for your role, answers will appear here.
                            @endif
                        </div>
                    @endif

                    {{-- Loop 75: chat treatment — composer bar below, and
                         the exchange rendered as message bubbles with
                         citation chips (same fields, same POST).
                         Loop 88: suggested-prompt chips under the
                         composer — they fill the question box (the
                         teacher can edit before sending); every chip is
                         a question the document corpus can plausibly
                         cover, and the service still refuses honestly
                         when nothing permitted covers it. --}}
                    <form method="POST" action="{{ route('ask.ask') }}" id="ask-form">
                        @csrf
                        <div class="ask-composer">
                            <label for="question">Your question</label>
                            <textarea name="question" id="question" rows="3" maxlength="500" required class="form-control @error('question') is-invalid @enderror" placeholder="e.g. When is the Annual Sports Day?">{{ old('question', $question) }}</textarea>
                            @include('partials.errors.field', ['field' => 'question'])
                            <div class="ask-composer-bar">
                                <span class="ask-hint">Answers cite the school's own circulars and policies only.</span>
                                <button type="submit" class="btn btn-primary" id="ask-submit"><i class="icon-paperplane mr-1"></i> Ask</button>
                            </div>
                        </div>
                        @if($visible_chunks > 0)
                            <div class="l88-suggest">
                                <span class="l88-suggest-label">Try asking</span>
                                @foreach($suggestions as $suggestion)
                                    <button type="button" class="l88-chip" data-question="{{ $suggestion }}">{{ $suggestion }}</button>
                                @endforeach
                            </div>
                        @endif
                    </form>

                    @if($answer)
                        <div class="ask-thread">
                            @if($question)
                                <div class="ask-row ask-row-user">
                                    <div class="ask-bubble ask-bubble-user">{{ $question }}</div>
                                </div>
                            @endif
                            <div class="ask-row">
                                <div class="ask-bubble ask-bubble-bot{{ $refused ? ' ask-bubble-refused' : '' }}">
                                    {!! nl2br(e($answer)) !!}
                                    @if(!empty($sources))
                                        <div class="ask-sources">
                                            <span class="ask-sources-label">Sources</span>
                                            @foreach($sources as $source)
                                                <span class="ask-cite"><i class="icon-file-text2"></i>[{{ $source['label'] }}: {{ $source['title'] }}]@if($source['date']) <span class="ask-cite-date">· {{ $source['date'] }}</span>@endif</span>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>

@endsection

@section('scripts')
    <script>
        (function () {
            // Loop 88: suggestion chips fill the composer (never
            // auto-send — the user can edit first), and the submit
            // button shows a sending state so a slow answer never
            // reads as a dead page. Purely progressive enhancement:
            // the form works unchanged without JS.
            var textarea = document.getElementById('question');
            var chips = document.querySelectorAll('.l88-chip[data-question]');
            chips.forEach(function (chip) {
                chip.addEventListener('click', function () {
                    if (!textarea) return;
                    textarea.value = chip.getAttribute('data-question');
                    textarea.focus();
                    textarea.setSelectionRange(textarea.value.length, textarea.value.length);
                });
            });
            var form = document.getElementById('ask-form');
            var submit = document.getElementById('ask-submit');
            if (form && submit) {
                form.addEventListener('submit', function () {
                    submit.disabled = true;
                    submit.innerHTML = '<i class="icon-spinner2 spinner mr-1"></i> Asking&hellip;';
                });
            }
        })();
    </script>
@endsection
