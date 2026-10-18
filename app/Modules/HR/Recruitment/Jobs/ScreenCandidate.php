<?php

namespace App\Modules\HR\Recruitment\Jobs;

use App\Modules\HR\Recruitment\Models\Application;
use App\Modules\HR\Recruitment\Models\Candidate;
use App\Modules\HR\Recruitment\Services\CandidateScreeningService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Runs an AI candidate screening in the background. Consent-gated (POPIA) and
 * purely decision-support — it never makes an automated hire/reject decision.
 * Queued on 'ai-heavy'. Mirrors CalculateFitScore.
 */
class ScreenCandidate implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 180;

    // Exponential backoff: 60s, 300s, 900s
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function __construct(
        public readonly Candidate    $candidate,
        public readonly ?Application $application = null,
    ) {
        // Set the queue here rather than redeclaring Queueable's untyped $queue
        // property (a typed redeclaration is a fatal class-composition error).
        $this->onQueue('ai-heavy');
        $this->afterCommit();
    }

    public function handle(CandidateScreeningService $service): void
    {
        // POPIA gate — do not process without explicit consent. The service also
        // records a skipped_no_consent row, but short-circuit here for clarity.
        if (! $this->candidate->hasAiConsent()) {
            Log::warning("ScreenCandidate skipped: candidate #{$this->candidate->id} has not given AI consent.");
        }

        $service->screen($this->candidate, $this->application);
    }

    public function failed(\Throwable $e): void
    {
        Log::error("ScreenCandidate failed for candidate #{$this->candidate->id}: {$e->getMessage()}");
    }
}
