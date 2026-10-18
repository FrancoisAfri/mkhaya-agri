<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  body { font-family: sans-serif; background: #f8fafc; margin: 0; padding: 20px; }
  .card { max-width: 520px; margin: 0 auto; background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 24px rgba(0,0,0,.08); }
  .header { background: linear-gradient(135deg, #6366f1, #8b5cf6); padding: 32px 32px 24px; color: white; }
  .header h1 { font-size: 22px; font-weight: 700; margin: 0 0 4px; }
  .header p  { font-size: 13px; opacity: .8; margin: 0; }
  .body { padding: 28px 32px; }
  .body p { font-size: 14px; color: #475569; line-height: 1.6; margin: 0 0 16px; }
  .cred-box { background: #f1f5f9; border-radius: 10px; padding: 16px 20px; margin: 20px 0; }
  .cred-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
  .cred-row:last-child { margin-bottom: 0; }
  .cred-label { font-size: 11px; font-weight: 700; text-transform: uppercase; color: #94a3b8; letter-spacing: .5px; }
  .cred-value { font-size: 14px; font-weight: 600; color: #1e293b; font-family: monospace; }
  .warning { background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 12px 16px; font-size: 12px; color: #92400e; margin-top: 16px; }
  .footer { text-align: center; padding: 16px 32px; border-top: 1px solid #f1f5f9; font-size: 11px; color: #94a3b8; }
</style>
</head>
<body>
<div class="card">
  <div class="header">
    <h1>Welcome, {{ $name }} 👋</h1>
    <p>Your account has been created successfully.</p>
  </div>
  <div class="body">
    <p>Hello <strong>{{ $name }}</strong>, an account has been created for you. Here are your login credentials:</p>

    <div class="cred-box">
      <div class="cred-row">
        <span class="cred-label">Email</span>
        <span class="cred-value">{{ $email }}</span>
      </div>
      <div class="cred-row">
        <span class="cred-label">Password</span>
        <span class="cred-value">{{ $password }}</span>
      </div>
    </div>

    <div class="warning">
      ⚠️ Please change your password after your first login.
    </div>
  </div>
  <div class="footer">© {{ date('Y') }} Mkhaya Hub — This is an automated message.</div>
</div>
</body>
</html>
