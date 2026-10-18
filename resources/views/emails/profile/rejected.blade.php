{{-- resources/views/emails/profile/rejected.blade.php --}}
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
  body {
    font-family: system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
    background: #f8fafc;
    margin: 0;
    padding: 20px;
    line-height: 1.5;
  }
  .card {
    max-width: 520px;
    margin: 0 auto;
    background: white;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 4px 24px rgba(0,0,0,.08);
  }
  .header {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    padding: 32px 32px 24px;
    color: white;
  }
  .header h1 {
    font-size: 22px;
    font-weight: 700;
    margin: 0 0 4px;
  }
  .header p {
    font-size: 13px;
    opacity: .9;
    margin: 0;
  }
  .body {
    padding: 28px 32px;
  }
  .body p {
    font-size: 14px;
    color: #475569;
    line-height: 1.6;
    margin: 0 0 16px;
  }
  .rejected-badge {
    display: inline-block;
    background: #fee2e2;
    color: #991b1b;
    font-size: 12px;
    font-weight: 700;
    padding: 4px 12px;
    border-radius: 20px;
    margin-bottom: 16px;
  }
  .reason-box {
    background: #fef2f2;
    border-left: 4px solid #ef4444;
    border-radius: 8px;
    padding: 16px 20px;
    margin: 20px 0;
  }
  .reason-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    color: #991b1b;
    letter-spacing: .5px;
    margin-bottom: 8px;
  }
  .reason-text {
    font-size: 14px;
    color: #7f1d1d;
    font-style: italic;
    margin: 0;
  }
  .info-box {
    background: #f8fafc;
    border-radius: 10px;
    padding: 16px 20px;
    margin: 20px 0;
  }
  .info-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
  }
  .info-row:last-child {
    margin-bottom: 0;
  }
  .info-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    color: #64748b;
    letter-spacing: .5px;
  }
  .info-value {
    font-size: 14px;
    font-weight: 600;
    color: #1e293b;
  }
  .approver-box {
    display: flex;
    align-items: center;
    gap: 12px;
    background: #f1f5f9;
    border-radius: 10px;
    padding: 14px 16px;
    margin: 20px 0;
  }
  .approver-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, #64748b, #475569);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 16px;
    flex-shrink: 0;
  }
  .approver-info {
    flex: 1;
  }
  .approver-name {
    font-weight: 700;
    color: #1e293b;
    font-size: 14px;
  }
  .approver-role {
    font-size: 12px;
    color: #64748b;
  }
  .btn {
    display: inline-block;
    background: #6366f1;
    color: white;
    font-weight: 600;
    font-size: 14px;
    padding: 12px 24px;
    border-radius: 8px;
    text-decoration: none;
    margin-top: 8px;
    transition: background .15s;
  }
  .btn:hover {
    background: #4f46e5;
  }
  .divider {
    height: 1px;
    background: #e2e8f0;
    margin: 24px 0;
  }
  .footer {
    text-align: center;
    padding: 16px 32px;
    border-top: 1px solid #f1f5f9;
    font-size: 11px;
    color: #94a3b8;
  }
  .footer a {
    color: #64748b;
    text-decoration: none;
  }
  .help-text {
    background: #f8fafc;
    border-radius: 8px;
    padding: 12px 16px;
    font-size: 13px;
    color: #475569;
    margin-top: 16px;
  }
</style>
</head>
<body>
<div class="card">
  <div class="header">
    <h1>❌ Request Declined</h1>
    <p>Your profile update request has been rejected</p>
  </div>

  <div class="body">
    <span class="rejected-badge">
      <i style="margin-right: 4px;">✗</i> Request Denied
    </span>

    <p>Hello <strong>{{ $fullName }}</strong>,</p>

    <p>We regret to inform you that the profile changes you requested have been <strong>declined</strong>.</p>

    <!-- Approver Information -->
    <div class="approver-box">
      <div class="approver-avatar">
        {{ strtoupper(substr($approver->name ?? 'A', 0, 1)) }}
      </div>
      <div class="approver-info">
        <div class="approver-name">{{ $approver->name ?? 'Administrator' }}</div>
        <div class="approver-role">Reviewed on {{ now()->format('F j, Y') }}</div>
      </div>
    </div>

    <!-- Reason Box -->
    @if(!empty($reason))
    <div class="reason-box">
      <div class="reason-label">
        <i style="margin-right: 4px;">📝</i> Reason for rejection
      </div>
      <p class="reason-text">"{{ $reason }}"</p>
    </div>
    @else
    <div class="reason-box">
      <div class="reason-label">
        <i style="margin-right: 4px;">📝</i> Reason for rejection
      </div>
      <p class="reason-text">No specific reason was provided.</p>
    </div>
    @endif

    <!-- Summary -->
    <div class="info-box">
      <div class="info-row">
        <span class="info-label">Employee</span>
        <span class="info-value">{{ $fullName }}</span>
      </div>
      @if($person->employee_number)
      <div class="info-row">
        <span class="info-label">Employee Number</span>
        <span class="info-value">{{ $person->employee_number }}</span>
      </div>
      @endif
      <div class="info-row">
        <span class="info-label">Request Date</span>
        <span class="info-value">{{ $change->created_at->format('M d, Y') }}</span>
      </div>
    </div>

    <p>If you believe this was a mistake or need further clarification, please contact your HR department or resubmit your request with the necessary corrections.</p>

    <!-- Call to Action -->
    <a href="{{ url('/profile/edit') }}" class="btn">
      Submit New Request →
    </a>

    <div class="divider"></div>

    <div class="help-text">
      <i style="margin-right: 6px;">💡</i>
      <strong>Need help?</strong> Contact your HR representative for assistance with your profile update.
    </div>
  </div>

  <div class="footer">
    © {{ date('Y') }} Mkhaya Agri — This is an automated message.<br>
    <a href="{{ url('/') }}">Visit Dashboard</a> ·
    <a href="{{ url('/profile') }}">My Profile</a> ·
    <a href="mailto:hr@mkhayahub.com">Contact HR</a>
  </div>
</div>
</body>
</html>
