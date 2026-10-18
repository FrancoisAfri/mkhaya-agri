<?php

namespace App\Modules\HR\Leave\Console;

use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Leave\Models\LeaveProfileAllocation;
use App\Modules\HR\Leave\Models\LeaveType;
use App\Modules\HR\Leave\Services\LeaveProbationAccrualService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class BackfillLeaveAccrualCommand extends Command
{
    protected $signature = 'leave:backfill
                            {--employee= : HR person ID to backfill (omit for all active employees)}
                            {--from=     : Override accrual start date (Y-m-d). Defaults to date_joined.}
                            {--dry-run   : Show what would be credited without writing anything}
                            {--company=  : Process only this company}
                            {--sync      : Process companies inline instead of fanning out to the queue}';

    protected $description = 'Backfill missed (probation) leave accrual per company for employees whose accounts were created after their hire date.';

    public function handle(LeaveProbationAccrualService $accrualService, \App\Modules\Tenancy\Services\TenantContext $tenant): int
    {
        $fromOverride = $this->option('from') ? Carbon::parse($this->option('from')) : null;
        $dryRun       = (bool) $this->option('dry-run');

        // Single company (fan-out job or manual --company).
        if ($cid = $this->option('company')) {
            $tenant->run((int) $cid, fn () => $this->backfillForCompany($accrualService, $fromOverride, $dryRun));
            return self::SUCCESS;
        }

        // Targeting one employee: resolve their company and run in that context so
        // RLS scopes the read and the credit is tagged to the right tenant.
        if ($employeeId = $this->option('employee')) {
            $companyId = (int) (HRPerson::whereKey($employeeId)->value('company_id') ?? 0);
            if (! $companyId) {
                $this->warn('Employee not found or has no company.');
                return self::SUCCESS;
            }
            $tenant->run($companyId, fn () => $this->backfillForCompany($accrualService, $fromOverride, $dryRun));
            return self::SUCCESS;
        }

        // Inline mode (small installs / local).
        if ($this->option('sync')) {
            $tenant->eachCompany(fn () => $this->backfillForCompany($accrualService, $fromOverride, $dryRun));
            return self::SUCCESS;
        }

        // Default: one queued job per active/trial company.
        $opts = [];
        if ($this->option('from')) { $opts['--from'] = $this->option('from'); }
        if ($dryRun) { $opts['--dry-run'] = true; }
        $n = $tenant->dispatchEachCompany('leave:backfill', $opts);
        $this->info("Dispatched probation-accrual backfill to {$n} company(ies).");
        return self::SUCCESS;
    }

    /** Backfill probation accrual for the company whose tenant context is set. */
    private function backfillForCompany(LeaveProbationAccrualService $accrualService, ?Carbon $fromOverride, bool $dryRun): void
    {
        $employeeId = $this->option('employee');

        $query = HRPerson::query()
            ->where('status', 1)
            ->whereNotNull('date_joined')
            ->whereNotNull('leave_profile')->where('leave_profile', '>', 0);

        if ($employeeId) {
            $query->where('id', $employeeId);
        }

        $employees = $query->get();

        if ($employees->isEmpty()) {
            return;
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '') . "Backfilling accrual for {$employees->count()} employee(s)...");
        $this->newLine();

        $totalCredited = 0.0;

        foreach ($employees as $employee) {
            $allocations = LeaveProfileAllocation::where('leave_profile_id', $employee->leave_profile)
                ->where('active', true)
                ->with('leaveType')
                ->get();

            foreach ($allocations as $allocation) {
                /** @var LeaveType|null $leaveType */
                $leaveType = $allocation->leaveType;
                if (! $leaveType) {
                    continue;
                }

                $from = $fromOverride ?? (
                    is_numeric($employee->date_joined)
                        ? Carbon::createFromTimestamp((int) $employee->date_joined)
                        : Carbon::parse($employee->date_joined)
                );

                $wouldCredit = $accrualService->calculateAccruedHours($employee, $leaveType);

                if ($dryRun) {
                    if ($wouldCredit > 0) {
                        $this->line(sprintf(
                            '  Employee #%d (%s) — %s: would credit %.2f h (%.1f days)',
                            $employee->id,
                            trim("{$employee->first_name} {$employee->surname}"),
                            $leaveType->name,
                            $wouldCredit,
                            $wouldCredit / 8,
                        ));
                    }
                    continue;
                }

                $credited = $accrualService->applyAccrual(
                    employee:  $employee,
                    leaveType: $leaveType,
                    from:      $from,
                    note:      'Backfill via leave:backfill command.',
                );

                if ($credited > 0) {
                    $totalCredited += $credited;
                    $this->line(sprintf(
                        '  Employee #%d (%s) — %s: credited %.2f h (%.1f days)',
                        $employee->id,
                        trim("{$employee->first_name} {$employee->surname}"),
                        $leaveType->name,
                        $credited,
                        $credited / 8,
                    ));
                }
            }
        }

        $this->newLine();

        if ($dryRun) {
            $this->warn('Dry run complete — nothing was written.');
        } else {
            $this->info(sprintf('Backfill complete. Total credited: %.2f h (%.1f days).', $totalCredited, $totalCredited / 8));
        }
    }
}
