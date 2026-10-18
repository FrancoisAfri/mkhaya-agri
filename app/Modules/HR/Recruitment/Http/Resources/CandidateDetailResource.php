<?php

namespace App\Modules\HR\Recruitment\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CandidateDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'uuid'           => $this->uuid,
            'full_name'      => $this->full_name,
            'first_name'     => $this->first_name,
            'last_name'      => $this->last_name,
            'email'          => $this->email,
            'phone'          => $this->phone,
            'linkedin_url'   => $this->linkedin_url,
            'is_duplicate'   => $this->is_duplicate_of !== null,
            'has_ai_consent' => $this->hasAiConsent(),
            'ai_consent_at'  => $this->ai_consent_at?->toIso8601String(),
            'ai_profile'     => $this->whenLoaded('aiProfile', fn() => new CandidateAiProfileResource($this->aiProfile)),
            'skills'         => $this->whenLoaded('skills', fn() => $this->skills->map(fn($s) => [
                'id'                => $s->id,
                'skill'             => $s->skill,
                'proficiency_level' => $s->proficiency_level,
                'years_experience'  => $s->years_experience,
                'ai_extracted'      => $s->ai_extracted,
            ])),
            'experience'     => $this->whenLoaded('experience', fn() => $this->experience->map(fn($e) => [
                'id'           => $e->id,
                'company_name' => $e->company_name,
                'job_title'    => $e->job_title,
                'start_date'   => $e->start_date?->format('Y-m'),
                'end_date'     => $e->end_date?->format('Y-m'),
                'is_current'   => $e->is_current,
                'description'  => $e->description,
                'ai_extracted' => $e->ai_extracted,
            ])),
            'qualifications' => $this->whenLoaded('qualifications', fn() => $this->qualifications->map(fn($q) => [
                'id'                 => $q->id,
                'institution'        => $q->institution,
                'qualification_type' => $q->qualification_type,
                'field_of_study'     => $q->field_of_study,
                'year_completed'     => $q->year_completed,
                'ai_extracted'       => $q->ai_extracted,
            ])),
            'documents'      => $this->whenLoaded('documents', fn() => $this->documents->map(fn($d) => [
                'id'            => $d->id,
                'type'          => $d->type,
                'original_name' => $d->original_name,
                'mime_type'     => $d->mime_type,
                'file_size'     => $d->file_size,
                'parse_status'  => $d->parse_status,
                'parsed_at'     => $d->parsed_at?->toIso8601String(),
                'is_clean'      => $d->isVirusClean(),
                'uploaded_at'   => $d->created_at->toIso8601String(),
            ])),
            'created_at'     => $this->created_at->toIso8601String(),
        ];
    }
}
