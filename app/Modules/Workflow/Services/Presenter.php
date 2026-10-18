<?php

namespace App\Modules\Workflow\Services;

use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowTask;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Shapes workflow records for the screens, reading people's names once per request. */
class Presenter
{
    /** @var array<int, string> names by user id, filled a company at a time */
    private array $names = [];

    /** @var array<int, bool> companies already loaded */
    private array $loaded = [];

    public function name(?int $userId): ?string
    {
        if (! $userId) {
            return null;
        }
        if (! isset($this->names[$userId]) && ($company = \App\Modules\Workflow\Support\WorkflowCompany::id())) {
            $this->load($company);
        }
        if (! isset($this->names[$userId])) {
            // Someone outside the company being viewed (a platform administrator viewing a tenant).
            $this->names += $this->query()->where('u.id', $userId)->pluck('name', 'u.id')->all();
        }

        return $this->names[$userId] ?? null;
    }

    /** @return list<array{id:int, name:string}> the active people of the company (the record's, when given) */
    public function people(?int $companyId = null): array
    {
        $companyId = \App\Modules\Workflow\Support\WorkflowCompany::id($companyId);
        if (! $companyId) {
            return [];
        }
        $this->load($companyId);
        $active = DB::table('users')->where('company_id', $companyId)->where('status', '!=', 0)->whereNull('deleted_at')->pluck('id')->flip();

        return collect($this->names)->filter(fn ($n, $id) => isset($active[$id]))->map(fn ($n, $id) => ['id' => (int) $id, 'name' => $n])->sortBy('name')->values()->all();
    }

    /** @return list<object{id:int, name:string}> the company's departments and other organisation units */
    public function departments(?int $companyId = null): array
    {
        $companyId = \App\Modules\Workflow\Support\WorkflowCompany::id($companyId);

        return $companyId ? DB::table('company_units')->where('company_id', $companyId)->where('active', 1)
            ->orderByRaw("CASE type WHEN 'department' THEN 0 ELSE 1 END")->orderBy('name')
            ->get(['id', 'name', 'type'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->type === 'department' ? $u->name : "{$u->name} ({$u->type})"])->all() : [];
    }

    private function load(int $companyId): void
    {
        if (! isset($this->loaded[$companyId])) {
            $this->names += $this->query()->where('u.company_id', $companyId)->limit(5000)->pluck('name', 'u.id')->all();
            $this->loaded[$companyId] = true;
        }
    }

    private function query()
    {
        return DB::table('users as u')->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->selectRaw("u.id, coalesce(nullif(trim(coalesce(p.first_name,'') || ' ' || coalesce(p.surname,'')), ''), u.email) as name");
    }

    public function task(WorkflowTask $t): array
    {
        $i = $t->relationLoaded('instance') ? $t->instance : null;

        return [
            'uuid' => $t->uuid, 'name' => $t->name, 'type' => $t->type, 'status' => $t->status, 'decision' => $t->decision,
            'assignee' => $this->name($t->assignee_id), 'assignee_id' => $t->assignee_id, 'queue' => $t->assignee_id === null && ! empty($t->candidates),
            'decided_by' => $this->name($t->decided_by), 'on_behalf_of' => $this->name($t->decided_on_behalf_of),
            'priority' => $t->priority, 'due_at' => $t->due_at?->toIso8601String(), 'sla_state' => $t->sla_state,
            'comment' => $t->comment, 'created_at' => $t->created_at?->toIso8601String(), 'completed_at' => $t->completed_at?->toIso8601String(),
            'instance' => $i ? ['uuid' => $i->uuid, 'reference' => $i->reference, 'title' => $i->title, 'requester' => $this->name($i->requester_id),
                'workflow' => $i->definition?->name, 'category' => $i->definition?->category, 'confidential' => (bool) $i->definition?->is_confidential] : null,
        ];
    }

    public function instance(WorkflowInstance $i): array
    {
        return [
            'uuid' => $i->uuid, 'reference' => $i->reference, 'title' => $i->title, 'status' => $i->status, 'outcome' => $i->outcome,
            'workflow' => $i->definition?->name, 'workflow_uuid' => $i->definition?->uuid, 'category' => $i->definition?->category,
            'version' => $i->relationLoaded('version') ? $i->version?->version : null,
            'requester' => $this->name($i->requester_id), 'priority' => $i->priority, 'subject_ref' => $i->subject_ref, 'subject_type' => $i->subject_type,
            'started_at' => $i->started_at?->toIso8601String(), 'completed_at' => $i->completed_at?->toIso8601String(), 'error' => $i->error,
            'confidential' => (bool) $i->definition?->is_confidential,
        ];
    }
}
