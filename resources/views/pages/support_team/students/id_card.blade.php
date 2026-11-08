@extends('layouts.master')
@section('page_title', 'ID Card - '.$sr->user->name)
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Students &nbsp;/&nbsp; <a href="{{ route('students.index') }}">Student Information</a> &nbsp;/&nbsp; {!! $__env->yieldContent('page_title') !!}
@endsection
@section('content')

    {{-- Item 14: the card document is the common front+back design
         (partials/id_card_design), built by IdCardFactory. Screen
         shows both faces enlarged; print outputs the true CR80
         size. --}}
    @include('pages.support_team.partials.id_card_styles')
    @include('pages.support_team.partials.id_card_screen_fx')
    <style>
        .idc2-stage{display:flex;flex-wrap:wrap;gap:26px;justify-content:center;background:#eef2f7;border:1px solid #e4eaf1;border-radius:12px;margin-top:22px;padding:30px 18px}
        @media screen{ .idc2-zoom{zoom:1.45;display:flex;gap:26px;flex-wrap:wrap;justify-content:center} }
        @media print{ .idc2-stage{background:#fff;border:none;padding:0} }
    </style>
    <div class="idc-page">
        <div class="card idc-screen-only">
            <div class="card-body">
                <p class="idc-lead">{{ $sr->user->name }} &middot; {{ $sr->my_class->name }}@if($sr->section) &middot; {{ $sr->section->name }}@endif &middot; Adm No {{ $sr->adm_no ?: '—' }}</p>
                <button type="button" class="btn btn-primary" onclick="window.print()"><i class="icon-printer mr-2"></i> Print ID Card</button>
            </div>
        </div>

        <div class="idc2-stage">
            <div class="idc2-zoom">
                @include('pages.support_team.partials.id_card_design', ['card' => $card, 'brand' => $brand])
            </div>
        </div>
    </div>

@endsection
