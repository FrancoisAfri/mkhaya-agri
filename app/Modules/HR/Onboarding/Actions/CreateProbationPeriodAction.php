<?php

namespace App\Modules\HR\Onboarding\Actions;

use App\Modules\HR\Onboarding\Models\ProbationPeriod;
use App\Modules\HR\PreBoarding\Models\HrPerson;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class CreateProbationPeriodAction
{
    public function execute(HrPerson $hrPerson, array $data, int $createdBy): ProbationPeriod
    {
        if (ProbationPeriod::where('hr_person_id', $hrPerson->id)
            ->whereIn('status', ['active', 'extended'])
            ->exists()) {
            throw ValidationException::withMessages([
                'hr_person_id' => 'An active probation period already exists for this person.',
            ]);
        }

        $durationMonths = (int) ($data['duration_months'] ?? 3);
        $startDate      = Carbon::parse($data['start_date']);
        $endDate        = $startDate->copy()->addMonths($durationMonths);

        // Mid-point review date
        $halfMonths     = (int) ceil($durationMonths / 2);
        $nextReviewDate = $startDate->copy()->addMonths($halfMonths);

        return ProbationPeriod::create([
            'hr_person_id'    => $hrPerson->id,
            'start_date'      => $startDate->toDateString(),
            'end_date'        => $endDate->toDateString(),
            'duration_months' => $durationMonths,
            'next_review_date' => $nextReviewDate->toDateString(),
            'created_by'      => $createdBy,
        ]);
    }
}
