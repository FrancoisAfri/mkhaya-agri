<?php

namespace App\Modules\HR\Offers\Actions;

use App\Modules\HR\Offers\Models\Offer;
use App\Modules\HR\Recruitment\Enums\OfferStatus;
use App\Modules\HR\Recruitment\Events\OfferAccepted;
use App\Modules\Policies\Traits\PolicyValidationTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

class AcceptOfferAction
{
    use PolicyValidationTrait;

    /**
     * Accept a sent offer after OTP verification.
     * Called from the candidate-facing portal — userId is null (unauthenticated candidate).
     */
    public function execute(Offer $offer, string $rawOtp): Offer
    {
        if ($offer->status !== OfferStatus::Sent) {
            throw ValidationException::withMessages([
                'status' => "Only sent offers can be accepted.",
            ]);
        }

        if ($offer->isOtpLocked()) {
            throw ValidationException::withMessages([
                'otp' => 'Too many failed attempts. This offer is locked.',
            ]);
        }

        if ($offer->expires_at?->isPast()) {
            /*
             * Assigned, not mass-assigned.
             *
             * `status` is deliberately absent from Offer::$fillable — the model says so — and
             * `update()` silently drops what it cannot fill. So this line looked like it closed
             * the offer and did nothing: an expired offer stayed "Sent" for ever, sitting in the
             * pipeline as live work and still passing the status check at the top of this method
             * on every later attempt.
             */
            $offer->status = OfferStatus::Expired;
            $offer->save();
            throw ValidationException::withMessages([
                'offer' => 'This offer has expired.',
            ]);
        }

        if (!$offer->hasValidOtp($rawOtp)) {
            $attempts = $offer->otp_attempts + 1;

            $offer->otp_attempts = $attempts;
            if ($attempts >= 5) {
                $offer->otp_locked_at = now();
            }
            $offer->save();

            throw ValidationException::withMessages([
                'otp' => 'Invalid or expired OTP.',
            ]);
        }

        return DB::transaction(function () use ($offer) {
            // Row-lock + re-check: without this, two concurrent requests (double
            // submit, or accept racing an HR withdraw) could both pass the guard
            // and fire OfferAccepted twice → duplicate hire/onboarding.
            $offer = Offer::whereKey($offer->id)->lockForUpdate()->first();
            if ($offer->status !== OfferStatus::Sent) {
                throw ValidationException::withMessages([
                    'status' => 'This offer has already been actioned.',
                ]);
            }

            $offer->status      = OfferStatus::Accepted->value;
            $offer->accepted_at = now();
            $offer->otp_hash    = null; // invalidate OTP after use
            $offer->save();

            $this->dispatchPolicyEvent(
                module:      'Offers',
                action:      'offer.accepted',
                subjectId:   $offer->id,
                subjectType: Offer::class,
                payload:     ['candidate_id' => $offer->candidate_id],
                userId:      null,
            );

            Event::dispatch(new OfferAccepted($offer));

            return $offer->fresh();
        });
    }
}
