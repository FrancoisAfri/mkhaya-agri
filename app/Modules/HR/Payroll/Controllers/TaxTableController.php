<?php

namespace App\Modules\HR\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Models\Bureau\SaTaxYear;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only viewer for the SARS statutory tax parameters, showing up to the
 * last 10 years of assessment. Shared by the Payroll and Payroll Bureau modules
 * (the {@see self::$module} prop drives the breadcrumb/back-link on the page).
 * These are the exact figures both payroll engines use to calculate PAYE, UIF,
 * SDL and the medical-aid tax credits.
 */
class TaxTableController extends Controller
{
    /** Payroll module viewer: /payroll/tax-tables */
    public function payroll(Request $request): Response
    {
        return $this->render($request, 'payroll');
    }

    /** Bureau module viewer: /payroll/bureau/tax-tables */
    public function bureau(Request $request): Response
    {
        return $this->render($request, 'bureau');
    }

    private function render(Request $request, string $module): Response
    {
        $country = strtoupper($request->query('country', 'ZA'));

        $countries = SaTaxYear::query()
            ->select('country')
            ->distinct()
            ->orderBy('country')
            ->pluck('country')
            ->all();

        $years = SaTaxYear::query()
            ->where('country', $country)
            ->orderByDesc('tax_year')
            ->limit(10)
            ->get()
            ->map(fn (SaTaxYear $y) => $this->present($y))
            ->all();

        return Inertia::render('Modules/HR/Payroll/TaxTables', [
            'module'    => $module,
            'country'   => $country,
            'countries' => $countries ?: ['ZA'],
            'years'     => $years,
        ]);
    }

    /** Shape one tax year for the viewer, expanding the raw bracket triples. */
    private function present(SaTaxYear $y): array
    {
        $brackets = collect($y->brackets ?? [])->map(function ($b, $i) use ($y) {
            [$lower, $rate, $base] = $b;
            $next  = ($y->brackets[$i + 1] ?? null);
            $upper = $next ? ((float) $next[0] - 1) : null;
            return [
                'lower' => (float) $lower,
                'upper' => $upper,
                'rate'  => (float) $rate,
                'base'  => (float) $base,
            ];
        })->all();

        return [
            'tax_year'       => $y->tax_year,
            'starts_on'      => optional($y->starts_on)->toDateString(),
            'ends_on'        => optional($y->ends_on)->toDateString(),
            'country'        => $y->country,
            'source'         => $y->source,
            'is_provisional' => $y->is_provisional,
            'confirmed_at'   => optional($y->confirmed_at)->toDateString(),
            'brackets'       => $brackets,
            'rebates'        => [
                'primary'   => (float) $y->primary_rebate,
                'secondary' => (float) $y->secondary_rebate,
                'tertiary'  => (float) $y->tertiary_rebate,
            ],
            'thresholds'     => [
                'under_65'  => (float) $y->threshold_under_65,
                'age_65_74' => (float) $y->threshold_65_to_74,
                'age_75'    => (float) $y->threshold_75_plus,
            ],
            'medical_credits' => [
                'main'            => (float) $y->medical_credit_main,
                'first_dependant' => (float) $y->medical_credit_first_dependant,
                'additional'      => (float) $y->medical_credit_additional,
            ],
            'uif' => [
                'rate'            => (float) $y->uif_rate,
                'monthly_ceiling' => (float) $y->uif_monthly_ceiling,
            ],
            'sdl' => [
                'rate'             => (float) $y->sdl_rate,
                'annual_threshold' => (float) $y->sdl_annual_threshold,
            ],
        ];
    }
}
