{{-- resources/views/emails/profile/submitted.blade.php --}}
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
    background: linear-gradient(135deg, #f59e0b, #d97706);
    padding: 32px 32px 24px;
    color: white;
  }
  .header.manager-header {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
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
  .pending-badge {
    display: inline-block;
    background: #fef3c7;
    color: #92400e;
    font-size: 12px;
    font-weight: 700;
    padding: 4px 12px;
    border-radius: 20px;
    margin-bottom: 16px;
  }
  .manager-badge {
    display: inline-block;
    background: #e0e7ff;
    color: #4338ca;
    font-size: 12px;
    font-weight: 700;
    padding: 4px 12px;
    border-radius: 20px;
    margin-bottom: 16px;
  }
  .info-box {
    background: #f8fafc;
    border-radius: 10px;
    padding: 16px 20px;
    margin: 20px 0;
  }
  .info-box.highlight {
    background: #fffbeb;
    border: 1px solid #fde68a;
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
  .employee-box {
    display: flex;
    align-items: center;
    gap: 12px;
    background: #f1f5f9;
    border-radius: 10px;
    padding: 14px 16px;
    margin: 20px 0;
  }
  .employee-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 16px;
    flex-shrink: 0;
  }
  .employee-info {
    flex: 1;
  }
  .employee-name {
    font-weight: 700;
    color: #1e293b;
    font-size: 14px;
  }
  .employee-detail {
    font-size: 12px;
    color: #64748b;
  }
  .btn {
    display: inline-block;
    background: #f59e0b;
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
    background: #d97706;
  }
  .btn-primary {
    background: #6366f1;
  }
  .btn-primary:hover {
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
  .note-box {
    background: #f0fdf4;
    border-left: 4px solid #22c55e;
    border-radius: 8px;
    padding: 12px 16px;
    font-size: 13px;
    color: #166534;
    margin-top: 16px;
  }
</style>
</head>
<body>

{{-- ============================================= --}}
{{-- VERSION MANAGER (approbateur)                --}}
{{-- ============================================= --}}
@if($isManager)
<div class="card">
  <div class="header manager-header">
    <h1>📋 New Approval Request</h1>
    <p>Profile changes are waiting for your review</p>
  </div>

  <div class="body">
    <span class="manager-badge">
      <i style="margin-right: 4px;">👔</i> Action Required
    </span>

    <p>Hello,</p>

    <p>A team member has submitted profile changes that require your approval as a manager.</p>

    <!-- Employee Information -->
    <div class="employee-box">
      <div class="employee-avatar" style="background: linear-gradient(135deg, #6366f1, #8b5cf6);">
        {{ strtoupper(substr($fullName, 0, 1)) }}
      </div>
      <div class="employee-info">
        <div class="employee-name">{{ $fullName }}</div>
        <div class="employee-detail">
          @if($user->person?->employee_number)
            Emp #{{ $user->person->employee_number }}
          @else
            {{ $user->email }}
          @endif
        </div>
      </div>
    </div>

    <!-- Request Summary -->
    <div class="info-box highlight">
      <div class="info-row">
        <span class="info-label">Request ID</span>
        <span class="info-value">#{{ $change->id }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">Submitted</span>
        <span class="info-value">{{ $change->created_at->format('M d, Y - h:i A') }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">Status</span>
        <span class="info-value" style="color: #d97706;">⏳ Pending Review</span>
      </div>
    </div>

    <p>Please review these changes at your earliest convenience. You can approve or reject the request from the approvals dashboard.</p>

    <!-- Call to Action -->
    <a href="{{ url('/settings/approvals/' . $change->id) }}" class="btn btn-primary">
      Review Changes →
    </a>

    <div class="divider"></div>

    <p style="font-size: 13px; color: #64748b;">
      <i style="margin-right: 4px;">🔔</i>
      You're receiving this email because you're designated as a manager for this employee.
    </p>
  </div>

  <div class="footer">
    © {{ date('Y') }} Mkhaya Agri — This is an automated message.<br>
    <a href="{{ url('/settings/approvals') }}">View All Approvals</a>
  </div>
</div>

{{-- ============================================= --}}
{{-- VERSION EMPLOYÉ (demandeur)                  --}}
{{-- ============================================= --}}
@else
<div class="card">
  <div class="header">
    <h1>📋 Changes Submitted</h1>
    <p>Your profile update request is pending approval</p>
  </div>

  <div class="body">
    <span class="pending-badge">
      <i style="margin-right: 4px;">⏳</i> Pending Review
    </span>

    <p>Hello <strong>{{ $fullName }}</strong>,</p>

    <p>Your profile change request has been <strong>submitted for approval</strong>. A manager will review your changes shortly.</p>

    <!-- Summary -->
    <div class="info-box">
      <div class="info-row">
        <span class="info-label">Request ID</span>
        <span class="info-value">#{{ $change->id }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">Submitted</span>
        <span class="info-value">{{ $change->created_at->format('M d, Y - h:i A') }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">Status</span>
        <span class="info-value" style="color: #d97706;">⏳ Pending Approval</span>
      </div>
    </div>

    <div class="note-box">
      <i style="margin-right: 6px;">ℹ️</i>
      <strong>What happens next?</strong><br>
      An HR manager will review your requested changes. You'll receive another email once a decision has been made. No further action is required from you at this time.
    </div>

    <p style="margin-top: 20px;">You can track the status of your request from your profile page.</p>

    <!-- Call to Action -->
    <a href="{{ url('/profile') }}" class="btn">
      View My Profile →
    </a>
  </div>

  <div class="footer">
    © {{ date('Y') }} Mkhaya Agri — This is an automated message.<br>
    <a href="{{ url('/profile') }}">My Profile</a> ·
    <a href="{{ url('/profile/edit') }}">Edit Profile</a>
  </div>
</div>
@endif

</body>
</html>
