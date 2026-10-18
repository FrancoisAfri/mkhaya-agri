<?php

namespace App\Modules\HR\Contracts\Jobs;

use App\Modules\HR\Contracts\Models\Contract;
use App\Modules\HR\Contracts\Models\SignatureRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendContractNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    /**
     * @param  array<int, string>  $rawTokens  Map of signature_request_id => raw_token
     */
    public function __construct(
        private readonly Contract $contract,
        private readonly array    $rawTokens,
    ) {
        // Queueable already declares $afterCommit — a typed redeclaration is a
        // PHP fatal on class load. Set it here instead.
        $this->afterCommit = true;
    }

    public function handle(): void
    {
        $signatories = SignatureRequest::with(['user', 'candidate'])
            ->where('contract_id', $this->contract->id)
            ->whereIn('id', array_keys($this->rawTokens))
            ->get();

        foreach ($signatories as $sigRequest) {
            $rawToken = $this->rawTokens[$sigRequest->id] ?? null;

            if (!$rawToken) {
                continue;
            }

            $portalUrl = route('recruitment.contracts.sign.view', [
                'signatureRequestUuid' => $sigRequest->uuid,
                'token'               => $rawToken,
            ]);

            $recipient = $sigRequest->candidate
                ? $sigRequest->candidate->email
                : $sigRequest->user?->email;

            Mail::to($recipient)->send(
                new \App\Modules\HR\Contracts\Mail\ContractSigningMail(
                    $this->contract,
                    $portalUrl,
                    $sigRequest->signatory_type,
                )
            );
        }
    }
}
