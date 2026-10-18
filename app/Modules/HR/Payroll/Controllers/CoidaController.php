<?php

namespace App\Modules\HR\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\HR\Payroll\Models\CoidaRegistration;
use App\Modules\HR\Payroll\Models\CoidaReturn;
use App\Modules\HR\Payroll\Services\CoidaReturnService;
use App\Modules\HR\Payroll\Support\LabourRates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * The Return of Earnings — registration details, the yearly return, and its export.
 *
 * One screen covering both engines: a bureau prepares one per client, and the tenant's own in-house
 * payroll is an employer too, so it appears in the same list with no client attached.
 */
class CoidaController extends Controller
{
    public function __construct(
        private readonly CoidaReturnService $returns,
        private readonly LabourRates $rates,
    ) {
    }

    public function index(Request $request): InertiaResponse
    {
        $year = (int) ($request->input('year') ?: $this->defaultYear());

        return Inertia::render('Modules/HR/Payroll/Coida/Index', [
            'year'          => $year,
            'year_label'    => CoidaReturn::yearLabel($year),
            'ceiling'       => $this->rates->coidaCeiling($this->returns->yearBounds($year)[1]),
            'returns'       => CoidaReturn::query()
                ->where('assessment_year', $year)
                ->with('bureauCompany:id,uuid,name')
                ->orderBy('id')
                ->get()
                ->map(fn (CoidaReturn $r) => [
                    'uuid'      => $r->uuid,
                    'employer'  => $r->bureauCompany?->name ?? 'In-house payroll',
                    'client'    => $r->bureauCompany?->uuid,
                    'status'    => $r->status,
                    'employees' => $r->employee_count,
                    'actual'    => (float) $r->total_actual_earnings,
                    'leviable'  => (float) $r->total_leviable_earnings,
                    'assessment' => (float) $r->assessment,
                    'prepared_at' => $r->prepared_at?->toDateTimeString(),
                    'submitted_at' => $r->submitted_at?->toDateTimeString(),
                ]),
            'registrations' => CoidaRegistration::query()
                ->with('bureauCompany:id,uuid,name')
                ->get()
                ->map(fn (CoidaRegistration $r) => [
                    'uuid'     => $r->uuid,
                    'employer' => $r->bureauCompany?->name ?? 'In-house payroll',
                    'client'   => $r->bureauCompany?->uuid,
                    'number'   => $r->registration_number,
                    'class'    => $r->industry_class,
                    'rate'     => (float) $r->assessment_rate_per_100,
                ]),
            'clients' => BureauCompany::query()->where('is_active', true)->orderBy('name')->get(['uuid', 'name']),
            'years'   => range($this->defaultYear(), $this->defaultYear() - 4),
        ]);
    }

    public function prepare(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'client'   => ['nullable', 'string', 'exists:bureau_companies,uuid'],
            'year'     => ['required', 'integer', 'between:2000,2100'],
            'estimate' => ['nullable', 'numeric', 'min:0'],
        ]);

        $client = $data['client'] ?? null
            ? BureauCompany::where('uuid', $data['client'])->firstOrFail()
            : null;

        if ($client) {
            abort_unless($client->accessibleBy(Auth::user()), 403);
        }

        $return = $this->returns->prepare($client, (int) $data['year'], $data['estimate'] ?? null);

        return back()->with('success', sprintf(
            'Return prepared: %d employees, R%s leviable.',
            $return->employee_count,
            number_format((float) $return->total_leviable_earnings, 2),
        ));
    }

    public function saveRegistration(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'client'                  => ['nullable', 'string', 'exists:bureau_companies,uuid'],
            'registration_number'     => ['nullable', 'string', 'max:40'],
            'industry_class'          => ['nullable', 'string', 'max:20'],
            'industry_description'    => ['nullable', 'string', 'max:255'],
            'assessment_rate_per_100' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $client = $data['client'] ?? null
            ? BureauCompany::where('uuid', $data['client'])->firstOrFail()
            : null;

        if ($client) {
            abort_unless($client->accessibleBy(Auth::user()), 403);
        }

        CoidaRegistration::updateOrCreate(
            ['bureau_company_id' => $client?->id],
            collect($data)->except('client')->all() + ['is_active' => true],
        );

        return back()->with('success', 'Registration details saved.');
    }

    public function markSubmitted(CoidaReturn $return): RedirectResponse
    {
        $this->returns->markSubmitted($return);

        return back()->with('success', 'Return marked as submitted. It is now a record of what was filed.');
    }

    public function download(CoidaReturn $return): Response
    {
        return response($this->returns->toCsv($return), 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="return-of-earnings-' . $return->assessment_year . '.csv"',
        ]);
    }

    /** The assessment year that has most recently ended — the one people are filing. */
    private function defaultYear(): int
    {
        $now = now();

        return $now->month >= 3 ? $now->year : $now->year - 1;
    }
}
