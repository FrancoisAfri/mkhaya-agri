<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The platform defaults, which are exactly the constants each module used to carry.
 *
 * The point of seeding these rather than leaving the table empty is that nothing changes on the day
 * the mapping table ships. Every tenant resolves to the same code that was hardcoded a moment
 * before, and only a tenant who edits one changes anything at all.
 *
 * Idempotent, and it never overwrites a tenant's own row — those carry a company_id and these do
 * not, so re-running is safe on an estate that has already customised.
 *
 * ── Written through the query builder, not the model ─────────────────────────
 *
 * This seeder is also called from the migration that creates the table, and a migration runs
 * against the schema as it stood on its own date. The FinAccountMapping model describes the schema
 * as it is today — so when it later gained `entity_id`, the model began writing a column that does
 * not exist yet at that point in the timeline, and every database built from scratch failed to
 * migrate. Naming the columns here keeps the seeder true to the table it is filling.
 *
 * These rows are the platform's own defaults in any case: no company and no business, available to
 * every tenant until one overrides them.
 */
class FinAccountMappingSeeder extends Seeder
{
    /** module => [key => [code, label]] — lifted verbatim from the constants they replace. */
    public const DEFAULTS = [
        'project_management' => [
            // Was AccountingExpenseSink::EXPENSE_ACCOUNTS.
            'cost.material'      => ['5100', 'Project cost — materials'],
            'cost.plant'         => ['5200', 'Project cost — plant'],
            'cost.subcontractor' => ['5300', 'Project cost — subcontractors'],
            'cost.expense'       => ['5400', 'Project cost — expenses'],
            'cost.travel'        => ['5410', 'Project cost — travel'],
            'cost.other'         => ['5400', 'Project cost — other'],
            // Was AccountingExpenseSink::PAYABLES_ACCOUNT.
            'payables'           => ['2100', 'Accounts payable'],
            // New with the revenue sink. Sales, which every chart on this platform carries.
            'revenue.fees'       => ['4100', 'Project fee income'],
            // Certified contractor claims. Cost and payable have obvious homes in the
            // standard chart; `claim.retention_payable` deliberately has none — there is no
            // retention-liability account on this platform, and choosing one is an
            // accounting decision rather than a default. Until it is mapped, claims post
            // nothing and say so.
            'claim.cost'         => ['5000', 'Certified contractor work'],
            'claim.payable'      => ['2100', 'Due to contractors'],
        ],
        'payroll' => [
            // Was PayrollLedgerPoster::ACC.
            'salaries' => ['6100', 'Gross salaries and wages'],
            'paye'     => ['2300', 'PAYE payable'],
            'uif'      => ['2310', 'UIF payable'],
            'pension'  => ['2330', 'Pension payable'],
            'other'    => ['2350', 'Other deductions payable'],
            'net'      => ['2400', 'Net pay payable'],
        ],
    ];

    public function run(): void
    {
        foreach (self::DEFAULTS as $module => $keys) {
            foreach ($keys as $key => [$code, $label]) {
                $existing = DB::table('fin_account_mappings')
                    ->whereNull('company_id')->where('source_module', $module)->where('key', $key)
                    ->first();

                if ($existing) {
                    DB::table('fin_account_mappings')->where('id', $existing->id)
                        ->update(['account_code' => $code, 'label' => $label, 'updated_at' => now()]);

                    continue;
                }

                DB::table('fin_account_mappings')->insert([
                    // The model generated this in a boot hook; the column is NOT NULL, and the
                    // query builder does not run boot hooks.
                    'uuid'          => (string) Str::uuid(),
                    'company_id'    => null,
                    'source_module' => $module,
                    'key'           => $key,
                    'account_code'  => $code,
                    'label'         => $label,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            }
        }
    }
}
