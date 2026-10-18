<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 11pt; color: #1a1a1a; line-height: 1.6; }
  .page { padding: 40px 50px; }
  .header { border-bottom: 2px solid #334155; margin-bottom: 28px; padding-bottom: 16px; }
  .header h1 { font-size: 18pt; color: #1e293b; margin-bottom: 4px; }
  .header .meta { font-size: 9pt; color: #64748b; }
  .content { margin-bottom: 40px; }
  .content img { max-width: 100%; }
  .footer { position: fixed; bottom: 20px; left: 50px; right: 50px; border-top: 1px solid #e2e8f0; padding-top: 8px; font-size: 8pt; color: #94a3b8; display: flex; justify-content: space-between; }
  table { width: 100%; border-collapse: collapse; }
  td, th { padding: 6px 8px; border: 1px solid #e2e8f0; }
</style>
</head>
<body>
<div class="page">
  <div class="header">
    <h1>Employment Contract</h1>
    <div class="meta">
      Reference: {{ $contract->uuid }}
      &nbsp;·&nbsp;
      Generated: {{ now()->format('d M Y') }}
      @if($contract->expires_at)
        &nbsp;·&nbsp; Valid until: {{ $contract->expires_at->format('d M Y') }}
      @endif
    </div>
  </div>

  <div class="content">
    {!! $contract->content !!}
  </div>
</div>

<div class="footer">
  <span>{{ config('app.name') }} — Confidential</span>
  <span>Page <span class="pageNumber"></span> of <span class="totalPages"></span></span>
</div>
</body>
</html>
