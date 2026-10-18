<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Policy Report</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 0; }
        .wrapper { max-width: 600px; margin: 30px auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,.12); }
        .header { background: linear-gradient(135deg, #005f56 0%, #0b8b78 100%); padding: 32px 36px; }
        .header h1 { color: #fff; margin: 0; font-size: 22px; }
        .header p { color: rgba(255,255,255,.85); margin: 6px 0 0; font-size: 13px; }
        .body { padding: 32px 36px; }
        .body p { color: #444; line-height: 1.6; margin: 0 0 14px; }
        .badge { display: inline-block; background: #e8f5f3; color: #005f56; border-radius: 4px; padding: 4px 10px; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 20px; }
        .footer { background: #f9f9f9; border-top: 1px solid #eee; padding: 18px 36px; font-size: 12px; color: #999; text-align: center; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="header">
            <h1>{{ $reportName }}</h1>
            <p>Mkhaya Agri — Automated Policy Report</p>
        </div>
        <div class="body">
            <span class="badge">{{ ucfirst($cadence) }} Report</span>
            <p>Please find the <strong>{{ $reportTitle }}</strong> attached to this email.</p>
            <p>This report was generated automatically on <strong>{{ now()->format('d F Y \a\t H:i') }}</strong> as part of your scheduled policy reporting configuration.</p>
            <p>If you have any questions or believe you received this email in error, please contact your system administrator.</p>
        </div>
        <div class="footer">
            &copy; {{ date('Y') }} Mkhaya Agri. This is an automated message — please do not reply directly to this email.
        </div>
    </div>
</body>
</html>
