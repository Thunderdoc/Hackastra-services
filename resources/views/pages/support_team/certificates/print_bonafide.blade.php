@extends('layouts.master')
@section('page_title', 'Bonafide Certificate - ' . $c->student_name)
@section('content')
    <div class="card">
        <div class="card-body" style="max-width: 760px; margin: 0 auto; font-family: Georgia, serif;">
            <div class="text-center mb-4">
                @if($s['logo'])<img src="{{ $s['logo'] }}" alt="School logo" style="max-height: 80px;" class="mb-2">@endif
                <h3 class="mb-0">{{ $s['school'] }}</h3>
                @if($s['address'])<div class="text-muted">{{ $s['address'] }}</div>@endif
                @if($s['phone'])<div class="text-muted">Phone: {{ $s['phone'] }}</div>@endif
            </div>
            <h4 class="text-center mb-4"><u>BONAFIDE CERTIFICATE</u></h4>
            <p class="text-muted">Serial No: <strong>{{ $c->serial }}</strong> &nbsp;|&nbsp; Date: {{ $c->issued_at ? $c->issued_at->format('d F Y') : '—' }}</p>
            <p style="line-height: 1.9;">This is to certify that <strong>{{ $c->student_name }}</strong>
                @if($c->adm_no)(Admission No: {{ $c->adm_no }})@endif
                is a bonafide student of this school, studying in
                <strong>{{ $c->class_name ?? '—' }} {{ $c->section_name ?? '' }}</strong>
                during the academic session <strong>{{ $c->session ?? '—' }}</strong>.</p>
            <p style="line-height: 1.9;">This certificate is issued on request for official purposes.</p>
            <div class="d-flex justify-content-between align-items-end mt-5">
                <div>
                    @if($s['stamp'])<img src="{{ $s['stamp'] }}" alt="School stamp" style="max-height: 90px;">@endif
                </div>
                <div class="text-center">
                    @if($s['signature'])<img src="{{ $s['signature'] }}" alt="Principal signature" style="max-height: 60px; max-width: 320px;"><br>@endif
                    <strong>{{ $s['principal'] ?? 'Principal' }}</strong><br>
                    <span class="text-muted">Principal</span>
                </div>
            </div>
            <div class="text-center mt-4 d-print-none">
                <button class="btn btn-primary" onclick="window.print()">Print</button>
                <a href="{{ route('certificates.index') }}" class="btn btn-light">Back to Register</a>
            </div>
        </div>
    </div>
@endsection
