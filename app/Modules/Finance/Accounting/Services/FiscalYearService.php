<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Models\FinFiscalYear;
use App\Modules\Finance\Accounting\Models\FinSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Dynamic, per-tenant financial years. Each company runs its own fiscal calendar via a start month
 * (1=Jan … 12=Dec), so a Mar–Feb or Aug–Jul company gets periods generated from its own start rather
 * than always Jan–Dec. Handles creating/switching/archiving years and generating their 12 monthly
 * periods with human labels ("Aug 2026") and a year label ("FY2026/2027").
 */
class FiscalYearService
{
    /** The company's fiscal-year start month (1–12); defaults to January. */
    public function startMonth(?int $companyId): int
    {
        $m = (int) (FinSetting::current($companyId)->fiscal_year_start_month ?? 1);

        return ($m >= 1 && $m <= 12) ? $m : 1;
    }

    /** Year label: "FY2027" for a Jan start, else "FY2026/2027". */
    public function label(Carbon $start, Carbon $end): string
    {
        return $start->month === 1 ? 'FY' . $start->year : 'FY' . $start->year . '/' . $end->year;
    }

    /** Start/end for the fiscal year that starts in calendar year $startYear. */
    public function range(int $startYear, int $startMonth): array
    {
        $start = Carbon::create($startYear, $startMonth, 1)->startOfDay();
        $end = (clone $start)->addYear()->subDay()->endOfDay();

        return [$start, $end];
    }

    /** The calendar start-year of the fiscal year that contains $date, given the start month. */
    public function startYearForDate(Carbon $date, int $startMonth): int
    {
        return $date->month >= $startMonth ? $date->year : $date->year - 1;
    }

    /** The current fiscal year for a company — the flagged one, else the one containing today. */
    public function currentYear(?int $companyId): ?FinFiscalYear
    {
        $q = FinFiscalYear::query()->when($companyId, fn ($x) => $x->where('company_id', $companyId), fn ($x) => $x->whereNull('company_id'));

        return (clone $q)->where('is_current', true)->first()
            ?? (clone $q)->whereDate('start_date', '<=', now())->whereDate('end_date', '>=', now())->first();
    }

    /** Ensure a company has a fiscal year covering today (auto-provision on first visit). */
    public function ensureCurrentYear(?int $companyId): FinFiscalYear
    {
        if ($year = $this->currentYear($companyId)) {
            return $year;
        }
        $startMonth = $this->startMonth($companyId);
        $year = $this->createYear($companyId, $this->startYearForDate(Carbon::today(), $startMonth));
        $this->switchCurrent($year);

        return $year;
    }

    /**
     * Create a fiscal year (and its 12 monthly periods) starting in calendar year $startYear. Uses
     * the company's start month. Idempotent — returns the existing year if one already starts there.
     */
    public function createYear(?int $companyId, int $startYear): FinFiscalYear
    {
        $startMonth = $this->startMonth($companyId);
        [$start, $end] = $this->range($startYear, $startMonth);

        $existing = FinFiscalYear::where('company_id', $companyId)->whereDate('start_date', $start->toDateString())->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($companyId, $start, $end) {
            $year = FinFiscalYear::create([
                'company_id' => $companyId,
                'label'      => $this->label($start, $end),
                'start_date' => $start->toDateString(),
                'end_date'   => $end->toDateString(),
                'status'     => 'open',
                'is_current' => false,
            ]);
            $this->generatePeriods($year);

            return $year;
        });
    }

    /** The next fiscal year after a company's latest (or the current one). */
    public function createNextYear(?int $companyId): FinFiscalYear
    {
        $latest = FinFiscalYear::when($companyId, fn ($q) => $q->where('company_id', $companyId), fn ($q) => $q->whereNull('company_id'))
            ->orderByDesc('start_date')->first();
        $startYear = $latest ? Carbon::parse($latest->start_date)->addYear()->year : Carbon::today()->year;

        return $this->createYear($companyId, $startYear);
    }

    /** Generate (or top up) the 12 monthly periods for a fiscal year. */
    public function generatePeriods(FinFiscalYear $year): void
    {
        $start = Carbon::parse($year->start_date);
        for ($p = 1; $p <= 12; $p++) {
            $periodStart = (clone $start)->addMonths($p - 1)->startOfMonth();
            FinFiscalPeriod::firstOrCreate(
                ['company_id' => $year->company_id, 'fiscal_year_id' => $year->id, 'period_no' => $p],
                [
                    'fiscal_year' => $periodStart->year,
                    'start_date'  => $periodStart->toDateString(),
                    'end_date'    => (clone $periodStart)->endOfMonth()->toDateString(),
                    'label'       => $periodStart->format('M Y'),
                    'status'      => 'open',
                ]
            );
        }
    }

    /** Make $year the current one for its company (clears the flag on the others). */
    public function switchCurrent(FinFiscalYear $year): void
    {
        FinFiscalYear::where('company_id', $year->company_id)->where('id', '!=', $year->id)->update(['is_current' => false]);
        $year->update(['is_current' => true]);
    }

    public function archive(FinFiscalYear $year): void
    {
        $year->update(['status' => 'archived', 'is_current' => false]);
    }

    public function updateLabel(FinFiscalYear $year, string $label): void
    {
        $year->update(['label' => $label]);
    }

    /** Status counts + outstanding checks + progress for a year's summary card. */
    public function summary(FinFiscalYear $year, PeriodService $periods): array
    {
        $ps = $year->periods()->get();
        $byStatus = ['open' => 0, 'soft_close' => 0, 'hard_close' => 0, 'locked' => 0];
        foreach ($ps as $p) {
            $byStatus[$p->status] = ($byStatus[$p->status] ?? 0) + 1;
        }

        $outstanding = 0;
        foreach ($ps->whereIn('status', ['open', 'soft_close']) as $p) {
            if (collect($periods->preCloseChecks($p))->where('level', 'error')->isNotEmpty()) {
                $outstanding++;
            }
        }

        return [
            'by_status'   => $byStatus,
            'outstanding' => $outstanding,
            'progress'    => $year->progress(),
        ];
    }

    /** For the FY-start settings dropdown: [1 => 'January', …]. */
    public static function months(): array
    {
        return collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => Carbon::create(2000, $m, 1)->format('F')])->all();
    }
}
