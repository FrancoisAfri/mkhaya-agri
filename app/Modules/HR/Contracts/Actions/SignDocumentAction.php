<?php

namespace App\Modules\HR\Contracts\Actions;

use App\Modules\HR\Contracts\Models\Contract;
use App\Modules\HR\Contracts\Models\SignatureRequest;
use App\Modules\HR\Recruitment\Enums\ContractStatus;
use App\Modules\HR\Recruitment\Events\CandidateConverted;
use App\Modules\Policies\Traits\PolicyValidationTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

class SignDocumentAction
{
    use PolicyValidationTrait;

    /**
     * Record a signature on a specific signature request.
     * If all requests are now signed, mark the contract as fully signed
     * and dispatch CandidateConverted.
     */
    public function execute(
        SignatureRequest $signatureRequest,
        string          $rawOtp,
        Request         $request,
    ): SignatureRequest {
        if ($signatureRequest->isSigned()) {
            throw ValidationException::withMessages([
                'signature' => 'This document has already been signed.',
            ]);
        }

        if ($signatureRequest->isOtpLocked()) {
            throw ValidationException::withMessages([
                'otp' => 'Too many failed attempts. This signature request is locked.',
            ]);
        }

        if ($signatureRequest->contract->expires_at?->isPast()) {
            throw ValidationException::withMessages([
                'contract' => 'This contract signing window has expired.',
            ]);
        }

        if (!$signatureRequest->hasValidOtp($rawOtp)) {
            $attempts = $signatureRequest->otp_attempts + 1;
            $signatureRequest->otp_attempts = $attempts;

            if ($attempts >= 5) {
                $signatureRequest->otp_locked_at = now();
            }

            $signatureRequest->save();

            throw ValidationException::withMessages([
                'otp' => 'Invalid or expired OTP.',
            ]);
        }

        return DB::transaction(function () use ($signatureRequest, $request) {
            $signatureRequest->signed_at             = now();
            $signatureRequest->signature_ip          = $request->ip();
            $signatureRequest->signature_user_agent  = $request->userAgent();
            $signatureRequest->otp_hash              = null; // invalidate after use
            $signatureRequest->save();

            $contract = $signatureRequest->contract;

            $this->dispatchPolicyEvent(
                module:      'Contracts',
                action:      'document.signed',
                subjectId:   $signatureRequest->id,
                subjectType: SignatureRequest::class,
                payload:     [
                    'contract_id'    => $contract->id,
                    'signatory_type' => $signatureRequest->signatory_type,
                    'signature_ip'   => $signatureRequest->signature_ip,
                ],
                userId:      $signatureRequest->user_id,
            );

            // If all parties have signed, mark contract as fully signed
            if ($contract->isFullySigned()) {
                $contract->status          = ContractStatus::Signed->value;
                $contract->fully_signed_at = now();
                $contract->save();

                Event::dispatch(new CandidateConverted($contract));
            }

            return $signatureRequest->fresh();
        });
    }
}
