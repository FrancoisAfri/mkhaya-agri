<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #333; }
  .header { background: #4a148c; color: #fff; padding: 18px 24px; margin-bottom: 20px; }
  .header h1 { font-size: 18px; margin-bottom: 4px; }
  .header p  { font-size: 11px; opacity: .85; }
  .meta { display: flex; gap: 24px; margin-bottom: 16px; padding: 0 4px; }
  .meta span { font-size: 10px; color: #666; }
  .meta strong { color: #333; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
  thead tr { background: #7367f0; color: #fff; }
  thead th { padding: 7px 8px; text-align: left; font-size: 10px; font-weight: 700; }
  tbody tr:nth-child(even) { background: #f5f5ff; }
  tbody td { padding: 6px 8px; font-size: 10px; border-bottom: 1px solid #e0e0e0; }
  .footer { margin-top: 20px; text-align: right; font-size: 9px; color: #aaa; }
  .badge { display: inline-block; padding: 2px 7px; border-radius: 10px; font-size: 9px; font-weight: 700; }
  .badge-success { background: #e8f5e9; color: #388e3c; }
  .badge-error   { background: #ffebee; color: #c62828; }
  .badge-warning { background: #fff8e1; color: #f57f17; }
  .badge-info    { background: #e3f2fd; color: #1565c0; }
</style>
</head>
<body>

<div class="header">
  <h1>{{ $title }}</h1>
  <p>Performance Appraisal &mdash; Executive Intelligence Report</p>
</div>

<div class="meta">
  <span>Generated: <strong>{{ $generatedAt }}</strong></span>
  <span>Total Records: <strong>{{ count($rows) }}</strong></span>
</div>

@if(count($rows))
<table>
  <thead>
    <tr>
      @foreach($headers as $h)
        <th>{{ $h }}</th>
      @endforeach
    </tr>
  </thead>
  <tbody>
    @foreach($rows as $row)
      <tr>
        @foreach($row as $cell)
          <td>{{ $cell ?? '—' }}</td>
        @endforeach
      </tr>
    @endforeach
  </tbody>
</table>
@else
  <p style="color:#888; padding:20px 4px;">No data found for the selected filters.</p>
@endif

<div class="footer">
  Mkhaya Agri &bull; Performance Management &bull; {{ $generatedAt }}
</div>

</body>
</html>
