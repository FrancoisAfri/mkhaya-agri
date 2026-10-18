<?php

namespace App\Modules\SupplierManagement\Console;

use App\Modules\SupplierManagement\DueDiligence\Services\ContinuousMonitor;
use App\Modules\SupplierManagement\DueDiligence\Support\DueDiligenceAddon;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Re-runs the checks that have gone stale, tenant by tenant.
 *
 * The companion to `suppliers:monitor-reputation`: that one watches what the world says, this one
 * watches what the sources say. Kept separate because they cost different money and fail
 * differently — a news API being down should not stop a registry sweep, and a customer who licenses
 * one and not the other should get the half they paid for.
 *
 * Due-ness comes from each check's own freshness horizon scaled by the supplier's risk band, so the
 * work is proportionate rather than uniform: a critical supplier's tax status is re-checked four
 * times as often as a low-risk one's, and nobody re-checks a registered address weekly.
 *
 * Per-tenant through RunsPerTenant, and a tenant that throws does not take the rest of the estate
 * with it — a cron that stops silently is worse than none, because the file says somebody was
 * watching.
 */
class SweepSupplierChecksCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'suppliers:sweep-checks
                            {--company= : One tenant only}
                            {--supplier= : One supplier only}
                            {--force : Re-run every answerable check, ignoring freshness}
                            {--limit=200 : Most suppliers to sweep per tenant in one pass}';

    protected $description = 'Re-run due supplier verification checks and record what has changed';

    public function handle(ContinuousMonitor $monitor, TenantContext $tenants): int
    {
        $this->eachTenant($tenants, function ($companyId) use ($monitor) {
            if (! DueDiligenceAddon::active()) {
                return;
            }

            $swept = 0;
            $changed = 0;

            foreach ($this->candidates($monitor) as $supplier) {
                $run = $monitor->sweep($supplier, 'scheduled', (bool) $this->option('force'));
                $swept++;

                if ($run->findings_new > 0) {
                    $changed++;
                    $this->line("  {$supplier->company_name}: {$run->findings_new} change(s), {$run->alerts_raised} needing attention");
                }
            }

            $this->info("Company {$companyId}: swept {$swept} supplier(s), {$changed} with changes.");
        });

        return self::SUCCESS;
    }

    /**
     * Suppliers worth sweeping now.
     *
     * Ordered by risk band so that when the limit bites it bites on the low-risk tail rather than on
     * the suppliers most worth watching.
     */
    private function candidates(ContinuousMonitor $monitor)
    {
        $query = Supplier::query()->where('is_active', 1);

        if ($id = $this->option('supplier')) {
            return $query->where('id', $id)->get();
        }

        return $query
            ->orderByRaw("CASE risk_band WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'moderate' THEN 2 ELSE 3 END")
            ->orderByRaw('monitoring_due_at IS NULL DESC')
            ->orderBy('monitoring_due_at')
            ->limit((int) $this->option('limit'))
            ->get()
            ->filter(fn (Supplier $supplier) => $this->option('force') || $monitor->isDue($supplier));
    }
}
