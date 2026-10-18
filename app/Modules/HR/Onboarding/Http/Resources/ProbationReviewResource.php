<?php

namespace App\Modules\HR\Onboarding\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProbationReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'review_date'          => $this->review_date?->toDateString(),
            'review_type'          => $this->review_type,
            'performance_score'    => $this->performance_score,
            'manager_feedback'     => $this->manager_feedback,
            'employee_feedback'    => $this->employee_feedback,
            'areas_of_improvement' => $this->areas_of_improvement,
            'recommendation'       => $this->recommendation,
            'completed_at'         => $this->completed_at?->toISOString(),
            'created_at'           => $this->created_at->toISOString(),
        ];
    }
}
