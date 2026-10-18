<?php

namespace App\Modules\Procurement\Governance\Console;

use App\Support\Console\RunsPerTenant;
use App\Modules\Procurement\Governance\Support\Integration\ChainLinker;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reconciles §11's chain: links each procurement record to the requisition, orders, contracts and
 * invoices that have appeared for it since the last run, and opens a record for any requisition
 * that does not have one.
 *
 *   php artisan procgov:sync-chain
 *   php artisan procgov:sync-chain --company=7 --limit=2000
 *   php artisan procgov:sync-chain --no-open
 *
 * This runs nightly rather than only at award time because the documents it links are created
 * afterwards, in other modules, by other people — a contract signed on Tuesday has to show up on
 * the procurement record on Wednesday morning without anyone going to find it.
 *
 * On a tenant with none of the neighbouring modules installed this is a no-op: every step is gated
 * on the owning module being available.
 */
class SyncChainCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'procgov:sync-chain
                            {--company= : Restrict to one company id}
                            {--limit=500 : Maximum records to reconcile per company}
                            {--no-open : Do not open records for unlinked requisitions}';

    protected $description = 'Link procurement records to their requisitions, orders, contracts and invoices';

    public function handle(TenantContext $tenants, ChainLinker $linker): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $open = ! $this->option('no-open');

        $linked = 0;
        $opened = 0;

        $run = function (int $companyId) use ($linker, $limit, $open, &$linked, &$opened): void {
            $companyLinked = $linker->syncAll($companyId, $limit);
            $companyOpened = 0;

            if ($open && Schema::hasTable('procurement_requisitions')) {
                // Requisitions that have progressed past raising but have no procurement record.
                // Opening one is what makes the chain start at the request, as the brief specifies.
                $orphans = DB::table('procurement_requisitions as q')
                    ->where('q.company_id', $companyId)
                    ->whereNull('q.deleted_at')
                    ->whereNotIn('q.status', ['cancelled', 'draft'])
                    ->whereNotExists(function ($sub) {
                        $sub->select(DB::raw(1))
                            ->from('procgov_record_links as l')
                            ->whereColumn('l.entity_id', 'q.id')
                            ->where('l.entity_type', 'procurement_requisitions');
                    })
                    ->orderBy('q.id')
                    ->limit($limit)
                    ->pluck('q.id');

                foreach ($orphans as $requisitionId) {
                    if ($linker->openFromRequisition($companyId, (int) $requisitionId)) {
                        $companyOpened++;
                    }
                }
            }

            $linked += $companyLinked;
            $opened += $companyOpened;

            if ($companyLinked > 0 || $companyOpened > 0) {
                $this->components->twoColumnDetail(
                    "Company {$companyId}",
                    "{$companyLinked} link(s), {$companyOpened} record(s) opened"
                );
            }
        };

        $this->eachTenant($tenants, $run);

        $this->components->info("Procurement chain reconciled: {$linked} link(s), {$opened} record(s) opened.");

        $this->reportTenantFailures('Chain reconciliation');

        return $this->hadTenantFailures() ? self::FAILURE : self::SUCCESS;
    }
}
