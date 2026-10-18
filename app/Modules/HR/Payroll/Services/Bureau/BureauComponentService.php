<?php

namespace App\Modules\HR\Payroll\Services\Bureau;

use App\Modules\HR\Payroll\Models\Bureau\BureauComponent;
use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\HR\Payroll\Models\Bureau\BureauEmployee;
use App\Modules\HR\Payroll\Models\Bureau\BureauEmployeeComponent;
use Illuminate\Validation\ValidationException;

/** Manages a company's reusable item catalogue and employee assignments. */
class BureauComponentService
{
    public function create(BureauCompany $company, array $data): BureauComponent
    {
        if (blank($data['name'] ?? null) || blank($data['code'] ?? null)) {
            throw ValidationException::withMessages(['code' => 'Code and name are required.']);
        }
        if (! in_array($data['type'] ?? '', BureauComponent::TYPES, true)) {
            throw ValidationException::withMessages(['type' => 'Invalid component type.']);
        }
        if (! in_array($data['calc_method'] ?? 'fixed', BureauComponent::CALC_METHODS, true)) {
            throw ValidationException::withMessages(['calc_method' => 'Invalid calculation method.']);
        }
        if ($company->components()->where('code', $data['code'])->exists()) {
            throw ValidationException::withMessages(['code' => 'A component with this code already exists.']);
        }

        return $company->components()->create([
            'code'             => $data['code'],
            'name'             => $data['name'],
            'type'             => $data['type'],
            'sars_source_code' => $data['sars_source_code'] ?? null,
            'category'         => $data['category'] ?? 'other',
            'calc_method'      => $data['calc_method'] ?? 'fixed',
            'default_amount'   => $data['default_amount'] ?? 0,
            'taxable'          => $data['taxable'] ?? true,
            'taxable_portion'  => $data['taxable_portion'] ?? 1,
            'affects_uif'      => $data['affects_uif'] ?? true,
            'affects_sdl'      => $data['affects_sdl'] ?? true,
            'is_pretax'        => $data['is_pretax'] ?? false,
            'is_recurring'     => $data['is_recurring'] ?? false,
        ]);
    }

    /** Seed the standard SA benefits into a company's catalogue (idempotent). Returns the count added. */
    public function applyStandardBenefits(BureauCompany $company): int
    {
        $added = 0;
        foreach (BureauComponent::STANDARD_BENEFITS as [$code, $name, $type, $source, $category, $taxable, $uif, $sdl, $pretax]) {
            if ($company->components()->where('code', $code)->exists()) {
                continue;
            }
            $company->components()->create([
                'code'             => $code,
                'name'             => $name,
                'type'             => $type,
                'sars_source_code' => $source,
                'category'         => $category,
                'calc_method'      => 'fixed',
                'default_amount'   => 0,
                'taxable'          => $taxable,
                // SARS 3701 travel allowance is only 80% taxable; everything else fully taxable.
                'taxable_portion'  => $source === '3701' ? 0.80 : 1.0,
                'affects_uif'      => $uif,
                'affects_sdl'      => $sdl,
                'is_pretax'        => $pretax,
                'is_recurring'     => true,
            ]);
            $added++;
        }

        return $added;
    }

    public function assign(BureauEmployee $employee, BureauComponent $component, ?float $amount = null, float $quantity = 1): BureauEmployeeComponent
    {
        if ($component->bureau_company_id !== $employee->bureau_company_id) {
            throw ValidationException::withMessages(['component' => 'Component belongs to a different company.']);
        }

        return BureauEmployeeComponent::updateOrCreate(
            ['bureau_employee_id' => $employee->id, 'bureau_component_id' => $component->id],
            ['amount' => $amount, 'quantity' => $quantity, 'active' => true],
        );
    }
}
