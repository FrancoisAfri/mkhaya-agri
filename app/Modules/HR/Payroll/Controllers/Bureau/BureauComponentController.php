<?php

namespace App\Modules\HR\Payroll\Controllers\Bureau;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\HR\Payroll\Models\Bureau\BureauComponent;
use App\Modules\HR\Payroll\Models\Bureau\BureauEmployee;
use App\Modules\HR\Payroll\Services\Bureau\BureauComponentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BureauComponentController extends Controller
{
    public function __construct(private readonly BureauComponentService $components)
    {
    }

    public function store(Request $request, BureauCompany $company): RedirectResponse
    {
        $this->gate($company);
        $data = $request->validate([
            'code'             => ['required', 'string', 'max:40'],
            'name'             => ['required', 'string', 'max:120'],
            'type'             => ['required', 'in:earning,deduction,company_contribution,fringe_benefit'],
            'sars_source_code' => ['nullable', 'string', 'max:10'],
            'category'         => ['nullable', 'in:medical,retirement,risk,allowance,fringe,other'],
            'calc_method'      => ['nullable', 'in:fixed,percentage_of_basic,per_hour'],
            'default_amount'   => ['nullable', 'numeric'],
            'taxable'          => ['sometimes', 'boolean'],
            'taxable_portion'  => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'affects_uif'      => ['sometimes', 'boolean'],
            'affects_sdl'      => ['sometimes', 'boolean'],
            'is_pretax'        => ['sometimes', 'boolean'],
            'is_recurring'     => ['sometimes', 'boolean'],
        ]);
        $this->components->create($company, $data);

        return back()->with('success', 'Component added to the catalogue.');
    }

    /** Seed the standard SA benefit set into the company's catalogue. */
    public function applyStandard(BureauCompany $company): RedirectResponse
    {
        $this->gate($company);
        $count = $this->components->applyStandardBenefits($company);

        return back()->with('success', "Added {$count} standard benefit(s) to the catalogue.");
    }

    public function assign(Request $request, BureauCompany $company, BureauEmployee $employee): RedirectResponse
    {
        $this->gate($company);
        abort_unless($employee->bureau_company_id === $company->id, 404);
        $data = $request->validate([
            'component_uuid' => ['required', 'string'],
            'amount'         => ['nullable', 'numeric'],
            'quantity'       => ['nullable', 'numeric', 'min:0'],
        ]);
        $component = $company->components()->where('uuid', $data['component_uuid'])->firstOrFail();
        $this->components->assign($employee, $component, $data['amount'] ?? null, $data['quantity'] ?? 1);

        return back()->with('success', 'Component assigned.');
    }

    private function gate(BureauCompany $company): void
    {
        abort_unless($company->accessibleBy(Auth::user()), 403);
    }
}
