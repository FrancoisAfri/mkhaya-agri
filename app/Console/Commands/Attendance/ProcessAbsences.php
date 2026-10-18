<?php

namespace App\Console\Commands\Attendance;

use App\Modules\HR\Hr\Services\ReadErsDetails;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessAbsences extends Command
{
    use RunsPerTenant;

    protected $signature   = 'attendance:process-absences {--company= : Process only this company} {--sync : Process companies inline instead of fanning out to the queue}';
    protected $description = 'Detect absent employees via ERS, send leave reminder emails, and trigger auto-apply if configured';

    public function handle(ReadErsDetails $service): int
    {
        $tenant = app(\App\Modules\Tenancy\Services\TenantContext::class);
        $run = fn () => $service->getErsDetails();

        if ($cid = $this->option('company')) {
            $tenant->run((int) $cid, $run);
            return self::SUCCESS;
        }

        if ($this->option('sync')) {
            try {
                $this->eachTenant($tenant, fn () => $run());
                Log::info('Cron attendance:process-absences ran @ ' . now());
                return self::SUCCESS;
            } catch (\Throwable $e) {
                Log::error('Cron attendance:process-absences failed: ' . $e->getMessage());
                return self::FAILURE;
            }
        }

        $n = $tenant->dispatchEachCompany('attendance:process-absences');
        $this->info("Dispatched absence processing for {$n} company(ies).");
        return self::SUCCESS;
    }
}
