<?php

namespace App\Modules\HR\Recruitment\Listeners;

use App\Modules\HR\PreBoarding\Actions\ConvertCandidateToEmployeeAction;
use App\Modules\HR\Recruitment\Events\CandidateConverted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class TriggerOnboarding implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = 'default';

    public bool $afterCommit = true;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(private readonly ConvertCandidateToEmployeeAction $convertAction) {}

    public function handle(CandidateConverted $event): void
    {
        $contract  = $event->contract;
        $candidate = $contract->candidate;

        Log::info(
            "CandidateConverted: candidate #{$candidate?->id} (contract #{$contract->id}) " .
            "— creating HrPerson and pre-boarding plan."
        );

        // Pin the conversion to the contract's company: if this event is ever
        // fired from a console/backfill context (no tenant), the new HrPerson +
        // plan would otherwise be written with company_id = NULL.
        app(\App\Modules\Tenancy\Services\TenantContext::class)->run(
            $contract->company_id !== null ? (int) $contract->company_id : null,
            fn () => $this->convertAction->execute($contract, $contract->created_by),
        );
    }
}
