<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\WorkCalendar;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Project lifecycle. Every mutation happens here rather than in a controller, so the same rules
 * apply whether the caller is the web UI, the API or a sync worker — and so the audit trail and
 * outbox events cannot be forgotten by one of them.
 */
class ProjectService
{
    public function __construct(
        private readonly Outbox $outbox,
        private readonly CurrentCompany $currentCompany,
        private readonly InternalEmitter $emitter,
    ) {
    }

    /**
     * @param  array<string,mixed>  $data  May carry a client-generated `uuid` (PM-ADR-004).
     */
    public function create(array $data, ?int $userId = null): Project
    {
        return DB::transaction(function () use ($data, $userId) {
            $data['calendar_id'] ??= $this->defaultCalendar()->id;
            $data['code'] = $data['code'] ?? $this->nextCode();
            $data['created_by'] = $userId;
            $data['status'] ??= 'draft';
            $data['stage'] ??= 'initiation';
            $data['timezone'] ??= config('app.timezone', 'UTC');

            $project = Project::create($data);

            $this->outbox->publish('pm.project.created', 'project', $project->uuid, [
                'project_uuid' => $project->uuid,
                'code'         => $project->code,
                'name'         => $project->name,
                'status'       => $project->status,
            ]);

            return $project;
        });
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function update(Project $project, array $data): Project
    {
        return DB::transaction(function () use ($project, $data) {
            // The public id is not editable — repointing it would break every external reference
            // to this project, including offline clients holding it (PM-ADR-003).
            unset($data['uuid'], $data['company_id']);

            $previousStatus = $project->status;
            $statusChanged = isset($data['status']) && $data['status'] !== $project->status;

            $project->fill($data)->save();

            if ($statusChanged) {
                $this->outbox->publish('pm.project.status_changed', 'project', $project->uuid, [
                    'project_uuid' => $project->uuid,
                    'status'       => $project->status,
                ]);

                // A status change is evidence, and it is recorded in the same transaction as the
                // change itself so the ledger cannot end up missing something that happened
                // (PM-1004). source_ref makes a retried save record the fact once.
                $this->emitter->emit($project, 'status.changed', [
                    'source_ref' => "project:{$project->id}:status:{$project->status}",
                    'payload'    => ['from' => $previousStatus, 'to' => $project->status],
                ]);
            }

            return $project;
        });
    }

    /**
     * Soft delete (PM-ADR-007). The project, its WBS and its tasks go together so a restore brings
     * back a coherent structure rather than fragments — and the evidence behind it all survives
     * regardless, because the ledger has no delete at all.
     */
    public function delete(Project $project): void
    {
        DB::transaction(function () use ($project) {
            $project->tasks()->delete();
            $project->dependencies()->delete();
            $project->wbsNodes()->delete();
            $project->delete();

            $this->outbox->publish('pm.project.deleted', 'project', $project->uuid, [
                'project_uuid' => $project->uuid,
            ]);
        });
    }

    public function restore(Project $project): Project
    {
        return DB::transaction(function () use ($project) {
            $project->restore();
            $project->wbsNodes()->withTrashed()->restore();
            $project->tasks()->withTrashed()->restore();
            $project->dependencies()->withTrashed()->restore();

            $this->outbox->publish('pm.project.restored', 'project', $project->uuid, [
                'project_uuid' => $project->uuid,
            ]);

            return $project;
        });
    }

    /**
     * The tenant's fallback working calendar, created on first use. A project always has a
     * calendar: the scheduler cannot compute a single date without one.
     */
    public function defaultCalendar(): WorkCalendar
    {
        $existing = WorkCalendar::where('is_default', true)->first();

        if ($existing) {
            return $existing;
        }

        return WorkCalendar::create([
            'name'       => 'Standard working week',
            'timezone'   => config('app.timezone', 'UTC'),
            'work_week'  => WorkCalendar::STANDARD_WEEK,
            'is_default' => true,
        ]);
    }

    /**
     * Sequential per tenant (PRJ-0001), which is what people expect to quote and file by. It is a
     * label, not an identifier — the public id is the UUID, so this leaks no volume outside the
     * tenant that generated it (PM-ADR-003).
     *
     * Public because the create form shows the next code up front rather than making the user
     * guess or leave it blank: they see what it will be, and can overwrite it.
     */
    public function nextCode(): string
    {
        $companyId = $this->currentCompany->id();

        $last = Project::withTrashed()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where('code', 'like', 'PRJ-%')
            ->orderByDesc('id')
            ->value('code');

        $next = $last ? ((int) Str::afterLast($last, '-')) + 1 : 1;

        return 'PRJ-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
