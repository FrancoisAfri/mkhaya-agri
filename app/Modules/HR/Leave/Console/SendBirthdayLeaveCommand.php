<?php

namespace App\Modules\HR\Leave\Console;

use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Leave\Models\LeaveCycle;
use App\Modules\HR\Leave\Models\LeaveConfiguration;
use App\Modules\HR\Leave\Models\LeaveType;
use App\Modules\HR\Leave\Models\LeaveBalanceTransaction;
use App\Modules\HR\Leave\Enums\LeaveTransactionType;
use App\Modules\HR\Leave\Notifications\BirthdayLeaveAllocatedNotification;
use App\Modules\HR\Leave\Notifications\BirthdayWishNotification;
use Carbon\Carbon;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendBirthdayLeaveCommand extends Command
{
    use RunsPerTenant;

    protected $signature   = 'leave:birthday {--dry-run : Preview without sending or allocating} {--company= : Process only this company} {--sync : Process companies inline instead of fanning out to the queue}';
    protected $description = 'Send birthday wishes and (if enabled) allocate birthday leave 7 days in advance.';

    public function handle(\App\Modules\Tenancy\Services\TenantContext $tenant): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // One company (invoked by the fan-out job, or manually).
        if ($cid = $this->option('company')) {
            $tenant->run((int) $cid, fn () => $this->runForCompany($dryRun));
            return self::SUCCESS;
        }
        // Inline (small installs / local).
        if ($this->option('sync')) {
            $this->eachTenant($tenant, fn () => $this->runForCompany($dryRun));
            return self::SUCCESS;
        }
        // Default: one queued job per active/trial company.
        $n = $tenant->dispatchEachCompany('leave:birthday', $dryRun ? ['--dry-run' => true] : []);
        $this->info("Dispatched birthday leave to {$n} company(ies).");
        return self::SUCCESS;
    }

    /** Birthday wishes + birthday-leave allocation for the current tenant. */
    private function runForCompany(bool $dryRun): void
    {
        $today   = Carbon::today();
        $config  = LeaveConfiguration::first();

        $birthdayLeaveEnabled = (bool) ($config?->birthday_leave_enabled ?? false);
        $birthdayLeaveDays    = (float) ($config?->birthday_leave_days   ?? 0);

        // ── 1. Load birthday leave 7 days before the birthday ────────────────
        if ($birthdayLeaveEnabled && $birthdayLeaveDays > 0) {
            $targetBirthday = $today->copy()->addDays(7);
            $birthdayType   = LeaveType::where('is_birthday_leave', true)->where('status', 1)->first();
            $cycle          = LeaveCycle::active();

            if (! $birthdayType) {
                $this->warn('Birthday leave is enabled but no Birthday Leave type found. Skipping allocation.');
            } else {
                $employees = $this->employeesWithBirthdayOn($targetBirthday);

                $this->info("Found {$employees->count()} employee(s) with birthday on {$targetBirthday->format('d M')} (allocating leave today).");

                foreach ($employees as $person) {
                    if (! $person->user) {
                        continue;
                    }

                    // Guard: skip if already allocated this year to prevent duplicate runs
                    $alreadyAllocated = LeaveBalanceTransaction::where('employee_id', $person->id)
                        ->where('leave_type_id', $birthdayType->id)
                        ->where('transaction_type', LeaveTransactionType::Allocation)
                        ->whereYear('created_at', $today->year)
                        ->exists();

                    if ($alreadyAllocated) {
                        $this->line("  Skipping {$person->first_name} {$person->surname} — already allocated this year.");
                        continue;
                    }

                    if ($dryRun) {
                        $this->line("  [dry-run] Would allocate {$birthdayLeaveDays}d to {$person->first_name} {$person->surname}");
                        continue;
                    }

                    $this->allocateBirthdayLeave($person, $birthdayType->id, $birthdayLeaveDays, $cycle?->id);

                    $person->user->notify(
                        new BirthdayLeaveAllocatedNotification($birthdayLeaveDays, $targetBirthday)
                    );

                    $this->line("  Allocated {$birthdayLeaveDays}d to {$person->first_name} {$person->surname}");
                }
            }
        }

        // ── 2. Send happy birthday messages to employees whose birthday is today ──
        $birthdayPeople = $this->employeesWithBirthdayOn($today);

        $this->info("Found {$birthdayPeople->count()} employee(s) with birthday today — sending wishes.");

        foreach ($birthdayPeople as $person) {
            if (! $person->user) {
                continue;
            }

            if ($dryRun) {
                $this->line("  [dry-run] Would send birthday wish to {$person->first_name} {$person->surname}");
                continue;
            }

            $person->user->notify(
                new BirthdayWishNotification($birthdayLeaveEnabled, $birthdayLeaveDays)
            );

            $this->line("  Sent birthday wish to {$person->first_name} {$person->surname}");
        }

        $this->info('Birthday leave command completed.');
    }

    private function employeesWithBirthdayOn(Carbon $date): \Illuminate\Database\Eloquent\Collection
    {
        // date_of_birth is stored as a Unix timestamp (bigint), read via
        // Carbon::createFromTimestamp() under the UTC app timezone — so
        // whereMonth/whereDay (EXTRACT on a bigint) fail. Extract from the
        // converted timestamp in UTC to match the accessor exactly.
        return HRPerson::whereNotNull('date_of_birth')
            ->where('status', 1)
            ->whereRaw("EXTRACT(MONTH FROM to_timestamp(date_of_birth) AT TIME ZONE 'UTC') = ?", [$date->month])
            ->whereRaw("EXTRACT(DAY FROM to_timestamp(date_of_birth) AT TIME ZONE 'UTC') = ?", [$date->day])
            ->with('user')
            ->get();
    }

    private function allocateBirthdayLeave(HRPerson $person, int $leaveTypeId, float $days, ?int $cycleId): void
    {
        $hours = $days * 8;

        DB::transaction(function () use ($person, $leaveTypeId, $hours, $cycleId) {
            $last   = LeaveBalanceTransaction::where('employee_id', $person->id)
                ->where('leave_type_id', $leaveTypeId)
                ->latest('id')
                ->first();

            $before = (float) ($last?->balance_after ?? 0);
            $after  = $before + $hours;

            LeaveBalanceTransaction::create([
                'employee_id'      => $person->id,
                'leave_type_id'    => $leaveTypeId,
                'leave_cycle_id'   => $cycleId,
                'transaction_type' => LeaveTransactionType::Allocation,
                'hours'            => $hours,
                'balance_before'   => $before,
                'balance_after'    => $after,
                'reference_type'   => 'birthday_leave',
                'description'      => 'Birthday leave allocation',
                'created_at'       => now(),
            ]);
        });
    }
}
