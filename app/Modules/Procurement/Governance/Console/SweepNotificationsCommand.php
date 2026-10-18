<?php

namespace App\Modules\Procurement\Governance\Console;

use App\Support\Console\RunsPerTenant;
use App\Modules\Procurement\Governance\Services\NotificationService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Console\Command;

/**
 * The daily §9 sweep: pending approvals, evaluation deadlines, overdue evaluations, procurement
 * delays, expiring documents and contracts, compliance failures and capacity alerts — followed by
 * the escalation pass for anything still unresolved past its window.
 *
 *   php artisan procgov:notify                # every tenant
 *   php artisan procgov:notify --company=7
 *   php artisan procgov:notify --no-escalate
 *
 * Runs per tenant through TenantContext so the company session variable is set and row-level
 * security scopes every query. Without that a console run sees every tenant's rows at once, and
 * this command would cheerfully mail one customer's overdue evaluations to another's manager.
 */
class SweepNotificationsCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'procgov:notify
                            {--company= : Restrict to one company id}
                            {--no-escalate : Send reminders but skip the escalation pass}';

    protected $description = 'Send Procurement Governance reminders and escalate overdue approvals';

    public function handle(TenantContext $tenants, NotificationService $notifications): int
    {
        $escalate = ! $this->option('no-escalate');

        $totalSent = 0;
        $totalEscalated = 0;

        $sweep = function (int $companyId) use ($notifications, $escalate, &$totalSent, &$totalEscalated): void {
            $sent = $notifications->sweep($companyId);
            $escalated = $escalate ? $notifications->escalate($companyId) : 0;

            $totalSent += $sent;
            $totalEscalated += $escalated;

            if ($sent > 0 || $escalated > 0) {
                $this->components->twoColumnDetail(
                    "Company {$companyId}",
                    "{$sent} notice(s), {$escalated} escalation(s)"
                );
            }
        };

        $this->eachTenant($tenants, $sweep);

        $this->components->info(
            "Procurement Governance: {$totalSent} notice(s) sent, {$totalEscalated} escalation(s) raised."
        );

        $this->reportTenantFailures('The reminder sweep');

        return $this->hadTenantFailures() ? self::FAILURE : self::SUCCESS;
    }
}
