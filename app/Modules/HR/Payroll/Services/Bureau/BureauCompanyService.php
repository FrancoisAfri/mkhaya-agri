<?php

namespace App\Modules\HR\Payroll\Services\Bureau;

use App\Core\User\Models\User;
use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Manages a payroll-bureau agent's client companies. All reads are scoped to the
 * agent so one bureau can never see another's clients.
 */
class BureauCompanyService
{
    /** Companies the user owns or is a team member of. @return Collection<int,BureauCompany> */
    public function forAgent(User $agent): Collection
    {
        return BureauCompany::query()
            ->where(fn ($q) => $q->where('agent_id', $agent->id)
                ->orWhereHas('members', fn ($m) => $m->where('users.id', $agent->id)))
            ->orderByDesc('is_active')->orderBy('name')
            ->get();
    }

    public function create(User $agent, array $data): BureauCompany
    {
        if (blank($data['name'] ?? null)) {
            throw ValidationException::withMessages(['name' => 'A company name is required.']);
        }
        $frequency = $data['pay_frequency'] ?? 'monthly';
        if (! in_array($frequency, BureauCompany::FREQUENCIES, true)) {
            throw ValidationException::withMessages(['pay_frequency' => 'Invalid pay frequency.']);
        }

        return BureauCompany::create([
            'agent_id'            => $agent->id,
            'name'                => $data['name'],
            'trading_name'        => $data['trading_name'] ?? null,
            'registration_number' => $data['registration_number'] ?? null,
            'paye_reference'      => $data['paye_reference'] ?? null,
            'uif_reference'       => $data['uif_reference'] ?? null,
            'sdl_reference'       => $data['sdl_reference'] ?? null,
            'contact_name'        => $data['contact_name'] ?? null,
            'contact_email'       => $data['contact_email'] ?? null,
            'contact_phone'       => $data['contact_phone'] ?? null,
            'address'             => $data['address'] ?? null,
            'pay_frequency'       => $frequency,
            'country'             => isset($data['country']) && isset(\App\Modules\HR\Payroll\Models\Bureau\BureauCompany::COUNTRIES[$data['country']]) ? $data['country'] : 'ZA',
            'currency'            => \App\Modules\HR\Payroll\Models\Bureau\BureauCompany::COUNTRIES[$data['country'] ?? 'ZA'] ?? 'ZAR',
            'tax_year'            => $data['tax_year'] ?? null,
            'created_by'          => $agent->id,
        ]);
    }

    public function update(BureauCompany $company, array $data): BureauCompany
    {
        if (array_key_exists('pay_frequency', $data) && ! in_array($data['pay_frequency'], BureauCompany::FREQUENCIES, true)) {
            throw ValidationException::withMessages(['pay_frequency' => 'Invalid pay frequency.']);
        }
        $company->fill(array_intersect_key($data, array_flip([
            'name', 'trading_name', 'registration_number', 'paye_reference', 'uif_reference', 'sdl_reference',
            'contact_name', 'contact_email', 'contact_phone', 'address', 'pay_frequency', 'tax_year', 'is_active',
            'bank', 'bank_account_name', 'bank_account_number', 'bank_branch_code', 'bank_user_code', 'auto_email_payslips', 'country', 'currency',
        ])))->save();

        return $company;
    }
}
