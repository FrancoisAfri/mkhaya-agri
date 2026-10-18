<?php

namespace App\Modules\HR\Contracts\Actions;

use App\Modules\HR\Contracts\Jobs\GenerateContractPdfJob;
use App\Modules\HR\Contracts\Models\Contract;
use App\Modules\HR\Offers\Models\Offer;
use App\Modules\HR\Recruitment\Enums\ContractStatus;
use App\Modules\Policies\Traits\PolicyValidationTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateContractAction
{
    use PolicyValidationTrait;

    public function execute(Offer $offer, int $createdBy, ?string $content = null): Contract
    {
        if (!$offer->status->isTerminal() === false) {
            // Offer must be accepted before contract can be created
        }

        if ($offer->status !== \App\Modules\HR\Recruitment\Enums\OfferStatus::Accepted) {
            throw ValidationException::withMessages([
                'offer' => 'A contract can only be created for an accepted offer.',
            ]);
        }

        if ($offer->contract()->exists()) {
            throw ValidationException::withMessages([
                'contract' => 'A contract already exists for this offer.',
            ]);
        }

        $this->validatePolicyAction(
            module: 'Contracts',
            action: 'contract.created',
            payload: [
                'offer_id'     => $offer->id,
                'candidate_id' => $offer->candidate_id,
                'created_by'   => $createdBy,
            ],
            userId: $createdBy,
        );

        return DB::transaction(function () use ($offer, $createdBy, $content) {
            $contract = Contract::create([
                'offer_id'       => $offer->id,
                'application_id' => $offer->application_id,
                'job_id'         => $offer->job_id,
                'candidate_id'   => $offer->candidate_id,
                'created_by'     => $createdBy,
            ]);

            $contract->status  = ContractStatus::Draft->value;
            $contract->content = $content;
            $contract->save();

            $this->dispatchPolicyEvent(
                module:      'Contracts',
                action:      'contract.created',
                subjectId:   $contract->id,
                subjectType: Contract::class,
                payload:     ['offer_id' => $offer->id],
                userId:      $createdBy,
            );

            // Queue PDF generation after commit
            GenerateContractPdfJob::dispatch($contract)->onQueue('documents');

            return $contract;
        });
    }
}
