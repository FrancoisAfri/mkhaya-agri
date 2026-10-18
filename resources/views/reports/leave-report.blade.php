<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
  body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #222; }
  h1   { font-size: 14px; margin-bottom: 2px; }
  p    { margin: 0 0 8px; color: #666; font-size: 9px; }
  table { width: 100%; border-collapse: collapse; margin-top: 8px; }
  th    { background: #4a148c; color: #fff; padding: 5px 6px; text-align: left; font-size: 9px; }
  td    { padding: 4px 6px; border-bottom: 1px solid #e0e0e0; font-size: 9px; }
  tr:nth-child(even) td { background: #f5f5f5; }
  .empty { text-align: center; color: #999; padding: 20px; }
</style>
</head>
<body>
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
</body>
</html>
