@extends('layouts.master')
@section('page_title', 'My ID Card')
@section('content')

    {{-- Loop 108: legacy card-header chrome dropped (panel options +
         repeated title — the common header carries the page title).
         The ID card document itself (Loop 27 .idc- design, print
         behaviour via .idc-screen-only) is untouched. --}}
    <div class="idc-page">
        <div class="card idc-screen-only">
            <div class="card-body">
                @if($mode === 'parent' && $children->count() > 1)
                    <div class="idc-switcher">
                        <span class="idc-switcher-label">Child:</span>
                        @foreach($children as $c)
                            <a href="{{ route('id_card.mine', ['child' => $c->id]) }}" class="btn btn-sm {{ $sr && $sr->id === $c->id ? 'btn-primary' : 'btn-light' }}">{{ $c->user->name }}</a>
                        @endforeach
                    </div>
                @endif

                @if($sr)
                    <p class="idc-lead">
                        @if($mode === 'parent')
                            {{ $sr->user->name }} &middot; {{ $sr->my_class->name }}@if($sr->section) &middot; {{ $sr->section->name }}@endif
                        @else
                            {{ $sr->my_class->name }}@if($sr->section) &middot; Section {{ $sr->section->name }}@endif &middot; Adm No {{ $sr->adm_no ?: '—' }}
                        @endif
                    </p>
                    <button type="button" class="btn btn-primary" onclick="window.print()"><i class="icon-printer mr-2"></i> Print ID Card</button>
                @else
                    <div class="alert alert-info text-center" style="margin:10px 0 4px">
                        @if($mode === 'parent')
                            No children are linked to your account yet.
                        @else
                            Your student record could not be found. Please contact the school office.
                        @endif
                    </div>
                @endif
            </div>
        </div>

        @if($sr)
            @include('pages.support_team.partials.id_card_styles')
    @include('pages.support_team.partials.id_card_screen_fx')
            <style>
                .idc2-stage{display:flex;flex-wrap:wrap;gap:26px;justify-content:center;background:#eef2f7;border:1px solid #e4eaf1;border-radius:12px;margin-top:22px;padding:30px 18px}
                @media screen{ .idc2-zoom{zoom:1.45;display:flex;gap:26px;flex-wrap:wrap;justify-content:center} }
                @media print{ .idc2-stage{background:#fff;border:none;padding:0} }
            </style>
            <div class="idc2-stage">
                <div class="idc2-zoom">
                    @include('pages.support_team.partials.id_card_design', ['card' => $card, 'brand' => $brand])
                </div>
            </div>
        @endif
    </div>

@endsection
