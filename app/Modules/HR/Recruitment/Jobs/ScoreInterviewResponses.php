<?php

namespace App\Modules\HR\Recruitment\Jobs;

use App\Modules\HR\Recruitment\AI\RecruitmentAI;
use App\Modules\HR\Recruitment\Models\Interview;
use App\Modules\HR\Recruitment\Models\InterviewScore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScoreInterviewResponses implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int  $tries       = 3;
    public int  $timeout     = 60;


    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function __construct(public readonly Interview $interview) { $this->afterCommit(); }

    public function handle(RecruitmentAI $ai): void
    {
        $interview = $this->interview->load([
            'questions.responses',
            'application.candidate',
            'application.job',
        ]);

        $candidate = $interview->application->candidate;

        if (!$candidate->hasAiConsent()) {
            Log::warning("ScoreInterviewResponses skipped: candidate #{$candidate->id} has not given AI consent.");
            return;
        }

        $answers = $interview->questions->flatMap(function ($question) use ($candidate) {
            $response = $question->responses->firstWhere('candidate_id', $candidate->id);
            return $response ? [[
                'question' => $question->question,
                'answer'   => $response->response ?? '',
                'category' => $question->category,
            ]] : [];
        })->values()->toArray();

        if (empty($answers)) {
            Log::info("ScoreInterviewResponses: no responses for interview #{$interview->id}.");
            return;
        }

        $jobDescription = [
            'description'     => $interview->application->job->description ?? '',
            'required_skills' => [],
        ];

        $result = $ai->evaluateInterviewAnswers($answers, $jobDescription);

        if ($result->data['error'] ?? false) {
            Log::error("ScoreInterviewResponses AI error for interview #{$interview->id}: " . ($result->data['message'] ?? ''));
            return;
        }

        DB::transaction(function () use ($interview, $result) {
            InterviewScore::updateOrCreate(
                ['interview_id' => $interview->id],
                [
                    'scored_by'           => null, // AI-generated, no human scorer
                    'overall_score'       => $result->data['overall_score'] ?? null,
                    'technical_score'     => null,
                    'communication_score' => null,
                    'culture_fit_score'   => null,
                    'recommendation'      => $this->mapRecommendation($result->data['recommendation'] ?? ''),
                    'comments'            => $result->data['summary'] ?? null,
                    'scored_at'           => now(),
                ],
            );
        });
    }

    private function mapRecommendation(string $aiRecommendation): ?string
    {
        return match($aiRecommendation) {
            'strong_proceed' => 'strong_yes',
            'proceed'        => 'yes',
            'reject'         => 'no',
            default          => 'maybe',
        };
    }

    public function failed(\Throwable $e): void
    {
        Log::error("ScoreInterviewResponses failed for interview #{$this->interview->id}: {$e->getMessage()}");
    }
}
