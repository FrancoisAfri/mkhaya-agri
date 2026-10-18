<?php

namespace App\Console\Commands\Finance;

use App\Modules\Finance\Accounting\Support\AddressText;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Move VAT numbers out of address fields and into the field that now exists for them.
 *
 * Before the invoice had a VAT line, appending the number to the address was the only way to get
 * it onto the document. The documents now print it properly, so leaving it in the address shows it
 * twice — and worse, the address is the field that gets copied onto delivery notes and envelopes.
 *
 * Runs dry by default. A pass that rewrites addresses across the estate is not something to do on
 * a typo, so you see the exact before and after first and commit with --write.
 */
class LiftVatFromAddresses extends Command
{
    use RunsPerTenant;

    protected $signature = 'finance:lift-vat-from-addresses
                            {--write : Apply the changes; without this the command only reports}
                            {--company= : Limit the pass to one company}';

    protected $description = 'Move VAT numbers typed into address fields into the VAT number field';

    /**
     * CRM owns the address of any party it syncs, so fixing the party alone would be undone on the
     * next sync. Both sides are cleaned, source first.
     */
    private const TARGETS = [
        ['table' => 'crm_accounts', 'address' => ['address'], 'vat' => null],
        ['table' => 'fin_parties', 'address' => ['address', 'postal_address'], 'vat' => 'vat_number'],
    ];

    public function handle(TenantContext $tenants): int
    {
        $write = (bool) $this->option('write');
        $total = 0;

        $this->eachTenant($tenants, function ($companyId) use ($write, &$total) {
            foreach (self::TARGETS as $target) {
                $total += $this->sweep($target, $companyId, $write);
            }
        });

        /*
         * Rows belonging to no company, swept once and outside any tenant context.
         *
         * `fin_parties.company_id` is nullable with no default and no row-level security, and
         * BelongsToCompany stamps from the signed-in user — so anything created by a platform
         * admin, by a console pass, or by an importer lands with no company. On a standalone
         * install that can be the whole book, and a per-tenant sweep would have quietly done
         * nothing at all there.
         *
         * Safe to clean without a tenant: a row owned by nobody cannot be another tenant's. It
         * runs after the per-tenant passes precisely because crm_accounts DOES force row-level
         * security, and a NULL-company row is invisible while a company session variable is set.
         *
         * Skipped entirely under `--company`, because scoping a sweep to one company has to mean
         * one company. Rows outside it are not this run's business even when they are outside
         * every other company too.
         */
        if ($this->option('company') === null) {
            $total += $this->sweepOrphans($write);
        }

        $this->newLine();
        $this->info($write
            ? "Cleaned {$total} address(es)."
            : "{$total} address(es) would be cleaned. Re-run with --write to apply.");

        return self::SUCCESS;
    }

    /** @see the call site for why these are swept separately, and last. */
    private function sweepOrphans(bool $write): int
    {
        $cleaned = 0;
        foreach (self::TARGETS as $target) {
            $cleaned += $this->sweep($target, null, $write);
        }

        return $cleaned;
    }

    private function sweep(array $target, $companyId, bool $write): int
    {
        $columns = array_merge(['id'], $target['address'], array_filter([$target['vat']]));
        $cleaned = 0;

        /*
         * Scoped explicitly rather than trusting row-level security to do it.
         *
         * A first run reported the same party three times — once per tenant — because these
         * queries go through the query builder, which neither CompanyScope (inert in console) nor
         * the RLS session variable filtered here. The writes happened to be idempotent, so nothing
         * would have corrupted; but one company's address was being read while acting as another,
         * and a sweep that rewrites customer records is the last place to leave that to chance.
         */
        DB::table($target['table'])
            ->select(array_merge($columns, ['company_id']))
            ->when($companyId !== null,
                fn ($query) => $query->where('company_id', $companyId),
                fn ($query) => $query->whereNull('company_id'))
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($target, $write, &$cleaned) {
                foreach ($rows as $row) {
                    $changes = [];
                    $found = null;

                    foreach ($target['address'] as $column) {
                        $number = AddressText::taxNumberIn($row->$column);
                        if ($number === null) {
                            continue;
                        }
                        $found ??= $number;
                        $changes[$column] = AddressText::withoutTaxNumber($row->$column);
                    }

                    if ($changes === []) {
                        continue;
                    }

                    // Never overwrite a VAT number somebody set deliberately — the one in the
                    // address is the fallback, not the authority.
                    if ($target['vat'] && blank($row->{$target['vat']})) {
                        $changes[$target['vat']] = $found;
                    }

                    // Report the column that actually changed, not the first one we looked at —
                    // most of these records carry the number in the postal address, not the street.
                    foreach ($changes as $column => $value) {
                        if ($column === ($target['vat'] ?? null)) {
                            continue;
                        }
                        $this->line(sprintf(
                            '  %s#%d %s: %s  →  %s',
                            $target['table'], $row->id, $column, $row->$column, $value ?? '(empty)'
                        ));
                    }

                    if ($write) {
                        DB::table($target['table'])->where('id', $row->id)->update($changes);
                    }
                    $cleaned++;
                }
            });

        return $cleaned;
    }
}
