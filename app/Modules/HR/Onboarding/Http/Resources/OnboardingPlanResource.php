<?php

namespace App\Modules\HR\Onboarding\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OnboardingPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'uuid'       => $this->uuid,
            'status'     => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
                'color' => $this->status->color(),
            ],
            'start_date'  => $this->start_date?->toDateString(),
            'end_date'    => $this->end_date?->toDateString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'completion'   => $this->completionPercentage(),
            'hr_person'    => $this->whenLoaded('hrPerson', fn() => [
                'id'        => $this->hrPerson->id,
                'uuid'      => $this->hrPerson->uuid,
                // Read through the person, not the candidate: someone imported in bulk has no
                // candidate and would otherwise appear on every onboarding screen as a blank name.
                'candidate' => [
                    'full_name' => $this->hrPerson->fullName(),
                    'email'     => $this->hrPerson->emailAddress(),
                ],
            ]),
            // ->resolve() strips Laravel's "data" wrapper. Nested inside another resource the
            // collection serialises as {"data": [...]}, and Show.vue does plan.tasks.filter(...) —
            // which on an object is a TypeError, so the whole page died on every plan. Wrapping is
            // on globally, so this has to be undone here rather than switched off app-wide.
            // The closure form matters: on the index the relation is not eager-loaded, and
            // calling ->resolve() on the MissingValue that whenLoaded() returns blows up the whole
            // response. This way the key is simply absent when tasks were not loaded.
            'tasks'      => $this->whenLoaded(
                'tasks',
                fn () => OnboardingTaskResource::collection($this->tasks)->resolve()
            ),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
