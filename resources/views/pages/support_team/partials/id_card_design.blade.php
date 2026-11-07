{{--
    ID card (front + back) — item 14 design (frozen reference
    idcard-final9). Expects:
      $card  — an IdCardFactory card array
      $brand — an IdCardFactory::brand() array
    Both faces render at CR80-vertical point geometry (see
    id_card_styles). Real Code39 barcode of $card['code'].
    Layout uses tables for the tile grid, identity strip and
    signature row — dompdf-safe by construction.
--}}
<div class="idc2 idc2-{{ $card['role'] }}{{ !$card['is_student'] ? ' idc2-staff' : '' }}">
    <div class="idc2-band">
        @if(!empty($card['band_pattern']))<img class="idc2-bandpat" src="{{ $card['band_pattern'] }}" alt="">@endif
        <span class="idc2-logo">@if($brand['logo_img'])<img src="{{ $brand['logo_img'] }}" alt="">@else<span class="idc2-mono">{{ $brand['monogram'] }}</span>@endif</span>
        <div class="idc2-sch">{{ $brand['name'] }}</div>
        <div class="idc2-schid">IDENTITY CARD</div>
    </div>
    <div class="idc2-photospacer"></div>
    <div class="idc2-photowrap">
        <div class="idc2-photobox">
            <span class="idc2-photo">@if($card['photo_img'])<img src="{{ $card['photo_img'] }}" alt="">@else<span class="idc2-ini">{{ $card['initials'] }}</span>@endif
                @if($brand['stamp_img'])<img class="idc2-stampf" src="{{ $brand['stamp_img'] }}" alt="">@endif
                <span class="idc2-seal"><span class="idc2-seal-a">{{ $brand['monogram'] }}</span><span class="idc2-seal-b">GENUINE</span></span>
            </span>
        </div>
    </div>
    <div class="idc2-name">{{ $card['name'] }}</div>
    <div class="idc2-micro">{{ strtoupper($brand['name']) }} &bull; {{ strtoupper($brand['name']) }} &bull; {{ strtoupper($brand['name']) }} &bull;</div>
    <div class="idc2-pillrow"><span class="idc2-pill">{{ $card['role_label'] }}</span></div>
    @php
        $idcRows = [];
        $idcPair = [];
        foreach ($card['front_tiles'] as $idcTile) {
            if ($idcTile['wide']) {
                if ($idcPair) { $idcRows[] = $idcPair; $idcPair = []; }
                $idcRows[] = [$idcTile];
            } else {
                $idcPair[] = $idcTile;
                if (count($idcPair) === 2) { $idcRows[] = $idcPair; $idcPair = []; }
            }
        }
        if ($idcPair) { $idcRows[] = $idcPair; }
    @endphp
    <div class="idc2-tiles">
        <table class="idc2-tgrid">
            @foreach($idcRows as $idcRow)
                <tr>
                    @if(count($idcRow) === 1 && $idcRow[0]['wide'])
                        <td class="idc2-tdw" colspan="2"><div class="idc2-tilebox idc2-widebox"><span class="idc2-fl">{{ $idcRow[0]['label'] }}</span><span class="idc2-fv{{ $idcRow[0]['mono'] ? ' idc2-mono' : '' }}">{{ $idcRow[0]['value'] }}</span></div></td>
                    @elseif(count($idcRow) === 1)
                        <td class="idc2-tdl"><div class="idc2-tilebox"><span class="idc2-fl">{{ $idcRow[0]['label'] }}</span><span class="idc2-fv{{ $idcRow[0]['mono'] ? ' idc2-mono' : '' }}">{{ $idcRow[0]['value'] }}</span></div></td>
                        <td class="idc2-tdr"></td>
                    @else
                        <td class="idc2-tdl"><div class="idc2-tilebox"><span class="idc2-fl">{{ $idcRow[0]['label'] }}</span><span class="idc2-fv{{ $idcRow[0]['mono'] ? ' idc2-mono' : '' }}">{{ $idcRow[0]['value'] }}</span></div></td>
                        <td class="idc2-tdr"><div class="idc2-tilebox"><span class="idc2-fl">{{ $idcRow[1]['label'] }}</span><span class="idc2-fv{{ $idcRow[1]['mono'] ? ' idc2-mono' : '' }}">{{ $idcRow[1]['value'] }}</span></div></td>
                    @endif
                </tr>
            @endforeach
        </table>
    </div>
    @if($card['is_student'])
        <div class="idc2-valid">Valid for session <b>{{ $brand['session'] }}</b></div>
    @else
        <div class="idc2-schoolpanel">
            <span class="idc2-sp-lab">SCHOOL</span>
            <div class="idc2-sp-addr">@if($brand['address']){{ $brand['address'] }}<br>@endif<b>{{ $brand['phone'] }}</b></div>
        </div>
        <div class="idc2-prop">THIS CARD IS THE PROPERTY OF {{ strtoupper($brand['name']) }}</div>
    @endif
    <div class="idc2-accent"></div>
</div>

<div class="idc2 idc2-{{ $card['role'] }}{{ !$card['is_student'] ? ' idc2-staff' : '' }}{{ !empty($idcLast) ? ' idc2-last' : '' }}">
    <div class="idc2-bhead">
        @if(!empty($card['band_pattern_head']))<img class="idc2-bandpat idc2-bandpat-head" src="{{ $card['band_pattern_head'] }}" alt="">@endif
        <span class="idc2-logo">@if($brand['logo_img'])<img src="{{ $brand['logo_img'] }}" alt="">@else<span class="idc2-mono">{{ $brand['monogram'] }}</span>@endif</span>
        <div class="idc2-sch">{{ $brand['name'] }}</div>
        <div class="idc2-schid">{{ $card['is_student'] ? 'STUDENT DETAILS' : 'STAFF DETAILS' }}</div>
    </div>
    <div class="idc2-istrip">
        <span class="idc2-mini"><span class="idc2-minii">{{ $card['initials'] }}</span></span>
        <span class="idc2-pill2">{{ $card['role_label'] }}</span>
        <span class="idc2-in{{ mb_strlen($card['name']) > 16 ? ' idc2-in-long' : '' }}"><b>{{ $card['name'] }}</b><small>{{ $card['sub'] }}</small></span>
        <div class="idc2-clear"></div>
    </div>
    @foreach($card['back_sections'] as $sec)
        <div class="idc2-bsec">
            <div class="idc2-bt">{{ $sec[0] }}</div>
            @if(is_array($sec[1]))
                @foreach($sec[1] as $kv)
                    <div class="idc2-bl"><span class="idc2-k">{{ $kv[0] }}</span><b>{{ $kv[1] }}</b></div>
                @endforeach
            @else
                <div class="idc2-addr">{{ $sec[1] }}</div>
            @endif
        </div>
    @endforeach
    <div class="idc2-barcode">
        <img src="{{ \App\Support\Code39::pngDataUri($card['code']) }}" alt="">
        <div class="idc2-bcode">{{ $card['code'] }}</div>
    </div>
    <div class="idc2-office">
        <div class="idc2-ot">SCHOOL OFFICE</div>
        <div class="idc2-ob">
            <b>{{ $brand['name'] }}</b>@if($brand['address'])<br>{{ $brand['address'] }}@endif<br>
            <b>{{ $brand['phone'] }}</b>@if($brand['email']) &middot; {{ $brand['email'] }}@endif<br>
            <span class="idc2-found">If found, please return to the school office. This card is non-transferable.</span>
        </div>
    </div>
    <div class="idc2-sigrow">
        <div class="idc2-sig">
                        @if($card['is_principal'])
                            <div class="idc2-blank"></div>
                            <div class="idc2-sl">Authorised Signatory</div>
                        @elseif($brand['sign_img'])
                            <img class="idc2-signimg" src="{{ $brand['sign_img'] }}" alt="">
                            <div class="idc2-sl">Principal</div>
                        @else
                            <div class="idc2-blank"></div>
                            <div class="idc2-sl">Principal</div>
                        @endif
        </div>
        @if($brand['stamp_img'])
            <img class="idc2-stamp" src="{{ $brand['stamp_img'] }}" alt="">
        @endif
        <div class="idc2-clear"></div>
    </div>
    <div class="idc2-accent"></div>
</div>
