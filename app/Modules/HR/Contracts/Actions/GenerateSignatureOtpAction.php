<?php

namespace App\Modules\HR\Contracts\Actions;

use App\Modules\HR\Contracts\Jobs\SendContractOtpJob;
use App\Modules\HR\Contracts\Models\SignatureRequest;
use Illuminate\Validation\ValidationException;

class GenerateSignatureOtpAction
{
    public function execute(SignatureRequest $sigRequest): void
    {
        if ($sigRequest->isSigned()) {
            throw ValidationException::withMessages([
                'signature' => 'This document has already been signed.',
            ]);
        }

        if ($sigRequest->isOtpLocked()) {
            throw ValidationException::withMessages([
                'otp' => 'Too many failed attempts. This signature request is locked.',
            ]);
        }

        $rawOtp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $sigRequest->otp_hash       = hash('sha256', $rawOtp);
        $sigRequest->otp_expires_at = now()->addMinutes(15);
        $sigRequest->save();

        SendContractOtpJob::dispatch($sigRequest, $rawOtp)->onQueue('notifications');
    }
}
