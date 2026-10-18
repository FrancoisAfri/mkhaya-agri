<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Modules\Finance\Accounting\Models\FinArSnapshot;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Accounts-receivable performance metrics for the Debtors Manager: DSO (Days Sales Outstanding),
 * best-possible DSO, average days-to-pay, promise-kept rate, CEI (Collection Effectiveness Index),
 * and the ageing trend. Point-in-time metrics come from live data; period metrics (CEI, trend) come
 * from the daily fin_ar_snapshots written by the scheduled reminder command.
 *
 * All queries respect the active company (RLS / BelongsToCompany), so it is correct in both the
 * web request and inside TenantContext::run in the scheduled command.
 */
class DebtorMetricsService
{
    /** Live receivable invoices with a balance (credit notes excluded). */
    private function base()
    {
        return FinInvoice::query()
            ->where('direction', 'receivable')->where('doc_type', 'invoice')
            ->whereIn('status', ['open', 'part_paid'])
            ->whereRaw('total - amount_paid > 0.001');
    }

    public function metrics(): array
    {
        $arNow = round((float) $this->base()->sum(DB::raw('total - amount_paid')), 2);
        $currentAr = round((float) $this->base()->whereRaw('due_date >= CURRENT_DATE')->sum(DB::raw('total - amount_paid')), 2);

        $sales365 = round((float) FinInvoice::query()
            ->where('direction', 'receivable')->where('doc_type', 'invoice')
            ->whereDate('invoice_date', '>=', Carbon::today()->subDays(365))
            ->sum('total'), 2);

        $dso = $sales365 > 0 ? round($arNow / $sales365 * 365, 1) : null;
        $bestDso = $sales365 > 0 ? round($currentAr / $sales365 * 365, 1) : null;

        return [
            'ar_total'          => $arNow,
            'dso'               => $dso,
            'best_possible_dso' => $bestDso,
            'dso_gap'           => ($dso !== null && $bestDso !== null) ? round($dso - $bestDso, 1) : null,
            'avg_days_to_pay'   => $this->avgDaysToPay(),
            'promise_kept_rate' => $this->promiseKeptRate(),
            'cei'               => $this->cei(),
            'trend'             => $this->trend(),
        ];
    }

    /** Average days between invoice date and its settling payment, over the last 365 days. */
    private function avgDaysToPay(): ?float
    {
        $row = DB::table('fin_invoices as inv')
            ->tap(fn ($q) => FinanceReportService::scopeToEntity($q, 'inv.entity_id'))
            ->join(DB::raw('(SELECT a.invoice_id, MAX(p.payment_date) AS paid_date
                             FROM fin_payment_allocations a
                             JOIN fin_payments p ON p.id = a.payment_id
                             GROUP BY a.invoice_id) pd'), 'pd.invoice_id', '=', 'inv.id')
            ->where('inv.direction', 'receivable')->where('inv.doc_type', 'invoice')->where('inv.status', 'paid')
            ->whereRaw('pd.paid_date >= CURRENT_DATE - 365')
            ->selectRaw('AVG(pd.paid_date - inv.invoice_date) AS avg_days')
            ->value('avg_days');

        return $row !== null ? round((float) $row, 1) : null;
    }

    /** Share of matured promises that were honoured (paid by promised date + grace). */
    private function promiseKeptRate(): ?array
    {
        $matured = DB::table('fin_collection_activities as ca')
            ->tap(fn ($q) => FinanceReportService::scopeToEntity($q, 'ca.entity_id'))
            ->join('fin_invoices as inv', 'inv.id', '=', 'ca.invoice_id')
            ->where('ca.type', 'promise')
            ->whereNotNull('ca.promised_date')
            ->whereRaw('ca.promised_date < CURRENT_DATE')
            ->whereRaw('ca.promised_date >= CURRENT_DATE - 365')
            ->count();

        if ($matured === 0) {
            return null;
        }

        $kept = DB::table('fin_collection_activities as ca')
            ->tap(fn ($q) => FinanceReportService::scopeToEntity($q, 'ca.entity_id'))
            ->join('fin_invoices as inv', 'inv.id', '=', 'ca.invoice_id')
            ->where('ca.type', 'promise')->whereNotNull('ca.promised_date')
            ->whereRaw('ca.promised_date < CURRENT_DATE')->whereRaw('ca.promised_date >= CURRENT_DATE - 365')
            ->where('inv.status', 'paid')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))->from('fin_payment_allocations as a')
                    ->join('fin_payments as p', 'p.id', '=', 'a.payment_id')
                    ->whereColumn('a.invoice_id', 'inv.id')
                    ->whereRaw('p.payment_date <= ca.promised_date + 2');
            })
            ->count();

        return ['kept' => $kept, 'total' => $matured, 'rate' => round($kept / $matured * 100, 1)];
    }

    /**
     * Collection Effectiveness Index from the daily snapshots:
     *   CEI = (beginningAR + creditSales − endingTotalAR) / (beginningAR + creditSales − endingCurrentAR) × 100
     * Uses the snapshot closest to 90 days ago as the beginning. Null until enough history exists.
     */
    private function cei(): ?float
    {
        $end = FinArSnapshot::orderByDesc('as_of')->first();
        if (! $end) {
            return null;
        }
        $begin = FinArSnapshot::whereDate('as_of', '<=', Carbon::parse($end->as_of)->subDays(80))
            ->orderByDesc('as_of')->first();
        if (! $begin) {
            return null;
        }

        $days = Carbon::parse($begin->as_of)->diffInDays(Carbon::parse($end->as_of));
        if ($days < 1) {
            return null;
        }

        $creditSales = (float) FinInvoice::query()
            ->where('direction', 'receivable')->where('doc_type', 'invoice')
            ->whereBetween('invoice_date', [$begin->as_of, $end->as_of])->sum('total');

        $beginAr = (float) $begin->total;
        $endAr = (float) $end->total;
        $endCurrent = (float) $end->current;

        $denom = $beginAr + $creditSales - $endCurrent;
        if (abs($denom) < 0.01) {
            return null;
        }

        return round(($beginAr + $creditSales - $endAr) / $denom * 100, 1);
    }

    /** Last ~12 snapshots for the AR/overdue trend chart. */
    private function trend(): array
    {
        return FinArSnapshot::orderByDesc('as_of')->limit(26)->get()
            ->sortBy('as_of')->values()
            ->map(fn ($s) => [
                'date' => $s->as_of->toDateString(),
                'total' => (float) $s->total, 'overdue' => (float) $s->overdue, 'current' => (float) $s->current,
            ])->all();
    }

    /** Persist today's ageing snapshot for a company (idempotent per day). */
    public function writeSnapshot(?int $companyId): void
    {
        $agg = $this->base()->where('company_id', $companyId)->selectRaw("
            SUM(total - amount_paid) AS total,
            SUM(CASE WHEN due_date < CURRENT_DATE THEN total - amount_paid ELSE 0 END) AS overdue,
            SUM(CASE WHEN due_date >= CURRENT_DATE THEN total - amount_paid ELSE 0 END) AS current,
            SUM(CASE WHEN due_date >= CURRENT_DATE THEN 0 WHEN CURRENT_DATE - due_date <= 30 THEN total - amount_paid ELSE 0 END) AS b_1_30,
            SUM(CASE WHEN CURRENT_DATE - due_date BETWEEN 31 AND 60 THEN total - amount_paid ELSE 0 END) AS b_31_60,
            SUM(CASE WHEN CURRENT_DATE - due_date BETWEEN 61 AND 90 THEN total - amount_paid ELSE 0 END) AS b_61_90,
            SUM(CASE WHEN CURRENT_DATE - due_date BETWEEN 91 AND 120 THEN total - amount_paid ELSE 0 END) AS b_91_120,
            SUM(CASE WHEN CURRENT_DATE - due_date > 120 THEN total - amount_paid ELSE 0 END) AS b_120_plus")
            ->first();

        FinArSnapshot::updateOrCreate(
            ['company_id' => $companyId, 'as_of' => Carbon::today()->toDateString()],
            [
                'total' => round((float) ($agg->total ?? 0), 2),
                'overdue' => round((float) ($agg->overdue ?? 0), 2),
                'current' => round((float) ($agg->current ?? 0), 2),
                'b_1_30' => round((float) ($agg->b_1_30 ?? 0), 2),
                'b_31_60' => round((float) ($agg->b_31_60 ?? 0), 2),
                'b_61_90' => round((float) ($agg->b_61_90 ?? 0), 2),
                'b_91_120' => round((float) ($agg->b_91_120 ?? 0), 2),
                'b_120_plus' => round((float) ($agg->b_120_plus ?? 0), 2),
            ]
        );
    }
}
