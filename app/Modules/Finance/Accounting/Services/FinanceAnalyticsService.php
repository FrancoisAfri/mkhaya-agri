<?php

namespace App\Modules\Finance\Accounting\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Finance BI analytics — chart-ready datasets built from the ledger and invoices:
 * income by account, expenses by category, income by customer, and a monthly
 * income/expense/net trend.
 */
class FinanceAnalyticsService
{
    public function __construct(private AgeingService $ageing)
    {
    }

    public function analytics(string $from, string $to, ?int $companyId = null): array
    {
        $from = Carbon::parse($from)->toDateString();
        $to   = Carbon::parse($to)->toDateString();
        $year = Carbon::parse($to)->year;

        return [
            'period'               => ['from' => $from, 'to' => $to],
            'income_by_account'    => $this->byAccount('income', 'credit', $from, $to, $companyId),
            'expenses_by_category' => $this->byAccount('expense', 'debit', $from, $to, $companyId),
            'income_by_customer'   => $this->byCustomer('receivable', $from, $to, $companyId),
            'top_suppliers'        => $this->byCustomer('payable', $from, $to, $companyId),
            'monthly'              => $this->monthly($year, $companyId),
            'expense_monthly'      => $this->expenseMonthly($year, $companyId),
            'ar_ap'                => $this->arAp($to, $companyId),
        ];
    }

    /** Receivables vs payables by ageing bucket, for a grouped bar. */
    private function arAp(string $to, ?int $companyId): array
    {
        $buckets = ['current', '1-30', '31-60', '61-90', '91-120', '120+'];
        $ar = $this->ageing->ageing('receivable', $to, $companyId)['totals'];
        $ap = $this->ageing->ageing('payable', $to, $companyId)['totals'];

        return [
            'buckets'     => $buckets,
            'receivables' => array_map(fn ($b) => round((float) ($ar[$b] ?? 0), 2), $buckets),
            'payables'    => array_map(fn ($b) => round((float) ($ap[$b] ?? 0), 2), $buckets),
        ];
    }

    /** Top-5 expense accounts as monthly series, for a stacked bar. */
    private function expenseMonthly(int $year, ?int $companyId): array
    {
        $top = collect($this->byAccount('expense', 'debit', Carbon::create($year, 1, 1)->toDateString(), Carbon::create($year, 12, 31)->toDateString(), $companyId))
            ->take(5)->pluck('name')->all();
        if (! $top) {
            return ['months' => [], 'series' => []];
        }

        $rows = DB::table('fin_journal_lines as l')
            ->tap(fn ($q) => FinanceReportService::scopeToEntity($q, 'l.entity_id'))
            ->join('fin_journals as j', 'j.id', '=', 'l.journal_id')
            ->join('fin_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.type', 'expense')->whereIn('a.name', $top)->where('j.status', '!=', 'draft')
            ->whereBetween('j.journal_date', [Carbon::create($year, 1, 1)->toDateString(), Carbon::create($year, 12, 31)->toDateString()])
            ->when($companyId, fn ($q) => $q->where('l.company_id', $companyId))
            ->selectRaw("a.name, EXTRACT(MONTH FROM j.journal_date) as m, SUM(l.base_debit - l.base_credit) as v")
            ->groupByRaw('a.name, EXTRACT(MONTH FROM j.journal_date)')->get();

        $series = [];
        foreach ($top as $name) {
            $data = array_fill(1, 12, 0.0);
            foreach ($rows->where('name', $name) as $r) {
                $data[(int) $r->m] = round((float) $r->v, 2);
            }
            $series[] = ['name' => $name, 'data' => array_values($data)];
        }

        return ['months' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'], 'series' => $series];
    }

    private function byAccount(string $type, string $side, string $from, string $to, ?int $companyId): array
    {
        $rows = DB::table('fin_journal_lines as l')
            ->tap(fn ($q) => FinanceReportService::scopeToEntity($q, 'l.entity_id'))
            ->join('fin_journals as j', 'j.id', '=', 'l.journal_id')
            ->join('fin_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.type', $type)->where('j.status', '!=', 'draft')
            ->whereBetween('j.journal_date', [$from, $to])
            ->when($companyId, fn ($q) => $q->where('l.company_id', $companyId))
            ->groupBy('a.id', 'a.name')
            ->selectRaw('a.name, ' . ($side === 'credit' ? 'SUM(l.base_credit - l.base_debit)' : 'SUM(l.base_debit - l.base_credit)') . ' as value')
            ->get();

        return $rows->map(fn ($r) => ['name' => $r->name, 'value' => round((float) $r->value, 2)])
            ->filter(fn ($r) => $r['value'] > 0.01)->sortByDesc('value')->values()->all();
    }

    private function byCustomer(string $direction, string $from, string $to, ?int $companyId): array
    {
        return DB::table('fin_invoices as i')
            ->tap(fn ($q) => FinanceReportService::scopeToEntity($q, 'i.entity_id'))
            ->join('fin_parties as p', 'p.id', '=', 'i.party_id')
            ->where('i.direction', $direction)->where('i.doc_type', 'invoice')
            ->whereBetween('i.invoice_date', [$from, $to])
            ->when($companyId, fn ($q) => $q->where('i.company_id', $companyId))
            ->groupBy('p.id', 'p.name')
            ->selectRaw('p.name, SUM(i.total) as value')
            ->orderByDesc('value')->limit(10)->get()
            ->map(fn ($r) => ['name' => $r->name, 'value' => round((float) $r->value, 2)])->all();
    }

    private function monthly(int $year, ?int $companyId): array
    {
        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $start = Carbon::create($year, $m, 1)->startOfMonth();
            $end = $start->copy()->endOfMonth();
            $row = DB::table('fin_journal_lines as l')
                ->tap(fn ($q) => FinanceReportService::scopeToEntity($q, 'l.entity_id'))
                ->join('fin_journals as j', 'j.id', '=', 'l.journal_id')
                ->join('fin_accounts as a', 'a.id', '=', 'l.account_id')
                ->where('j.status', '!=', 'draft')
                ->whereBetween('j.journal_date', [$start->toDateString(), $end->toDateString()])
                ->when($companyId, fn ($q) => $q->where('l.company_id', $companyId))
                ->selectRaw("
                    COALESCE(SUM(CASE WHEN a.type='income' THEN l.base_credit - l.base_debit END),0) income,
                    COALESCE(SUM(CASE WHEN a.type='expense' THEN l.base_debit - l.base_credit END),0) expense
                ")->first();
            $income = round((float) ($row->income ?? 0), 2);
            $expense = round((float) ($row->expense ?? 0), 2);
            $out[] = ['month' => $start->format('M'), 'income' => $income, 'expense' => $expense, 'net' => round($income - $expense, 2)];
        }

        return $out;
    }
}
