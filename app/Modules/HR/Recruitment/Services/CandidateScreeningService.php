<?php

namespace App\Modules\HR\Recruitment\Services;

use App\Modules\HR\Recruitment\AI\CandidateScreeningAI;
use App\Modules\HR\Recruitment\Enums\ScreeningStatus;
use App\Modules\HR\Recruitment\Models\Application;
use App\Modules\HR\Recruitment\Models\Candidate;
use App\Modules\HR\Recruitment\Models\CandidateScreening;
use App\Modules\HR\Recruitment\Models\CandidateScreeningFinding;
use App\Modules\HR\Recruitment\Models\CandidateScreeningQuestion;
use App\Modules\HR\Recruitment\Services\Screening\ProfileDiscoveryManager;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates one AI candidate screening: consent gate → discover public items →
 * AI analysis → persist screening, findings and neutral interview questions.
 * Fully defensive — the AI may return an empty structure, and no output is ever an
 * automated hire/reject decision.
 */
class CandidateScreeningService
{
    public function __construct(
        private readonly ProfileDiscoveryManager $discovery,
        private readonly CandidateScreeningAI $ai,
    ) {
    }

    /**
     * Run a screening for a candidate (optionally tied to an application).
     */
    public function screen(Candidate $candidate, ?Application $application = null): CandidateScreening
    {
        // POPIA gate — never analyse a candidate without explicit AI consent.
        if (! $candidate->hasAiConsent()) {
            return CandidateScreening::create([
                'candidate_id'   => $candidate->id,
                'application_id' => $application?->id,
                'status'         => ScreeningStatus::SkippedNoConsent->value,
                'ai_summary'     => 'Screening skipped: candidate has not granted AI processing consent.',
            ]);
        }

        $candidatePayload = [
            'full_name'    => $candidate->full_name,
            'headline'     => $candidate->ai_summary ?? null,
            'linkedin_url' => $candidate->linkedin_url ?? null,
            'location'     => $candidate->address ?? null,
        ];

        $publicItems = $this->discovery->gather($candidatePayload);

        $result = $this->ai->screenPublicProfile($candidatePayload, $publicItems);

        return DB::transaction(function () use ($candidate, $application, $result, $publicItems) {
            $screening = CandidateScreening::create([
                'candidate_id'               => $candidate->id,
                'application_id'             => $application?->id,
                'status'                     => ScreeningStatus::Completed->value,
                'overall_score'              => $this->num($result['overall_score'] ?? null),
                'reputation_score'           => $this->num($result['reputation_score'] ?? null),
                'professionalism_score'      => $this->num($result['professionalism_score'] ?? null),
                'communication_score'        => $this->num($result['communication_score'] ?? null),
                'leadership_score'           => $this->num($result['leadership_score'] ?? null),
                'community_score'            => $this->num($result['community_score'] ?? null),
                'risk_level'                 => $result['risk_level'] ?? 'low',
                'ai_summary'                 => $result['summary'] ?? '',
                'recommendation'             => $result['recommendation'] ?? '',
                'possible_misidentification' => (bool) ($result['possible_misidentification'] ?? false),
                'discovered_profiles'        => $publicItems,
                'analyzed_at'                => now(),
            ]);

            foreach (($result['findings'] ?? []) as $finding) {
                if (! is_array($finding) || empty($finding['title'])) {
                    continue;
                }
                CandidateScreeningFinding::create([
                    'screening_id'   => $screening->id,
                    'candidate_id'   => $candidate->id,
                    'type'           => $finding['type'] ?? 'context',
                    'category'       => $finding['category'] ?? 'general',
                    'severity'       => $finding['severity'] ?? 'info',
                    'confidence'     => $this->num($finding['confidence'] ?? null),
                    'source'         => $finding['source'] ?? null,
                    'title'          => (string) $finding['title'],
                    'excerpt'        => $finding['excerpt'] ?? null,
                    'evidence_url'   => $finding['evidence_url'] ?? null,
                    'ai_explanation' => $finding['explanation'] ?? null,
                    'detected_at'    => ! empty($finding['detected_at']) ? $finding['detected_at'] : null,
                ]);
            }

            foreach (($result['interview_questions'] ?? []) as $question) {
                if (! is_array($question) || empty($question['question'])) {
                    continue;
                }
                CandidateScreeningQuestion::create([
                    'screening_id' => $screening->id,
                    'question'     => (string) $question['question'],
                    'rationale'    => $question['rationale'] ?? null,
                    'based_on'     => $question['based_on'] ?? null,
                ]);
            }

            return $screening;
        });
    }

    /**
     * Coerce an AI-supplied value to a numeric score or null (never a fatal cast).
     */
    private function num(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
