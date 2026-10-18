<?php

namespace App\Modules\HR\Leave\Console;

use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Leave\Notifications\LeaveBalanceStatementNotification;
use App\Modules\HR\Leave\Services\LeaveBalanceService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Email every active employee their current leave balances. Runs monthly; the
 * legacy intranet did the same thing but its cron name never matched a command,
 * so the statements were never actually sent.
 */
class SendLeaveBalancesCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'leave:send-balances
                            {--company= : Process only this company}
                            {--sync : Process companies inline instead of fanning out to the queue}
                            {--dry-run : Report who would be emailed without sending}';

    protected $description = 'Email each employee a statement of their current leave balances.';

    public function handle(TenantContext $tenant, LeaveBalanceService $balances): int
    {
        if ($cid = $this->option('company')) {
            $tenant->run((int) $cid, fn () => $this->runForCompany($balances));
            return self::SUCCESS;
        }
        if ($this->option('sync')) {
            $this->eachTenant($tenant, fn () => $this->runForCompany($balances));
            return self::SUCCESS;
        }

        $n = $tenant->dispatchEachCompany('leave:send-balances', $this->option('dry-run') ? ['--dry-run' => true] : []);
        $this->info("Dispatched leave balance statements to {$n} company(ies).");
        return self::SUCCESS;
    }

    private function runForCompany(LeaveBalanceService $balanceService): void
    {
        $dryRun = (bool) $this->option('dry-run');
        $sent = 0;

        HRPerson::where('status', 1)
            ->whereNotNull('user_id')
            ->with('user')
            ->chunk(200, function ($employees) use ($balanceService, $dryRun, &$sent) {
                foreach ($employees as $employee) {
                    $user = $employee->user;
                    if (! $user || blank($user->email)) {
                        continue;
                    }

                    $rows = collect($balanceService->getAllBalances($employee->id))
                        ->map(fn ($b) => [
                            'leave_type' => $b['leave_type'] ?? ($b['leave_type_name'] ?? 'Leave'),
                            'balance'    => (float) ($b['balance'] ?? 0),
                        ])
                        // Nothing accrued and nothing taken — no statement worth sending.
                        ->filter(fn ($b) => $b['balance'] != 0.0)
                        ->values()
                        ->all();

                    if (empty($rows)) {
                        continue;
                    }

                    if ($dryRun) {
                        $this->line("  [dry-run] would email {$user->email} (" . count($rows) . ' balance rows)');
                        $sent++;
                        continue;
                    }

                    try {
                        $user->notify(new LeaveBalanceStatementNotification(
                            trim($employee->first_name . ' ' . $employee->surname),
                            $rows
                        ));
                        $sent++;
                    } catch (\Throwable $e) {
                        Log::error("leave:send-balances failed for employee #{$employee->id}: " . $e->getMessage());
                    }
                }
            });

        $this->info("Leave balance statements sent: {$sent}");
    }
}
