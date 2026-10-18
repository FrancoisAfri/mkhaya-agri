<?php

namespace App\Modules\HR\Recruitment\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'status'         => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'source'         => $this->source,
            'applied_at'     => $this->applied_at->toIso8601String(),
            'stage_moved_at' => $this->stage_moved_at?->toIso8601String(),
            'rejected_at'    => $this->rejected_at?->toIso8601String(),
            'rejected_reason'=> $this->rejected_reason,
            'withdrawn_at'   => $this->withdrawn_at?->toIso8601String(),
            'created_at'     => $this->created_at->toIso8601String(),
            'job'            => $this->whenLoaded('job', fn() => [
                'id'    => $this->job->id,
                'title' => $this->job->title,
            ]),
            'candidate'      => $this->whenLoaded('candidate', fn() => new CandidateResource($this->candidate)),
            'current_stage'  => $this->whenLoaded('pipelineStage', fn() => $this->pipelineStage ? [
                'id'   => $this->pipelineStage->id,
                'name' => $this->pipelineStage->name,
                'type' => $this->pipelineStage->type->value,
            ] : null),
            'recruiter' => $this->whenLoaded('recruiter', fn() => [
                'id'   => $this->recruiter->id,
                'name' => $this->recruiter->name,
            ]),
            'fit_score' => $this->whenLoaded('fitScore', fn() => $this->fitScore ? [
                'overall'       => $this->fitScore->overall_score,
                'skills'        => $this->fitScore->skills_score,
                'experience'    => $this->fitScore->experience_score,
                'qualification' => $this->fitScore->qualification_score,
                'cultural'      => $this->fitScore->cultural_score,
                'calculated_at' => $this->fitScore->calculated_at?->toIso8601String(),
            ] : null),
            'interviews_count' => $this->whenCounted('interviews'),
        ];
    }
}
