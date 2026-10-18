<?php

namespace App\Console\Commands\Recruitment;

use App\Modules\HR\Recruitment\Jobs\GenerateMobilityMatchJob;
use App\Modules\HR\Recruitment\Models\Job;
use Illuminate\Console\Command;

class GenerateMobilityMatchesCommand extends Command
{
    protected $signature = 'recruitment:generate-mobility-matches
                            {--job= : Only run for a specific job ID}
                            {--dry-run : Show which jobs would be processed without dispatching}';

    protected $description = 'Dispatch AI mobility-match scoring jobs for all open positions';

    public function handle(): int
    {
        // Per company: mobility matches compare a company's open jobs against ITS
        // OWN employees. Unscoped, this read every tenant's jobs/people at once
        // and wrote matches with company_id = NULL. Dispatching inside the tenant
        // context also propagates the company to the queued AI jobs.
        app(\App\Modules\Tenancy\Services\TenantContext::class)->eachCompany(function () {
            // recruitment_jobs records an open vacancy as `is_active`; there is no `status`
            // column, so this threw before it could read a single row.
            $jobs = Job::where('is_active', true)
                ->when($this->option('job'), fn($q, $id) => $q->where('id', $id))
                ->get();

            if ($jobs->isEmpty()) {
                return;
            }

            if ($this->option('dry-run')) {
                $this->info("Dry-run: would dispatch GenerateMobilityMatchJob for {$jobs->count()} job(s):");
                $jobs->each(fn($job) => $this->line("  - [{$job->id}] {$job->title}"));
                return;
            }

            foreach ($jobs as $job) {
                GenerateMobilityMatchJob::dispatch($job)->onQueue('ai-fast');
            }

            $this->info("Dispatched mobility-match jobs for {$jobs->count()} position(s).");
        });

        return self::SUCCESS;
    }
}
