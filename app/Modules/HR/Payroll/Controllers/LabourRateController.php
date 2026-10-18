<?php

namespace App\Modules\HR\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Support\LabourRates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where the labour-law figures are maintained.
 *
 * These moved out of PHP constants into rows so that a March rate change is data entry — but data
 * entry needs somewhere to enter it, or the only way to update the minimum wage is a SQL client.
 *
 * National figures, so this sits with the tax tables rather than in a tenant's own settings: a
 * company does not get its own minimum wage. A sectoral determination that genuinely differs is
 * captured as a named sector, which is why that field is here and optional.
 */
class LabourRateController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Modules/HR/Payroll/LabourRates/Index', [
            'rates' => DB::table('sa_labour_rates')
                ->orderByDesc('effective_from')
                ->orderBy('sector')
                ->get()
                ->map(fn ($r) => [
                    'id'             => $r->id,
                    'country'        => $r->country,
                    'sector'         => $r->sector,
                    'effective_from' => $r->effective_from,
                    'effective_to'   => $r->effective_to,
                    'nmw_hourly'     => (float) $r->nmw_hourly,
                    'nmw_monthly'    => round((float) $r->nmw_hourly * (float) $r->standard_monthly_hours, 2),
                    'standard_monthly_hours'      => (float) $r->standard_monthly_hours,
                    'max_overtime_hours_per_week' => (int) $r->max_overtime_hours_per_week,
                    'overtime_multiplier'         => (float) $r->overtime_multiplier,
                    'sunday_multiplier'           => (float) $r->sunday_multiplier,
                    'public_holiday_multiplier'   => (float) $r->public_holiday_multiplier,
                    'coida_earnings_ceiling'      => $r->coida_earnings_ceiling === null ? null : (float) $r->coida_earnings_ceiling,
                    'source'                      => $r->source,
                ]),
            // What is actually in force today, which is the question somebody opens this to answer.
            'current' => app(LabourRates::class)->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        // The determination it replaces stops the day before this one starts, so no date is covered
        // by two rows at once — otherwise which rate applies becomes a matter of sort order.
        DB::table('sa_labour_rates')
            ->where('country', $data['country'])
            ->when($data['sector'] === null, fn ($q) => $q->whereNull('sector'), fn ($q) => $q->where('sector', $data['sector']))
            ->whereNull('effective_to')
            ->whereDate('effective_from', '<', $data['effective_from'])
            ->update(['effective_to' => date('Y-m-d', strtotime($data['effective_from'] . ' -1 day'))]);

        DB::table('sa_labour_rates')->updateOrInsert(
            [
                'country'        => $data['country'],
                'sector'         => $data['sector'],
                'effective_from' => $data['effective_from'],
            ],
            $data + ['updated_at' => now(), 'created_at' => now()],
        );

        LabourRates::flush();

        return back()->with('success', 'Labour rates saved. They apply from ' . $data['effective_from'] . '.');
    }

    public function update(Request $request, int $rate): RedirectResponse
    {
        $data = $this->validated($request, creating: false);

        DB::table('sa_labour_rates')->where('id', $rate)->update($data + ['updated_at' => now()]);

        LabourRates::flush();

        return back()->with('success', 'Labour rates updated.');
    }

    private function validated(Request $request, bool $creating = true): array
    {
        $data = $request->validate([
            'country'        => ['nullable', 'string', 'size:2'],
            'sector'         => ['nullable', 'string', 'max:60'],
            'effective_from' => [$creating ? 'required' : 'sometimes', 'date'],
            'effective_to'   => ['nullable', 'date', 'after:effective_from'],
            'nmw_hourly'     => [$creating ? 'required' : 'sometimes', 'numeric', 'min:0', 'max:10000'],
            'standard_monthly_hours'      => ['nullable', 'numeric', 'min:1', 'max:400'],
            'max_overtime_hours_per_week' => ['nullable', 'integer', 'min:0', 'max:80'],
            'overtime_multiplier'         => ['nullable', 'numeric', 'min:1', 'max:5'],
            'sunday_multiplier'           => ['nullable', 'numeric', 'min:1', 'max:5'],
            'public_holiday_multiplier'   => ['nullable', 'numeric', 'min:1', 'max:5'],
            'coida_earnings_ceiling'      => ['nullable', 'numeric', 'min:0'],
            'source'                      => ['nullable', 'string', 'max:255'],
        ]);

        $data['country'] = $data['country'] ?? 'ZA';
        $data['sector'] = blank($data['sector'] ?? null) ? null : $data['sector'];

        return $data;
    }
}
