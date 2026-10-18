<?php

namespace App\Console\Commands\Recruitment;

use App\Modules\HR\Recruitment\Models\Candidate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PurgeCandidateDataCommand extends Command
{
    protected $signature = 'recruitment:purge-candidate-data
                            {--months=24 : Purge candidates inactive for this many months}
                            {--dry-run   : List candidates without making changes}';

    protected $description = 'POPIA compliance: anonymise/delete PII for inactive candidates older than retention period';

    public function handle(): int
    {
        $months  = (int) $this->option('months');
        $cutoff  = now()->subMonths($months);

        // Target: rejected/withdrawn candidates with no active application, beyond retention window
        $query = Candidate::whereDoesntHave('applications', function ($q) {
                $q->whereNotIn('status', ['rejected', 'withdrawn']);
            })
            ->where('updated_at', '<', $cutoff)
            ->whereNull('deleted_at');

        if ($this->option('dry-run')) {
            $count = $query->count();
            $this->info("Dry-run: {$count} candidate(s) eligible for purge (>{$months} months inactive).");
            return self::SUCCESS;
        }

        $purged = 0;

        $query->each(function (Candidate $candidate) use (&$purged): void {
            DB::transaction(function () use ($candidate): void {
                // Delete CV from S3
                if ($candidate->cv_path) {
                    Storage::disk('s3')->delete($candidate->cv_path);
                }

                // Anonymise PII in place before soft-delete
                $candidate->update([
                    'email'         => "purged-{$candidate->id}@redacted.invalid",
                    'phone'         => null,
                    'id_number'     => null,
                    'date_of_birth' => null,
                    'cv_path'       => null,
                    'ai_consent_ip' => null,
                ]);

                $candidate->delete(); // soft-delete
            });

            $purged++;
        });

        $this->info("Purged {$purged} candidate record(s).");

        return self::SUCCESS;
    }
}
