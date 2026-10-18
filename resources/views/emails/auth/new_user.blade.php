<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Account Activated</title>

    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            font-size: 16px;
            line-height: 1.5;
            color: #374151;
            background-color: #f9fafb;
        }

        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 40px 20px;
        }

        .email-wrapper {
            background-color: #ffffff;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .header {
            text-align: center;
            padding: 40px 40px 20px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }

        .logo {
            max-height: 60px;
            margin-bottom: 20px;
        }

        .header h1 {
            color: #ffffff;
            font-size: 28px;
            font-weight: 600;
            margin: 0;
        }

        .success-icon {
            width: 60px;
            height: 60px;
            background-color: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 30px;
        }

        .content {
            padding: 40px;
        }

        .greeting {
            font-size: 18px;
            font-weight: 500;
            color: #111827;
            margin-bottom: 20px;
        }

        .message {
            margin-bottom: 20px;
            color: #6b7280;
            line-height: 1.6;
        }

        .credentials-box {
            background-color: #f3f4f6;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            margin: 25px 0;
        }

        .credentials-box h3 {
            color: #374151;
            font-size: 16px;
            font-weight: 600;
            margin: 0 0 15px 0;
        }

        .credential-item {
            margin-bottom: 10px;
        }

        .credential-label {
            font-weight: 500;
            color: #374151;
            display: inline-block;
            width: 80px;
        }

        .credential-value {
            font-family: 'Courier New', monospace;
            background-color: #ffffff;
            padding: 4px 8px;
            border-radius: 4px;
            border: 1px solid #d1d5db;
            color: #111827;
        }

        .security-warning {
            background-color: #fef3cd;
            border-left: 4px solid #f59e0b;
            border-radius: 8px;
            padding: 20px;
            margin: 25px 0;
        }

        .security-warning h4 {
            color: #92400e;
            font-size: 16px;
            font-weight: 600;
            margin: 0 0 10px 0;
            display: flex;
            align-items: center;
        }

        .security-warning p {
            margin: 8px 0;
            color: #92400e;
            font-size: 14px;
        }

        .warning-icon {
            margin-right: 8px;
            font-size: 18px;
        }

        .cta-button {
            display: inline-block;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: #ffffff;
            text-decoration: none;
            padding: 16px 32px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 16px;
            margin: 20px 0;
            transition: transform 0.2s;
        }

        .cta-button:hover {
            transform: translateY(-1px);
        }

        .secondary-button {
            display: inline-block;
            background-color: #ffffff;
            color: #374151;
            border: 2px solid #d1d5db;
            text-decoration: none;
            padding: 14px 30px;
            border-radius: 8px;
            font-weight: 500;
            font-size: 16px;
            margin: 10px 10px 10px 0;
            transition: all 0.2s;
        }

        .secondary-button:hover {
            border-color: #10b981;
            color: #10b981;
        }

        .footer {
            padding: 30px 40px;
            background-color: #f9fafb;
            border-top: 1px solid #e5e7eb;
            text-align: center;
        }

        .footer p {
            margin: 8px 0;
            font-size: 13px;
            color: #9ca3af;
        }

        .footer a {
            color: #10b981;
            text-decoration: none;
        }

        .button-group {
            text-align: center;
            margin: 30px 0;
        }

        @media only screen and (max-width: 600px) {
            .container {
                padding: 20px 10px;
            }

            .content, .header, .footer {
                padding: 30px 20px;
            }

            .header h1 {
                font-size: 24px;
            }

            .cta-button, .secondary-button {
                display: block;
                text-align: center;
                width: calc(100% - 64px);
                margin: 10px 0;
            }

            .credential-label {
                width: 100%;
                display: block;
                margin-bottom: 5px;
            }
        }
    </style>
</head>

<body>
<div class="container">
    <div class="email-wrapper">
        <!-- Header -->
        <div class="header">
            <img src="{{ $logo ?? '' }}" alt="{{ $company_name ?? 'Company' }} Logo" style="max-height: 60px;">
            <div class="success-icon">✓</div>
            <h1>Account Activated!</h1>
        </div>

        <!-- Main Content -->
        <div class="content">
            <div class="greeting">
                Welcome, {{ $mailData['name'] }}!
            </div>

            <p class="message">
                Great news! Your account has been successfully activated and is ready to use. You can now log in with the credentials provided below.
            </p>

            <div class="credentials-box">
                <h3>Your Login Credentials</h3>
                <div class="credential-item">
                    <span class="credential-label">Email:</span>
                    <span class="credential-value">{{ $mailData['email'] }}</span>
                </div>
                <div class="credential-item">
                    <span class="credential-label">Password:</span>
                    <span class="credential-value">{{ $mailData['password'] }}</span>
                </div>
            </div>

            <div class="security-warning">
                <h4><span class="warning-icon">⚠️</span>Important Security Notice</h4>
                <p><strong>For your account security, we strongly recommend changing your password immediately after your first login.</strong></p>
                <p>This temporary password was generated automatically and should be replaced with a strong, unique password that only you know.</p>
            </div>

            <div class="button-group">
                <a href="{{ $mailData['login_url'] }}" class="cta-button">Login to Your Account</a>
                <a href="{{ $mailData['change_password_url'] }}" class="secondary-button">Change Password</a>
            </div>

            <p class="message">
                <strong>Tips for creating a strong password:</strong>
            </p>
            <ul style="color: #6b7280; margin: 10px 0; padding-left: 20px;">
                <li>Use at least 8 characters</li>
                <li>Include uppercase and lowercase letters</li>
                <li>Add numbers and special characters</li>
                <li>Avoid using personal information</li>
            </ul>

            <p class="message" style="margin-top: 30px;">
                If you didn't request this account or have any concerns, please contact our support team immediately.
            </p>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p><strong>Need help?</strong> Contact our support team at <a href="mailto:{{ $mailData['support_email'] }}">{{ $mailData['support_email'] }}</a></p>
            <p>© {{ date('Y') }} {{ $mailData['company_name'] }}. All rights reserved.</p>
            <p>
                For security tips and account management, visit our
{{--                <a href="{{ $mailData['help_center_url'] }}">Help Center</a>--}}
            </p>
        </div>
    </div>
</div>
</body>
</html>
