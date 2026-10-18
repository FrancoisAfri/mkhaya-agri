<?php

namespace App\Modules\HR\Payroll\Controllers\Bureau;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\HR\Payroll\Models\Bureau\BureauEmployee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Moving a team from one manager to another.
 *
 * Who approves whose leave changes far more often than anything else about an employee — somebody
 * resigns, a department is split, a supervisor goes on long leave — and it always changes for a
 * group rather than for one person. Doing that through the employee form means opening fourteen
 * records and remembering which fourteen, which in practice means it is left half done and leave
 * requests pile up against a manager who left in March.
 *
 * So the whole team moves at once, and the screen is organised by manager rather than by employee.
 */
class BureauManagerController extends Controller
{
    public function index(BureauCompany $company): Response
    {
        $this->gate($company);

        $employees = $company->employees()
            ->where('status', BureauEmployee::STATUS_ACTIVE)
            ->with('manager:id,uuid,first_name,last_name')
            ->orderBy('last_name')->get();

        // Grouped by manager, because "who reports to whom" is the question this screen answers and
        // a flat list of four hundred names does not answer it.
        $teams = $employees->groupBy(fn (BureauEmployee $e) => $e->manager?->uuid ?? '')
            ->map(fn ($members, $managerUuid) => [
                'manager' => $managerUuid ?: null,
                'manager_name' => $members->first()->manager
                    ? trim($members->first()->manager->first_name . ' ' . $members->first()->manager->last_name)
                    : null,
                'members' => $members->map(fn (BureauEmployee $e) => [
                    'uuid'   => $e->uuid,
                    'name'   => $e->fullName(),
                    'number' => $e->employee_number,
                    'occupation' => $e->occupation,
                ])->values(),
            ])->values()
            // Unassigned first: it is the group that needs attention, not the one that is fine.
            ->sortBy(fn ($t) => $t['manager'] === null ? '' : mb_strtolower((string) $t['manager_name']))
            ->values();

        return Inertia::render('Modules/HR/Payroll/Bureau/Managers', [
            'company' => ['uuid' => $company->uuid, 'name' => $company->name],
            'teams'   => $teams,
            'people'  => $employees->map(fn (BureauEmployee $e) => [
                'uuid' => $e->uuid, 'name' => $e->fullName(), 'number' => $e->employee_number,
            ])->values(),
        ]);
    }

    /**
     * Point a selection of employees at a different manager.
     *
     * Refuses the whole move if any one of them would end up reporting to themselves, rather than
     * quietly skipping that person — a half-applied reassignment is the thing this screen exists to
     * prevent.
     */
    public function reassign(Request $request, BureauCompany $company): RedirectResponse
    {
        $this->gate($company);

        $data = $request->validate([
            'employees'   => ['required', 'array', 'min:1'],
            'employees.*' => ['required', 'string'],
            // Null is a legitimate destination: it detaches a team from a manager who has left,
            // which is honest about the state rather than parking them under somebody arbitrary.
            'manager'     => [
                'nullable', 'string',
                Rule::exists('bureau_employees', 'uuid')
                    ->where(fn ($q) => $q->where('bureau_company_id', $company->id)),
            ],
        ], [
            'manager.exists' => 'That manager is not on this client\'s register.',
        ]);

        $moving = BureauEmployee::query()
            ->where('bureau_company_id', $company->id)
            ->whereIn('uuid', $data['employees'])
            ->get();

        if ($moving->isEmpty()) {
            throw ValidationException::withMessages(['employees' => 'None of those employees are on this client\'s register.']);
        }

        $manager = null;
        if (! blank($data['manager'] ?? null)) {
            $manager = BureauEmployee::query()
                ->where('bureau_company_id', $company->id)->where('uuid', $data['manager'])->firstOrFail();

            if ($moving->contains('id', $manager->id)) {
                throw ValidationException::withMessages([
                    'manager' => trim($manager->first_name . ' ' . $manager->last_name)
                        . ' is in the selection, and nobody can approve their own leave.',
                ]);
            }
        }

        DB::transaction(fn () => BureauEmployee::query()
            ->where('bureau_company_id', $company->id)
            ->whereIn('id', $moving->pluck('id'))
            ->update(['manager_employee_id' => $manager?->id]));

        return back()->with('success', sprintf(
            '%d employee(s) now report to %s.',
            $moving->count(),
            $manager ? trim($manager->first_name . ' ' . $manager->last_name) : 'nobody',
        ));
    }

    private function gate(BureauCompany $company): void
    {
        abort_unless($company->accessibleBy(Auth::user()), 403);
    }
}
