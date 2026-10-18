<?php

namespace App\Modules\HR\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Models\Beneficiary;
use App\Modules\HR\Payroll\Models\DeductionReference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The register of reference numbers each fund knows each employee by.
 *
 * A deduction file is unusable without these: the receiving system matches on its own reference,
 * not on a name, so a line without one is money the fund holds and cannot apply. The register is
 * therefore its own screen rather than a field buried on an employee record — somebody chases these
 * numbers as a job of work when a new fund is onboarded, and doing that one employee at a time
 * through a profile page is why it does not get done.
 */
class DeductionReferenceController extends Controller
{
    public function index(Request $request): Response
    {
        $beneficiaryUuid = $request->string('beneficiary')->value();

        $beneficiaries = Beneficiary::query()->where('active', true)->orderBy('name')
            ->get(['id', 'uuid', 'name', 'type', 'file_spec_code']);

        $selected = $beneficiaryUuid
            ? $beneficiaries->firstWhere('uuid', $beneficiaryUuid)
            : $beneficiaries->first();

        return Inertia::render('Modules/HR/Payroll/DeductionReferences/Index', [
            'beneficiaries' => $beneficiaries->map(fn ($b) => [
                'uuid' => $b->uuid, 'name' => $b->name, 'type' => $b->type,
                // Shown because it is what decides whether the references below are merely useful
                // or are the difference between a file and no file.
                'needs_file' => (bool) $b->file_spec_code,
            ]),
            'selected'   => $selected ? ['uuid' => $selected->uuid, 'name' => $selected->name] : null,
            'references' => $selected ? $this->referencesFor((int) $selected->id) : [],
            // Everybody on the payroll, so a reference can be captured without leaving the screen.
            'employees'  => $this->employees(),
        ]);
    }

    /** One fund's register, newest first, with the employee's name resolved. */
    private function referencesFor(int $beneficiaryId): array
    {
        return DeductionReference::query()
            ->where('beneficiary_id', $beneficiaryId)
            ->orderByDesc('active')
            ->orderByDesc('id')
            ->get()
            ->map(function (DeductionReference $r) {
                $person = $r->employee_type === DeductionReference::HR
                    ? DB::table('hr_people')->where('user_id', $r->employee_id)->first(['first_name', 'surname', 'employee_number'])
                    : DB::table('bureau_employees')->where('id', $r->employee_id)->first(['first_name', 'last_name as surname', 'employee_number']);

                return [
                    'uuid'        => $r->uuid,
                    'employee_id' => $r->employee_id,
                    'employee'    => $person ? trim($person->first_name . ' ' . $person->surname) : "#{$r->employee_id}",
                    'employee_number' => $person->employee_number ?? null,
                    'reference'   => $r->reference,
                    'secondary_reference' => $r->secondary_reference,
                    'deduction_type' => $r->deduction_type,
                    'effective_from' => $r->effective_from?->toDateString(),
                    'effective_to'   => $r->effective_to?->toDateString(),
                    'instalment'  => $r->instalment !== null ? (float) $r->instalment : null,
                    'total_amount' => $r->total_amount !== null ? (float) $r->total_amount : null,
                    'active'      => $r->active,
                ];
            })->values()->all();
    }

    private function employees(): array
    {
        return DB::table('users as u')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->where('u.status', 1)
            ->selectRaw("u.id, COALESCE(NULLIF(TRIM(CONCAT(COALESCE(p.first_name,''), ' ', COALESCE(p.surname,''))), ''), u.email) as name, p.employee_number")
            ->orderBy('name')
            ->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name, 'number' => $r->employee_number])
            ->all();
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $beneficiary = Beneficiary::where('uuid', $data['beneficiary'])->firstOrFail();

        DeductionReference::create([
            'employee_type'  => DeductionReference::HR,
            'employee_id'    => $data['employee_id'],
            'beneficiary_id' => $beneficiary->id,
            'reference'      => $data['reference'],
            'secondary_reference' => $data['secondary_reference'] ?? null,
            'deduction_type' => $data['deduction_type'] ?? null,
            'effective_from' => $data['effective_from'] ?? null,
            'effective_to'   => $data['effective_to'] ?? null,
            'instalment'     => $data['instalment'] ?? null,
            'total_amount'   => $data['total_amount'] ?? null,
            'notes'          => $data['notes'] ?? null,
            'active'         => $data['active'] ?? true,
            'created_by'     => Auth::id(),
        ]);

        return back()->with('success', 'Reference captured.');
    }

    public function update(Request $request, DeductionReference $reference): RedirectResponse
    {
        $data = $this->validated($request, creating: false);
        unset($data['beneficiary'], $data['employee_id']);

        $reference->update($data);

        return back()->with('success', 'Reference updated.');
    }

    /**
     * Ending a reference rather than deleting it.
     *
     * The file sent last month carried this number, and a deleted row makes that file
     * unreproducible — which is the question a fund asks when it queries a payment.
     */
    public function destroy(DeductionReference $reference): RedirectResponse
    {
        $reference->update(['active' => false, 'effective_to' => $reference->effective_to ?? now()->toDateString()]);

        return back()->with('success', 'Reference ended. It stays on file for periods already sent.');
    }

    private function validated(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'beneficiary'    => [$creating ? 'required' : 'sometimes', 'string'],
            'employee_id'    => [$creating ? 'required' : 'sometimes', 'integer', 'exists:users,id'],
            'reference'      => [$creating ? 'required' : 'sometimes', 'string', 'max:60'],
            'secondary_reference' => ['nullable', 'string', 'max:60'],
            'deduction_type' => ['nullable', 'string', 'max:40'],
            'effective_from' => ['nullable', 'date'],
            'effective_to'   => ['nullable', 'date', 'after_or_equal:effective_from'],
            'instalment'     => ['nullable', 'numeric', 'min:0'],
            'total_amount'   => ['nullable', 'numeric', 'min:0'],
            'notes'          => ['nullable', 'string', 'max:500'],
            'active'         => ['nullable', 'boolean'],
        ]);
    }
}
