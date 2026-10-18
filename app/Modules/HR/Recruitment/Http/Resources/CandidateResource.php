<?php

namespace App\Modules\HR\Recruitment\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CandidateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'uuid'        => $this->uuid, // used in candidate-portal routes
            'full_name'   => $this->full_name,
            'first_name'  => $this->first_name,
            'last_name'   => $this->last_name,
            'email'       => $this->email,
            'phone'       => $this->phone,
            'nationality' => $this->nationality,
            'gender'      => $this->gender,
            'linkedin_url'=> $this->linkedin_url,
            'cv_uploaded' => $this->cv_path !== null,
            'cv_verified' => $this->cv_verified_at !== null,
            'has_ai_consent' => $this->ai_consent_at !== null,
            'ai_consent_at'  => $this->ai_consent_at?->toIso8601String(),
            'is_duplicate'=> $this->is_duplicate_of !== null,
            'ai_profile'  => $this->whenLoaded('aiProfile', fn() => [
                'summary'   => $this->aiProfile->summary,
                'strengths' => $this->aiProfile->strengths,
                'flags'     => $this->aiProfile->flags,
            ]),
            'skills'         => $this->whenLoaded('skills', fn() =>
                $this->skills->map(fn($s) => [
                    'skill'       => $s->skill,
                    'proficiency' => $s->proficiency_level,
                    'years'       => $s->years_experience,
                ])
            ),
            'applications_count' => $this->whenCounted('applications'),
            'created_at'         => $this->created_at?->toIso8601String(),
        ];
    }
}
