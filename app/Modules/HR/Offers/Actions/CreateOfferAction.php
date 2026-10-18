<?php

namespace App\Modules\HR\Offers\Actions;

use App\Modules\HR\Offers\Models\Offer;
use App\Modules\HR\Recruitment\Enums\OfferStatus;
use App\Modules\HR\Recruitment\Models\Application;
use App\Modules\Policies\Traits\PolicyValidationTrait;
use Illuminate\Support\Facades\DB;

class CreateOfferAction
{
    use PolicyValidationTrait;

    public function execute(Application $application, array $terms, int $createdBy): Offer
    {
        $this->validatePolicyAction(
            module: 'Offers',
            action: 'offer.created',
            payload: [
                'application_id' => $application->id,
                'job_id'         => $application->job_id,
                'candidate_id'   => $application->candidate_id,
                'created_by'     => $createdBy,
            ],
            userId: $createdBy,
        );

        return DB::transaction(function () use ($application, $terms, $createdBy) {
            $offer = Offer::create([
                'application_id'  => $application->id,
                'job_id'          => $application->job_id,
                'candidate_id'    => $application->candidate_id,
                'created_by'      => $createdBy,
                'position_title'  => $terms['position_title'],
                'department'      => $terms['department'] ?? null,
                'start_date'      => $terms['start_date'],
                'salary_amount'   => $terms['salary_amount'],
                'salary_currency' => $terms['salary_currency'] ?? 'ZAR',
                'contract_type'   => $terms['contract_type'] ?? 'permanent',
                'notes'           => $terms['notes'] ?? null,
            ]);

            // Same reason as above: `status` is guarded, so `update()` would drop it. Draft is the
            // column default, so this was a no-op rather than a fault — but it read as intent.
            $offer->status = OfferStatus::Draft;
            $offer->save();

            $this->dispatchPolicyEvent(
                module:      'Offers',
                action:      'offer.created',
                subjectId:   $offer->id,
                subjectType: Offer::class,
                payload:     ['application_id' => $application->id, 'created_by' => $createdBy],
                userId:      $createdBy,
            );

            return $offer;
        });
    }
}
