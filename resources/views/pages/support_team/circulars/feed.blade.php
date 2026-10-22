@extends('layouts.master')
@section('page_title', 'Circulars')
@section('content')
    {{-- Loop 75: the audience feed renders through the same shared circulars browser as the management list, scoped by the controller to this reader's audiences. --}}
    @include('pages.support_team.circulars._browser')
@endsection
