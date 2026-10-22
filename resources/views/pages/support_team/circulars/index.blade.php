@extends('layouts.master')
@section('page_title', 'Circulars')
@section('content')
    {{-- Loop 75: the management list renders through the shared circulars browser (summary chips, filter bar, cards with kebab actions). --}}
    @include('pages.support_team.circulars._browser')
@endsection
