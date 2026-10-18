<?php

namespace App\Modules\Finance\Tax\Http\Controllers;

use App\Modules\Finance\Tax\Models\TaxRegistration;
use App\Modules\Finance\Tax\Models\TaxType;
use App\Modules\Finance\Tax\Services\TaxPeriodService;
use App\Modules\Finance\Tax\Services\TaxRegistrationService;
use App\Modules\Finance\Tax\Support\TaxPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §4 Tax registrations, per legal entity. */
class TaxRegistrationController extends TaxController
{
    public function __construct(private readonly TaxRegistrationService $registrations, private readonly TaxPeriodService $periods)
    {
    }

    public function index(): Response
    {
        $this->allow(TaxPermissions::VIEW);

        $business = $this->businessFilter();
        $rows = TaxRegistration::query()->with(['taxType', 'versions'])->forBusiness($business)->orderBy('entity_id')->orderBy('tax_type_id')->get();
        $names = collect($this->people())->pluck('name', 'id');

        return Inertia::render('Modules/Finance/Tax/Registrations', [
            'registrations' => $rows->map(fn (TaxRegistration $r) => [
                'uuid' => $r->uuid, 'entity_id' => $r->entity_id, 'entity' => $this->entityName($r->entity_id),
                'tax_type_id' => $r->tax_type_id, 'tax_type' => $r->taxType?->code, 'tax_type_name' => $r->taxType?->name,
                'reference' => $r->reference, 'effective_from' => $r->effective_from?->toDateString(),
                'effective_to' => $r->effective_to?->toDateString(), 'filing_frequency' => $r->filing_frequency,
                'year_end_month' => $r->year_end_month, 'authority' => $r->authority, 'status' => $r->status,
                'preparer_id' => $r->preparer_id, 'reviewer_id' => $r->reviewer_id, 'escalation_id' => $r->escalation_id,
                'preparer' => $names[$r->preparer_id] ?? null, 'reviewer' => $names[$r->reviewer_id] ?? null,
                'applicability_confirmed' => $r->applicability_confirmed, 'notes' => $r->notes, 'version' => $r->version,
                'missing' => $this->registrations->missingDetails($r),
                'history' => $r->versions->take(20)->map(fn ($v) => [
                    'version' => $v->version, 'reason' => $v->reason, 'effective_date' => $v->effective_date?->toDateString(),
                    'changes' => $v->changes, 'by' => $names[$v->changed_by] ?? null, 'at' => $v->created_at?->toDateTimeString(),
                ])->values(),
                'documents' => TaxEvidenceController::listFor($r),
            ])->values(),
            'taxTypes' => TaxType::where('is_active', true)->orderBy('id')->get(['id', 'code', 'name', 'frequencies', 'default_frequency']),
            'people' => $this->people(),
            'can' => ['manage' => request()->user()->can(TaxPermissions::MANAGE_REGISTRATIONS)],
        ] + $this->businessProps($business));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->allow(TaxPermissions::MANAGE_REGISTRATIONS, 'registration.create');
        $data = $this->validated($request, true);

        return $this->attempt(fn () => $this->registrations->create($data), 'Registration recorded. Confirm that it applies before periods are generated.');
    }

    public function update(Request $request, TaxRegistration $registration): RedirectResponse
    {
        $this->allow(TaxPermissions::MANAGE_REGISTRATIONS, 'registration.update');
        $data = $this->validated($request, false);
        $meta = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000'], 'effective_date' => ['required', 'date']]);

        return $this->attempt(fn () => $this->registrations->update($registration, $data, $meta['reason'], $meta['effective_date']), 'Registration updated.');
    }

    public function confirm(Request $request, TaxRegistration $registration): RedirectResponse
    {
        $this->allow(TaxPermissions::MANAGE_REGISTRATIONS, 'registration.confirm');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);

        return $this->attempt(function () use ($registration, $data) {
            $this->registrations->confirmApplicability($registration, $data['reason']);
            $n = $this->periods->generate($registration->fresh());

            return "Confirmed. {$n} period(s) and their deadlines created.";
        }, 'Confirmed.');
    }

    public function generate(TaxRegistration $registration): RedirectResponse
    {
        $this->allow(TaxPermissions::MANAGE_PERIODS, 'periods.generate');

        return $this->attempt(fn () => $this->periods->generate($registration) . ' period(s) created.', 'Done.');
    }

    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'entity_id' => [$creating ? 'required' : 'prohibited', 'integer', $this->businessRule()],
            'tax_type_id' => [$creating ? 'required' : 'prohibited', 'integer', 'exists:tax_types,id'],
            'reference' => ['nullable', 'string', 'max:40'],
            'effective_from' => [$creating ? 'required' : 'sometimes', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'filing_frequency' => [$creating ? 'required' : 'sometimes', 'string', 'in:' . implode(',', TaxRegistration::FREQUENCIES)],
            'year_end_month' => ['nullable', 'integer', 'between:1,12'],
            'authority' => ['nullable', 'string', 'max:40'],
            'preparer_id' => ['nullable', 'integer', 'exists:users,id'],
            'reviewer_id' => ['nullable', 'integer', 'exists:users,id'],
            'escalation_id' => ['nullable', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => [$creating ? 'prohibited' : 'sometimes', 'in:active,suspended,deregistered'],
        ]);
    }
}
