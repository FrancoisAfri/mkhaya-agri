<?php

namespace App\Modules\HR\Recruitment\Actions\Cv;

use App\Modules\HR\Recruitment\Models\AiProfile;
use App\Modules\HR\Recruitment\Models\Candidate;
use Illuminate\Support\Facades\DB;

class UpdateCandidateProfileFromCvAction
{
    public function execute(Candidate $candidate, array $parsed, string $cvText, float $confidenceScore): void
    {
        DB::transaction(function () use ($candidate, $parsed, $cvText, $confidenceScore) {
            // Update AiProfile with parsed data
            AiProfile::updateOrCreate(
                ['candidate_id' => $candidate->id],
                [
                    'cv_text'               => $cvText,
                    'parsed_skills'         => $parsed['skills'] ?? [],
                    'parsed_experience'     => $parsed['experience'] ?? [],
                    'parsed_qualifications' => $parsed['qualifications'] ?? [],
                    'confidence_score'      => $confidenceScore,
                    'parse_status'          => 'completed',
                    'parse_error'           => null,
                    'generated_at'          => now(),
                    'ai_model_used'         => 'recruitment-ai-cv-parser',
                ]
            );

            // Upsert skills — delete ai-extracted ones and re-insert from CV parse
            $candidate->skills()->where('ai_extracted', true)->delete();
            foreach ($parsed['skills'] ?? [] as $s) {
                $candidate->skills()->create([
                    'skill'             => $s['skill'],
                    'proficiency_level' => $s['proficiency_level'] ?? null,
                    'years_experience'  => $s['years_experience'] ?? null,
                    'ai_extracted'      => true,
                ]);
            }

            // Upsert experience
            $candidate->experience()->where('ai_extracted', true)->delete();
            foreach ($parsed['experience'] ?? [] as $e) {
                $candidate->experience()->create([
                    'company_name' => $e['company_name'],
                    'job_title'    => $e['job_title'],
                    'start_date'   => $e['start_date'] ?? null,
                    'end_date'     => $e['end_date'] ?? null,
                    'is_current'   => (bool) ($e['is_current'] ?? false),
                    'description'  => $e['description'] ?? null,
                    'ai_extracted' => true,
                ]);
            }

            // Upsert qualifications
            $candidate->qualifications()->where('ai_extracted', true)->delete();
            foreach ($parsed['qualifications'] ?? [] as $q) {
                $candidate->qualifications()->create([
                    'institution'        => $q['institution'],
                    'qualification_type' => $q['qualification_type'] ?? 'other',
                    'field_of_study'     => $q['field_of_study'] ?? null,
                    'year_completed'     => $q['year_completed'] ?? null,
                    'ai_extracted'       => true,
                ]);
            }

            // Update candidate summary from parsed data if not already set manually
            if (empty($candidate->getOriginal('ai_summary')) && !empty($parsed['summary'])) {
                $candidate->update(['ai_summary' => $parsed['summary']]);
            }
        });
    }
}
