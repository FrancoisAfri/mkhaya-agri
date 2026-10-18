<?php

namespace App\Modules\HR\PreBoarding\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HrPersonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $daysUntilStart = $this->started_on
            ? (int) now()->startOfDay()->diffInDays($this->started_on->copy()->startOfDay(), false)
            : null;

        $activePlan       = $this->relationLoaded('activePlan') ? $this->activePlan : null;
        $pendingTaskCount = $activePlan
            ? $activePlan->tasks->where('status', 'pending')->count()
            : null;

        return [
            'id'              => $this->id,
            'uuid'            => $this->uuid,
            'employee_number' => $this->employee_number,
            'started_on'      => $this->started_on?->toDateString(),
            'department'      => $this->department,
            'job_title'       => $this->job_title,
            'days_until_start' => $daysUntilStart,
            'pending_tasks'    => $pendingTaskCount,
            'status'          => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
                'color' => $this->status->color(),
            ],
            // Always present, whichever kind of person this is. whenLoaded() only says the
            // relation was eager-loaded, not that there is one to read — with a null candidate_id
            // it resolves to null and dereferencing it was a fatal.
            'full_name' => $this->fullName(),
            'email'     => $this->emailAddress(),
            'standalone' => $this->isStandalone(),
            'candidate' => $this->whenLoaded('candidate', fn() => $this->candidate ? [
                'id'        => $this->candidate->id,
                'full_name' => $this->candidate->full_name,
                'email'     => $this->candidate->email,
            ] : null),
            'buddy' => $this->whenLoaded('buddy', fn() => $this->buddy
                ? ['id' => $this->buddy->id, 'name' => $this->buddy->name]
                : null
            ),
            'active_plan'        => new PreBoardingPlanResource($this->whenLoaded('activePlan')),
            'pre_boarding_plans' => PreBoardingPlanResource::collection($this->whenLoaded('preBoardingPlans')),
            'onboarding_plans'   => OnboardingPlanResource::collection($this->whenLoaded('onboardingPlans')),
            'created_at'         => $this->created_at->toISOString(),
        ];
    }
}
