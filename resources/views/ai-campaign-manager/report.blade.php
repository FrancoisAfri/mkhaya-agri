<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><style>
body { font-family: DejaVu Sans, sans-serif; color:#1a1a1a; font-size:12px; line-height:1.55; }
h1 { font-size:22px; margin:0 0 4px; } .meta { color:#666; font-size:11px; margin-bottom:16px; }
table { width:100%; border-collapse:collapse; margin:8px 0 16px; } td,th { border:1px solid #e2e5e9; padding:6px 8px; text-align:left; font-size:11px; }
h2 { font-size:15px; margin:16px 0 6px; border-bottom:1px solid #e2e5e9; padding-bottom:3px; }
.footer { margin-top:24px; color:#999; font-size:10px; border-top:1px solid #eee; padding-top:8px; }
</style></head><body>
<h1>{{ $period }} Advertising Report</h1>
<div class="meta">{{ config('app.name') }} · AI Campaign Manager · {{ now()->format('d M Y') }}</div>
<table>
  <tr><th>Campaigns</th><td>{{ $summary['campaigns'] }}</td><th>Running</th><td>{{ $summary['running'] }}</td></tr>
  <tr><th>Spend</th><td>R{{ number_format($summary['spend'], 2) }}</td><th>Revenue</th><td>R{{ number_format($summary['revenue'], 2) }}</td></tr>
  <tr><th>Leads</th><td>{{ $summary['leads'] }}</td><th>ROAS</th><td>{{ $summary['roas'] }}x</td></tr>
</table>
<div>{!! \Illuminate\Support\Str::markdown($commentary ?? '') !!}</div>
<div class="footer">Figures come from connected ad accounts; 0 until an account is connected. AI commentary is advisory.</div>
</body></html>
