<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family: sans-serif; font-size: 11px; color: #1e293b; }
  .header { background: #6366f1; color: white; padding: 16px 20px; margin-bottom: 20px; }
  .header h1 { font-size: 18px; font-weight: 700; }
  .header p  { font-size: 11px; opacity: .8; margin-top: 2px; }
  .meta { display: flex; gap: 20px; padding: 0 20px 14px; border-bottom: 1px solid #e2e8f0; margin-bottom: 14px; }
  .meta span { font-size: 10px; color: #64748b; }
  .meta b { color: #1e293b; }
  table { width: 100%; border-collapse: collapse; }
  thead tr { background: #f1f5f9; }
  th { text-align: left; padding: 8px 10px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: #64748b; border-bottom: 2px solid #e2e8f0; }
  td { padding: 7px 10px; border-bottom: 1px solid #f1f5f9; font-size: 10px; }
  tr:nth-child(even) td { background: #f8fafc; }
  .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 9px; font-weight: 700; }
  .badge-create  { background: #dcfce7; color: #166534; }
  .badge-updated { background: #fef9c3; color: #854d0e; }
  .badge-deleted { background: #fee2e2; color: #991b1b; }
  .badge-login   { background: #dbeafe; color: #1e40af; }
  .footer { margin-top: 20px; text-align: right; font-size: 9px; color: #94a3b8; padding: 0 20px; }
</style>
</head>
<body>

<div class="header">
  <h1>Audit Report</h1>
  <p>Generated: {{ $generated }}</p>
</div>

<div class="meta">
  <span>Period: <b>{{ $date_from ?: 'All time' }} → {{ $date_to ?: 'Now' }}</b></span>
  <span>Total logs: <b>{{ $logs->count() }}</b></span>
</div>

<table>
  <thead>
    <tr>
      <th>#</th>
      <th>Log Name</th>
      <th>Event</th>
      <th>Description</th>
      <th>User</th>
      <th>Date</th>
    </tr>
  </thead>
  <tbody>
    @foreach($logs as $i => $log)
    <tr>
      <td>{{ $i + 1 }}</td>
      <td>{{ $log->log_name }}</td>
      <td>
        <span class="badge badge-{{ $log->event }}">{{ $log->event }}</span>
      </td>
      <td>{{ Str::limit($log->description, 60) }}</td>
      <td>{{ $log->firstname ?? 'System' }}</td>
      <td>{{ \Carbon\Carbon::parse($log->created_at)->format('d/m/Y H:i') }}</td>
    </tr>
    @endforeach
  </tbody>
</table>

<div class="footer">Mkhaya Agri — Audit Report — {{ $generated }}</div>

</body>
</html>
