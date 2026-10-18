<?php

namespace Database\Factories\Contracts;

use App\Core\User\Models\User;
use App\Modules\HR\Contracts\Models\Contract;
use App\Modules\HR\Offers\Models\Offer;
use App\Modules\HR\Recruitment\Enums\ContractStatus;
use App\Modules\HR\Recruitment\Models\Application;
use App\Modules\HR\Recruitment\Models\Candidate;
use App\Modules\HR\Recruitment\Models\Job;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContractFactory extends Factory
{
    protected $model = Contract::class;

    public function definition(): array
    {
        $offer = Offer::factory()->accepted()->create();

        return [
            'offer_id'       => $offer->id,
            'application_id' => $offer->application_id,
            'job_id'         => $offer->job_id,
            'candidate_id'   => $offer->candidate_id,
            'created_by'     => $offer->created_by,
            'status'         => ContractStatus::Draft->value,
            'content'        => '<p>Standard employment contract.</p>',
        ];
    }

    public function sentForSigning(): static
    {
        return $this->state([
            'status'     => ContractStatus::SentForSigning->value,
            'sent_at'    => now(),
            'expires_at' => now()->addDays(7),
        ]);
    }

    public function signed(): static
    {
        return $this->state([
            'status'          => ContractStatus::Signed->value,
            'fully_signed_at' => now(),
        ]);
    }
}
