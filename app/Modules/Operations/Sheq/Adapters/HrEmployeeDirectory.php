<?php

namespace App\Modules\Operations\Sheq\Adapters;

use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\Operations\Sheq\Contracts\EmployeeDirectory;
use Illuminate\Support\Facades\Schema;

/**
 * Reads the HR module through its Eloquent model (SHEQ-ADR-002).
 *
 * The whole point of this class is that it is the *only* place in SHEQ that knows HR exists. When
 * HR gains an outbox, this file is replaced by one that reads a projection, and nothing else in the
 * module changes.
 *
 * It deliberately reads through the model rather than the table, so HR's tenancy scope, soft
 * deletes and accessors apply — most importantly the department accessor, which resolves through
 * company_unit_user_pivots rather than a column on hr_people.
 */
class HrEmployeeDirectory implements EmployeeDirectory
{
    public function find(int $hrPersonId): ?array
    {
        return $this->findMany([$hrPersonId])[$hrPersonId] ?? null;
    }

    public function findMany(array $hrPersonIds): array
    {
        if ($hrPersonIds === [] || ! $this->isAvailable()) {
            return [];
        }

        return HRPerson::query()
            ->whereIn('id', $hrPersonIds)
            ->get()
            ->mapWithKeys(fn (HRPerson $person) => [$person->id => $this->present($person)])
            ->all();
    }

    public function roster(int $companyId): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        return HRPerson::query()
            // Explicit rather than trusting the model's scope: this runs from a controller where
            // a platform admin has no company, and an unscoped roster would offer one tenant's
            // staff as the injured party on another tenant's incident.
            ->where('company_id', $companyId)
            ->orderBy('first_name')->orderBy('surname')
            ->get()
            ->map(fn (HRPerson $person) => [
                'id' => $person->id,
                'full_name' => $this->present($person)['full_name'],
                'employee_number' => $person->employee_number ?? null,
                'job_title' => $person->position ?? null,
            ])
            ->values()
            ->all();
    }

    public function isAvailable(): bool
    {
        return Schema::hasTable('hr_people');
    }

    /** @return array<string,mixed> */
    private function present(HRPerson $person): array
    {
        // Name is assembled from first_name + surname: there is no `name` column on users, and a
        // SHEQ record naming the wrong person is worse than one naming nobody.
        $name = trim(($person->first_name ?? '').' '.($person->surname ?? ''));

        return [
            'id' => $person->id,
            'employee_number' => $person->employee_number ?? null,
            'full_name' => $name !== '' ? $name : ('Employee #'.$person->id),
            'job_title' => $person->position ?? null,
            // The department accessor handles the pivot lookup; guard because it can be absent.
            'department' => $this->safely(fn () => $person->department?->name ?? $person->department ?? null),
            'manager_name' => null,
            'employment_status' => $person->employment_status ?? null,
        ];
    }

    /**
     * An accessor that throws must not take an incident report down with it. A missing department
     * is a gap in the snapshot; a 500 during a fatality report is a different order of problem.
     */
    private function safely(callable $resolve): ?string
    {
        try {
            $value = $resolve();

            return is_string($value) ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
