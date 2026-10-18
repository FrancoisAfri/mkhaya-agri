<?php

namespace App\Console\Commands\Finance;

use App\Modules\Finance\Accounting\Models\FinAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Checks that the accounts the ledger posts against actually exist, and installs them if not.
 *
 * Every automatic posting names a control account by code — receivables, payables, VAT, the bank.
 * They come from config, and if one of them is missing from the chart the failure surfaces at the
 * worst possible moment: somebody raises an invoice and is told "Account [1200] not found", with
 * nothing on screen to say which chart, whose company, or what to do about it.
 *
 * This answers that question directly. It reports what is missing before it changes anything, so
 * running it to look is safe, and `--install` is the separate decision.
 */
class EnsureChartOfAccounts extends Command
{
    protected $signature = 'finance:ensure-chart
        {--company= : Check one company. Omit to check the shared chart and every company.}
        {--install : Create the missing control accounts rather than only reporting them.}';

    protected $description = 'Report (and optionally install) the control accounts the ledger posts against';

    /**
     * The accounts the posting engine reaches for, and what each is.
     *
     * Read from config rather than hardcoded, because an installation may have moved one — the
     * point is to check what this installation will actually ask for.
     *
     * @return array<string, array{0: string, 1: string}> code => [name, type]
     */
    private function required(): array
    {
        $wanted = [
            'ar_control' => ['Accounts Receivable', 'asset'],
            'ap_control' => ['Accounts Payable', 'liability'],
            'vat_output' => ['VAT Output (Control)', 'liability'],
            'vat_input' => ['VAT Input (Control)', 'asset'],
            'default_bank' => ['Bank — Current Account', 'asset'],
            'retained_earnings_account' => ['Retained Earnings', 'equity'],
            'bad_debt_account' => ['Bad Debts Written Off', 'expense'],
            'retention_account' => ['Retention Receivable', 'asset'],
            'inventory_account' => ['Inventory', 'asset'],
            'cogs_account' => ['Cost of Sales', 'expense'],
            'fixed_asset_account' => ['Equipment', 'asset'],
            'accum_depreciation_account' => ['Accumulated Depreciation', 'asset'],
            'depreciation_expense_account' => ['Depreciation', 'expense'],
            'asset_disposal_account' => ['Gain/Loss on Disposal', 'income'],
            'staff_loan_receivable' => ['Staff Loans Receivable', 'asset'],
            // Manufacturing: material issued to production, and what an order's cost differed by.
            'wip_account' => ['Work in Progress', 'asset'],
            'production_variance_account' => ['Production Variance', 'expense'],
            'scrap_account' => ['Scrap & Spoilage', 'expense'],
        ];

        $required = [];

        foreach ($wanted as $key => [$name, $type]) {
            $code = (string) config("finance.{$key}");

            if ($code !== '') {
                $required[$code] = [$name, $type];
            }
        }

        return $required;
    }

    public function handle(): int
    {
        $companies = $this->option('company') !== null
            ? [(int) $this->option('company')]
            : array_merge([null], DB::table('companies')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all());

        $required = $this->required();
        $anyMissing = false;

        foreach ($companies as $companyId) {
            $label = $companyId === null ? 'the shared chart' : "company {$companyId}";

            /*
             * The company's own accounts plus the shared ones, because that is what the ledger
             * resolves against — a company with no chart of its own posts perfectly well against
             * the shared one, and reporting it as broken would send somebody fixing nothing.
             */
            $available = FinAccount::query()
                ->where(fn ($q) => $q->whereNull('company_id')
                    ->when($companyId, fn ($inner) => $inner->orWhere('company_id', $companyId)))
                ->get()
                ->keyBy('code');

            $missing = [];
            $unpostable = [];

            foreach ($required as $code => [$name, $type]) {
                $account = $available->get($code);

                if (! $account) {
                    $missing[$code] = [$name, $type];
                } elseif (! $account->is_postable || ! $account->is_active) {
                    $unpostable[$code] = $account->name;
                }
            }

            if ($missing === [] && $unpostable === []) {
                $this->info("✓ {$label}: all " . count($required) . ' control accounts present and postable.');

                continue;
            }

            $anyMissing = true;
            $this->warn("✗ {$label}:");

            foreach ($missing as $code => [$name, $type]) {
                $this->line("    missing    {$code}  {$name} ({$type})");
            }

            foreach ($unpostable as $code => $name) {
                $this->line("    not postable  {$code}  {$name}");
            }

            if ($this->option('install') && $missing !== []) {
                foreach ($missing as $code => [$name, $type]) {
                    FinAccount::create([
                        'uuid' => (string) Str::uuid(),
                        'company_id' => $companyId,
                        'code' => $code,
                        'name' => $name,
                        'type' => $type,
                        'is_postable' => true,
                        'is_active' => true,
                    ]);
                }

                $this->info('    installed ' . count($missing) . " account(s) for {$label}.");
            }
        }

        if ($anyMissing && ! $this->option('install')) {
            $this->newLine();
            $this->line('Run again with --install to create the missing accounts.');
            $this->line('An account marked "not postable" is a heading; fix that one by hand — making a');
            $this->line('heading postable would put transactions above its own subtotals.');
        }

        return $anyMissing && ! $this->option('install') ? self::FAILURE : self::SUCCESS;
    }
}
