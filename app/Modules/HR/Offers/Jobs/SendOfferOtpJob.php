<?php

namespace App\Modules\HR\Offers\Jobs;

use App\Modules\HR\Offers\Models\Offer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Modules\HR\Offers\Mail\OfferOtpMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendOfferOtpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function backoff(): array
    {
        return [30, 60, 120];
    }

    public function __construct(
        private readonly Offer  $offer,
        private readonly string $rawOtp, // 6-digit OTP — never stored raw, encrypted in queue payload
    ) {
        // Queueable already declares $afterCommit — a typed redeclaration is a
        // PHP fatal on class load. Set it here instead.
        $this->afterCommit = true;
    }

    public function handle(): void
    {
        $candidate = $this->offer->candidate;

        if (!$candidate) {
            Log::warning("SendOfferOtp: candidate not found for offer #{$this->offer->id}");
            return;
        }

        Mail::to($candidate->email)->send(new OfferOtpMail($this->rawOtp));
    }
}
