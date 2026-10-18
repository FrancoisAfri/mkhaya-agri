<?php

namespace App\Console\Commands\Attendance;

use App\Modules\HR\Hr\Services\ReadErsDetails;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncErsClockings extends Command
{
    use RunsPerTenant;

    protected $signature   = 'attendance:sync-ers {--company= : Process only this company} {--sync : Process companies inline instead of fanning out to the queue}';
    protected $description = 'Pull today\'s biometric clock-in/out data from ERS and save to attendance records';

    public function handle(ReadErsDetails $service): int
    {
        $tenant = app(\App\Modules\Tenancy\Services\TenantContext::class);
        $run = function () use ($service) {
            try {
                $service->getTimeAndAttendance();
                Log::info('Cron attendance:sync-ers ran @ ' . now());
            } catch (\Throwable $e) {
                Log::error('Cron attendance:sync-ers failed: ' . $e->getMessage());
                throw $e;
            }
        };

        if ($cid = $this->option('company')) {
            $tenant->run((int) $cid, $run);
            return self::SUCCESS;
        }

        if ($this->option('sync')) {
            $failed = false;
            $this->eachTenant($tenant, function () use ($run, &$failed) {
                try { $run(); } catch (\Throwable) { $failed = true; }
            });
            return $failed ? self::FAILURE : self::SUCCESS;
        }

        $n = $tenant->dispatchEachCompany('attendance:sync-ers');
        $this->info("Dispatched ERS sync for {$n} company(ies).");
        return self::SUCCESS;
    }
}
