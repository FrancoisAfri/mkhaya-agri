@if(!empty($company['bank']) || !empty($company['message']))
    <table style="width:100%; border:0; margin-top:18px;"><tr>
        @if(!empty($company['bank']))
            <td style="border:0; vertical-align:top; width:50%; font-size:10px; color:#555;">
                <div style="font-weight:bold; color:#222; margin-bottom:3px;">Banking details</div>
                @foreach($company['bank'] as $label => $value)
                    <div>{{ $label }}: <strong>{{ $value }}</strong></div>
                @endforeach
            </td>
        @endif
        @if(!empty($company['message']))
            <td style="border:0; vertical-align:top; font-size:10px; color:#555;">
                {!! nl2br(e($company['message'])) !!}
            </td>
        @endif
    </tr></table>
@endif

@if(!empty($terms))
    <div style="margin-top:16px; padding-top:8px; border-top:1px solid #ddd; font-size:9.5px; color:#555;">
        <div style="font-weight:bold; color:#222; margin-bottom:3px;">Terms &amp; Conditions</div>
        {!! nl2br(e($terms)) !!}
    </div>
@endif

@if(!empty($company['footer_note']))
    <div style="margin-top:12px; text-align:center; font-size:9px; color:#999;">
        {!! nl2br(e($company['footer_note'])) !!}
    </div>
@endif
