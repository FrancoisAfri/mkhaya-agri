<?php

namespace App\Modules\Finance\Accounting\Services;

use Illuminate\Support\Facades\DB;

/**
 * Normalises every finance report into a uniform {title, columns, rows, totals}
 * shape so one tabbed page can render and export them all (Phase 6.2).
 */
class StatementsService
{
    public const TABS = [
        'profit_loss'          => 'Profit & Loss',
        'balance_sheet'        => 'Balance Sheet',
        'trial_balance'        => 'Trial Balance',
        'cash_flow'            => 'Cash Flow',
        'income_by_customer'   => 'Income by Customer',
        'expenses_by_category' => 'Expenses by Category',
        'ar_aging'             => 'AR Aging',
        'ap_aging'             => 'AP Aging',
        'vat201'               => 'VAT 201',
        'by_tracking'          => 'By Tracking',
    ];

    public function __construct(
        private FinanceReportService $reports,
        private AgeingService $ageing,
        private TaxService $tax,
        private FinanceAnalyticsService $analytics,
    ) {
    }

    private function col(string $key, string $label, string $align = 'left'): array
    {
        return compact('key', 'label', 'align');
    }

    public function report(string $tab, string $from, string $to, ?int $companyId = null): array
    {
        $title = self::TABS[$tab] ?? 'Report';
        [$columns, $rows, $totals] = match ($tab) {
            'profit_loss'          => $this->profitLoss($companyId, $to),
            'balance_sheet'        => $this->balanceSheet($companyId, $to),
            'trial_balance'        => $this->trialBalance($companyId, $to),
            'cash_flow'            => $this->cashFlow($companyId, $from, $to),
            'income_by_customer'   => $this->incomeByCustomer($from, $to, $companyId),
            'expenses_by_category' => $this->expensesByCategory($from, $to, $companyId),
            'ar_aging'             => $this->aging('receivable', $to, $companyId),
            'ap_aging'             => $this->aging('payable', $to, $companyId),
            'vat201'               => $this->vat($from, $to, $companyId),
            'by_tracking'          => $this->byTracking($from, $to, $companyId),
            default                => [[], [], null],
        };

        return ['tab' => $tab, 'title' => $title, 'columns' => $columns, 'rows' => $rows, 'totals' => $totals];
    }

    private function amountCols(): array
    {
        return [$this->col('label', 'Account'), $this->col('amount', 'Amount', 'right')];
    }

    private function profitLoss(?int $companyId, ?string $to): array
    {
        $r = $this->reports->incomeStatement($companyId, $to);
        $rows = [];
        $rows[] = ['label' => 'INCOME', 'amount' => null, 'group' => true];
        foreach ($r['income'] as $i) {
            $rows[] = ['label' => "{$i['code']} · {$i['name']}", 'amount' => $i['balance']];
        }
        $rows[] = ['label' => 'Total income', 'amount' => $r['income_total'], 'subtotal' => true];
        $rows[] = ['label' => 'EXPENSES', 'amount' => null, 'group' => true];
        foreach ($r['expenses'] as $e) {
            $rows[] = ['label' => "{$e['code']} · {$e['name']}", 'amount' => $e['balance']];
        }
        $rows[] = ['label' => 'Total expenses', 'amount' => $r['expense_total'], 'subtotal' => true];

        return [$this->amountCols(), $rows, ['label' => 'Net profit / (loss)', 'amount' => $r['net_profit']]];
    }

    private function balanceSheet(?int $companyId, ?string $to): array
    {
        $r = $this->reports->balanceSheet($companyId, $to);
        $rows = [];
        foreach ([['ASSETS', 'assets', 'asset_total'], ['LIABILITIES', 'liabilities', 'liability_total'], ['EQUITY', 'equity', 'equity_total']] as [$hdr, $key, $tot]) {
            $rows[] = ['label' => $hdr, 'amount' => null, 'group' => true];
            foreach ($r[$key] as $a) {
                $rows[] = ['label' => "{$a['code']} · {$a['name']}", 'amount' => $a['balance']];
            }
            $rows[] = ['label' => "Total {$hdr}", 'amount' => $r[$tot], 'subtotal' => true];
        }
        $rows[] = ['label' => 'Current-year earnings', 'amount' => $r['current_earnings']];

        return [$this->amountCols(), $rows, ['label' => 'Liabilities + equity', 'amount' => $r['liab_equity_total']]];
    }

    private function trialBalance(?int $companyId, ?string $to): array
    {
        $r = $this->reports->trialBalance($companyId, $to);
        $cols = [$this->col('code', 'Code'), $this->col('name', 'Account'), $this->col('debit', 'Debit', 'right'), $this->col('credit', 'Credit', 'right')];
        $rows = collect($r['rows'])->map(fn ($x) => ['code' => $x['code'], 'name' => $x['name'], 'debit' => $x['debit_col'], 'credit' => $x['credit_col']])->all();

        return [$cols, $rows, ['code' => '', 'name' => 'Total', 'debit' => $r['total_debit'], 'credit' => $r['total_credit']]];
    }

    private function cashFlow(?int $companyId, ?string $from, ?string $to): array
    {
        $r = $this->reports->cashFlow($companyId, $from, $to);
        $rows = [];
        foreach ([['OPERATING', 'operating', 'operating_total'], ['INVESTING', 'investing', 'investing_total'], ['FINANCING', 'financing', 'financing_total']] as [$hdr, $key, $tot]) {
            $rows[] = ['label' => $hdr, 'amount' => null, 'group' => true];
            foreach ($r[$key] as $line) {
                $rows[] = ['label' => $line['label'], 'amount' => $line['amount']];
            }
            $rows[] = ['label' => "Net {$hdr}", 'amount' => $r[$tot], 'subtotal' => true];
        }
        $rows[] = ['label' => 'Opening cash', 'amount' => $r['opening_cash']];
        $rows[] = ['label' => 'Closing cash', 'amount' => $r['closing_cash'], 'subtotal' => true];

        return [$this->amountCols(), $rows, ['label' => 'Net change in cash', 'amount' => $r['net_change']]];
    }

    private function incomeByCustomer(string $from, string $to, ?int $companyId): array
    {
        $data = $this->analytics->analytics($from, $to, $companyId)['income_by_customer'];
        $rows = array_map(fn ($s) => ['label' => $s['name'], 'amount' => $s['value']], $data);

        return [[$this->col('label', 'Customer'), $this->col('amount', 'Invoiced', 'right')], $rows, ['label' => 'Total', 'amount' => round(array_sum(array_column($data, 'value')), 2)]];
    }

    private function expensesByCategory(string $from, string $to, ?int $companyId): array
    {
        $data = $this->analytics->analytics($from, $to, $companyId)['expenses_by_category'];
        $rows = array_map(fn ($s) => ['label' => $s['name'], 'amount' => $s['value']], $data);

        return [[$this->col('label', 'Category'), $this->col('amount', 'Spent', 'right')], $rows, ['label' => 'Total', 'amount' => round(array_sum(array_column($data, 'value')), 2)]];
    }

    private function aging(string $direction, ?string $to, ?int $companyId): array
    {
        $r = $this->ageing->ageing($direction, $to, $companyId);
        $buckets = ['current', '1-30', '31-60', '61-90', '91-120', '120+', 'total'];
        $cols = array_merge([$this->col('party', $direction === 'receivable' ? 'Customer' : 'Supplier')], array_map(fn ($b) => $this->col($b, ucfirst($b), 'right'), $buckets));
        $rows = array_values($r['rows']);
        $totals = array_merge(['party' => 'Total'], array_intersect_key($r['totals'], array_flip($buckets)));

        return [$cols, $rows, $totals];
    }

    private function vat(string $from, string $to, ?int $companyId): array
    {
        $r = $this->tax->vat201($from, $to, $companyId);
        $cols = [$this->col('field', 'Box'), $this->col('label', 'Description'), $this->col('amount', 'Amount', 'right')];

        return [$cols, $r['boxes'], ['field' => '', 'label' => $r['is_payable'] ? 'Net VAT payable' : 'Net VAT refundable', 'amount' => abs($r['net'])]];
    }

    private function byTracking(string $from, string $to, ?int $companyId): array
    {
        $rows = DB::table('fin_journal_lines as l')
            ->tap(fn ($q) => FinanceReportService::scopeToEntity($q, 'l.entity_id'))
            ->join('fin_journals as j', 'j.id', '=', 'l.journal_id')
            ->join('fin_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('j.status', '!=', 'draft')
            ->whereBetween('j.journal_date', [$from, $to])
            ->whereIn('a.type', ['income', 'expense'])
            ->when($companyId, fn ($q) => $q->where('l.company_id', $companyId))
            ->selectRaw("COALESCE(l.dimensions->>'tracking', 'Untracked') as tracking,
                COALESCE(SUM(CASE WHEN a.type='income' THEN l.base_credit - l.base_debit END),0) income,
                COALESCE(SUM(CASE WHEN a.type='expense' THEN l.base_debit - l.base_credit END),0) expense")
            ->groupByRaw("COALESCE(l.dimensions->>'tracking', 'Untracked')")
            ->get()
            ->map(fn ($r) => ['tracking' => $r->tracking, 'income' => round((float) $r->income, 2), 'expense' => round((float) $r->expense, 2), 'net' => round((float) $r->income - (float) $r->expense, 2)])
            ->all();

        $cols = [$this->col('tracking', 'Tracking'), $this->col('income', 'Income', 'right'), $this->col('expense', 'Expense', 'right'), $this->col('net', 'Net', 'right')];
        $totals = ['tracking' => 'Total', 'income' => round(array_sum(array_column($rows, 'income')), 2), 'expense' => round(array_sum(array_column($rows, 'expense')), 2), 'net' => round(array_sum(array_column($rows, 'net')), 2)];

        return [$cols, $rows, $totals];
    }
}
