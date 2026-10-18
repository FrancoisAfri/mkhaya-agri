<?php

namespace App\Modules\HR\Leave\Console;

use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Leave\Enums\LeaveTransactionType;
use App\Modules\HR\Leave\Models\LeaveProfileAllocation;
use App\Modules\HR\Leave\Models\LeaveType;
use App\Modules\HR\Leave\Repositories\EloquentLeaveBalanceRepository;
use App\Modules\Tenancy\Services\CurrentCompany;
use App\Modules\Tenancy\Services\TenantContext;
use Carbon\Carbon;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * BCEA 36-month sick-leave cycle automation (PER COMPANY). BCEA §22 entitles an
 * employee to sick leave equal to the days they'd normally work in 6 weeks, over
 * a rolling 36-month cycle. This opens a new cycle (and credits the sick balance)
 * for any employee who has no open cycle or whose current one has expired, then
 * records the window in employee_sick_leave_cycles so it isn't re-granted until it
 * rolls. Idempotent — safe to run monthly. Fans out one job per tenant.
 *
 * Entitlement per cycle = the sick allocation's default_hours if set, else the
 * sick leave type's bcea_minimum_days × 8 (default 30 days = 240h for a 5-day
 * week). NOTE: the BCEA first-6-months pro-rata (1 day / 26 days worked) is NOT
 * modelled here — full entitlement is granted at cycle start; if you need the
 * probation-period taper for sick leave, that's a policy decision to layer on.
 */
class SickLeaveCycleCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'leave:sick-cycle
                            {--company= : Process only this company}
                            {--sync : Process companies inline instead of fanning out to the queue}
                            {--dry-run : Preview without granting}';

    protected $description = 'Open/reset each employee\'s BCEA 36-month sick-leave cycle and grant the sick entitlement.';

    public function handle(TenantContext $tenant, EloquentLeaveBalanceRepository $repo, CurrentCompany $current): int
    {
        if ($cid = $this->option('company')) {
            $tenant->run((int) $cid, fn () => $this->runForCompany($repo, $current));
            return self::SUCCESS;
        }
        if ($this->option('sync')) {
            $this->eachTenant($tenant, fn () => $this->runForCompany($repo, $current));
            return self::SUCCESS;
        }
        $n = $tenant->dispatchEachCompany('leave:sick-cycle', $this->option('dry-run') ? ['--dry-run' => true] : []);
        $this->info("Dispatched sick-leave cycle processing to {$n} company(ies).");
        return self::SUCCESS;
    }

    private function runForCompany(EloquentLeaveBalanceRepository $repo, CurrentCompany $current): void
    {
        $dryRun    = (bool) $this->option('dry-run');
        $companyId = $current->id();

        $sickType = LeaveType::where('statutory_type', 'sick')->where('status', 1)->first();
        if (! $sickType) {
            return; // this company doesn't run a sick leave type
        }

        $today = Carbon::today();
        $employees = HRPerson::where('status', 1)->whereNotNull('leave_profile')->where('leave_profile', '>', 0)
            ->select('id', 'leave_profile')->get();

        $granted = 0;
        foreach ($employees as $emp) {
            // Employee must have the sick type in their leave profile to be entitled.
            $alloc = LeaveProfileAllocation::where('leave_profile_id', $emp->leave_profile)
                ->where('leave_type_id', $sickType->id)->where('active', true)->first();
            if (! $alloc) {
                continue;
            }

            // Skip if the employee already has an OPEN cycle (cycle_end >= today).
            $open = DB::table('employee_sick_leave_cycles')
                ->where('company_id', $companyId)->where('employee_id', $emp->id)
                ->where('cycle_end', '>=', $today->toDateString())
                ->exists();
            if ($open) {
                continue;
            }

            $entitlementHours = (float) ($alloc->default_hours ?: (($sickType->bcea_minimum_days ?: 30) * 8));
            if ($entitlementHours <= 0) {
                continue;
            }

            if ($dryRun) {
                $this->line("  [dry-run] would open 36-month sick cycle for #{$emp->id} (+{$entitlementHours}h)");
                continue;
            }

            $start = $today->copy();
            $end   = $start->copy()->addMonthsNoOverflow(36)->subDay();

            DB::transaction(function () use ($repo, $emp, $sickType, $entitlementHours, $companyId, $start, $end) {
                $repo->credit(
                    $emp->id,
                    $sickType->id,
                    $entitlementHours,
                    LeaveTransactionType::Allocation->value,
                    ['description' => "BCEA 36-month sick-leave entitlement ({$start->toDateString()} → {$end->toDateString()})"],
                );
                DB::table('employee_sick_leave_cycles')->insert([
                    'company_id'    => $companyId,
                    'employee_id'   => $emp->id,
                    'leave_type_id' => $sickType->id,
                    'cycle_start'   => $start->toDateString(),
                    'cycle_end'     => $end->toDateString(),
                    'granted_hours' => $entitlementHours,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            });
            $granted++;
        }

        if ($granted > 0) {
            $this->info("Opened {$granted} sick-leave cycle(s).");
        }
    }
}
