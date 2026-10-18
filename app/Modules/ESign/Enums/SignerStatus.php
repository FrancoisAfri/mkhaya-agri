<?php

namespace App\Modules\ESign\Enums;

enum SignerStatus: string
{
    case Pending     = 'pending';
    case Notified    = 'notified';
    case Viewed      = 'viewed';
    case OtpSent     = 'otp_sent';
    case OtpVerified = 'otp_verified';
    case Signed      = 'signed';
    case Declined    = 'declined';

    public function label(): string
    {
        return match($this) {
            self::Pending     => 'Pending',
            self::Notified    => 'Notified',
            self::Viewed      => 'Viewed',
            self::OtpSent     => 'OTP Sent',
            self::OtpVerified => 'OTP Verified',
            self::Signed      => 'Signed',
            self::Declined    => 'Declined',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Pending     => 'default',
            self::Notified    => 'info',
            self::Viewed      => 'info',
            self::OtpSent     => 'warning',
            self::OtpVerified => 'warning',
            self::Signed      => 'success',
            self::Declined    => 'error',
        };
    }
}
