<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
  body  { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #222; }
  .logo { font-size: 16px; font-weight: bold; color: #4a148c; margin-bottom: 2px; }
  h1   { font-size: 13px; margin: 4px 0 2px; }
  p    { margin: 0 0 6px; color: #666; font-size: 8px; }
  table { width: 100%; border-collapse: collapse; margin-top: 8px; }
  th    { background: #4a148c; color: #fff; padding: 5px 6px; text-align: left; font-size: 8px; white-space: nowrap; }
  td    { padding: 4px 6px; border-bottom: 1px solid #e0e0e0; font-size: 8px; }
  tr:nth-child(even) td { background: #f8f5ff; }
  .empty { text-align: center; color: #999; padding: 20px; }
  .footer { margin-top: 16px; font-size: 8px; color: #aaa; border-top: 1px solid #ddd; padding-top: 4px; }
</style>
</head>
<body>
  <div class="logo">MKHAYA AGRI — Disciplinary Module</div>
  <h1>{{ $title }}</h1>
  <p>Generated: {{ $date }} &nbsp;|&nbsp; {{ count($rows) }} records</p>

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
              <td>{{ $cell }}</td>
            @endforeach
          </tr>
        @endforeach
      </tbody>
    </table>
  @else
    <p class="empty">No data available for this report.</p>
  @endif

  <div class="footer">Disciplinary &amp; Misconduct Report — Confidential — {{ $date }}</div>
</body>
</html>
