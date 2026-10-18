<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Modules\Finance\Accounting\Models\FinBankAccount;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinRecurringInvoice;
use Illuminate\Support\Carbon;

/**
 * Short-term cash-flow forecast (Phase 3.5): starts from the current cash position
 * and projects a weekly balance from open receivables (in), open payables (out)
 * and upcoming recurring invoices (in). Overdue items land in the first week.
 */
class CashFlowForecastService
{
    public function __construct(private FinanceReportService $reports)
    {
    }

    public function forecast(int $weeks = 13, ?int $companyId = null): array
    {
        $weeks = max(1, min(52, $weeks));
        $today = Carbon::today();

        // Opening cash = sum of the GL balances behind the cash-type bank accounts.
        $cashAccountIds = FinBankAccount::whereIn('type', ['bank', 'petty_cash', 'wallet'])
            ->where('is_active', true)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->pluck('account_id')->filter()->all();

        $balances = collect($this->reports->accountBalances($companyId));
        $opening = round($balances->whereIn('account_id', $cashAccountIds)->sum('balance'), 2);

        // Weekly buckets.
        $buckets = [];
        for ($i = 0; $i < $weeks; $i++) {
            $start = $today->copy()->addWeeks($i);
            $buckets[$i] = [
                'week'    => $i + 1,
                'start'   => $start->toDateString(),
                'end'     => $start->copy()->addDays(6)->toDateString(),
                'inflow'  => 0.0,
                'outflow' => 0.0,
            ];
        }
        $horizonEnd = $today->copy()->addWeeks($weeks)->subDay();

        $bucketFor = function (?Carbon $date) use ($today, $weeks): ?int {
            if (! $date) {
                return null;
            }
            if ($date->lt($today)) {
                return 0; // overdue → collect/pay now
            }
            $idx = (int) floor($today->diffInDays($date) / 7);

            return $idx < $weeks ? $idx : null;
        };

        // Open receivables (money in) and payables (money out).
        FinInvoice::query()->invoicesOnly()->open()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->get(['id', 'direction', 'due_date', 'total', 'amount_paid'])
            ->each(function (FinInvoice $inv) use (&$buckets, $bucketFor) {
                $out = $inv->outstanding();
                if ($out <= 0) {
                    return;
                }
                $idx = $bucketFor($inv->due_date ? Carbon::parse($inv->due_date) : null);
                if ($idx === null) {
                    return;
                }
                $buckets[$idx][$inv->direction === 'receivable' ? 'inflow' : 'outflow'] += $out;
            });

        // Upcoming recurring invoices (expected money in).
        FinRecurringInvoice::with('lines:id,recurring_id,quantity,unit_price,tax_rate')
            ->where('status', 'active')
            ->whereNotNull('next_run_date')
            ->whereDate('next_run_date', '<=', $horizonEnd)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->get()
            ->each(function (FinRecurringInvoice $rec) use (&$buckets, $bucketFor) {
                $idx = $bucketFor(Carbon::parse($rec->next_run_date));
                if ($idx === null) {
                    return;
                }
                $amount = round($rec->lines->sum(fn ($l) => (float) $l->quantity * (float) $l->unit_price * (1 + (float) $l->tax_rate / 100)), 2);
                $buckets[$idx]['inflow'] += $amount;
            });

        // Running projected balance + summary.
        $running = $opening;
        $lowest = ['balance' => $opening, 'week_start' => $today->toDateString()];
        $totalIn = $totalOut = 0.0;

        foreach ($buckets as &$b) {
            $b['inflow'] = round($b['inflow'], 2);
            $b['outflow'] = round($b['outflow'], 2);
            $b['net'] = round($b['inflow'] - $b['outflow'], 2);
            $running = round($running + $b['net'], 2);
            $b['balance'] = $running;
            $totalIn += $b['inflow'];
            $totalOut += $b['outflow'];
            if ($running < $lowest['balance']) {
                $lowest = ['balance' => $running, 'week_start' => $b['start']];
            }
        }
        unset($b);

        return [
            'opening' => $opening,
            'weeks'   => array_values($buckets),
            'summary' => [
                'total_in'      => round($totalIn, 2),
                'total_out'     => round($totalOut, 2),
                'closing'       => $running,
                'lowest'        => $lowest,
                'goes_negative' => $lowest['balance'] < 0,
                'horizon_weeks' => $weeks,
            ],
        ];
    }
}
