@extends('layouts.master')
@section('page_title', 'Bonafide Certificate - ' . $c->student_name)
@section('content')
    <style>
        .cert-card { background: transparent; box-shadow: none; border: none; }
        .cert-sheet { position: relative; max-width: 780px; margin: 0 auto; background: #fff; color: #1c2733;
            font-family: Georgia, 'Times New Roman', serif; padding: 52px 60px 44px;
            border: 2.5px solid #10263b; outline: 1px solid #b98a2f; outline-offset: -9px;
            print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .cert-wm { position: absolute; top: 0; left: 0; right: 0; bottom: 0; margin: auto;
            width: 320px; height: 320px; text-align: center; opacity: .06; pointer-events: none; }
        .cert-wm img { max-width: 320px; max-height: 320px; }
        .cert-wm .cert-mono { font-size: 150px; line-height: 320px; font-weight: bold; color: #10263b; }
        .cert-head { text-align: center; position: relative; }
        .cert-head img { max-height: 84px; }
        .cert-school { font-size: 27px; letter-spacing: 1.5px; margin: 6px 0 2px; color: #10263b; }
        .cert-meta-line { color: #5a6a7a; font-size: 13.5px; }
        .cert-rule { height: 3px; background: #10263b; margin: 16px 0 4px; }
        .cert-rule-thin { height: 1px; background: #b98a2f; margin-bottom: 22px; }
        .cert-title { text-align: center; font-size: 20px; letter-spacing: 4px; color: #10263b; margin: 4px 0 6px; }
        .cert-title-bar { width: 72px; height: 2px; background: #b98a2f; margin: 0 auto 20px; }
        .cert-serial { display: flex; justify-content: space-between; font-size: 13.5px; color: #5a6a7a; margin-bottom: 18px; }
        .cert-serial strong { color: #1c2733; }
        .cert-body { position: relative; font-size: 16.5px; line-height: 2.05; text-align: justify; }
        .cert-sign { display: flex; justify-content: space-between; align-items: flex-end; margin-top: 64px; position: relative; }
        .cert-seal { text-align: center; color: #5a6a7a; font-size: 12.5px; }
        .cert-seal img { display: block; margin: 0 auto 4px; }
        .cert-principal { text-align: center; min-width: 220px; }
        .cert-principal img { }
        .cert-sign-line { border-top: 1.5px solid #10263b; margin-top: 6px; padding-top: 6px; }
        .cert-foot { margin-top: 40px; text-align: center; font-size: 12px; color: #8494a7;
            border-top: 1px solid #d8dee6; padding-top: 10px; }
        @media print {
            .cert-sheet { max-width: none; }
            @page { margin: 11mm; }
        }
    </style>
    <div class="card cert-card">
        <div class="card-body">
            <div class="cert-sheet">
                <div class="cert-wm">
                    @if($s['logo'])<img src="{{ $s['logo'] }}" alt="">
                    @else<span class="cert-mono">{{ strtoupper(substr($s['school'] ?? 'S', 0, 1)) }}</span>@endif
                </div>
                <div class="cert-head">
                    @if($s['logo'])<img src="{{ $s['logo'] }}" alt="School logo">@endif
                    <div class="cert-school">{{ $s['school'] }}</div>
                    @if($s['address'])<div class="cert-meta-line">{{ $s['address'] }}</div>@endif
                    @if($s['phone'])<div class="cert-meta-line">Phone: {{ $s['phone'] }}</div>@endif
                </div>
                <div class="cert-rule"></div>
                <div class="cert-rule-thin"></div>
                <div class="cert-title">BONAFIDE CERTIFICATE</div>
                <div class="cert-title-bar"></div>
                <div class="cert-serial">
                    <span>Serial No: <strong>{{ $c->serial }}</strong></span>
                    <span>Date: <strong>{{ $c->issued_at ? $c->issued_at->format('d F Y') : '—' }}</strong></span>
                </div>
                <div class="cert-body">
                    <p>This is to certify that <strong>{{ $c->student_name }}</strong>
                        @if($c->adm_no)(Admission No: {{ $c->adm_no }})@endif
                        is a bonafide student of this school, studying in
                        <strong>{{ $c->class_name ?? '—' }} {{ $c->section_name ?? '' }}</strong>
                        during the academic session <strong>{{ $c->session ?? '—' }}</strong>.</p>
                    <p>This certificate is issued on request for official purposes.</p>
                </div>
                <div class="cert-sign">
                    <div class="cert-seal">
                        @if($s['stamp'])<img src="{{ $s['stamp'] }}" alt="School stamp" style="max-height: 90px;">@endif
                        <span>(School Seal)</span>
                    </div>
                    <div class="cert-principal">
                        @if($s['signature'])<img src="{{ $s['signature'] }}" alt="Principal signature" style="max-height: 60px; max-width: 320px;"><br>@endif
                        <div class="cert-sign-line">
                            <strong>{{ $s['principal'] ?? 'Principal' }}</strong><br>
                            <span style="color:#5a6a7a;">Principal</span>
                        </div>
                    </div>
                </div>
                <div class="cert-foot">Issued by {{ $s['school'] }} · Serial No. {{ $c->serial }} · Verify at the school office</div>
            </div>
            <div class="text-center mt-4 d-print-none">
                <button class="btn btn-primary" onclick="window.print()">Print</button>
                <a href="{{ route('certificates.index') }}" class="btn btn-light">Back to Register</a>
            </div>
        </div>
    </div>
@endsection
