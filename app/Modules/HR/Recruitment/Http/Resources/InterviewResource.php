<?php

namespace App\Modules\HR\Recruitment\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InterviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'type'             => $this->type->value,
            'type_label'       => $this->type->label(),
            'status'           => $this->status,
            'scheduled_at'     => $this->scheduled_at->toIso8601String(),
            'duration_minutes' => $this->duration_minutes,
            'location'         => $this->location,
            'meeting_url'      => $this->meeting_url,
            'notes'            => $this->notes,
            'interviewer'      => $this->whenLoaded('interviewer', fn() => [
                'id'   => $this->interviewer->id,
                'name' => $this->interviewer->name,
            ]),
            'questions' => $this->whenLoaded('questions', fn() =>
                $this->questions->map(fn($q) => [
                    'id'           => $q->id,
                    'question'     => $q->question,
                    'category'     => $q->category,
                    'ai_generated' => $q->ai_generated,
                    'order'        => $q->order_index,
                ])
            ),
            'score' => $this->whenLoaded('score', fn() => $this->score ? [
                'overall_score'      => $this->score->overall_score,
                'technical_score'    => $this->score->technical_score,
                'communication_score'=> $this->score->communication_score,
                'culture_fit_score'  => $this->score->culture_fit_score,
                'recommendation'     => $this->score->recommendation,
                'comments'           => $this->score->comments,
            ] : null),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
