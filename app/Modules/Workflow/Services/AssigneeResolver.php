<?php

namespace App\Modules\Workflow\Services;

use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Support\ProviderRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Who a task goes to (PRS §8.1), in one place instead of the six "line manager" lookups the modules
 * each carry. Only active people of the instance's company are ever returned; an empty answer means
 * the engine raises an exception rather than letting the task disappear.
 */
class AssigneeResolver
{
    public function __construct(private readonly ProviderRegistry $providers)
    {
    }

    /**
     * @param  array{type?:string, value?:mixed}  $spec
     * @return list<int>
     */
    public function resolve(array $spec, WorkflowInstance $instance): array
    {
        $value = $spec['value'] ?? null;
        $ids = match ($spec['type'] ?? null) {
            'user' => array_filter(array_map('intval', (array) $value)),
            'role' => $this->usersWithRole((string) $value, $instance->company_id),
            'department_head' => $this->departmentHead($value ? (int) $value : $this->departmentOf($instance->requester_id)),
            'department_members' => $this->departmentMembers($value ? (int) $value : $this->departmentOf($instance->requester_id)),
            'line_manager' => array_filter([$this->managerOf($instance->requester_id)]),
            'requester' => array_filter([$instance->requester_id]),
            'record_owner' => array_filter([$this->fromProvider($instance, 'ownerId')]),
            'budget_holder' => array_filter([$this->fromProvider($instance, 'budgetHolderId')]),
            'field' => array_filter(array_map('intval', (array) data_get($instance->data, (string) $value))),
            'process_owner' => array_filter([$instance->definition?->process_owner_id]),
            default => [],
        };

        return $this->active(array_values(array_unique(array_map('intval', $ids))), $instance->company_id);
    }

    /** The line manager of a user: hr_people.manager_id (an hr_people id), then the second manager. */
    public function managerOf(?int $userId): ?int
    {
        if (! $userId) {
            return null;
        }
        $person = DB::table('hr_people')->where('user_id', $userId)->first(['manager_id', 'second_manager_id']);
        foreach ([$person->manager_id ?? null, $person->second_manager_id ?? null] as $managerPersonId) {
            if ($managerPersonId) {
                $managerUser = DB::table('hr_people')->where('id', $managerPersonId)->value('user_id');
                if ($managerUser && (int) $managerUser !== $userId) {
                    return (int) $managerUser;
                }
            }
        }

        return null;
    }

    /** The department unit a user belongs to (company_units of type department). */
    public function departmentOf(?int $userId): ?int
    {
        if (! $userId) {
            return null;
        }

        // company_unit_user_pivots keys the unit in its company_id column.
        return DB::table('company_unit_user_pivots as p')->join('company_units as u', 'u.id', '=', 'p.company_id')
            ->where('p.user_id', $userId)->where('u.type', 'department')->value('u.id');
    }

    /** @return list<int> */
    public function departmentHead(?int $unitId): array
    {
        return $unitId ? array_filter([(int) DB::table('company_units')->where('id', $unitId)->value('user_id')]) : [];
    }

    /** @return list<int> */
    public function departmentMembers(?int $unitId): array
    {
        return $unitId ? DB::table('company_unit_user_pivots')->where('company_id', $unitId)->pluck('user_id')->map(fn ($v) => (int) $v)->all() : [];
    }

    /** @return list<int> */
    public function usersWithRole(string $role, ?int $companyId): array
    {
        if ($role === '') {
            return [];
        }

        return DB::table('model_has_roles as mr')->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->join('users as u', 'u.id', '=', 'mr.model_id')
            ->where('mr.model_type', \App\Core\User\Models\User::class)->where('r.name', $role)
            ->where('u.company_id', $companyId)->pluck('u.id')->map(fn ($v) => (int) $v)->all();
    }

    /** @return list<int> */
    public function active(array $ids, ?int $companyId): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('users')->whereIn('id', $ids)->where('company_id', $companyId)->where('status', '!=', 0)
            ->whereNull('deleted_at')->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    private function fromProvider(WorkflowInstance $instance, string $method): ?int
    {
        $provider = $this->providers->forSubjectType($instance->subject_type);
        $record = $provider && $instance->subject_id ? $provider->find($instance->subject_id) : null;

        return $record ? $provider->{$method}($record) : null;
    }
}
