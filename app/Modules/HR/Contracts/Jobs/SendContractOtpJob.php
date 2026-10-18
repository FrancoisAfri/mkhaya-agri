<?php

namespace App\Modules\HR\Contracts\Jobs;

use App\Modules\HR\Contracts\Models\SignatureRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendContractOtpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function backoff(): array
    {
        return [30, 60, 120];
    }

    public function __construct(
        private readonly SignatureRequest $sigRequest,
        private readonly string          $rawOtp,
    ) {
        // Queueable already declares $afterCommit — a typed redeclaration is a
        // PHP fatal on class load. Set it here instead.
        $this->afterCommit = true;
    }

    public function handle(): void
    {
        $recipient = $this->sigRequest->candidate?->email
            ?? $this->sigRequest->user?->email;

        if (!$recipient) {
            Log::warning("SendContractOtp: no recipient for signature_request #{$this->sigRequest->id}");
            return;
        }

        Mail::to($recipient)->send(new \App\Modules\HR\Contracts\Mail\ContractOtpMail($this->rawOtp));
    }
}
