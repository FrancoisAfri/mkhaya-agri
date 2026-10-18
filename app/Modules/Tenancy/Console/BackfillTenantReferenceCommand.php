<?php

namespace App\Modules\Tenancy\Console;

use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\TenantContext;
use App\Modules\Tenancy\Services\TenantReferenceSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Backfills the per-tenant reference/lookup data (leave types, document types,
 * categories, statuses, settings, reasons, …) for companies that were created before
 * — or that missed — the automatic provisioning seed. Clones each reference table from
 * the source company (default: company 1, the master).
 *
 * Idempotent: a table already populated for a company is skipped, so this is safe to
 * re-run and won't duplicate. Use --force to re-clone regardless (rarely needed).
 *
 *   php artisan tenants:backfill-reference                 # all companies, dry safe
 *   php artisan tenants:backfill-reference --company=6     # just one company
 *   php artisan tenants:backfill-reference --source=1      # clone from a different master
 *   php artisan tenants:backfill-reference --force         # re-clone even if data exists
 */
class BackfillTenantReferenceCommand extends Command
{
    protected $signature = 'tenants:backfill-reference
        {--company= : Only backfill this company id}
        {--source=1 : Source company id to clone reference data from}
        {--force : Re-clone even tables that already have data}
        {--report : Only REPORT per-company reference coverage; do not seed}';

    protected $description = 'Backfill per-tenant reference data (leave types, etc.) for existing companies';

    public function handle(TenantReferenceSeeder $seeder): int
    {
        $sourceId = (int) $this->option('source');
        $force = (bool) $this->option('force');

        $companies = Company::query()
            ->when($this->option('company'), fn ($q) => $q->whereKey((int) $this->option('company')))
            ->when(! $this->option('report'), fn ($q) => $q->where('id', '!=', $sourceId))
            ->orderBy('id')
            ->get();

        if ($companies->isEmpty()) {
            $this->warn('No target companies found.');

            return self::SUCCESS;
        }

        if ($this->option('report')) {
            return $this->report($companies);
        }

        $this->info("Backfilling reference data from company #{$sourceId} into {$companies->count()} company(ies)…");

        $totalRows = 0;
        foreach ($companies as $company) {
            $res = $seeder->seedFor($company, $sourceId, $force);
            $totalRows += $res['seeded'];
            $skipped = $res['skipped'] ? ' (skipped: '.implode(', ', $res['skipped']).')' : '';
            $this->line("  #{$company->id} {$company->name}: +{$res['seeded']} rows across {$res['tables']} table(s){$skipped}");
        }

        $this->info("Done — seeded {$totalRows} reference rows in total.");

        return self::SUCCESS;
    }

    /**
     * Report only the ACTIONABLE gaps: tables the SOURCE company actually has data in
     * but a tenant doesn't (i.e. what the backfill will actually clone). Tables that are
     * empty in the source too (e.g. asset_type / contract_types that users populate
     * themselves) are NOT gaps the backfill can fix, so they're excluded from the noise.
     */
    private function report(Collection $companies): int
    {
        $seeder = app(TenantReferenceSeeder::class);
        $tenant = app(TenantContext::class);
        $sourceId = (int) $this->option('source');
        $tables = $seeder->managedTables();

        $cloneable = $tenant->run($sourceId, fn () => array_values(array_filter(
            $tables,
            fn ($t) => DB::table($t)->where('company_id', $sourceId)->exists()
        )));

        $this->info("Cloneable reference tables in source company #{$sourceId}: ".count($cloneable));
        $this->line('  '.implode(', ', $cloneable));
        $this->newLine();

        foreach ($companies as $company) {
            if ((int) $company->id === $sourceId) {
                continue;
            }
            $missing = $tenant->run((int) $company->id, fn () => array_values(array_filter(
                $cloneable,
                fn ($t) => ! DB::table($t)->where('company_id', $company->id)->exists()
            )));

            $label = "#{$company->id} {$company->name}";
            $this->line(empty($missing)
                ? "  <info>{$label}: up to date</info>"
                : "  <comment>{$label}: will seed (".count($missing).') '.implode(', ', $missing).'</comment>');
        }

        return self::SUCCESS;
    }
}
