<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Board Pack — {{ $pack->meeting->title ?? 'Board Meeting' }}</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 11px; color: #1a1a2e; background: #fff; }

  /* ── Watermark ── */
  .watermark {
    position: fixed;
    top: 45%;
    left: 0;
    width: 100%;
    text-align: center;
    font-size: 72px;
    font-weight: 900;
    color: rgba(180, 0, 0, 0.08);
    transform: rotate(-35deg);
    z-index: -1;
    letter-spacing: 12px;
    pointer-events: none;
  }

  /* ── Cover Page ── */
  .cover-page {
    page-break-after: always;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    text-align: center;
    padding: 80px 60px;
    background: linear-gradient(160deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
    color: #fff;
  }
  .cover-org    { font-size: 13px; letter-spacing: 4px; text-transform: uppercase; opacity: 0.7; margin-bottom: 48px; }
  .cover-title  { font-size: 28px; font-weight: 700; margin-bottom: 16px; line-height: 1.3; }
  .cover-sub    { font-size: 14px; opacity: 0.85; margin-bottom: 48px; }
  .cover-meta   { font-size: 11px; opacity: 0.6; line-height: 2; }
  .cover-badge  {
    display: inline-block;
    padding: 6px 18px;
    border: 1px solid rgba(255,215,0,0.6);
    border-radius: 4px;
    font-size: 11px;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: #ffd700;
    margin-top: 40px;
  }

  /* ── Table of Contents ── */
  .toc-page { page-break-after: always; padding: 60px 60px 40px; }
  .toc-page h2 { font-size: 18px; font-weight: 700; border-bottom: 2px solid #0f3460; padding-bottom: 10px; margin-bottom: 28px; color: #0f3460; }
  .toc-row  { display: flex; justify-content: space-between; border-bottom: 1px dotted #ccc; padding: 7px 0; font-size: 11px; }
  .toc-num  { width: 40px; color: #0f3460; font-weight: 600; }
  .toc-title{ flex: 1; padding: 0 12px; }

  /* ── Section pages ── */
  .section-page { page-break-before: always; padding: 50px 60px 40px; }
  .section-header { border-bottom: 2px solid #0f3460; padding-bottom: 8px; margin-bottom: 28px; }
  .section-num   { font-size: 11px; color: #0f3460; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; }
  .section-title { font-size: 20px; font-weight: 700; color: #1a1a2e; margin-top: 4px; }
  .section-type  { font-size: 10px; color: #888; margin-top: 4px; }
  .section-body  {
    margin-top: 24px;
    padding: 20px;
    background: #f8f9fa;
    border-left: 4px solid #0f3460;
    border-radius: 0 4px 4px 0;
    font-size: 11px;
    color: #444;
    line-height: 1.8;
  }
  .separator-page { page-break-before: always; padding: 200px 60px; text-align: center; }
  .separator-label{ font-size: 24px; font-weight: 700; color: #0f3460; letter-spacing: 6px; text-transform: uppercase; }

  /* ── Footer (page numbers via DomPDF) ── */
  .page-footer {
    position: fixed;
    bottom: 20px;
    left: 60px;
    right: 60px;
    font-size: 9px;
    color: #aaa;
    border-top: 1px solid #e0e0e0;
    padding-top: 6px;
    display: flex;
    justify-content: space-between;
  }
</style>
</head>
<body>

@if($watermark)
<div class="watermark">{{ strtoupper($watermark) }}</div>
@endif

{{-- ── Cover Page ── --}}
<div class="cover-page">
  <div class="cover-org">Board &amp; Committee Management</div>
  <div class="cover-title">{{ $pack->meeting->title ?? 'Board Meeting' }}</div>
  <div class="cover-sub">
    {{ $pack->meeting->scheduled_at?->format('l, d F Y') ?? '' }}
    @if($pack->meeting->location) &nbsp;·&nbsp; {{ $pack->meeting->location }} @endif
  </div>
  <div class="cover-meta">
    <div>Pack Version: v{{ $pack->version }}</div>
    <div>Compiled: {{ $pack->compiled_at?->format('d F Y H:i') ?? now()->format('d F Y H:i') }}</div>
    <div>Committee: {{ $pack->meeting->committee?->name ?? 'Board' }}</div>
  </div>
  <div class="cover-badge">{{ strtoupper($pack->classification ?? 'Confidential') }}</div>
</div>

{{-- ── Table of Contents ── --}}
@php
  $tocItems = $items->filter(fn($i) => $i->include_in_toc && $i->item_type !== 'cover' && $i->item_type !== 'toc')->values();
@endphp
@if($tocItems->count() > 0)
<div class="toc-page">
  <h2>Table of Contents</h2>
  @foreach($tocItems as $tocItem)
  <div class="toc-row">
    <span class="toc-num">{{ $tocItem->section_number ?? ($loop->iteration . '.') }}</span>
    <span class="toc-title">{{ $tocItem->title }}</span>
    <span style="color:#888;font-size:10px;">{{ \App\Modules\Governance\Board\Models\GovernancePackItem::ITEM_TYPES[$tocItem->item_type] ?? $tocItem->item_type }}</span>
  </div>
  @endforeach
</div>
@endif

{{-- ── Content sections ── --}}
@foreach($items->filter(fn($i) => !in_array($i->item_type, ['cover','toc'])) as $item)

  @if($item->item_type === 'separator')
  <div class="separator-page">
    <div class="separator-label">{{ $item->title }}</div>
  </div>

  @else
  <div class="section-page">
    <div class="section-header">
      @if($item->section_number)
      <div class="section-num">{{ $item->section_number }}</div>
      @endif
      <div class="section-title">{{ $item->title }}</div>
      <div class="section-type">{{ \App\Modules\Governance\Board\Models\GovernancePackItem::ITEM_TYPES[$item->item_type] ?? $item->item_type }}</div>
    </div>

    <div class="section-body">
      @if($item->file_name)
      <p><strong>Document:</strong> {{ $item->file_name }}</p>
      @if($item->file_size_bytes)
      <p><strong>Size:</strong>
        @php
          $bytes = $item->file_size_bytes;
          echo $bytes >= 1048576 ? round($bytes/1048576,1).' MB' : ($bytes >= 1024 ? round($bytes/1024,1).' KB' : $bytes.' B');
        @endphp
      </p>
      @endif
      <p style="margin-top:16px;color:#666;font-style:italic;">
        [Document attached — see digital distribution for full content]
      </p>
      @else
      <p style="color:#888;font-style:italic;">No document attached for this section.</p>
      @endif
    </div>
  </div>
  @endif

@endforeach

<div class="page-footer">
  <span>{{ $pack->meeting->title ?? 'Board Pack' }} &mdash; v{{ $pack->version }} &mdash; {{ strtoupper($pack->classification ?? 'CONFIDENTIAL') }}</span>
  <span>Page <span class="pagenum"></span></span>
</div>

</body>
</html>
