<?php

namespace App\Modules\Operations\ProjectManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\ClientProvider;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\PurchaseProvider;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\WorkCalendar;
use App\Modules\Operations\ProjectManagement\Services\ClientSyncService;
use App\Modules\Operations\ProjectManagement\Services\ModuleRegistry;
use App\Modules\Operations\ProjectManagement\Services\ProjectLinkService;
use App\Modules\Operations\ProjectManagement\Services\ProjectService;
use App\Modules\Operations\ProjectManagement\Services\ProviderRegistry;
use App\Modules\Operations\ProjectManagement\Support\Cities;
use App\Modules\Operations\ProjectManagement\Support\ProjectPresenter;
use App\Modules\Operations\ProjectManagement\Support\Timezones;
use App\Support\Money\Currencies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Projects — the register and its CRUD.
 *
 * Every action authorises through ProjectPolicy before it does anything, so the API is as protected
 * as the UI (DoD 4). Tenancy is not checked here and must not be: CompanyScope and row-level
 * security make another tenant's project unresolvable, so route binding fails with 404 long before
 * a policy could run.
 */
class ProjectController extends Controller
{
    public function __construct(
        private readonly ProjectService $projects,
        private readonly ModuleRegistry $registry,
        private readonly ClientSyncService $clients,
        private readonly ProjectLinkService $links,
        private readonly ProviderRegistry $providers,
    ) {
    }

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Project::class);

        // Project-scoped users see only the projects they are on (PM-1020); a tenant-wide grant
        // sees everything in the tenant. Applied to the query, so the list itself is the boundary.
        $query = Project::query()->visibleTo($request->user())->with('clientRef');

        // Filtering follows the module's API conventions: ?filter[status]=active
        $filters = (array) $request->input('filter', []);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $query->where(fn ($q) => $q->where('name', 'ilike', $search)->orWhere('code', 'ilike', $search));
        }

        $projects = $query->orderByDesc('id')->paginate(25)->withQueryString();

        return Inertia::render('Modules/ProjectManagement/Projects/Index', [
            'projects' => [
                'data'  => collect($projects->items())
                    ->map(fn (Project $p) => ProjectPresenter::redactFor(ProjectPresenter::summary($p), $request->user(), $p))
                    ->all(),
                'meta'  => [
                    'current_page' => $projects->currentPage(),
                    'last_page'    => $projects->lastPage(),
                    'total'        => $projects->total(),
                ],
            ],
            'filters'      => $filters,
            'statuses'     => Project::STATUSES,
            'stages'       => Project::STAGES,
            'priorities'   => Project::PRIORITIES,
            'calendars'    => WorkCalendar::orderBy('name')->get(['uuid', 'name', 'timezone'])
                ->map(fn (WorkCalendar $c) => ['id' => $c->uuid, 'name' => $c->name, 'timezone' => $c->timezone]),
            'timezones'    => Timezones::options(),
            // Shown in the create form so the user sees the code they are about to get, and can
            // overwrite it. Advisory only — the server regenerates if the field arrives blank.
            'nextCode'     => $this->projects->nextCode(),
            'currencies'   => Currencies::options(),
            // Tells the client which integration actions are real for this tenant, so it never
            // renders a button that cannot work.
            'capabilities' => $this->registry->forResponse(),
            'can'          => [
                'create' => $request->user()->can('create', Project::class),
                // Whether the calendar picker may offer to make one. Same gate as the Setup screen
                // that owns calendars, so the two cannot disagree about who is allowed.
                'manageSettings' => $request->user()->can('manageSettings', Project::class),
            ],
        ]);
    }

    public function show(Project $project): Response
    {
        $this->authorize('view', $project);

        $project->load('clientRef', 'calendar');

        return Inertia::render('Modules/ProjectManagement/Projects/Show', [
            'project'      => ProjectPresenter::redactFor(
                ProjectPresenter::detail($project), request()->user(), $project
            ),
            'capabilities' => $this->registry->forResponse(),
            // Records from other modules attached to this project, and the kinds this tenant can
            // attach. Both empty on a standalone workspace, and the panel hides itself — an empty
            // picker for a module nobody has is how a working system looks broken.
            'links'        => $this->links->forProject($project),
            'linkKinds'    => $this->links->availableKinds(),
            // Stage gates give the `stage` field teeth: moving on becomes a decision with named
            // criteria, a decider and a date, rather than a label somebody changed.
            'gates'        => $project->stageGates()->get()
                ->map(fn ($g) => [
                    'id'       => $g->uuid,
                    'stage'    => $g->stage,
                    'name'     => $g->name,
                    'criteria' => $g->criteria,
                    'status'   => $g->status,
                    'note'     => $g->decision_note,
                    'decided_at' => optional($g->decided_at)->toDateString(),
                ])->all(),
            // Only offered on a project with no plan yet: laying a template over one that already
            // has forty tasks would duplicate every one of them.
            'templates'    => $project->tasks()->exists() ? [] : \App\Modules\Operations\ProjectManagement\Models\ProjectTemplate::usable()
                ->orderBy('industry')->orderBy('name')->get(['uuid', 'name', 'industry'])
                ->map(fn ($t) => ['id' => $t->uuid, 'name' => $t->name, 'industry' => $t->industry])->all(),
            // The deal this project came from (`crm.opportunities`), when CRM knows of one.
            'opportunities' => $this->opportunitiesFor($project),
            'currencies'   => Currencies::options(),
            'timezones'    => Timezones::options(),
            'calendars'    => WorkCalendar::orderBy('name')->get(['uuid', 'name', 'timezone'])
                ->map(fn (WorkCalendar $c) => ['id' => $c->uuid, 'name' => $c->name, 'timezone' => $c->timezone]),
            'statuses'     => Project::STATUSES,
            'stages'       => Project::STAGES,
            'priorities'   => Project::PRIORITIES,
            'can'          => [
                'update'       => request()->user()->can('update', $project),
                'delete'       => request()->user()->can('delete', $project),
                'changeStatus' => request()->user()->can('changeStatus', $project),
                // Asked of the provider rather than of the entitlement: Procurement can be licensed
                // on a workspace whose deployment never ran its migrations, and the button has to
                // reflect what will actually happen when it is pressed.
                'raisePurchase' => request()->user()->can('update', $project)
                    && $this->providers->for(PurchaseProvider::class)->canRaise(),
                // Project Invoicing is an add-on and an account is nothing but cost, so the tab is
                // drawn only for somebody the invoicing routes would actually admit.
                'viewInvoicing' => request()->user()->can('viewCosts', $project),
            ],
        ]);
    }

    /**
     * The won deals behind this project's client.
     *
     * Answers "what did we sell them, and for how much?" on the page where somebody is being
     * measured against it — a figure that otherwise lives only in a memory of the proposal.
     *
     * @return list<array<string,mixed>>
     */
    private function opportunitiesFor(Project $project): array
    {
        $clientRef = $project->clientRef;

        if (! $clientRef || $clientRef->isLocal()) {
            return [];
        }

        return array_map(
            fn ($record) => ['label' => $record->label] + $record->meta,
            app(ProviderRegistry::class)->for(ClientProvider::class)
                ->opportunitiesFor((string) $clientRef->source_id, 5),
        );
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Project::class);

        $project = $this->projects->create($this->validated($request), Auth::id());

        /*
         * A project that ended up with no company belongs to nobody.
         *
         * Row-level security matches `company_id = <session>` and NULL equals nothing, so it is
         * invisible to every tenant user while still listing for the platform admin who made it.
         * One reached exactly that state: its members could not see the job they were on, and its
         * contract could not be signed, because the signatories could not load it.
         *
         * Said, not blocked. Refusing the save — at the model or here — turned out to reject the
         * project every factory, seeder and test creates outside a tenant context, which is a
         * legitimate path in this codebase. So the project is created and the person who made it
         * is told immediately, while they still remember doing it. `pm:adopt-orphans` repairs it.
         */
        if ($project->company_id === null) {
            return redirect()
                ->route('project-management.projects.show', $project->uuid)
                ->with('warning', __('project_management.flash.project_needs_company'));
        }

        return redirect()
            ->route('project-management.projects.show', $project->uuid)
            ->with('success', __('project_management.flash.project_created'));
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $data = $this->validated($request, $project);

        // Changing status is a heavier act than editing a name — it is its own grant.
        if (($data['status'] ?? $project->status) !== $project->status) {
            $this->authorize('changeStatus', $project);
        }

        $this->projects->update($project, $data);

        return back()->with('success', __('project_management.flash.project_updated'));
    }

    public function destroy(Project $project): RedirectResponse
    {
        $this->authorize('delete', $project);

        $this->projects->delete($project);

        return redirect()
            ->route('project-management.projects.index')
            ->with('success', __('project_management.flash.project_deleted'));
    }

    public function restore(string $uuid): RedirectResponse
    {
        $project = Project::withTrashed()->where('uuid', $uuid)->firstOrFail();

        $this->authorize('restore', $project);

        $this->projects->restore($project);

        return back()->with('success', __('project_management.flash.project_restored'));
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
            'name'          => ['required', 'string', 'max:190'],
            'description'   => ['nullable', 'string'],
            'lens_code'     => ['nullable', 'string', 'max:30'],
            'status'        => ['nullable', Rule::in(Project::STATUSES)],
            'stage'         => ['nullable', Rule::in(Project::STAGES)],
            'priority'      => ['nullable', Rule::in(Project::PRIORITIES)],
            'manager_id'    => ['nullable', 'integer'],
            // Money always states its currency (PM-ADR-006), and arrives as a decimal string so a
            // JavaScript client cannot round it on the way here.
            // Checked against the ISO-4217 list rather than "three characters", so a typo becomes
            // a validation message instead of an unusable currency on a document (PM-ADR-006).
            'currency'      => ['required', 'string', 'size:3', Rule::in(Currencies::codes())],
            'budget_amount' => ['nullable', 'numeric', 'decimal:0,2'],
            'timezone'      => ['required', 'timezone'],
            'calendar_uuid' => ['nullable', 'uuid', Rule::exists('pm_calendars', 'uuid')->whereNull('deleted_at')],
            'planned_start' => ['nullable', 'date'],
            'planned_end'   => ['nullable', 'date', 'after_or_equal:planned_start'],
            'actual_start'  => ['nullable', 'date'],
            'actual_end'    => ['nullable', 'date', 'after_or_equal:actual_start'],
            'location_name' => ['nullable', 'string', 'max:190'],
            'geo_lat'       => ['nullable', 'numeric', 'between:-90,90'],
            'geo_lng'       => ['nullable', 'numeric', 'between:-180,180'],
            'is_billable'   => ['nullable', 'boolean'],
            'timesheet_approval_required' => ['nullable', 'boolean'],
            // Whichever system owns clients here: a pm_external_refs uuid without CRM, a CRM
            // account id with it. The controller never has to know which — the sync service
            // resolves the id through the installed provider.
            'client_id'     => ['nullable', 'string', 'max:64'],
        ]);

        // Public uuid in, internal id out — the service and the column speak bigint.
        if (array_key_exists('calendar_uuid', $data)) {
            $data['calendar_id'] = $data['calendar_uuid']
                ? WorkCalendar::where('uuid', $data['calendar_uuid'])->value('id')
                : null;
            unset($data['calendar_uuid']);
        }

        if (array_key_exists('client_id', $data)) {
            $data['client_ref_id'] = $data['client_id']
                ? $this->clients->refFor($data['client_id'])?->id
                : null;
            unset($data['client_id']);
        }

        return $data;
    }

    /**
     * Search clients for the picker, and create one when the customer is not on the list yet.
     *
     * Creating from here rather than sending the user to another module is the difference between
     * a client field people fill in and one they leave blank. Where the new client lands is the
     * provider's business: CRM when it is installed, PM's own refs when it is not.
     */
    public function clients(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Project::class);

        $provider = app(ProviderRegistry::class)->for(ClientProvider::class);

        return response()->json([
            'clients'     => array_map(
                fn ($record) => $record->toArray(),
                $provider->search((string) $request->query('q', ''), 20),
            ),
            'owned_by_crm' => $provider->isAuthoritative(),
            // Picked from the platform's list rather than typed, so "Jhb" and "Johannesburg" do
            // not become two cities in every report that groups by one.
            'cities'       => Cities::options(),
        ]);
    }

    public function storeClient(Request $request): RedirectResponse
    {
        $this->authorize('create', Project::class);

        $data = $request->validate([
            'name'  => ['required', 'string', 'max:190'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            // Validated against the list the form offered, so the column cannot fill up with
            // spellings nothing can group. Nullable, because a place not on the list is better
            // left blank than recorded as the wrong one.
            'city'  => ['nullable', 'string', 'max:120', Rule::in(Cities::options())],
            'notes' => ['nullable', 'string', 'max:1000'],

            /*
             * What an invoice is addressed with.
             *
             * A client could be added with a name, an email and a town, and then its first invoice
             * printed an empty "To:" block and a missing VAT number — a document that is not a
             * valid tax invoice. These are asked for here, once, at the point somebody already has
             * the details in front of them, rather than retyped on the first account of every
             * project.
             */
            'address' => ['nullable', 'string', 'max:1000'],
            'country' => ['nullable', 'string', 'max:120'],
            'website' => ['nullable', 'string', 'max:190'],
            'vat_number' => ['nullable', 'string', 'max:40'],
            'registration_number' => ['nullable', 'string', 'max:40'],
        ]);

        /*
         * Split the same way the setup screen splits it.
         *
         * A CRM account has no VAT number and no "attention", so those live on PM's own link
         * record where a CRM sync cannot overwrite them with fields the account does not have.
         * Everything else belongs to whichever system owns the client.
         */
        $invoicing = array_intersect_key($data, array_flip(['vat_number', 'registration_number']));

        $record = app(ProviderRegistry::class)->for(ClientProvider::class)
            ->create(array_diff_key($data, $invoicing));

        // Materialise the ref now, so the id the form gets back is one a project can be saved with
        // whether or not CRM is the owner.
        $ref = $this->clients->refFor($record->id);

        /*
         * After refFor, deliberately.
         *
         * refFor *replaces* the snapshot with what the provider reports, while this merges into it
         * — so remembering first and resolving second would quietly discard the VAT number the
         * person had just typed. It also needs the ref to exist at all, which on the CRM path it
         * does not until refFor has run.
         */
        $this->clients->rememberInvoicingDetails($record, array_filter($invoicing));

        return back()->with('success', __('project_management.flash.client_created'))
            ->with('client', ['id' => $ref?->uuid ?? $record->id, 'label' => $record->label]);
    }
}
