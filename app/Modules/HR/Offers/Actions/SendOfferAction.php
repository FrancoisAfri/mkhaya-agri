<?php

namespace App\Modules\HR\Offers\Actions;

use App\Modules\HR\Offers\Jobs\SendOfferNotificationJob;
use App\Modules\HR\Offers\Models\Offer;
use App\Modules\HR\Recruitment\Enums\OfferStatus;
use App\Modules\Policies\Traits\PolicyValidationTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SendOfferAction
{
    use PolicyValidationTrait;

    public function execute(Offer $offer, int $senderId): Offer
    {
        if (!$offer->status->canTransitionTo(OfferStatus::Sent)) {
            throw ValidationException::withMessages([
                'status' => "Offer cannot be sent from status: {$offer->status->label()}.",
            ]);
        }

        $this->validatePolicyAction(
            module: 'Offers',
            action: 'offer.sent',
            payload: ['offer_id' => $offer->id, 'sender_id' => $senderId],
            userId:  $senderId,
        );

        return DB::transaction(function () use ($offer, $senderId) {
            // Generate a cryptographically secure token for the candidate portal link.
            // We store only the SHA-256 hash — the raw value goes into the email link only.
            $rawToken = Str::random(64);

            $offer->token_hash      = hash('sha256', $rawToken);
            $offer->token_expires_at = now()->addHours(48);
            $offer->status           = OfferStatus::Sent->value;
            $offer->sent_at          = now();
            $offer->expires_at       = now()->addHours(48);
            $offer->save();

            $this->dispatchPolicyEvent(
                module:      'Offers',
                action:      'offer.sent',
                subjectId:   $offer->id,
                subjectType: Offer::class,
                payload:     ['sender_id' => $senderId],
                userId:      $senderId,
            );

            // Dispatch notification after commit — carries the raw token, not the hash
            SendOfferNotificationJob::dispatch($offer, $rawToken)->onQueue('notifications');

            return $offer->fresh();
        });
    }
}
