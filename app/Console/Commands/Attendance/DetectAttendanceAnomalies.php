<?php

namespace App\Console\Commands\Attendance;

use App\Modules\HR\Hr\Services\AttendanceAnomalyService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Weekly attendance-anomaly scan (last 7 days) per company — including unauthorised absences
 * (no clock-in + no approved leave). Runs per tenant (SaaS) and once (self_hosted).
 */
class DetectAttendanceAnomalies extends Command
{
    use RunsPerTenant;

    protected $signature = 'attendance:detect-anomalies
                            {--company= : Scan only this company}
                            {--days=7 : Look back this many days}
                            {--sync : Scan companies inline instead of fanning out to the queue}';

    protected $description = 'Detect attendance anomalies (last week) per company.';

    public function handle(TenantContext $tenant, AttendanceAnomalyService $service): int
    {
        $days  = (int) $this->option('days');
        $start = now()->subDays($days)->toDateString();
        $end   = now()->toDateString();

        $run = fn () => $service->runFullScan($start, $end);

        if ($cid = $this->option('company')) {
            $tenant->run((int) $cid, $run);
            return self::SUCCESS;
        }

        if ($this->option('sync')) {
            $this->eachTenant($tenant, $run);
            $this->info('Attendance anomaly scan complete (sync).');
            return self::SUCCESS;
        }

        $n = $tenant->dispatchEachCompany('attendance:detect-anomalies', ['--days' => $days]);
        $this->info("Dispatched attendance anomaly scan to {$n} company(ies).");
        return self::SUCCESS;
    }
}
