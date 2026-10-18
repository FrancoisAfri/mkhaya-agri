<?php

namespace App\Modules\HR\Offers\Actions;

use App\Modules\HR\Offers\Models\Offer;
use App\Modules\HR\Recruitment\Enums\OfferStatus;
use App\Modules\Policies\Traits\PolicyValidationTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WithdrawOfferAction
{
    use PolicyValidationTrait;

    public function execute(Offer $offer, int $userId, string $reason): Offer
    {
        if ($offer->status->isTerminal()) {
            throw ValidationException::withMessages([
                'status' => "Cannot withdraw an offer that is {$offer->status->label()}.",
            ]);
        }

        $this->validatePolicyAction(
            module: 'Offers',
            action: 'offer.withdrawn',
            payload: ['offer_id' => $offer->id, 'reason' => $reason],
            userId:  $userId,
        );

        return DB::transaction(function () use ($offer, $userId, $reason) {
            $offer->status         = OfferStatus::Withdrawn->value;
            $offer->withdrawn_at   = now();
            $offer->decline_reason = $reason;
            $offer->save();

            $this->dispatchPolicyEvent(
                module:      'Offers',
                action:      'offer.withdrawn',
                subjectId:   $offer->id,
                subjectType: Offer::class,
                payload:     ['reason' => $reason, 'withdrawn_by' => $userId],
                userId:      $userId,
            );

            return $offer->fresh();
        });
    }
}
