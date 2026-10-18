<?php

namespace App\Modules\ContractManagement\Jobs;

use App\Core\User\Models\User;
use App\Modules\ContractManagement\Models\Contract;
use App\Modules\ContractManagement\Services\ContractAiService;
use App\Shared\Notifications\GenericInAppNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Background AI analysis of an uploaded contract. Dispatched with
 * ->afterResponse() so large/bulk uploads are processed after the HTTP response
 * (no queue worker required); also implements ShouldQueue so it scales onto a
 * real worker if one is running. Notifies the contract owner when done.
 */
class ProcessContractAiAnalysis implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $contractId, public ?int $notifyUserId = null) {}

    public function handle(ContractAiService $ai): void
    {
        $contract = Contract::find($this->contractId);
        if (! $contract) {
            return;
        }

        $result = $ai->analyze($contract, $this->notifyUserId);

        $userId = $this->notifyUserId ?: $contract->owner_id;
        if (! $userId) {
            return;
        }

        $user = User::find($userId);
        if (! $user) {
            return;
        }

        if ($result->status === 'completed') {
            $user->notify(new GenericInAppNotification(
                'Contract analysis ready',
                "AI analysis of {$contract->contract_number} ({$contract->title}) is complete.",
                "/contracts/{$contract->id}",
                'contract_analyzed'
            ));
        } else {
            $user->notify(new GenericInAppNotification(
                'Contract analysis failed',
                "AI analysis of {$contract->contract_number} could not be completed.",
                "/contracts/{$contract->id}",
                'contract_analyze_failed'
            ));
        }
    }
}
