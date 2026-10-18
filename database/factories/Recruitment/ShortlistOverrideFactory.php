<?php

namespace Database\Factories\Recruitment;

use App\Core\User\Models\User;
use App\Modules\HR\Recruitment\Models\Candidate;
use App\Modules\HR\Recruitment\Models\Shortlist;
use App\Modules\HR\Recruitment\Models\ShortlistOverride;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShortlistOverrideFactory extends Factory
{
    protected $model = ShortlistOverride::class;

    public function definition(): array
    {
        return [
            'shortlist_id'    => Shortlist::factory(),
            'candidate_id'    => Candidate::factory(),
            'action'          => 'added',
            'override_reason' => fake()->sentence(10),
            'overridden_by'   => User::factory(),
            'approver_id'     => null,
            'approved_at'     => null,
        ];
    }
}
