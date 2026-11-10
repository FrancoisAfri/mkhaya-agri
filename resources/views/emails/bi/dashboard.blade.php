<!doctype html>
<html><body style="margin:0;background:#F7F7FA;font-family:'Nunito Sans',Arial,sans-serif;color:#2B2B38">
  <table width="100%" cellpadding="0" cellspacing="0" style="padding:28px 12px"><tr><td align="center">
    <table width="560" cellpadding="0" cellspacing="0" style="background:#fff;border:1px solid #EEEEF3;border-radius:18px;padding:28px">
      <tr><td>
        <div style="font-size:12px;font-weight:700;color:#6A0DAD;letter-spacing:.3px">{{ $company }} · BUSINESS INTELLIGENCE</div>
        <h1 style="font-family:Montserrat,Arial,sans-serif;font-size:22px;margin:6px 0 2px;color:#16161F">{{ $title }}</h1>
        <div style="font-size:13px;color:#77778A;margin-bottom:16px">{{ $period }}</div>
        @if ($lines)
          <table width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #EEEEF3">
            @foreach ($lines as [$label, $value])
              <tr><td style="padding:8px 0;font-size:14px;border-bottom:1px solid #EEEEF3">{{ $label }}</td>
                  <td align="right" style="padding:8px 0;font-size:14px;font-weight:700;border-bottom:1px solid #EEEEF3">{{ $value }}</td></tr>
            @endforeach
          </table>
        @endif
        <p style="margin:20px 0 0"><a href="{{ $link }}" style="display:inline-block;background:#6A0DAD;color:#fff;text-decoration:none;padding:10px 18px;border-radius:10px;font-size:14px;font-weight:700">Open the dashboard</a></p>
        <p style="font-size:13px;color:#77778A;margin:18px 0 0">The figures behind every visual are attached ({{ strtoupper(pathinfo($file['name'], PATHINFO_EXTENSION)) }}).
          You receive this because an email schedule was set up on this dashboard in Mkhaya → Business Intelligence.</p>
      </td></tr>
    </table>
  </td></tr></table>
</body></html>
