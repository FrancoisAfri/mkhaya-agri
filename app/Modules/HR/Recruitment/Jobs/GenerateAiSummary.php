<?php

namespace App\Modules\HR\Recruitment\Jobs;

use App\Modules\HR\Recruitment\AI\RecruitmentAI;
use App\Modules\HR\Recruitment\Models\AiProfile;
use App\Modules\HR\Recruitment\Models\Candidate;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerateAiSummary implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int  $tries       = 3;
    public int  $timeout     = 60;


    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function __construct(
        public readonly Candidate $candidate,
        public readonly array     $jobDescription = [],
    ) {
        $this->afterCommit();
    }

    public function handle(RecruitmentAI $ai): void
    {
        $candidate = $this->candidate->load(['skills', 'experience', 'qualifications']);

        if (!$candidate->hasAiConsent()) {
            Log::warning("GenerateAiSummary skipped: candidate #{$candidate->id} has not given AI consent.");
            return;
        }

        $profile = [
            'resume'          => ['text' => ''],
            'skills'          => $candidate->skills->pluck('skill')->toArray(),
            'experience_years'=> $candidate->experience->sum('duration_months') / 12,
            'qualifications'  => $candidate->qualifications->pluck('qualification_type')->toArray(),
        ];

        $jobDesc = !empty($this->jobDescription) ? $this->jobDescription : [
            'description' => '',
            'required_skills' => [],
        ];

        $result = $ai->scoreCandidateFit($profile, $jobDesc);

        if ($result->data['error'] ?? false) {
            Log::error("GenerateAiSummary AI error for candidate #{$candidate->id}: " . ($result->data['message'] ?? ''));
            return;
        }

        DB::transaction(function () use ($candidate, $result) {
            AiProfile::updateOrCreate(
                ['candidate_id' => $candidate->id],
                [
                    'summary'       => $result->data['justification'] ?? null,
                    'strengths'     => $result->data['strengths'] ?? [],
                    'weaknesses'    => $result->data['development_areas'] ?? [],
                    'flags'         => $result->data['estimated_retention_risk'] !== 'low'
                                        ? ['retention_risk' => $result->data['estimated_retention_risk']]
                                        : [],
                    'keywords'      => [],
                    'generated_at'  => now(),
                    'ai_model_used' => 'recruitment-ai',
                ],
            );

            // Cache the summary text on the candidate for full-text search
            $candidate->update(['ai_summary' => $result->data['justification'] ?? null]);
        });
    }

    public function failed(\Throwable $e): void
    {
        Log::error("GenerateAiSummary failed for candidate #{$this->candidate->id}: {$e->getMessage()}");
    }
}
