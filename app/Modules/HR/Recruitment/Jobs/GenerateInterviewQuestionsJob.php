<?php

namespace App\Modules\HR\Recruitment\Jobs;

use App\Modules\HR\Recruitment\AI\RecruitmentAI;
use App\Modules\HR\Recruitment\Models\Interview;
use App\Modules\HR\Recruitment\Models\InterviewQuestion;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerateInterviewQuestionsJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int  $tries       = 3;
    public int  $timeout     = 90;


    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function __construct(public readonly Interview $interview) { $this->afterCommit(); }

    public function handle(RecruitmentAI $ai): void
    {
        $interview = $this->interview->load([
            'application.job',
            'application.candidate.skills',
            'application.candidate.experience',
        ]);

        $candidate  = $interview->application->candidate;
        $job        = $interview->application->job;

        $jobDescription = [
            'description'     => $job->description ?? '',
            'required_skills' => $job->required_skills ?? [],
            'title'           => $job->title ?? '',
        ];

        $resume = [
            'skills'           => $candidate->skills->pluck('skill')->toArray(),
            'experience_years' => $candidate->experience->sum('duration_months') / 12,
        ];

        $result = $ai->generateInterviewQuestions($jobDescription, $resume);

        if ($result->failed()) {
            Log::error("GenerateInterviewQuestionsJob failed for interview #{$interview->id}");
            return;
        }

        $parsed    = json_decode($result->content, true);
        $questions = $parsed['questions'] ?? [];

        if (empty($questions)) {
            Log::warning("GenerateInterviewQuestionsJob: no questions returned for interview #{$interview->id}");
            return;
        }

        DB::transaction(function () use ($interview, $questions) {
            // Remove previously AI-generated questions (keep manually added ones)
            $interview->questions()->where('ai_generated', true)->delete();

            foreach ($questions as $index => $q) {
                InterviewQuestion::create([
                    'interview_id' => $interview->id,
                    'question'     => $q['question'] ?? '',
                    'category'     => $q['type'] ?? 'general',
                    'ai_generated' => true,
                    'order_index'  => $index + 1,
                ]);
            }
        });
    }

    public function failed(\Throwable $e): void
    {
        Log::error("GenerateInterviewQuestionsJob failed for interview #{$this->interview->id}: {$e->getMessage()}");
    }
}
