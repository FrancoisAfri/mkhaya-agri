<?php

namespace App\Modules\SupplierManagement\Console;

use App\Modules\SupplierManagement\DueDiligence\Services\ReputationMonitor;
use App\Modules\SupplierManagement\DueDiligence\Services\RiskEngine;
use App\Modules\SupplierManagement\DueDiligence\Support\DueDiligenceAddon;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierMonitoringRun;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * The continuous half of continuous monitoring.
 *
 * Cadence follows risk, because watching two thousand suppliers weekly is neither affordable nor
 * useful: high and critical suppliers weekly, everyone else monthly. A supplier that has never been
 * assessed is treated as high — not knowing is a reason to look more often, not less.
 *
 * Per-tenant fan-out through RunsPerTenant, which is not optional. A cron that loops companies
 * naively stops at the first tenant that throws and nobody finds out, because the run still reports
 * success — and monitoring that silently stopped is worse than none, since the file says somebody
 * was watching.
 */
class MonitorSupplierReputationCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'suppliers:monitor-reputation
                            {--company= : One tenant only}
                            {--supplier= : One supplier only}
                            {--force : Ignore the cadence and run every eligible supplier}
                            {--limit=250 : Most suppliers to process per tenant in one pass}';

    protected $description = 'Search news and social sources for what is being said about suppliers';

    public function handle(ReputationMonitor $monitor, RiskEngine $risk, TenantContext $tenants): int
    {
        $this->eachTenant($tenants, function ($companyId) use ($monitor, $risk) {
            // The add-on is a per-tenant entitlement, so a tenant that has not bought it is skipped
            // rather than quietly given the feature by a cron.
            if (! DueDiligenceAddon::active()) {
                return;
            }

            $suppliers = $this->due();
            $ran = 0;

            foreach ($suppliers as $supplier) {
                $run = $monitor->run($supplier, 'full', 'scheduled');
                $risk->assess($supplier->fresh(), 'monitoring');
                $ran++;

                if ($run->foundSomething()) {
                    $this->line("  {$supplier->company_name}: {$run->findings_new} new, {$run->findings_updated} seen again");
                }
            }

            $this->info("Company {$companyId}: monitored {$ran} supplier(s).");
        });

        // RunsPerTenant corrects this to a failure if any tenant threw — a partial pass that reports
        // success is how a broken cron survives for months.
        return self::SUCCESS;
    }

    /**
     * Suppliers due a pass.
     *
     * Due-ness is computed from the last run rather than a schedule column, so a supplier that was
     * monitored by hand this morning is not monitored again tonight, and one whose risk band changed
     * moves cadence immediately instead of at the next reset.
     */
    private function due()
    {
        $query = Supplier::query()->where('is_active', 1);

        if ($id = $this->option('supplier')) {
            return $query->where('id', $id)->get();
        }

        return $query
            ->orderByRaw("CASE WHEN risk_band IN ('high','critical') THEN 0 ELSE 1 END")
            ->limit((int) $this->option('limit'))
            ->get()
            ->filter(fn (Supplier $supplier) => $this->option('force') || $this->isDue($supplier));
    }

    private function isDue(Supplier $supplier): bool
    {
        $last = SupplierMonitoringRun::query()
            ->where('supplier_id', $supplier->id)
            ->orderByDesc('started_at')
            ->first();

        if (! $last) {
            return true;
        }

        // Never assessed counts as high: not knowing is a reason to look more often, not less.
        $weekly = in_array($supplier->risk_band, ['high', 'critical'], true) || $supplier->risk_band === null;

        return $last->started_at->lt(now()->sub($weekly ? '7 days' : '30 days'));
    }
}
