<?php

namespace App\Modules\HR\Offers\Actions;

use App\Modules\HR\Offers\Models\Offer;
use App\Modules\HR\Recruitment\Enums\OfferStatus;
use App\Modules\Policies\Traits\PolicyValidationTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeclineOfferAction
{
    use PolicyValidationTrait;

    public function execute(Offer $offer, string $reason): Offer
    {
        if ($offer->status !== OfferStatus::Sent) {
            throw ValidationException::withMessages([
                'status' => "Only sent offers can be declined.",
            ]);
        }

        return DB::transaction(function () use ($offer, $reason) {
            $offer->status         = OfferStatus::Declined->value;
            $offer->declined_at    = now();
            $offer->decline_reason = $reason;
            $offer->save();

            $this->dispatchPolicyEvent(
                module:      'Offers',
                action:      'offer.declined',
                subjectId:   $offer->id,
                subjectType: Offer::class,
                payload:     ['candidate_id' => $offer->candidate_id, 'reason' => $reason],
                userId:      null,
            );

            return $offer->fresh();
        });
    }
}
