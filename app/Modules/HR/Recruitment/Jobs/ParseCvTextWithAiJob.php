<?php

namespace App\Modules\HR\Recruitment\Jobs;

use App\Modules\HR\Recruitment\Actions\Cv\UpdateCandidateProfileFromCvAction;
use App\Modules\HR\Recruitment\AI\RecruitmentAI;
use App\Modules\HR\Recruitment\Models\AiProfile;
use App\Modules\HR\Recruitment\Models\Candidate;
use App\Modules\HR\Recruitment\Models\CandidateDocument;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ParseCvTextWithAiJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public string $queue      = 'ai-heavy';
    public int    $tries      = 3;
    public int    $timeout    = 120;
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function __construct(
        public readonly Candidate         $candidate,
        public readonly CandidateDocument $document,
        public readonly string            $cvText,
    ) {
        $this->afterCommit();
    }

    public function handle(RecruitmentAI $ai, UpdateCandidateProfileFromCvAction $action): void
    {
        $result = $ai->parseCvText($this->cvText);

        if ($result->failed()) {
            Log::error("ParseCvTextWithAiJob: AI call failed for candidate #{$this->candidate->id}: {$result->errorMessage}");
            AiProfile::updateOrCreate(
                ['candidate_id' => $this->candidate->id],
                ['parse_status' => 'failed', 'parse_error' => $result->errorMessage],
            );
            return;
        }

        $parsed = $result->toArray();

        if (empty($parsed) || isset($parsed['error'])) {
            $errMsg = $parsed['message'] ?? 'AI returned an empty or error response.';
            Log::error("ParseCvTextWithAiJob: invalid AI response for candidate #{$this->candidate->id}: {$errMsg}");
            AiProfile::updateOrCreate(
                ['candidate_id' => $this->candidate->id],
                ['parse_status' => 'failed', 'parse_error' => $errMsg],
            );
            return;
        }

        $confidence = (float) ($parsed['confidence_score'] ?? 0.75);

        $action->execute($this->candidate, $parsed, $this->cvText, $confidence);

        $document = $this->document->fresh();
        $document->parsed_at    = now();
        $document->parse_status = 'completed';
        $document->save();

        GenerateAiSummary::dispatch($this->candidate)->onQueue('ai-fast');
    }

    public function failed(\Throwable $e): void
    {
        Log::error("ParseCvTextWithAiJob permanently failed for candidate #{$this->candidate->id}: {$e->getMessage()}");
        AiProfile::updateOrCreate(
            ['candidate_id' => $this->candidate->id],
            ['parse_status' => 'failed', 'parse_error' => $e->getMessage()],
        );
    }
}
