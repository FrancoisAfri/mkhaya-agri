@php($accent = $company['colour'] ?? '#4b3fd4')
{{-- Logo placement comes from the document template; callers that pass no $style keep the
     historical top-left logo, so untemplated documents render exactly as before. --}}
{{-- Right by default: it is where every invoice this was asked to resemble puts it. --}}
@php($logoPos = $style['logo_position'] ?? 'right')
{{-- Bigger than it was. At 60px the mark sat in a corner of its own whitespace,
     which reads as an oversight rather than as restraint. --}}
@php($logoHeight = (int) ($style['logo_height'] ?? 92))
@php($logo = ($logoPos !== 'none' && ! empty($company['logo'])) ? $company['logo'] : null)

@if($logoPos === 'center' && $logo)
    {{-- Centred: the logo sits above the address block rather than beside it, so it can breathe. --}}
    <div style="text-align:center; margin-bottom:10px;">
        <img src="{{ $logo }}" style="max-height:{{ $logoHeight }}px; max-width:340px;" />
    </div>
    <table style="width:100%; border:0; margin-bottom:18px;">
        <tr>
            <td style="vertical-align:top; border:0; text-align:center; font-size:10px; color:#555;">
                <div style="font-weight:bold; color:#222; font-size:12px;">{{ $company['name'] }}</div>
                @if(!empty($company['address']))<div>{!! nl2br(e($company['address'])) !!}</div>@endif
                @if(!empty($company['phone']))<span>{{ $company['phone'] }}</span>@endif
                @if(!empty($company['email']))<span> · {{ $company['email'] }}</span>@endif
                @if(!empty($company['website']))<span> · {{ $company['website'] }}</span>@endif
                @if(!empty($company['vat']))<div>VAT: {{ $company['vat'] }}@if(!empty($company['reg'])) · Reg: {{ $company['reg'] }}@endif</div>
                @elseif(!empty($company['reg']))<div>Reg: {{ $company['reg'] }}</div>@endif
            </td>
        </tr>
    </table>
@else
    @php($logoRight = $logoPos === 'right' && $logo)
    <table style="width:100%; border:0; margin-bottom:18px;">
        <tr>
            {{-- Right placement swaps the two cells, so the address leads and the logo anchors the
                 opposite corner — the same letterhead, mirrored. --}}
            <td style="vertical-align:top; border:0; @if($logoRight) font-size:10px; color:#555; @endif">
                @if($logoRight)
                    <div style="font-weight:bold; color:#222; font-size:12px;">{{ $company['name'] }}</div>
                    @if(!empty($company['address']))<div>{!! nl2br(e($company['address'])) !!}</div>@endif
                    @if(!empty($company['phone']))<div>{{ $company['phone'] }}</div>@endif
                    @if(!empty($company['email']))<div>{{ $company['email'] }}</div>@endif
                    @if(!empty($company['website']))<div>{{ $company['website'] }}</div>@endif
                    @if(!empty($company['vat']))<div>VAT: {{ $company['vat'] }}</div>@endif
                    @if(!empty($company['reg']))<div>Reg: {{ $company['reg'] }}</div>@endif
                @elseif($logo)
                    <img src="{{ $logo }}" style="max-height:{{ $logoHeight }}px; max-width:300px;" />
                @else
                    {{-- No logo at all: the company name becomes the mark, so the page is never blank. --}}
                    <div style="font-size:20px; font-weight:bold; color:{{ $accent }};">{{ $company['name'] }}</div>
                @endif
            </td>
            <td style="vertical-align:top; text-align:right; border:0; @if(! $logoRight) font-size:10px; color:#555; @endif">
                @if($logoRight)
                    <img src="{{ $logo }}" style="max-height:{{ $logoHeight }}px; max-width:300px;" />
                @else
                    <div style="font-weight:bold; color:#222; font-size:12px;">{{ $company['name'] }}</div>
                    @if(!empty($company['address']))<div>{!! nl2br(e($company['address'])) !!}</div>@endif
                    @if(!empty($company['phone']))<div>{{ $company['phone'] }}</div>@endif
                    @if(!empty($company['email']))<div>{{ $company['email'] }}</div>@endif
                    @if(!empty($company['website']))<div>{{ $company['website'] }}</div>@endif
                    @if(!empty($company['vat']))<div>VAT: {{ $company['vat'] }}</div>@endif
                    @if(!empty($company['reg']))<div>Reg: {{ $company['reg'] }}</div>@endif
                @endif
            </td>
        </tr>
    </table>
@endif
