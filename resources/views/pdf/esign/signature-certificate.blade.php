<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<title>Signature Certificate — {{ $envelope->title }}</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }

  body {
    font-family: 'DejaVu Sans', Arial, sans-serif;
    font-size: 10pt;
    color: #1a1a1a;
    background: #fff;
    line-height: 1.5;
  }

  .page { padding: 40px 48px; }

  /* ── Banner ── */
  .banner {
    background: #1a3c6e;
    color: #fff;
    padding: 20px 30px;
    margin-bottom: 28px;
    border-radius: 4px;
  }
  .banner-title  { font-size: 15pt; font-weight: bold; letter-spacing: 0.5px; margin-bottom: 4px; }
  .banner-sub    { font-size: 9pt; opacity: 0.8; }

  /* ── Section ── */
  .section { margin-bottom: 28px; }
  .section-title {
    font-size: 10pt;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #1a3c6e;
    border-bottom: 1.5px solid #1a3c6e;
    padding-bottom: 4px;
    margin-bottom: 12px;
  }

  /* ── Meta grid ── */
  .meta-grid { width: 100%; }
  .meta-grid td { padding: 4px 8px 4px 0; font-size: 9.5pt; vertical-align: top; }
  .meta-label { color: #555; width: 160px; }

  /* ── Tables ── */
  table.data { width: 100%; border-collapse: collapse; font-size: 9pt; }
  table.data th {
    background: #f0f4f8;
    color: #1a3c6e;
    font-weight: bold;
    padding: 7px 8px;
    border: 1px solid #d0dae6;
    text-align: left;
    font-size: 8.5pt;
  }
  table.data td {
    padding: 7px 8px;
    border: 1px solid #e2e8f0;
    vertical-align: top;
  }
  table.data tr:nth-child(even) td { background: #f8fafc; }

  /* ── Status chips ── */
  .chip {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 8pt;
    font-weight: bold;
  }
  .chip-signed   { background: #d1fae5; color: #065f46; }
  .chip-pending  { background: #f1f5f9; color: #475569; }
  .chip-declined { background: #fee2e2; color: #991b1b; }

  /* ── Signature box ── */
  .sig-box {
    border: 1px solid #d0dae6;
    border-radius: 4px;
    padding: 8px 12px;
    background: #f8fafc;
    min-height: 36px;
    font-size: 9pt;
  }
  .sig-typed { font-family: 'DejaVu Sans', cursive; font-size: 13pt; color: #1a1a1a; }
  .sig-drawn { color: #64748b; font-style: italic; }

  /* ── Audit events ── */
  .audit-event { font-weight: bold; color: #1a3c6e; }
  .audit-meta  { color: #64748b; font-size: 8pt; }

  /* ── Legal notice ── */
  .legal {
    background: #f0f4f8;
    border: 1px solid #d0dae6;
    border-radius: 4px;
    padding: 12px 16px;
    font-size: 8.5pt;
    color: #475569;
    line-height: 1.6;
  }

  /* ── Hash box ── */
  .hash-box {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    padding: 8px 12px;
    font-family: 'DejaVu Sans Mono', monospace;
    font-size: 8pt;
    color: #475569;
    word-break: break-all;
  }

  /* ── Footer ── */
  .footer {
    position: fixed;
    bottom: 20px;
    left: 48px;
    right: 48px;
    border-top: 1px solid #e2e8f0;
    padding-top: 8px;
    font-size: 8pt;
    color: #94a3b8;
  }
  .footer-inner {
    display: table;
    width: 100%;
  }
  .footer-left  { display: table-cell; text-align: left; }
  .footer-right { display: table-cell; text-align: right; }

  .page-break { page-break-after: always; }
  .completed-badge {
    display: inline-block;
    background: #d1fae5;
    color: #065f46;
    padding: 3px 10px;
    border-radius: 3px;
    font-size: 9pt;
    font-weight: bold;
  }
</style>
</head>
<body>

<div class="page">

  {{-- ── Banner ── --}}
  <div class="banner">
    <div class="banner-title">Electronic Signature Certificate</div>
    <div class="banner-sub">{{ config('app.name') }} ERP — ESign Module &nbsp;·&nbsp; Generated {{ now()->format('d M Y H:i') }} SAST</div>
  </div>

  {{-- ── Document details ── --}}
  <div class="section">
    <div class="section-title">Document Information</div>
    <table class="meta-grid">
      <tr>
        <td class="meta-label">Envelope Title</td>
        <td><strong>{{ $envelope->title }}</strong></td>
        <td class="meta-label" style="padding-left:24px;">Document Type</td>
        <td>{{ $envelope->document_type->label() }}</td>
      </tr>
      <tr>
        <td class="meta-label">Envelope Reference</td>
        <td style="font-family: monospace; font-size:8.5pt;">{{ $envelope->uuid }}</td>
        <td class="meta-label" style="padding-left:24px;">Status</td>
        <td><span class="chip chip-signed">{{ strtoupper($envelope->status->label()) }}</span></td>
      </tr>
      <tr>
        <td class="meta-label">Sent</td>
        <td>{{ $envelope->sent_at?->format('d M Y H:i') ?? '—' }}</td>
        <td class="meta-label" style="padding-left:24px;">Completed</td>
        <td>{{ $envelope->completed_at?->format('d M Y H:i') ?? '—' }}</td>
      </tr>
      <tr>
        <td class="meta-label">Initiated By</td>
        <td>{{ $envelope->createdBy?->name ?? '—' }}</td>
        <td class="meta-label" style="padding-left:24px;">Expiry</td>
        <td>{{ $envelope->expires_at?->format('d M Y H:i') ?? 'No expiry set' }}</td>
      </tr>
      @if($envelope->message)
      <tr>
        <td class="meta-label">Message</td>
        <td colspan="3">{{ $envelope->message }}</td>
      </tr>
      @endif
    </table>
  </div>

  {{-- ── Signers ── --}}
  <div class="section">
    <div class="section-title">Signatories</div>
    <table class="data">
      <thead>
        <tr>
          <th>#</th>
          <th>Name</th>
          <th>Email</th>
          <th>Role</th>
          <th>OTP Channel</th>
          <th>Status</th>
          <th>Signed At</th>
          <th>IP Address</th>
        </tr>
      </thead>
      <tbody>
        @foreach($signers as $signer)
        <tr>
          <td>{{ $signer->signing_order }}</td>
          <td><strong>{{ $signer->name }}</strong></td>
          <td>{{ $signer->email }}</td>
          <td>{{ $signer->role->label() }}</td>
          <td>
            @if($signer->otpChallenges->isNotEmpty())
              {{ strtoupper($signer->otpChallenges->last()->channel) }}
              <br><span style="font-size:8pt;color:#64748b;">→ {{ $signer->otpChallenges->last()->sent_to }}</span>
            @else
              —
            @endif
          </td>
          <td>
            @if($signer->isSigned())
              <span class="chip chip-signed">SIGNED</span>
            @elseif($signer->isDeclined())
              <span class="chip chip-declined">DECLINED</span>
            @else
              <span class="chip chip-pending">PENDING</span>
            @endif
          </td>
          <td>{{ $signer->signed_at?->format('d M Y H:i') ?? '—' }}</td>
          <td style="font-family:monospace;font-size:8pt;">{{ $signer->ip_address ?? '—' }}</td>
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  {{-- ── Signatures ── --}}
  <div class="section">
    <div class="section-title">Captured Signatures</div>
    @foreach($signers->where('signed_at', '!=', null) as $signer)
    <div style="margin-bottom: 14px;">
      <div style="font-size:9pt;color:#475569;margin-bottom:4px;">
        <strong>{{ $signer->name }}</strong> &nbsp;·&nbsp; {{ $signer->role->label() }} &nbsp;·&nbsp; Signed {{ $signer->signed_at?->format('d M Y H:i') }}
      </div>
      <div class="sig-box">
        @if($signer->signature_type === 'typed')
          <span class="sig-typed">{{ $signer->signatureImage() }}</span>
        @elseif($signer->signature_type === 'drawn')
          @if(str_starts_with($signer->signatureImage() ?? '', 'data:image'))
            <img src="{{ $signer->signatureImage() }}" style="max-height:50px;max-width:250px;" />
          @else
            <span class="sig-drawn">Drawn signature captured (data stored securely)</span>
          @endif
        @else
          <span class="sig-drawn">—</span>
        @endif
      </div>
    </div>
    @endforeach
  </div>

  {{-- ── Audit trail ── --}}
  <div class="section">
    <div class="section-title">Audit Trail</div>
    <table class="data">
      <thead>
        <tr>
          <th>Timestamp</th>
          <th>Event</th>
          <th>Actor</th>
          <th>Email</th>
          <th>IP Address</th>
          <th>Detail</th>
        </tr>
      </thead>
      <tbody>
        @foreach($auditEntries as $entry)
        <tr>
          <td style="white-space:nowrap;font-size:8.5pt;">{{ $entry->created_at->format('d M Y H:i:s') }}</td>
          <td><span class="audit-event">{{ str_replace('_', ' ', strtoupper($entry->event)) }}</span></td>
          <td>{{ $entry->actor_name ?? '—' }}</td>
          <td>{{ $entry->actor_email ?? '—' }}</td>
          <td style="font-family:monospace;font-size:8pt;">{{ $entry->ip_address ?? '—' }}</td>
          <td class="audit-meta">
            @if($entry->metadata)
              @foreach($entry->metadata as $k => $v)
                {{ $k }}: {{ is_bool($v) ? ($v ? 'yes' : 'no') : $v }}&nbsp;
              @endforeach
            @endif
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  {{-- ── Document integrity ── --}}
  @if($envelope->document_hash)
  <div class="section">
    <div class="section-title">Document Integrity</div>
    <p style="font-size:9pt;color:#475569;margin-bottom:8px;">
      SHA-256 hash of the original document at time of signing. Recompute against the original file to detect tampering.
    </p>
    <div class="hash-box">SHA-256: {{ $envelope->document_hash }}</div>
  </div>
  @endif

  {{-- ── Legal notice ── --}}
  <div class="section">
    <div class="section-title">Legal Notice</div>
    <div class="legal">
      This certificate constitutes an <strong>Advanced Electronic Signature</strong> as defined under the
      <strong>Electronic Communications and Transactions Act 25 of 2002 (ECT Act)</strong>, Section 13(1).
      Each signatory was uniquely identified via a one-time password (OTP) delivered to their registered
      contact, and their IP address, device information, and timestamp were recorded at the time of signing.
      Consent to sign electronically was explicitly obtained from each signatory prior to signing.
      <br><br>
      This document and its audit trail are admissible as evidence in proceedings before the
      <strong>Commission for Conciliation, Mediation and Arbitration (CCMA)</strong> and South African courts
      in accordance with the ECT Act and the <strong>Protection of Personal Information Act 4 of 2013 (POPIA)</strong>.
      Personal information was processed only for the purpose of facilitating this signing event and is stored
      on South African infrastructure.
      <br><br>
      Generated by <strong>{{ config('app.name') }}</strong> &nbsp;·&nbsp; {{ now()->format('d M Y H:i:s') }} SAST
    </div>
  </div>

</div>

{{-- ── Footer ── --}}
<div class="footer">
  <div class="footer-inner">
    <span class="footer-left">{{ config('app.name') }} — ESign Certificate &nbsp;·&nbsp; Ref: {{ $envelope->uuid }}</span>
    <span class="footer-right">Page <span class="pageNumber"></span> of <span class="totalPages"></span></span>
  </div>
</div>

</body>
</html>
