<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Login OTP</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background: linear-gradient(to right, #09084a, #7a39a8);
            color: white;
            padding: 20px;
            text-align: center;
            border-radius: 5px 5px 0 0;
        }
        .content {
            background: #f9f9f9;
            padding: 30px;
            border-radius: 0 0 5px 5px;
        }
        .otp-code {
            background: #fff;
            border: 2px solid #09084a;
            padding: 15spark;
            font-size: 28px;
            font-weight: bold;
            text-align: center;
            letter-spacing: 5px;
            color: #09084a;
            margin: 20px 0;
            border-radius: 5px;
        }
        .warning {
            background: #fff3cd;
            border-left: 4px solid #ffd700;
            padding: 10px;
            margin: 20px 0;
        }
        .footer {
            text-align: center;
            color: #666;
            font-size: 12px;
            margin-top: 30px;
        }
    </style>
</head>
<body>
<div class="header">
    <h1>MkhayaHub</h1>
    <p>Your Login OTP</p>
</div>

<div class="content">
    <p>Hello {{ $userName }},</p>

    <p>You have requested to login using OTP. Please use the following code to complete your login:</p>

    <div class="otp-code">
        {{ $otp }}
    </div>

    <div class="warning">
        <strong>Important:</strong>
        <ul style="margin: 5px 0;">
            <li>This OTP is valid for 5 minutes only</li>
            <li>Do not share this code with anyone</li>
            <li>If you didn't request this OTP, please ignore this email</li>
        </ul>
    </div>

    <p>For security reasons, this OTP will expire after one use or after 5 minutes, whichever comes first.</p>
</div>

<div class="footer">
    <p>&copy; {{ date('Y') }} MkhayaHub. All rights reserved.</p>
    <p>This is an automated message, please do not reply.</p>
</div>
</body>
</html>
