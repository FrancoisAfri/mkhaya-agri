<?php

namespace App\Modules\HR\Recruitment\Jobs;

use App\Modules\HR\Recruitment\Models\Candidate;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DetectDuplicateCandidate implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 30;

    public function __construct(public readonly Candidate $candidate)
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        $candidate = $this->candidate->fresh();

        if (!$candidate) {
            return;
        }

        // Check for existing candidate with same email (excluding self)
        $duplicate = Candidate::where('id', '!=', $candidate->id)
            ->where(function ($q) use ($candidate) {
                $q->where('email', $candidate->email);

                if ($candidate->phone) {
                    $q->orWhere('phone', $candidate->phone);
                }
            })
            ->whereNull('is_duplicate_of')
            ->oldest()
            ->first();

        if ($duplicate) {
            Log::info("DetectDuplicateCandidate: candidate #{$candidate->id} marked as duplicate of #{$duplicate->id}.");

            $candidate->update(['is_duplicate_of' => $duplicate->id]);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error("DetectDuplicateCandidate failed for candidate #{$this->candidate->id}: {$e->getMessage()}");
    }
}
