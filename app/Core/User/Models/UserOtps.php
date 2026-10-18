<?php

namespace App\Core\User\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class UserOtps extends Model
{
    use LogsActivity;
    protected $fillable = [
        'email',
        'otp',
        'expires_at',
        'is_used'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'is_used' => 'boolean'
    ];

    /**
     * Generate OTP
     */
    public static function generateOTP($email)
    {
        // Delete old OTPs for this email
        self::where('email', $email)->delete();

        // Generate 6-digit OTP
        $otp = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

        // Create new OTP record
        return self::create([
            'email' => $email,
            'otp' => $otp,
            'expires_at' => Carbon::now()->addMinutes(5),
            'is_used' => false
        ]);
    }

    /**
     * Verify OTP
     */
    public static function verifyOTP($email, $otp): bool
    {
        $otpRecord = self::where('email', $email)
            ->where('otp', $otp)
            ->where('is_used', false)
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if ($otpRecord) {
            $otpRecord->update(['is_used' => true]);
            return true;
        }

        return false;
    }
public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('UserOtp')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->dontLogIfAttributesChangedOnly(['updated_at'])
            ->setDescriptionForEvent(fn (string $eventName) =>
                $this->getDescriptionForEvent($eventName)
            );
    }

    protected function getDescriptionForEvent(string $eventName): string
    {
        return match ($eventName) {
            'created' => "OTP generated for {$this->email}",
            'updated' => "OTP updated for {$this->email}",
            'deleted' => "OTP deleted for {$this->email}",
            default => "OTP {$eventName} event",
        };
    }
}
