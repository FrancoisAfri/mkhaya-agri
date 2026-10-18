<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 0; }
        .wrapper { max-width: 600px; margin: 40px auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .header { background: #1976D2; color: #fff; padding: 28px 32px; }
        .header h1 { margin: 0; font-size: 22px; }
        .body { padding: 32px; color: #333; font-size: 15px; line-height: 1.7; }
        .alert-box { background: #FFF3E0; border-left: 4px solid #FF6F00; border-radius: 4px; padding: 16px 20px; margin: 20px 0; }
        .footer { padding: 20px 32px; background: #f9f9f9; color: #888; font-size: 12px; text-align: center; }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="header">
        <h1>Absence Notification</h1>
    </div>
    <div class="body">
        <p>Dear <strong>{{ $employeeName }}</strong>,</p>
        <p>Our records show that you were not present at work on <strong>{{ $date }}</strong> and no leave application was found for this date.</p>
        <div class="alert-box">
            <strong>Action required:</strong> Please log in to the HR portal and submit a leave application for your absence. If this absence is not resolved within the configured period, the system may apply leave automatically on your behalf.
        </div>
        <p>If you believe this is an error, or if you clocked in via a device that may not have synced, please contact your HR administrator.</p>
        <p>Thank you,<br><strong>HR System</strong></p>
    </div>
    <div class="footer">
        This is an automated notification. Please do not reply to this email.
    </div>
</div>
</body>
</html>
