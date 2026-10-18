<?php

namespace App\Modules\Operations\ProjectManagement\Http\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Services\ModuleRegistry;
use App\Modules\Operations\ProjectManagement\Services\ProjectService;
use App\Modules\Operations\ProjectManagement\Support\Api\ApiQuery;
use App\Modules\Operations\ProjectManagement\Models\WorkCalendar;
use App\Modules\Operations\ProjectManagement\Support\ProjectPresenter;
use App\Support\Money\Currencies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Projects over the PM API (§4). The first resource on the new surface, and the one that
 * establishes the conventions the rest follow: cursor pagination, `filter[...]`, `?fields=`,
 * `Idempotency-Key`, problem+json errors, and the capability manifest in the envelope.
 *
 * Tenancy is not enforced here and must not be: ResolveCompanyFromToken pins the tenant,
 * RequireTenantContext refuses a request without one, and CompanyScope plus row-level security do
 * the filtering. A project belonging to another tenant does not resolve, so the client gets 404 —
 * never 403, which would confirm the record exists (PM-1010).
 */
class ProjectApiController extends Controller
{
    public function __construct(
        private readonly ProjectService $projects,
        private readonly ModuleRegistry $registry,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Project::class);

        $query = Project::query()->visibleTo($request->user())->with('clientRef');

        ApiQuery::applyFilters($query, $request, [
            'status'   => fn ($q, $v) => $q->whereIn('status', (array) $v),
            'stage'    => fn ($q, $v) => $q->whereIn('stage', (array) $v),
            'priority' => fn ($q, $v) => $q->whereIn('priority', (array) $v),
            'search'   => fn ($q, $v) => $q->where(fn ($w) => $w
                ->where('name', 'ilike', '%' . $v . '%')
                ->orWhere('code', 'ilike', '%' . $v . '%')),
        ]);

        // Cursor pagination needs a deterministic order; id is unique and monotonic.
        $page = $query->orderBy('id')->cursorPaginate(
            ApiQuery::limit($request),
            ['*'],
            'cursor',
            $request->query('cursor')
        );

        $data = collect($page->items())
            ->map(fn (Project $p) => ApiQuery::selectFields(
                ProjectPresenter::redactFor(ProjectPresenter::summary($p), $request->user(), $p),
                $request
            ))
            ->all();

        return response()->json(ApiQuery::envelope(
            $data,
            $page->nextCursor()?->encode(),
            $this->registry->forResponse(),
        ));
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $project->load('clientRef', 'calendar');

        return response()->json([
            'data' => ApiQuery::selectFields(
                ProjectPresenter::redactFor(ProjectPresenter::detail($project), $request->user(), $project),
                $request
            ),
            'meta' => ['capabilities' => $this->registry->forResponse()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Project::class);

        $project = $this->projects->create($this->validated($request), $request->user()?->id);

        return response()->json(
            ['data' => ProjectPresenter::detail($project)],
            201
        );
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $data = $this->validated($request, $project);

        if (($data['status'] ?? $project->status) !== $project->status) {
            $this->authorize('changeStatus', $project);
        }

        $this->projects->update($project, $data);

        return response()->json(['data' => ProjectPresenter::detail($project->fresh())]);
    }

    public function destroy(Project $project): JsonResponse
    {
        $this->authorize('delete', $project);

        $this->projects->delete($project);

        // Soft delete (PM-ADR-007): the record is archived, so 200 with the archived resource is
        // more honest than 204 "gone".
        return response()->json(['data' => ProjectPresenter::summary($project->fresh())]);
    }

    /**
     * @return array<string,mixed>
     */
    private function validated(Request $request, ?Project $project = null): array
    {
        $id = $project?->id;

        $data = $request->validate([
            // A client may supply the public id so an offline-created project keeps it (PM-ADR-004).
            'uuid'          => ['nullable', 'uuid', Rule::unique('pm_projects', 'uuid')->ignore($id)],
            'code'          => ['nullable', 'string', 'max:40', Rule::unique('pm_projects', 'code')->ignore($id)->whereNull('deleted_at')],
            'name'          => [$project ? 'sometimes' : 'required', 'string', 'max:190'],
            'description'   => ['nullable', 'string'],
            'lens_code'     => ['nullable', 'string', 'max:30'],
            'status'        => ['nullable', Rule::in(Project::STATUSES)],
            'stage'         => ['nullable', Rule::in(Project::STAGES)],
            'priority'      => ['nullable', Rule::in(Project::PRIORITIES)],
            'manager_id'    => ['nullable', 'integer'],
            'currency'      => [$project ? 'sometimes' : 'required', 'string', 'size:3', Rule::in(Currencies::codes())],
            'budget_amount' => ['nullable', 'numeric', 'decimal:0,2'],
            'timezone'      => [$project ? 'sometimes' : 'required', 'timezone'],
            'calendar_uuid' => ['nullable', 'uuid', Rule::exists('pm_calendars', 'uuid')->whereNull('deleted_at')],
            'planned_start' => ['nullable', 'date'],
            'planned_end'   => ['nullable', 'date', 'after_or_equal:planned_start'],
            'location_name' => ['nullable', 'string', 'max:190'],
            'is_billable'   => ['nullable', 'boolean'],
        ]);

        // Public uuid in, internal id out (PM-ADR-003): an API client never learns a bigint.
        if (array_key_exists('calendar_uuid', $data)) {
            $data['calendar_id'] = $data['calendar_uuid']
                ? WorkCalendar::where('uuid', $data['calendar_uuid'])->value('id')
                : null;
            unset($data['calendar_uuid']);
        }

        return $data;
    }
}
