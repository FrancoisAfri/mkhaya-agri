<?php

namespace App\Modules\HR\Recruitment\Jobs;

use App\Modules\HR\Recruitment\AI\RecruitmentAI;
use App\Modules\HR\Recruitment\Models\Application;
use App\Modules\HR\Recruitment\Models\FitExplanation;
use App\Modules\HR\Recruitment\Models\FitScore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CalculateFitScore implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int  $tries       = 3;
    public int  $timeout     = 180;


    // Exponential backoff: 60s, 300s, 900s
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function __construct(public readonly Application $application) { $this->afterCommit(); }

    public function handle(RecruitmentAI $ai): void
    {
        $application = $this->application->load([
            'candidate.skills',
            'candidate.experience',
            'candidate.qualifications',
            'candidate.certifications',
            'job',
        ]);

        $candidate = $application->candidate;

        // POPIA gate — do not process without explicit consent
        if (!$candidate->hasAiConsent()) {
            Log::warning("CalculateFitScore skipped: candidate #{$candidate->id} has not given AI consent.");
            return;
        }

        $candidateProfile = [
            'resume'          => ['text' => $candidate->ai_summary ?? ''],
            'skills'          => $candidate->skills->pluck('skill')->toArray(),
            'experience_years'=> $candidate->experience->sum('duration_months') / 12,
            'qualifications'  => $candidate->qualifications->pluck('qualification_type')->toArray(),
        ];

        $jobDescription = [
            'description'     => $application->job->description ?? '',
            'required_skills' => [], // extend when job requirements model is added
            'must_have_skills'=> [],
        ];

        $screenResult = $ai->screenResume($candidateProfile, $jobDescription);
        $fitResult    = $ai->scoreCandidateFit($candidateProfile, $jobDescription);

        DB::transaction(function () use ($application, $screenResult, $fitResult) {
            $score = FitScore::updateOrCreate(
                ['application_id' => $application->id],
                [
                    'job_id'              => $application->job_id,
                    'overall_score'       => $fitResult->data['final_score'] ?? null,
                    'skills_score'        => $screenResult->data['match_score'] ?? null,
                    'experience_score'    => null,
                    'qualification_score' => null,
                    'cultural_score'      => null,
                    'calculated_at'       => now(),
                    'ai_model_used'       => 'recruitment-ai',
                ],
            );

            // Write dimension explanations
            $dimensions = [
                'skills'    => $screenResult->data['reasons'] ?? [],
                'cultural'  => [$fitResult->data['justification'] ?? ''],
            ];

            foreach ($dimensions as $dimension => $lines) {
                $text = implode(' ', array_filter((array) $lines));
                if ($text) {
                    FitExplanation::updateOrCreate(
                        ['fit_score_id' => $score->id, 'dimension' => $dimension],
                        ['explanation' => $text, 'score' => $score->overall_score],
                    );
                }
            }

            $application->candidate->update(['fit_score_calculated_at' => now()]);
        });
    }

    public function failed(\Throwable $e): void
    {
        Log::error("CalculateFitScore failed for application #{$this->application->id}: {$e->getMessage()}");
    }
}
