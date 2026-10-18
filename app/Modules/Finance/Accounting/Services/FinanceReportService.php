<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Modules\Finance\Accounting\Models\FinAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Real-time financial reports computed straight from fin_journal_lines. Draft
 * journals are excluded; reversed journals net themselves out (original +
 * reversal lines both count).
 */
class FinanceReportService
{
    /**
     * Ask for the books that belong to no company, rather than for all books.
     *
     * `null` has always meant "do not filter by company" here, which is right for a report that
     * consolidates. It leaves no way to ask the opposite question — "show me only the rows with no
     * company_id" — and rows like that exist: everything posted before Finance was tenanted, and
     * everything in a self-hosted single-tenant install. Without this the parity check either skips
     * them entirely or folds them in with every tenant's, and both answers are wrong.
     */
    public const UNASSIGNED = 'unassigned';

    /** Apply a company filter that can express "no company" as well as "one company". */
    public static function scopeToCompany(mixed $query, string $column, int|string|null $companyId): mixed
    {
        if ($companyId === self::UNASSIGNED) {
            return $query->whereNull($column);
        }

        return $companyId ? $query->where($column, $companyId) : $query;
    }

    /**
     * Limit a raw query to the business currently being worked in.
     *
     * The models carry EntityScope, but a report does not read through the models: summing a
     * ledger through Eloquent would mean hydrating every journal line, so these queries go through
     * the query builder, and the query builder has never heard of a global scope. Without this the
     * separation is complete right up until somebody opens a report — which is the one place the
     * mixing would be believed, because a trial balance is what people check the others against.
     *
     * No entity resolved (the console, a cron with no tenant) means no filter, which matches what
     * EntityScope does and keeps a whole-estate sweep whole.
     *
     * A row with no business is included, for the reason EntityScope includes it: it belongs to
     * nobody and everybody posts to it. Since Phase 4 that is the only thing it can mean —
     * `CHECK (company_id IS NULL OR entity_id IS NOT NULL)` on every finance table makes a
     * tenant's row without a business impossible.
     */
    public static function scopeToEntity(mixed $query, string $column): mixed
    {
        $entityId = app(CurrentEntity::class)->id();

        if ($entityId === null) {
            return $query;
        }

        return $query->where(fn ($q) => $q->where($column, $entityId)->orWhereNull($column));
    }

    /**
     * Per-account debit/credit totals and net balance, as at a date.
     *
     * @param  int|string|null  $companyId  a company id, self::UNASSIGNED, or null for every book
     * @return Collection<int, array>
     */
    public function accountBalances(int|string|null $companyId = null, ?string $asOf = null)
    {
        $asOf = $asOf ?: Carbon::now()->toDateString();

        $sums = DB::table('fin_journal_lines as l')
            ->tap(fn ($q) => FinanceReportService::scopeToEntity($q, 'l.entity_id'))
            ->join('fin_journals as j', 'j.id', '=', 'l.journal_id')
            ->where('j.status', '!=', 'draft')
            ->whereDate('j.journal_date', '<=', $asOf)
            ->tap(fn ($q) => self::scopeToCompany($q, 'l.company_id', $companyId))
            ->groupBy('l.account_id')
            ->select('l.account_id', DB::raw('SUM(l.base_debit) as debit'), DB::raw('SUM(l.base_credit) as credit'))
            ->get()->keyBy('account_id');

        return FinAccount::query()
            ->where('is_postable', true)
            ->tap(fn ($q) => self::scopeToCompany($q, 'company_id', $companyId))
            ->orderBy('code')->get()
            ->map(function (FinAccount $a) use ($sums) {
                $debit = (float) ($sums[$a->id]->debit ?? 0);
                $credit = (float) ($sums[$a->id]->credit ?? 0);
                $net = $a->normal_balance === 'debit' ? $debit - $credit : $credit - $debit;

                return [
                    'account_id' => $a->id, 'code' => $a->code, 'name' => $a->name,
                    'type' => $a->type, 'normal_balance' => $a->normal_balance,
                    'debit' => round($debit, 2), 'credit' => round($credit, 2),
                    'balance' => round($net, 2),
                ];
            })
            ->filter(fn ($r) => $r['debit'] != 0 || $r['credit'] != 0)
            ->values();
    }

    /**
     * General Ledger for one account: opening balance, every line in the window
     * with a running balance, and the closing balance. The bookkeeper's workhorse.
     */
    public function accountLedger(int $accountId, ?string $from = null, ?string $to = null, ?int $companyId = null): array
    {
        $account = FinAccount::findOrFail($accountId);
        $to = $to ?: Carbon::now()->toDateString();
        $from = $from ?: Carbon::now()->startOfYear()->toDateString();
        $sign = fn (float $d, float $c) => $account->normal_balance === 'debit' ? $d - $c : $c - $d;

        $prior = DB::table('fin_journal_lines as l')
            ->tap(fn ($q) => FinanceReportService::scopeToEntity($q, 'l.entity_id'))
            ->join('fin_journals as j', 'j.id', '=', 'l.journal_id')
            ->where('l.account_id', $accountId)->where('j.status', '!=', 'draft')
            ->whereDate('j.journal_date', '<', $from)
            ->when($companyId, fn ($q) => $q->where('l.company_id', $companyId))
            ->selectRaw('COALESCE(SUM(l.base_debit),0) d, COALESCE(SUM(l.base_credit),0) c')->first();

        $opening = round($sign((float) $prior->d, (float) $prior->c), 2);

        $lines = DB::table('fin_journal_lines as l')
            ->tap(fn ($q) => FinanceReportService::scopeToEntity($q, 'l.entity_id'))
            ->join('fin_journals as j', 'j.id', '=', 'l.journal_id')
            ->where('l.account_id', $accountId)->where('j.status', '!=', 'draft')
            ->whereDate('j.journal_date', '>=', $from)->whereDate('j.journal_date', '<=', $to)
            ->when($companyId, fn ($q) => $q->where('l.company_id', $companyId))
            ->orderBy('j.journal_date')->orderBy('j.id')
            ->select('j.id as journal_id', 'j.journal_no', 'j.journal_date', 'j.description', 'j.source_module', 'j.status',
                'l.base_debit as debit', 'l.base_credit as credit', 'l.narration')
            ->get();

        $running = $opening;
        $rows = $lines->map(function ($l) use (&$running, $sign) {
            $running = round($running + $sign((float) $l->debit, (float) $l->credit), 2);

            return [
                'journal_id' => $l->journal_id, 'journal_no' => $l->journal_no,
                'date' => Carbon::parse($l->journal_date)->toDateString(), 'description' => $l->description,
                'source' => $l->source_module, 'status' => $l->status, 'narration' => $l->narration,
                'debit' => round((float) $l->debit, 2), 'credit' => round((float) $l->credit, 2),
                'balance' => $running,
            ];
        });

        return [
            'account' => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name,
                'type' => $account->type, 'normal_balance' => $account->normal_balance],
            'from' => $from, 'to' => $to,
            'opening' => $opening,
            'rows' => $rows,
            'debit_total' => round($rows->sum('debit'), 2),
            'credit_total' => round($rows->sum('credit'), 2),
            'closing' => $running,
        ];
    }

    /** Trial balance: every account with a movement, in debit/credit columns. */
    public function trialBalance(int|string|null $companyId = null, ?string $asOf = null): array
    {
        $rows = $this->accountBalances($companyId, $asOf)->map(function ($r) {
            // Present the net balance on its natural side.
            $debitCol = $r['balance'] >= 0 && $r['normal_balance'] === 'debit' ? $r['balance'] : ($r['normal_balance'] === 'debit' ? 0 : max(0, -$r['balance']));
            $creditCol = $r['balance'] >= 0 && $r['normal_balance'] === 'credit' ? $r['balance'] : ($r['normal_balance'] === 'credit' ? 0 : max(0, -$r['balance']));

            return $r + ['debit_col' => round($debitCol, 2), 'credit_col' => round($creditCol, 2)];
        });

        return [
            'rows' => $rows->values(),
            'total_debit' => round($rows->sum('debit_col'), 2),
            'total_credit' => round($rows->sum('credit_col'), 2),
            'balanced' => abs($rows->sum('debit_col') - $rows->sum('credit_col')) < 0.01,
        ];
    }

    /** Income statement (P&L). Net profit = income − expenses. */
    public function incomeStatement(?int $companyId = null, ?string $asOf = null): array
    {
        $bal = $this->accountBalances($companyId, $asOf);
        $income = $bal->where('type', 'income')->values();
        $expenses = $bal->where('type', 'expense')->values();
        $incomeTotal = round($income->sum('balance'), 2);
        $expenseTotal = round($expenses->sum('balance'), 2);

        return [
            'income' => $income,
            'expenses' => $expenses,
            'income_total' => $incomeTotal,
            'expense_total' => $expenseTotal,
            'net_profit' => round($incomeTotal - $expenseTotal, 2),
        ];
    }

    /**
     * Cash Flow (indirect method, MVP). Built from the accounting identity
     * (ΔCash = ΔLiabilities + ΔEquity + NetIncome − ΔNon-cash assets), so the
     * three activity buckets always reconcile to the actual movement in cash.
     * Classification into operating/investing/financing is by account type +
     * light heuristics (cash = bank-linked/named asset; investing = 15xx fixed
     * assets; financing = equity + 25xx/"loan" liabilities).
     */
    public function cashFlow(?int $companyId = null, ?string $from = null, ?string $to = null): array
    {
        $to = $to ?: Carbon::now()->toDateString();
        $from = $from ?: Carbon::now()->startOfYear()->toDateString();
        $start = Carbon::parse($from)->subDay()->toDateString();

        $endBal = $this->accountBalances($companyId, $to)->keyBy('account_id');
        $startBal = $this->accountBalances($companyId, $start)->keyBy('account_id');
        $ids = $endBal->keys()->merge($startBal->keys())->unique();
        $meta = FinAccount::whereIn('id', $ids)->get()->keyBy('id');

        $delta = fn ($id) => round(((float) ($endBal[$id]['balance'] ?? 0)) - ((float) ($startBal[$id]['balance'] ?? 0)), 2);
        $isCash = fn ($a) => $a->type === 'asset' && ($a->bank_link || preg_match('/cash|bank/i', $a->name));
        $isFixed = fn ($a) => $a->type === 'asset' && str_starts_with($a->code, '15');
        $isLoan = fn ($a) => $a->type === 'liability' && (str_starts_with($a->code, '25') || stripos($a->name, 'loan') !== false);

        $pl = $this->incomeStatementBetween($companyId, $from, $to);
        $netIncome = $pl['net_profit'];

        $operating = [['label' => 'Net income for the period', 'amount' => $netIncome]];
        $investing = $financing = [];
        $openingCash = $closingCash = 0.0;

        foreach ($meta as $id => $a) {
            $d = $delta($id);
            if ($isCash($a)) {
                $openingCash += (float) ($startBal[$id]['balance'] ?? 0);
                $closingCash += (float) ($endBal[$id]['balance'] ?? 0);

                continue;
            }
            if ($d == 0.0) {
                continue;
            }
            $label = "{$a->code} · {$a->name}";
            if ($a->type === 'asset' && $isFixed($a)) {
                $investing[] = ['label' => $label, 'amount' => round(-$d, 2)];       // buying assets uses cash
            } elseif ($a->type === 'asset') {
                $operating[] = ['label' => $label, 'amount' => round(-$d, 2)];        // ↑ receivables/inventory = use
            } elseif ($a->type === 'equity') {
                $financing[] = ['label' => $label, 'amount' => round($d, 2)];         // capital movements
            } elseif ($isLoan($a)) {
                $financing[] = ['label' => $label, 'amount' => round($d, 2)];         // loans drawn/repaid
            } elseif ($a->type === 'liability') {
                $operating[] = ['label' => $label, 'amount' => round($d, 2)];         // ↑ payables = source
            }
        }

        $sum = fn ($rows) => round(array_sum(array_column($rows, 'amount')), 2);
        $opTotal = $sum($operating);
        $invTotal = $sum($investing);
        $finTotal = $sum($financing);
        $netChange = round($opTotal + $invTotal + $finTotal, 2);

        return [
            'from' => $from, 'to' => $to,
            'operating' => $operating, 'operating_total' => $opTotal,
            'investing' => $investing, 'investing_total' => $invTotal,
            'financing' => $financing, 'financing_total' => $finTotal,
            'net_change' => $netChange,
            'opening_cash' => round($openingCash, 2),
            'closing_cash' => round($closingCash, 2),
            'reconciles' => abs($netChange - round($closingCash - $openingCash, 2)) < 0.01,
        ];
    }

    /** Income statement restricted to a date window (used by cash flow). */
    private function incomeStatementBetween(?int $companyId, string $from, string $to): array
    {
        $end = $this->incomeStatement($companyId, $to);
        $start = $this->incomeStatement($companyId, Carbon::parse($from)->subDay()->toDateString());

        return [
            'income' => round($end['income_total'] - $start['income_total'], 2),
            'expense' => round($end['expense_total'] - $start['expense_total'], 2),
            'net_profit' => round($end['net_profit'] - $start['net_profit'], 2),
        ];
    }

    /** Balance sheet. Assets = Liabilities + Equity (+ current-year earnings). */
    public function balanceSheet(?int $companyId = null, ?string $asOf = null): array
    {
        $bal = $this->accountBalances($companyId, $asOf);
        $assets = $bal->where('type', 'asset')->values();
        $liabilities = $bal->where('type', 'liability')->values();
        $equity = $bal->where('type', 'equity')->values();

        $assetTotal = round($assets->sum('balance'), 2);
        $liabilityTotal = round($liabilities->sum('balance'), 2);
        $equityTotal = round($equity->sum('balance'), 2);
        $currentEarnings = $this->incomeStatement($companyId, $asOf)['net_profit'];

        $equityAndEarnings = round($equityTotal + $currentEarnings, 2);

        return [
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'asset_total' => $assetTotal,
            'liability_total' => $liabilityTotal,
            'equity_total' => $equityTotal,
            'current_earnings' => $currentEarnings,
            'equity_and_earnings' => $equityAndEarnings,
            'liab_equity_total' => round($liabilityTotal + $equityAndEarnings, 2),
            'balanced' => abs($assetTotal - ($liabilityTotal + $equityAndEarnings)) < 0.01,
        ];
    }
}
