<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>{{ $document->title }}</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: 'DejaVu Sans', Arial, sans-serif; color: #1a1a2e; }
  .watermark {
    position: fixed; top: 42%; left: 0; width: 100%; text-align: center;
    font-size: 64px; font-weight: 900; color: rgba(180, 0, 0, 0.10);
    transform: rotate(-32deg); z-index: -1; letter-spacing: 10px;
  }
  .cover { min-height: 100vh; display: flex; flex-direction: column; justify-content: center; align-items: center; padding: 60px; text-align: center; }
  .badge { display:inline-block; padding:8px 22px; border-radius:6px; font-size:13px; letter-spacing:2px; text-transform:uppercase; font-weight:700; color:#fff; margin-bottom:28px; }
  .title { font-size: 26px; font-weight: 700; margin-bottom: 12px; }
  .meta { font-size: 12px; color: #666; line-height: 2; margin-top: 24px; }
  .note { margin-top: 40px; font-size: 11px; color: #999; max-width: 460px; line-height: 1.6; }
</style>
</head>
<body>
<div class="watermark">{{ strtoupper($watermark) }}</div>
<div class="cover">
  <span class="badge" style="background: {{ $document->classification === 'restricted' ? '#b71c1c' : ($document->classification === 'confidential' ? '#e65100' : '#1565c0') }}">
    {{ strtoupper($document->classification) }}
  </span>
  <div class="title">{{ $document->title }}</div>
  @if($document->description)<p style="color:#555;font-size:13px;max-width:480px">{{ $document->description }}</p>@endif
  <div class="meta">
    <div>Document Type: {{ \App\Modules\Governance\Board\Models\GovernanceDocument::DOCUMENT_TYPES[$document->document_type] ?? $document->document_type }}</div>
    <div>Version: v{{ $document->version }}</div>
    <div>Original File: {{ $document->file_name }}</div>
  </div>
  <p class="note">
    This watermarked cover certifies the classification of the attached governance document.
    Distribution is restricted to authorised recipients. Unauthorised disclosure may constitute
    a breach of fiduciary duty and governance policy.
  </p>
</div>
</body>
</html>
