@extends('layouts.master')
@section('page_title', 'Ask Hackstra')
@section('content')

    {{-- Ask Hackstra (Workstream DH) — RAG chat over the school's own
         documents. The service retrieves only what this user's role is
         allowed to see and cites its sources; when nothing permitted
         covers the question it says so instead of improvising. --}}
    <div class="card">
        <div class="card-header">
            <h5 class="card-title"><i class="icon-bubbles4 mr-2"></i> Ask Hackstra</h5>
        </div>
        <div class="card-body">
            @if(session('flash_danger'))
                <div class="alert alert-danger border-0">{{ session('flash_danger') }}</div>
            @endif

            @if(!$enabled)
                <div class="alert alert-info border-0 mb-0">
                    Ask Hackstra is switched off for this school right now.
                    @if(Qs::userIsTeamSA())
                        You can switch it on in <a href="{{ route('settings') }}">Settings → AI Assistant</a>.
                    @else
                        A school admin can switch it on in Settings.
                    @endif
                </div>
            @else
                <p class="text-muted">
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

                <form method="POST" action="{{ route('ask.ask') }}">
                    @csrf
                    <div class="form-group">
                        <label for="question">Your question</label>
                        <textarea name="question" id="question" rows="3" maxlength="500" required class="form-control @error('question') is-invalid @enderror" placeholder="e.g. When is the Annual Sports Day?">{{ old('question', $question) }}</textarea>
                        @include('partials.errors.field', ['field' => 'question'])
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="icon-paperplane mr-1"></i> Ask</button>
                </form>

                @if($answer)
                    <hr>
                    @if($question)
                        <p class="mb-1"><strong>You asked:</strong> {{ $question }}</p>
                    @endif
                    <div class="alert {{ $refused ? 'alert-warning' : 'alert-success' }} border-0">
                        {!! nl2br(e($answer)) !!}
                    </div>
                    @if(!empty($sources))
                        <h6 class="font-weight-semibold">Sources</h6>
                        <ul class="list-unstyled mb-0">
                            @foreach($sources as $source)
                                <li><i class="icon-file-text2 mr-1"></i> [{{ $source['label'] }}: {{ $source['title'] }}]@if($source['date']) <span class="text-muted">· {{ $source['date'] }}</span>@endif</li>
                            @endforeach
                        </ul>
                    @endif
                @endif
            @endif
        </div>
    </div>

@endsection
