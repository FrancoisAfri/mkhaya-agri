<?php

namespace Database\Seeders;

use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Ramsey\Uuid\Uuid;

/**
 * Phase 1 GL bootstrap: a South African SME chart of accounts + the current
 * fiscal year's monthly periods (open). Idempotent; company-agnostic (company_id
 * null) so the standalone GL works out of the box.
 */
class FinanceGlSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->accounts() as [$code, $name, $type, $postable, $extra]) {
            FinAccount::updateOrCreate(
                ['company_id' => null, 'code' => $code],
                [
                    // Set uuid explicitly: DatabaseSeeder runs WithoutModelEvents, so the
                    // model's creating-hook doesn't fire. Deterministic (uuid5 of the code)
                    // so re-seeding keeps the same stable identifier.
                    'uuid'           => Uuid::uuid5(Uuid::NAMESPACE_OID, "fin_account:{$code}")->toString(),
                    'name'           => $name,
                    'type'           => $type,
                    'normal_balance' => FinAccount::NORMAL_BALANCE[$type],
                    'is_postable'    => $postable,
                    'is_active'      => true,
                ] + $extra
            );
        }

        $this->seedPeriods();
    }

    /** [code, name, type, is_postable, extra]. */
    private function accounts(): array
    {
        return [
            // Assets
            ['1000', 'Current Assets',            'asset',     false, []],
            ['1100', 'Bank — Current Account',    'asset',     true,  ['bank_link' => 'primary']],
            ['1150', 'Petty Cash',                'asset',     true,  []],
            ['1200', 'Accounts Receivable',       'asset',     true,  []],
            ['1250', 'Retention Receivable',      'asset',     true,  []],
            ['1270', 'Staff Loans Receivable',    'asset',     true,  []],
            ['1300', 'Inventory',                 'asset',     true,  []],
            // Manufacturing: material issued to production sits here until the order completes.
            ['1310', 'Work in Progress',          'asset',     true,  []],
            ['1400', 'VAT Input (Control)',       'asset',     true,  ['tax_code' => 'VAT_INPUT']],
            ['1500', 'Fixed Assets',              'asset',     false, []],
            ['1510', 'Equipment',                 'asset',     true,  []],
            ['1520', 'Accumulated Depreciation',  'asset',     true,  []],
            // Liabilities
            ['2000', 'Current Liabilities',       'liability', false, []],
            ['2100', 'Accounts Payable',          'liability', true,  []],
            ['2150', 'Goods Received Not Invoiced', 'liability', true, []],
            ['2160', 'Landed Cost Clearing',        'liability', true, []],
            ['2200', 'VAT Output (Control)',      'liability', true,  ['tax_code' => 'VAT_OUTPUT']],
            ['2300', 'PAYE Payable',              'liability', true,  []],
            ['2310', 'UIF Payable',               'liability', true,  []],
            ['2320', 'SDL Payable',               'liability', true,  []],
            ['2330', 'Pension Payable',           'liability', true,  []],
            ['2350', 'Other Payroll Deductions',  'liability', true,  []],
            ['2400', 'Salaries Payable (Net)',    'liability', true,  []],
            ['2500', 'Loans Payable',             'liability', true,  []],
            // Equity
            ['3000', "Owner's Equity",            'equity',    false, []],
            ['3100', 'Retained Earnings',         'equity',    true,  []],
            ['3200', 'Current Year Earnings',     'equity',    true,  []],
            // Income
            ['4000', 'Revenue',                   'income',    false, []],
            ['4100', 'Sales',                     'income',    true,  []],
            ['4200', 'Service Income',            'income',    true,  []],
            ['4900', 'Other Income',              'income',    true,  []],
            // Expenses
            ['5000', 'Cost of Sales',             'expense',   true,  []],
            // Manufacturing. Not 5100-5410, which Project Management's mapping uses for its
            // historic cost codes.
            ['5600', 'Production Variance',       'expense',   true,  []],
            ['5610', 'Scrap & Spoilage',          'expense',   true,  []],
            ['5620', 'Inventory Adjustments',     'expense',   true,  []],
            ['5630', 'Inventory Revaluation',     'expense',   true,  []],
            ['6000', 'Operating Expenses',        'expense',   false, []],
            ['6100', 'Salaries & Wages',          'expense',   true,  []],
            ['6110', 'UIF Expense',               'expense',   true,  []],
            ['6120', 'SDL Expense',               'expense',   true,  []],
            ['6200', 'Rent',                      'expense',   true,  []],
            ['6300', 'Fuel',                      'expense',   true,  []],
            ['6400', 'Repairs & Maintenance',     'expense',   true,  []],
            ['6500', 'Depreciation',              'expense',   true,  []],
            ['6800', 'Bad Debts',                 'expense',   true,  []],
            ['6900', 'Bank Charges',              'expense',   true,  []],
        ];
    }

    private function seedPeriods(): void
    {
        // Fiscal year = calendar year for the default set (Mar–Feb SA option later).
        $year  = (int) Carbon::now()->year;
        $start = Carbon::create($year, 1, 1)->startOfDay();

        for ($p = 1; $p <= 12; $p++) {
            $periodStart = (clone $start)->addMonths($p - 1);
            FinFiscalPeriod::updateOrCreate(
                ['company_id' => null, 'fiscal_year' => $year, 'period_no' => $p],
                [
                    'start_date' => $periodStart->toDateString(),
                    'end_date'   => (clone $periodStart)->endOfMonth()->toDateString(),
                    'status'     => 'open',
                ]
            );
        }
    }
}
