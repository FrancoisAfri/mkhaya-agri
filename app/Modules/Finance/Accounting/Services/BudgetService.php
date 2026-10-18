<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Budget\Services\AccountingBudgetSyncService;
use App\Modules\Finance\Accounting\Models\FinBudget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Budgeting engine (Phase 5.3): save budgets by account/period and report budget
 * vs the actual GL movement with variance. Assumes a calendar fiscal year.
 */
class BudgetService
{
    public function save(array $data, ?FinBudget $budget = null): FinBudget
    {
        $saved = DB::transaction(function () use ($data, $budget) {
            $budget ??= new FinBudget();
            $budget->fill([
                'name'        => $data['name'],
                'fiscal_year' => (int) $data['fiscal_year'],
                'period_type' => $data['period_type'] ?? 'monthly',
                'notes'       => $data['notes'] ?? null,
                'company_id'  => $data['company_id'] ?? $budget->company_id,
            ]);
            $budget->created_by ??= Auth::id();
            $budget->save();

            $budget->lines()->delete();
            foreach ($data['lines'] ?? [] as $line) {
                if (empty($line['account_id'])) {
                    continue;
                }
                $budget->lines()->create([
                    'account_id' => $line['account_id'],
                    'period_no'  => (int) ($line['period_no'] ?? 0),
                    'amount'     => round((float) ($line['amount'] ?? 0), 2),
                    'dimension'  => $line['dimension'] ?? null,
                ]);
            }

            return $budget->fresh('lines');
        });

        $this->mirror($saved);

        return $saved;
    }

    /**
     * Push this budget into the module that controls the money, when that module is installed.
     *
     * Deliberately after the transaction commits, and deliberately swallowing its failures: a
     * company saving an accounting budget must not be shown an error because a downstream mirror
     * hiccuped. The nightly `budgets:sync-accounting` sweep repairs anything missed, so the worst
     * case is a mirror that is a day stale rather than an accountant who cannot save their work.
     */
    private function mirror(FinBudget $budget): void
    {
        try {
            app(AccountingBudgetSyncService::class)->sync($budget);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function setStatus(FinBudget $budget, string $status): FinBudget
    {
        $budget->update([
            'status'      => $status,
            'approved_by' => $status === 'approved' ? Auth::id() : $budget->approved_by,
            'approved_at' => $status === 'approved' ? now() : $budget->approved_at,
        ]);

        // Approving here is what makes the mirrored budget spendable downstream.
        $this->mirror($budget);

        return $budget;
    }

    /**
     * Budget vs actual for a fiscal year through a given month (1-12, default 12).
     * @return array{rows: array, totals: array}
     */
    public function variance(FinBudget $budget, ?int $throughMonth = null): array
    {
        $throughMonth = $throughMonth ? max(1, min(12, $throughMonth)) : 12;
        $yearStart = Carbon::create($budget->fiscal_year, 1, 1)->startOfDay();
        $periodEnd = Carbon::create($budget->fiscal_year, $throughMonth, 1)->endOfMonth();

        // Budgeted amount per account (period 0 = whole year; else months up to throughMonth).
        $budgetByAccount = [];
        foreach ($budget->lines as $l) {
            if ($l->period_no === 0 || $l->period_no <= $throughMonth) {
                $factor = $l->period_no === 0 ? $throughMonth / 12 : 1; // prorate an annual figure
                $budgetByAccount[$l->account_id] = ($budgetByAccount[$l->account_id] ?? 0) + (float) $l->amount * $factor;
            }
        }
        if (! $budgetByAccount) {
            return ['rows' => [], 'totals' => ['budget' => 0, 'actual' => 0, 'variance' => 0]];
        }

        // Actual GL movement per account, on its natural side.
        $accountIds = array_keys($budgetByAccount);
        $sums = DB::table('fin_journal_lines as l')
            ->tap(fn ($q) => FinanceReportService::scopeToEntity($q, 'l.entity_id'))
            ->join('fin_journals as j', 'j.id', '=', 'l.journal_id')
            ->where('j.status', '!=', 'draft')
            ->whereBetween('j.journal_date', [$yearStart->toDateString(), $periodEnd->toDateString()])
            ->whereIn('l.account_id', $accountIds)
            ->groupBy('l.account_id')
            ->selectRaw('l.account_id, SUM(l.base_debit) d, SUM(l.base_credit) c')
            ->get()->keyBy('account_id');

        $accounts = FinAccount::whereIn('id', $accountIds)->get()->keyBy('id');
        $rows = [];
        $tB = $tA = 0.0;

        foreach ($budgetByAccount as $accId => $bud) {
            $acc = $accounts[$accId] ?? null;
            if (! $acc) {
                continue;
            }
            $d = (float) ($sums[$accId]->d ?? 0);
            $c = (float) ($sums[$accId]->c ?? 0);
            $actual = round($acc->normal_balance === 'debit' ? $d - $c : $c - $d, 2);
            $bud = round($bud, 2);
            // For income, "over" is favourable; for expense, "over" is unfavourable.
            $variance = round($actual - $bud, 2);
            $favourable = $acc->type === 'income' ? $variance >= 0 : $variance <= 0;

            $rows[] = [
                'account_id' => $accId, 'code' => $acc->code, 'name' => $acc->name, 'type' => $acc->type,
                'budget' => $bud, 'actual' => $actual, 'variance' => $variance,
                'variance_pct' => $bud != 0 ? round($variance / abs($bud) * 100, 1) : null,
                'favourable' => $favourable,
            ];
            $tB += $bud;
            $tA += $actual;
        }

        usort($rows, fn ($a, $b) => strcmp($a['code'], $b['code']));

        return [
            'rows'   => $rows,
            'totals' => ['budget' => round($tB, 2), 'actual' => round($tA, 2), 'variance' => round($tA - $tB, 2), 'through_month' => $throughMonth],
        ];
    }
}
