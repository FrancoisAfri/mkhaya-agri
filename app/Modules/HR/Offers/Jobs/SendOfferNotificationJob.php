<?php

namespace App\Modules\HR\Offers\Jobs;

use App\Modules\HR\Offers\Models\Offer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendOfferNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function __construct(
        private readonly Offer  $offer,
        private readonly string $rawToken, // never persisted — only in this job payload (encrypted by queue)
    ) {
        // Queueable already declares $afterCommit — redeclaring it (typed or with
        // a different default) is a PHP fatal on class load, killing every offer
        // notification. Set it here instead.
        $this->afterCommit = true;
    }

    public function handle(): void
    {
        $candidate = $this->offer->candidate;

        if (!$candidate) {
            Log::warning("SendOfferNotification: candidate not found for offer #{$this->offer->id}");
            return;
        }

        // Build the candidate portal URL with the raw token
        $portalUrl = route('recruitment.offers.portal.view', [
            'offerUuid' => $this->offer->uuid,
            'token'     => $this->rawToken,
        ]);

        Mail::to($candidate->email)->send(new \App\Modules\HR\Offers\Mail\OfferLetterMail($this->offer, $portalUrl));
    }
}
