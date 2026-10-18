<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Modules\Finance\Accounting\Contracts\FinancialEvent;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Models\FinFiscalYear;
use App\Modules\Finance\Accounting\Models\FinJournal;
use Illuminate\Support\Carbon;

/**
 * Year-end run (1.3): closes the P&L into retained earnings and opens the next
 * year's periods. Balance-sheet accounts carry forward automatically because the
 * ledger is continuous — only income/expense need resetting each year.
 *
 * closeYear() is the fiscal-year-aware entry point (uses the year's real end date, so an Aug–Jul
 * year closes on 31 Jul); close(int) is kept for the legacy Jan–Dec command path. stepsFor() drives
 * the guided year-end wizard.
 */
class YearEndService
{
    public function __construct(
        private Ledger $ledger,
        private FinanceReportService $reports,
        private FiscalYearService $years,
    ) {
    }

    /**
     * Close a specific fiscal year (fiscal-calendar aware): zero the P&L into retained earnings as
     * at the year's real end date, mark the year closed, and generate the next fiscal year.
     */
    public function closeYear(FinFiscalYear $fy): array
    {
        $yearEnd = Carbon::parse($fy->end_date)->endOfDay();
        $key = "year_end_close:fy:{$fy->id}";

        $r = $this->postClosing($yearEnd, $fy->company_id, $key, 'Year-end close ' . $fy->label);

        $fy->update(['status' => 'closed', 'closed_at' => now()]);
        $next = $this->years->createNextYear($fy->company_id);

        return $r + ['next_year_label' => $next->label, 'next_year_id' => $next->id];
    }

    /**
     * Guided-wizard preview for a fiscal year — the step statuses shown before committing.
     *
     * @return array<int, array{key:string,label:string,done:bool,detail:?string}>
     */
    public function stepsFor(FinFiscalYear $fy): array
    {
        $periods = $fy->periods()->get();
        $closed = $periods->whereIn('status', ['hard_close', 'locked'])->count();
        $allClosed = $periods->count() > 0 && $closed === $periods->count();

        $yearEnd = Carbon::parse($fy->end_date)->toDateString();
        $bal = $this->reports->accountBalances($fy->company_id, $yearEnd);
        $income = round($bal->where('type', 'income')->sum('balance'), 2);
        $expenses = round($bal->where('type', 'expense')->sum('balance'), 2);
        $net = round($income - $expenses, 2);

        $alreadyClosed = FinJournal::where('idempotency_key', "year_end_close:fy:{$fy->id}")->exists();
        $nextExists = FinFiscalYear::where('company_id', $fy->company_id)
            ->whereDate('start_date', Carbon::parse($fy->end_date)->addDay()->toDateString())->exists();

        return [
            ['key' => 'periods_closed', 'label' => 'All periods hard-closed', 'done' => $allClosed, 'detail' => "{$closed} of {$periods->count()} closed"],
            ['key' => 'close_revenue', 'label' => 'Close revenue accounts', 'done' => $alreadyClosed, 'detail' => number_format($income, 2)],
            ['key' => 'close_expenses', 'label' => 'Close expense accounts', 'done' => $alreadyClosed, 'detail' => number_format($expenses, 2)],
            ['key' => 'transfer_profit', 'label' => 'Transfer net ' . ($net >= 0 ? 'profit' : 'loss') . ' to retained earnings', 'done' => $alreadyClosed, 'detail' => number_format($net, 2)],
            ['key' => 'opening_balances', 'label' => 'Opening balances carried forward', 'done' => true, 'detail' => 'automatic (continuous ledger)'],
            ['key' => 'next_year', 'label' => 'Generate next financial year', 'done' => $nextExists, 'detail' => null],
        ];
    }

    /**
     * Legacy Jan–Dec close by fiscal_year int (kept for the existing command path). Closes the P&L
     * and opens the next year's periods.
     */
    public function close(int $year, ?int $companyId = null, bool $createNextPeriods = true): array
    {
        $yearEnd = $this->yearEndDate($year, $companyId);
        $r = $this->postClosing($yearEnd, $companyId, 'year_end_close:' . $year . ($companyId ? ":{$companyId}" : ''), "Year-end close {$year}");
        $r['next_periods_created'] = $createNextPeriods ? $this->openNextYear($year + 1, $companyId, $yearEnd) : 0;

        return $r;
    }

    /**
     * Post the closing journal: zero every income/expense account into retained earnings as at
     * $yearEnd. Idempotent on $key — if already closed, report from the existing journal.
     *
     * @return array{journal_no: ?string, net_profit: float, income_closed: float, expense_closed: float, accounts_closed: int, already_closed: bool}
     */
    private function postClosing(Carbon $yearEnd, ?int $companyId, string $key, string $label): array
    {
        $existing = FinJournal::where('idempotency_key', $key)->with('lines')->first();
        if ($existing) {
            $reAccount = FinAccount::where('code', (string) config('finance.retained_earnings_account', '3100'))->first();
            $reLine = $reAccount ? $existing->lines->firstWhere('account_id', $reAccount->id) : null;
            $net = $reLine ? round((float) $reLine->credit - (float) $reLine->debit, 2) : 0.0;

            return ['journal_no' => $existing->journal_no, 'net_profit' => $net,
                'income_closed' => 0.0, 'expense_closed' => 0.0, 'accounts_closed' => 0, 'already_closed' => true];
        }

        $balances = $this->reports->accountBalances($companyId, $yearEnd->toDateString());
        $income   = $balances->where('type', 'income')->where('balance', '!=', 0.0);
        $expenses = $balances->where('type', 'expense')->where('balance', '!=', 0.0);
        $incomeTotal  = round($income->sum('balance'), 2);
        $expenseTotal = round($expenses->sum('balance'), 2);
        $netProfit    = round($incomeTotal - $expenseTotal, 2);

        $debits = $credits = [];
        foreach ($income as $r) {
            $debits[] = ['account' => $r['code'], 'amount' => round($r['balance'], 2), 'narration' => $label];
        }
        foreach ($expenses as $r) {
            $credits[] = ['account' => $r['code'], 'amount' => round($r['balance'], 2), 'narration' => $label];
        }
        $reCode = (string) config('finance.retained_earnings_account', '3100');
        if ($netProfit > 0) {
            $credits[] = ['account' => $reCode, 'amount' => $netProfit, 'narration' => $label];
        } elseif ($netProfit < 0) {
            $debits[] = ['account' => $reCode, 'amount' => abs($netProfit), 'narration' => $label];
        }

        $journalNo = null;
        if ($debits || $credits) {
            $this->assertRetainedEarningsAccount($reCode, $companyId);
            $journalNo = $this->ledger->post(new FinancialEvent(
                sourceModule: 'year_end', eventType: 'year_end.close',
                debits: $debits, credits: $credits, eventDate: $yearEnd, companyId: $companyId,
                sourceRef: $key, description: $label, idempotencyKey: $key,
            ))->journal_no;
        }

        return ['journal_no' => $journalNo, 'net_profit' => $netProfit, 'income_closed' => $incomeTotal,
            'expense_closed' => $expenseTotal, 'accounts_closed' => $income->count() + $expenses->count(), 'already_closed' => false];
    }

    private function assertRetainedEarningsAccount(string $code, ?int $companyId): void
    {
        $ok = FinAccount::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where('code', $code)->where('is_postable', true)->where('is_active', true)->exists();

        if (! $ok) {
            throw new PostingException("Retained earnings account [{$code}] is missing or not postable — set finance.retained_earnings_account.");
        }
    }

    /** Last day of the fiscal year (from the year's periods, else 31 Dec). */
    private function yearEndDate(int $year, ?int $companyId): Carbon
    {
        $end = FinFiscalPeriod::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId), fn ($q) => $q->whereNull('company_id'))
            ->where('fiscal_year', $year)->max('end_date');

        return $end ? Carbon::parse($end)->endOfDay() : Carbon::create($year, 12, 31)->endOfDay();
    }

    /** Ensure the next fiscal year (+ its periods, + a FinFiscalYear row) exist. Returns count created. */
    private function openNextYear(int $year, ?int $companyId, Carbon $prevYearEnd): int
    {
        $before = FinFiscalPeriod::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId), fn ($q) => $q->whereNull('company_id'))
            ->count();

        $this->years->createNextYear($companyId);

        $after = FinFiscalPeriod::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId), fn ($q) => $q->whereNull('company_id'))
            ->count();

        return max(0, $after - $before);
    }
}
