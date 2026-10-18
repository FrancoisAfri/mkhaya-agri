<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  body {
    font-family: system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
    background: #f8fafc;
    margin: 0;
    padding: 20px;
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
    background: linear-gradient(135deg, #10b981, #059669);
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
  .success-badge {
    display: inline-block;
    background: #d1fae5;
    color: #065f46;
    font-size: 12px;
    font-weight: 700;
    padding: 4px 12px;
    border-radius: 20px;
    margin-bottom: 16px;
  }
  .info-box {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
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
    background: #f8fafc;
    border-radius: 10px;
    padding: 14px 16px;
    margin: 20px 0;
  }
  .approver-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
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
    background: #10b981;
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
    background: #059669;
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
</style>
</head>
<body>
<div class="card">
  <div class="header">
    <h1>✅ Profile Updated</h1>
    <p>Your requested changes have been approved</p>
  </div>

  <div class="body">
    <span class="success-badge">
      <i style="margin-right: 4px;">✓</i> Changes Applied
    </span>

    <p>Hello <strong>{{ $fullName }}</strong>,</p>

    <p>Good news! The profile changes you requested have been reviewed and <strong>approved</strong> by {{ $approver->name ?? 'an administrator' }}. All updates have been applied to your profile.</p>

    <!-- Approver Information -->
    <div class="approver-box">
      <div class="approver-avatar">
        {{ substr($approver->name ?? 'A', 0, 1) }}
      </div>
      <div class="approver-info">
        <div class="approver-name">{{ $approver->name ?? 'Administrator' }}</div>
        <div class="approver-role">Approved on {{ now()->format('F j, Y') }}</div>
      </div>
    </div>

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
        <span class="info-label">Updated</span>
        <span class="info-value">{{ now()->format('M d, Y') }}</span>
      </div>
    </div>

    <p>You can now log in to your account to see the updated information.</p>

    <!-- Call to Action -->
    <a href="{{ url('/profile') }}" class="btn">
      View My Profile →
    </a>

    <div class="divider"></div>

    <p style="font-size: 13px; color: #64748b;">
      <i style="margin-right: 4px;">📋</i>
      If you didn't request these changes or believe this was a mistake, please contact your HR department immediately.
    </p>
  </div>

  <div class="footer">
    © {{ date('Y') }} Mkhaya Agri — This is an automated message.<br>
    <a href="{{ url('/') }}">Visit Dashboard</a> ·
    <a href="{{ url('/profile') }}">My Profile</a>
  </div>
</div>
</body>
</html>
