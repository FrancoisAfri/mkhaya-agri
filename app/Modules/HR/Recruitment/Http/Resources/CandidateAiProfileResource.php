<?php

namespace App\Modules\HR\Recruitment\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CandidateAiProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'summary'               => $this->summary,
            'parse_status'          => $this->parse_status,
            'confidence_score'      => $this->confidence_score,
            'strengths'             => $this->strengths ?? [],
            'weaknesses'            => $this->weaknesses ?? [],
            'flags'                 => $this->flags ?? [],
            'keywords'              => $this->keywords ?? [],
            'parsed_skills'         => $this->parsed_skills ?? [],
            'parsed_experience'     => $this->parsed_experience ?? [],
            'parsed_qualifications' => $this->parsed_qualifications ?? [],
            'generated_at'          => $this->generated_at?->toIso8601String(),
            'ai_model_used'         => $this->ai_model_used,
        ];
    }
}
