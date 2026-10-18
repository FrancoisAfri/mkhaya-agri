<?php

namespace App\Console\Commands\Attendance;

use App\Modules\HR\Hr\Services\ReadErsDetails;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AutoApplyMonthlyLeave extends Command
{
    use RunsPerTenant;

    protected $signature   = 'attendance:auto-apply-leave-monthly {--company= : Process only this company} {--sync : Process companies inline instead of fanning out to the queue}';
    protected $description = 'End-of-month catch-all: auto-apply leave for all employees still absent without a leave application';

    public function handle(ReadErsDetails $service, \App\Modules\Tenancy\Services\TenantContext $tenant): int
    {
        $run = function () use ($service) {
            $service->autoMonthlyApplyLeave();
            Log::info('Cron attendance:auto-apply-leave-monthly ran @ ' . now());
        };

        if ($cid = $this->option('company')) {
            $tenant->run((int) $cid, $run);
            return self::SUCCESS;
        }

        if ($this->option('sync')) {
            $failed = false;
            $this->eachTenant($tenant, function () use ($run, &$failed) {
                try { $run(); } catch (\Throwable $e) { Log::error('auto-apply failed: ' . $e->getMessage()); $failed = true; }
            });
            return $failed ? self::FAILURE : self::SUCCESS;
        }

        $n = $tenant->dispatchEachCompany('attendance:auto-apply-leave-monthly');
        $this->info("Dispatched monthly auto-apply to {$n} company(ies).");
        return self::SUCCESS;
    }
}
