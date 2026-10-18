<?php

namespace App\Modules\HR\Offers\Actions;

use App\Modules\HR\Offers\Jobs\SendOfferOtpJob;
use App\Modules\HR\Offers\Models\Offer;
use Illuminate\Validation\ValidationException;

class GenerateOfferOtpAction
{
    /**
     * Generate a 6-digit OTP for offer acceptance, store its SHA-256 hash,
     * and dispatch a notification job to deliver it to the candidate.
     */
    public function execute(Offer $offer): void
    {
        if ($offer->status !== \App\Modules\HR\Recruitment\Enums\OfferStatus::Sent) {
            throw ValidationException::withMessages([
                'offer' => 'OTP can only be requested for a sent offer.',
            ]);
        }

        if ($offer->isOtpLocked()) {
            throw ValidationException::withMessages([
                'otp' => 'Too many failed OTP attempts. This offer is locked.',
            ]);
        }

        $rawOtp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $offer->otp_hash       = hash('sha256', $rawOtp);
        $offer->otp_expires_at = now()->addMinutes(15);
        $offer->save();

        // Send raw OTP to candidate via email/SMS — never logged or stored raw
        SendOfferOtpJob::dispatch($offer, $rawOtp)->onQueue('notifications');
    }
}
