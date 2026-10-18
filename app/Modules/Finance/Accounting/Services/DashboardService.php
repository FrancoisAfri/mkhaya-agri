<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Modules\Finance\Accounting\Models\FinAsset;
use App\Modules\Finance\Accounting\Models\FinBankAccount;
use App\Modules\Finance\Accounting\Models\FinBudget;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Live finance dashboard (Phase 6.1). Every widget is computed from the ledger /
 * operational tables and carries a drill target so a KPI reaches its source
 * documents in a couple of clicks.
 */
class DashboardService
{
    public function __construct(
        private FinanceReportService $reports,
        private CashFlowForecastService $forecast,
        private TaxService $tax,
    ) {
    }

    /** Net movement of an account type on its natural side within a date range. */
    private function typeMovement(string $type, string $side, string $from, string $to, ?int $companyId): float
    {
        $row = DB::table('fin_journal_lines as l')
            ->tap(fn ($q) => FinanceReportService::scopeToEntity($q, 'l.entity_id'))
            ->join('fin_journals as j', 'j.id', '=', 'l.journal_id')
            ->join('fin_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.type', $type)->where('j.status', '!=', 'draft')
            ->whereBetween('j.journal_date', [$from, $to])
            ->when($companyId, fn ($q) => $q->where('l.company_id', $companyId))
            ->selectRaw('COALESCE(SUM(l.base_debit),0) d, COALESCE(SUM(l.base_credit),0) c')->first();
        $d = (float) ($row->d ?? 0);
        $c = (float) ($row->c ?? 0);

        return round($side === 'credit' ? $c - $d : $d - $c, 2);
    }

    private function profit(string $from, string $to, ?int $companyId): float
    {
        return round($this->typeMovement('income', 'credit', $from, $to, $companyId)
            - $this->typeMovement('expense', 'debit', $from, $to, $companyId), 2);
    }

    /** @return array<int, array<string, mixed>> ordered widget definitions with values */
    public function widgets(?int $companyId = null): array
    {
        $today = Carbon::today();
        $balances = collect($this->reports->accountBalances($companyId));

        // Cash & bank
        $cashIds = FinBankAccount::whereIn('type', ['bank', 'petty_cash', 'wallet'])->where('is_active', true)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))->pluck('account_id')->filter()->all();
        $cash = round($balances->whereIn('account_id', $cashIds)->sum('balance'), 2);

        // Net worth = assets − liabilities
        $assets = round($balances->where('type', 'asset')->sum('balance'), 2);
        $liabilities = round($balances->where('type', 'liability')->sum('balance'), 2);
        $netWorth = round($assets - $liabilities, 2);

        // AR / AP open + overdue
        $ar = FinInvoice::query()->invoicesOnly()->open()->receivable()->when($companyId, fn ($q) => $q->where('company_id', $companyId))->get(['total', 'amount_paid', 'due_date']);
        $ap = FinInvoice::query()->invoicesOnly()->open()->payable()->when($companyId, fn ($q) => $q->where('company_id', $companyId))->get(['total', 'amount_paid']);
        $arOut = round($ar->sum(fn ($i) => (float) $i->total - (float) $i->amount_paid), 2);
        $apOut = round($ap->sum(fn ($i) => (float) $i->total - (float) $i->amount_paid), 2);
        $overdue = $ar->filter(fn ($i) => $i->due_date && Carbon::parse($i->due_date)->lt($today) && ((float) $i->total - (float) $i->amount_paid) > 0.01);
        $overdueVal = round($overdue->sum(fn ($i) => (float) $i->total - (float) $i->amount_paid), 2);

        // VAT due (current SA 2-month cycle would need a calendar; use this month as a live view)
        $vat = $this->tax->vat201($today->copy()->startOfMonth()->toDateString(), $today->copy()->endOfMonth()->toDateString(), $companyId);

        // Asset book value
        $assetValue = round((float) FinAsset::active()->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->selectRaw('COALESCE(SUM(cost - accumulated_depreciation),0) v')->value('v'), 2);

        // Payroll due = Salaries Payable (Net) control balance
        $salPayable = $balances->firstWhere('code', '2400');
        $payrollDue = round((float) ($salPayable['balance'] ?? 0), 2);

        // Budget vs actual — the newest budget for the current fiscal year
        $budget = FinBudget::with('lines')->where('fiscal_year', (int) $today->year)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))->latest('id')->first();
        $budgetVariance = null;
        if ($budget) {
            $budgetVariance = app(BudgetService::class)->variance($budget, (int) $today->month)['totals'];
        }

        // Cash-flow projection
        $forecast = $this->forecast->forecast(4, $companyId)['summary'];

        $w = fn (string $key, string $title, $value, string $format, array $drill, ?string $sub = null) => compact('key', 'title', 'value', 'format', 'drill', 'sub');
        $ledger = fn (?int $accId) => ['label' => 'View ledger', 'route' => 'finance.accounting.ledger', 'params' => $accId ? ['account_id' => $accId] : []];

        return array_values(array_filter([
            $w('cash_bank', 'Cash & bank', $cash, 'money', ['label' => 'Banking', 'route' => 'finance.accounting.banking', 'params' => []]),
            $w('profit_month', 'Profit — this month', $this->profit($today->copy()->startOfMonth()->toDateString(), $today->toDateString(), $companyId), 'money', ['label' => 'Income statement', 'route' => 'finance.accounting.reports', 'params' => []], 'Today: ' . number_format($this->profit($today->toDateString(), $today->toDateString(), $companyId), 2)),
            $w('cashflow', 'Projected cash (4 wk)', $forecast['closing'], 'money', ['label' => 'Cash flow forecast', 'route' => 'finance.accounting.cash-forecast', 'params' => []], $forecast['goes_negative'] ? 'Dips to ' . number_format($forecast['lowest']['balance'], 2) : null),
            $w('ar_outstanding', 'Outstanding receivables', $arOut, 'money', ['label' => 'Receivables', 'route' => 'finance.accounting.receivables', 'params' => []]),
            $w('ap_outstanding', 'Outstanding payables', $apOut, 'money', ['label' => 'Payables', 'route' => 'finance.accounting.payables', 'params' => []]),
            $w('overdue', 'Overdue invoices', $overdueVal, 'money', ['label' => 'Debtors manager', 'route' => 'finance.accounting.debtors', 'params' => []], $overdue->count() . ' invoice(s)'),
            $w('vat_due', $vat['is_payable'] ? 'VAT payable (MTD)' : 'VAT refundable (MTD)', abs($vat['net']), 'money', ['label' => 'VAT / tax', 'route' => 'finance.accounting.tax', 'params' => []]),
            $w('payroll_due', 'Payroll due (net)', $payrollDue, 'money', $ledger($salPayable['account_id'] ?? null)),
            $w('asset_value', 'Asset book value', $assetValue, 'money', ['label' => 'Fixed assets', 'route' => 'finance.accounting.assets', 'params' => []]),
            $budgetVariance ? $w('budget', 'Budget variance (YTD)', $budgetVariance['variance'], 'money', ['label' => 'Budgets', 'route' => 'finance.accounting.budgets', 'params' => []], 'Actual ' . number_format($budgetVariance['actual'], 0) . ' vs ' . number_format($budgetVariance['budget'], 0)) : null,
            $w('expected_income', 'Expected income (4 wk)', $forecast['total_in'], 'money', ['label' => 'Cash flow forecast', 'route' => 'finance.accounting.cash-forecast', 'params' => []]),
            $w('expected_expenses', 'Expected outflows (4 wk)', $forecast['total_out'], 'money', ['label' => 'Cash flow forecast', 'route' => 'finance.accounting.cash-forecast', 'params' => []]),
            $w('net_worth', 'Company net worth', $netWorth, 'money', ['label' => 'Balance sheet', 'route' => 'finance.accounting.reports', 'params' => []], 'Assets ' . number_format($assets, 0) . ' − Liab ' . number_format($liabilities, 0)),
        ]));
    }
}
