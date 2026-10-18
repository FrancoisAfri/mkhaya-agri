<?php

namespace App\Modules\Operations\ProjectManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\ClientProvider;
use App\Modules\Operations\ProjectManagement\Models\ApprovalFlow;
use App\Modules\Operations\ProjectManagement\Models\ApprovalStep;
use App\Modules\Operations\ProjectManagement\Models\CalendarException;
use App\Modules\Operations\ProjectManagement\Models\ExternalRef;
use App\Modules\Operations\ProjectManagement\Models\InvoicingSetting;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\PmResource;
use App\Modules\Operations\ProjectManagement\Models\WorkCalendar;
use App\Modules\Operations\ProjectManagement\Services\ClientSyncService;
use App\Modules\Operations\ProjectManagement\Services\ModuleRegistry;
use App\Modules\Operations\ProjectManagement\Services\ProviderRegistry;
use App\Modules\Operations\ProjectManagement\Services\TeamService;
use App\Modules\Operations\ProjectManagement\Support\CapabilityCatalogue;
use App\Modules\Operations\ProjectManagement\Support\Cities;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use App\Core\Role\Models\Role;

/**
 * Module setup: working calendars and the integration surface.
 *
 * Gated at ADMIN by the hub guards as well as by permission, so an un-carded settings route added
 * later cannot quietly fall back to a lower tier.
 */
class SetupController extends Controller
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly ClientSyncService $clients,
    ) {
    }

    public function calendars(): Response
    {
        $this->authorize('manageSettings', Project::class);

        $calendars = WorkCalendar::with(['exceptions' => fn ($q) => $q->orderBy('exception_date')])
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (WorkCalendar $c) => [
                'id'         => $c->uuid,
                'name'       => $c->name,
                'timezone'   => $c->timezone,
                'work_week'  => $c->work_week,
                'is_default' => (bool) $c->is_default,
                'exceptions' => $c->exceptions->map(fn (CalendarException $e) => [
                    'id'         => $e->uuid,
                    'date'       => $e->exception_date->toDateString(),
                    'is_working' => (bool) $e->is_working,
                    'reason'     => $e->reason,
                ])->all(),
            ]);

        return Inertia::render('Modules/ProjectManagement/Setup/Calendars', [
            'calendars'    => $calendars,
            'standardWeek' => WorkCalendar::STANDARD_WEEK,
            'timezones'    => timezone_identifiers_list(),
        ]);
    }

    public function storeCalendar(Request $request): RedirectResponse
    {
        $this->authorize('manageSettings', Project::class);

        $calendar = WorkCalendar::create([...$this->calendarRules($request), 'created_by' => Auth::id()]);

        /*
         * Handed back so a caller can select what it just made.
         *
         * The Setup screen does not need this — it reloads its own list. The project form does: it
         * creates a calendar mid-way through creating a project, and without the new uuid the field
         * it was filling in stays empty and the person goes looking for the calendar they just
         * typed. Same shape and same reason as `client` from the client picker.
         */
        return back()
            ->with('success', __('project_management.flash.calendar_created'))
            ->with('calendar', [
                'id'    => $calendar->uuid,
                'label' => $calendar->name . ' · ' . $calendar->timezone,
            ]);
    }

    /**
     * Change a calendar's working hours.
     *
     * Without this a calendar could be created and never corrected — and every date the scheduler
     * computes depends on it, so "we got the hours wrong" would mean starting a new calendar and
     * repointing every project at it.
     */
    public function updateCalendar(Request $request, WorkCalendar $calendar): RedirectResponse
    {
        $this->authorize('manageSettings', Project::class);

        $calendar->update($this->calendarRules($request));

        return back()->with('success', __('project_management.flash.calendar_updated'));
    }

    /**
     * @return array<string,mixed>
     */
    private function calendarRules(Request $request): array
    {
        return $request->validate([
            'name'      => ['required', 'string', 'max:120'],
            'timezone'  => ['required', 'timezone'],
            // {"mon":[["07:00","16:00"]], "sat":[], …} — a day with no windows is a non-working day.
            'work_week' => ['required', 'array'],
            'work_week.*' => ['array'],
            'work_week.*.*' => ['array', 'size:2'],
            'work_week.*.*.*' => ['string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
        ]);
    }

    /**
     * What else this tenant has, what each integration does, and where in PM it is used.
     *
     * Read-only: availability comes from the platform's module and entitlement state, so the fix
     * for "not available" is a plan or licence change, not a toggle here.
     *
     * The page shows three states rather than two, which is the point of it. "Available" on its own
     * answered the wrong question — a tenant could read that `finance.budgets` was available and
     * learn nothing about whether anything in this module reads a Finance budget. `live` means PM
     * genuinely consumes it; `ready` means the module is there and PM's side is not built yet.
     */
    public function integrations(): Response
    {
        $this->authorize('manageSettings', Project::class);

        $available = $this->registry->forResponse();

        return Inertia::render('Modules/ProjectManagement/Setup/Integrations', [
            'capabilities' => $available,
            'catalogue'    => CapabilityCatalogue::describe($available),
            'counts'       => CapabilityCatalogue::counts($available),
        ]);
    }

    // ── Approval routing (EPIC-090) ──────────────────────────────────────────

    /**
     * The approval designer.
     *
     * Configuration rather than code because companies genuinely differ about who signs what, and
     * the two ways to handle that in a product are "one hard-coded rule everybody works around" or
     * this. The page also states what happens with *no* flow designed, because that is the state
     * most tenants will be in and leaving it implicit is how people assume nothing is routed.
     */
    public function approvals(): Response
    {
        $this->authorize('manageSettings', Project::class);

        return Inertia::render('Modules/ProjectManagement/Setup/Approvals', [
            'flows' => ApprovalFlow::with('steps')->orderBy('subject_type')->orderBy('name')->get()
                ->map(fn (ApprovalFlow $f) => [
                    'id'           => $f->uuid,
                    'name'         => $f->name,
                    'subject_type' => $f->subject_type,
                    'is_active'    => (bool) $f->is_active,
                    'threshold'    => $f->threshold_amount !== null ? (float) $f->threshold_amount : null,
                    'currency'     => $f->currency,
                    'project_id'   => $f->project?->uuid,
                    'description'  => $f->description,
                    'pending'      => $f->requests()->where('status', 'pending')->count(),
                    'steps'        => $f->steps->map(fn (ApprovalStep $s) => [
                        'id'            => $s->uuid,
                        'position'      => $s->position,
                        'name'          => $s->name,
                        'approver_type' => $s->approver_type,
                        'approver_ref'  => $s->approver_ref,
                        'min_approvals' => $s->min_approvals,
                        'allow_self'    => (bool) $s->allow_self_approval,
                    ])->all(),
                ])->all(),
            'subjectTypes'  => ApprovalFlow::SUBJECT_TYPES,
            'approverTypes' => ApprovalStep::APPROVER_TYPES,
            'projectRoles'  => TeamService::ROLES,
            'roles'         => Role::orderBy('name')->pluck('name')->all(),
            // People, for a step that names one. Typing a numeric user id was asking somebody to
            // look up a primary key in a database to configure an approval — and to get it wrong
            // silently, because a bad id is a step nobody can satisfy.
            'users'         => $this->selectableUsers(),
            'projects'      => Project::orderBy('code')->get(['uuid', 'code', 'name'])
                ->map(fn (Project $p) => ['id' => $p->uuid, 'label' => "{$p->code} · {$p->name}"])->all(),
            // A "manager" step needs HR to resolve one; without it the designer says so rather
            // than letting somebody build a ladder nobody can climb.
            'hasHr'         => $this->registry->has('hr.employees'),
        ]);
    }

    /**
     * Users a flow may name, with the name a person would recognise.
     *
     * There is no `users.name` column on this platform — the name lives in HR — so it is joined
     * here rather than left to the caller, and falls back to the email when somebody has a login
     * but no HR record.
     *
     * @return list<array{id:int, label:string}>
     */
    private function selectableUsers(): array
    {
        $userClass = config('auth.providers.users.model');

        return $userClass::query()
            ->with('person:id,user_id,first_name,surname')
            ->orderBy('email')
            ->limit(500)
            ->get(['id', 'email'])
            ->map(function ($user) {
                $name = trim(($user->person->first_name ?? '') . ' ' . ($user->person->surname ?? ''));

                return [
                    'id'    => (int) $user->id,
                    // The email always travels: two people share a name far more often than an
                    // address, and picking the wrong approver is not a mistake anybody notices.
                    'label' => $name !== '' ? "{$name} · {$user->email}" : (string) $user->email,
                ];
            })
            ->all();
    }

    public function storeFlow(Request $request): RedirectResponse
    {
        $this->authorize('manageSettings', Project::class);

        $data = $this->flowRules($request);

        $flow = ApprovalFlow::create([
            'name'                 => $data['name'],
            'subject_type'         => $data['subject_type'],
            'is_active'            => $data['is_active'] ?? true,
            'threshold_amount'     => $data['threshold'] ?? null,
            'currency'             => $data['currency'] ?? null,
            'project_id'           => isset($data['project_id'])
                ? Project::where('uuid', $data['project_id'])->value('id')
                : null,
            'escalate_after_hours' => $data['escalate_after_hours'] ?? null,
            'description'          => $data['description'] ?? null,
            'created_by'           => Auth::id(),
        ]);

        $this->syncSteps($flow, $data['steps'] ?? []);

        return back()->with('success', __('project_management.flash.flow_saved'));
    }

    public function updateFlow(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('manageSettings', Project::class);

        $flow = ApprovalFlow::where('uuid', $uuid)->firstOrFail();
        $data = $this->flowRules($request);

        $flow->update([
            'name'                 => $data['name'],
            'subject_type'         => $data['subject_type'],
            'is_active'            => $data['is_active'] ?? true,
            'threshold_amount'     => $data['threshold'] ?? null,
            'currency'             => $data['currency'] ?? null,
            'project_id'           => isset($data['project_id'])
                ? Project::where('uuid', $data['project_id'])->value('id')
                : null,
            'escalate_after_hours' => $data['escalate_after_hours'] ?? null,
            'description'          => $data['description'] ?? null,
        ]);

        $this->syncSteps($flow, $data['steps'] ?? []);

        return back()->with('success', __('project_management.flash.flow_saved'));
    }

    public function destroyFlow(string $uuid): RedirectResponse
    {
        $this->authorize('manageSettings', Project::class);

        $flow = ApprovalFlow::where('uuid', $uuid)->firstOrFail();

        // Requests already in the queue keep pointing at a soft-deleted flow, which is what lets
        // them finish under the rules they were raised under. Deleting the ladder out from under
        // somebody mid-climb would strand their submission.
        $flow->delete();

        return back()->with('success', __('project_management.flash.flow_deleted'));
    }

// ── Invoicing details ────────────────────────────────────────────────────

    /**
     * Who invoices are from, and where clients pay.
     *
     * Entered once for the practice rather than on every first invoice — which is how a bank
     * account number ends up wrong on one of them. Copied onto an invoice when it is opened; an
     * invoice already issued is never restated from here.
     */
    public function invoicing(): Response
    {
        $this->authorize('manageSettings', Project::class);

        $settings = InvoicingSetting::current();

        return Inertia::render('Modules/ProjectManagement/Setup/Invoicing', [
            'settings' => [
                'consultant_name'        => $settings->consultant_name,
                'consultant_address'     => $settings->consultant_address,
                'consultant_reference'   => $settings->consultant_reference,
                'consultant_vat_number'  => $settings->consultant_vat_number,
                'vendor_number'          => $settings->vendor_number,
                'banking_name'           => $settings->banking_name,
                'banking_account_name'   => $settings->banking_account_name,
                'banking_account_number' => $settings->banking_account_number,
                'banking_branch_code'    => $settings->banking_branch_code,
                'account_description'    => $settings->account_description,
                'vat_registered'         => (bool) $settings->vat_registered,
                'implementing_agent'     => (bool) $settings->implementing_agent,
                'default_project_funder' => $settings->default_project_funder,
                'vat_rate'               => (string) $settings->vat_rate,
                'disbursement_fee_rate'  => (string) $settings->disbursement_fee_rate,
                'report_recipient_ids'    => $settings->report_recipient_ids ?? [],
                'report_recipient_emails' => $settings->report_recipient_emails,
            ],
            // Who can be told when a monthly report is issued.
            'people' => \App\Core\User\Models\User::query()
                ->orderBy('email')->limit(500)->get(['id', 'email'])
                ->map(fn ($u) => ['id' => $u->id, 'label' => $u->email])->all(),
        ]);
    }

    public function updateInvoicing(Request $request): RedirectResponse
    {
        $this->authorize('manageSettings', Project::class);

        $data = $request->validate([
            'consultant_name'        => ['nullable', 'string', 'max:190'],
            'consultant_address'     => ['nullable', 'string', 'max:1000'],
            'consultant_reference'   => ['nullable', 'string', 'max:60'],
            'consultant_vat_number'  => ['nullable', 'string', 'max:40'],
            'vendor_number'          => ['nullable', 'string', 'max:60'],
            'banking_name'           => ['nullable', 'string', 'max:120'],
            'banking_account_name'   => ['nullable', 'string', 'max:190'],
            'banking_account_number' => ['nullable', 'string', 'max:40'],
            'banking_branch_code'    => ['nullable', 'string', 'max:20'],
            'account_description'    => ['nullable', 'string', 'max:190'],
            'vat_registered'         => ['boolean'],
            'implementing_agent'     => ['boolean'],
            'default_project_funder' => ['nullable', 'string', 'max:190'],
            'vat_rate'               => ['nullable', 'numeric', 'between:0,1'],
            'disbursement_fee_rate'  => ['nullable', 'numeric', 'between:0,1'],
            // Executives who are told the moment a monthly report is issued.
            'report_recipient_ids'    => ['nullable', 'array'],
            'report_recipient_ids.*'  => ['integer'],
            'report_recipient_emails' => ['nullable', 'string', 'max:2000'],
        ]);

        InvoicingSetting::current()->update($data + ['updated_by' => $request->user()->id]);

        return back()->with('success', __('project_management.flash.invoicing_settings_saved'));
    }

    // ── Clients (PM-1043) ────────────────────────────────────────────────────

    /**
     * The client list, and the one-press adoption into CRM.
     *
     * The case this page exists for: a tenant ran PM for a year without CRM, typed forty client
     * names into projects, then bought CRM. Adoption matches each of those names to a CRM account
     * — by email first, then exactly by name — and creates the account only when nothing matches.
     */
    public function clients(): Response
    {
        $this->authorize('manageSettings', Project::class);

        $clients = ExternalRef::query()
            ->where('local_type', 'project_client')
            ->orderBy('display_name')
            ->get();

        $usage = Project::query()
            ->whereNotNull('client_ref_id')
            ->selectRaw('client_ref_id, count(*) as total')
            ->groupBy('client_ref_id')
            ->pluck('total', 'client_ref_id');

        return Inertia::render('Modules/ProjectManagement/Setup/Clients', [
            'clients' => $clients->map(fn (ExternalRef $r) => [
                'id'       => $r->uuid,
                'name'     => $r->display_name,
                'source'   => $r->source_module,
                'status'   => $r->sync_status,
                'email'    => $r->snapshot['email'] ?? null,
                'phone'    => $r->snapshot['phone'] ?? null,
                'city'     => $r->snapshot['city'] ?? null,
                // What an invoice needs and a CRM account has no column for.
                'address'               => $r->snapshot['address'] ?? null,
                'vat_number'            => $r->snapshot['vat_number'] ?? null,
                'attention'             => $r->snapshot['attention'] ?? null,
                'client_project_number' => $r->snapshot['client_project_number'] ?? null,
                'projects' => (int) ($usage[$r->id] ?? 0),
                'synced_at' => $r->last_synced_at?->toDateTimeString(),
            ])->all(),
            'integrated' => $this->clients->isIntegrated(),
            'pending'    => $this->clients->pendingAdoptionCount(),
            'cities'     => Cities::options(),
        ]);
    }

    public function storeClient(Request $request): RedirectResponse
    {
        $this->authorize('manageSettings', Project::class);

        $data = $request->validate([
            'name'  => ['required', 'string', 'max:190'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            // From the platform's list rather than typed — see the client form for why.
            'city'  => ['nullable', 'string', 'max:120', Rule::in(Cities::options())],
            'notes' => ['nullable', 'string', 'max:1000'],
            // Invoicing details. Held here whether or not CRM owns the client, because a CRM
            // account has no VAT number or "attention" and an invoice cannot go out without them.
            'address'               => ['nullable', 'string', 'max:1000'],
            'vat_number'            => ['nullable', 'string', 'max:40'],
            'attention'             => ['nullable', 'string', 'max:190'],
            'client_project_number' => ['nullable', 'string', 'max:60'],
        ]);

        $invoicing = array_intersect_key($data, array_flip([
            'address', 'vat_number', 'attention', 'client_project_number',
        ]));

        $record = app(ProviderRegistry::class)->for(ClientProvider::class)->create(
            array_diff_key($data, $invoicing)
        );

        // Whichever system owns the client, the invoicing extras live on PM's link record — so
        // they survive a CRM sync rather than being overwritten by an account that has no such
        // fields to sync back.
        $this->clients->rememberInvoicingDetails($record, array_filter($invoicing));

        return back()->with('success', __('project_management.flash.client_created'));
    }

/**
     * Edit a client's invoicing details.
     *
     * Separate from creating one because the details that matter to an invoice — the VAT number,
     * who it is addressed to — are usually learned after the client already exists.
     */
    public function updateClient(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('manageSettings', Project::class);

        $ref = ExternalRef::where('local_type', 'project_client')->where('uuid', $uuid)->firstOrFail();

        $data = $request->validate([
            'address'               => ['nullable', 'string', 'max:1000'],
            'vat_number'            => ['nullable', 'string', 'max:40'],
            'attention'             => ['nullable', 'string', 'max:190'],
            'client_project_number' => ['nullable', 'string', 'max:60'],
        ]);

        // Only the keys the form sent, so clearing a field clears it and an absent one is left be.
        $ref->update(['snapshot' => array_merge($ref->snapshot ?? [], array_intersect_key($data, $request->all()))]);

        return back()->with('success', __('project_management.flash.client_updated'));
    }

    public function syncClients(): RedirectResponse
    {
        $this->authorize('manageSettings', Project::class);

        $adopted = $this->clients->adoptAll();
        $refreshed = $this->clients->refreshAll();

        return back()->with('success', __('project_management.flash.clients_synced', [
            'adopted'   => $adopted['adopted'] + $adopted['matched'],
            'refreshed' => $refreshed['refreshed'],
        ]));
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function flowRules(Request $request): array
    {
        return $request->validate([
            'name'                 => ['required', 'string', 'max:120'],
            'subject_type'         => ['required', Rule::in(ApprovalFlow::SUBJECT_TYPES)],
            'is_active'            => ['nullable', 'boolean'],
            'threshold'            => ['nullable', 'numeric', 'min:0'],
            'currency'             => ['nullable', 'string', 'size:3'],
            'project_id'           => ['nullable', 'uuid'],
            'escalate_after_hours' => ['nullable', 'integer', 'between:1,8760'],
            'description'          => ['nullable', 'string', 'max:1000'],
            // A flow needs at least one rung; an empty ladder approves nothing and blocks
            // everything, which is the worst of both.
            'steps'                => ['required', 'array', 'min:1'],
            'steps.*.name'          => ['required', 'string', 'max:120'],
            'steps.*.approver_type' => ['required', Rule::in(ApprovalStep::APPROVER_TYPES)],
            'steps.*.approver_ref'  => ['nullable', 'string', 'max:120'],
            'steps.*.min_approvals' => ['nullable', 'integer', 'between:1,10'],
            'steps.*.allow_self'    => ['nullable', 'boolean'],
        ]);
    }

    // ── Rooms, licences and plant PM owns itself (PM-1450) ──────────────────

    public function resources(): Response
    {
        $this->authorize('manageSettings', Project::class);

        return Inertia::render('Modules/ProjectManagement/Setup/Resources', [
            'resources' => PmResource::orderBy('kind')->orderBy('name')->get()
                ->map(fn (PmResource $r) => [
                    'id'             => $r->uuid,
                    'kind'           => $r->kind,
                    'name'           => $r->name,
                    'identifier'     => $r->identifier,
                    'capacity'       => $r->capacity,
                    'rate_per_day'   => $r->rate_per_day !== null ? (float) $r->rate_per_day : null,
                    'currency'       => $r->currency,
                    'available_from' => optional($r->available_from)->toDateString(),
                    'available_to'   => optional($r->available_to)->toDateString(),
                    'is_active'      => $r->is_active,
                    'notes'          => $r->notes,
                    // Computed here rather than in the browser: "expired" depends on the server's
                    // idea of today, and a stale tab would otherwise show a lapsed licence as live.
                    'expired'        => $r->hasExpired(),
                    'expiring_soon'  => $r->expiringSoon(),
                ])->all(),
            'kinds'     => PmResource::KINDS,
            'currency'  => config('app.currency', 'ZAR'),
        ]);
    }

    public function storeResource(Request $request): RedirectResponse
    {
        $this->authorize('manageSettings', Project::class);

        PmResource::create($this->validatedResource($request));

        return back()->with('success', __('project_management.flash.resource_added'));
    }

    public function updateResource(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('manageSettings', Project::class);

        PmResource::where('uuid', $uuid)->firstOrFail()->update($this->validatedResource($request));

        return back()->with('success', __('project_management.flash.resource_updated'));
    }

    /**
     * Soft delete, so anything already linked to this resource still resolves to a name.
     *
     * A project that booked a room last quarter should keep saying which room; hard-deleting would
     * turn that record into an orphaned id.
     */
    public function destroyResource(string $uuid): RedirectResponse
    {
        $this->authorize('manageSettings', Project::class);

        PmResource::where('uuid', $uuid)->firstOrFail()->delete();

        return back()->with('success', __('project_management.flash.resource_removed'));
    }

    /** @return array<string,mixed> */
    private function validatedResource(Request $request): array
    {
        return $request->validate([
            'kind'           => ['required', Rule::in(PmResource::KINDS)],
            'name'           => ['required', 'string', 'max:190'],
            'identifier'     => ['nullable', 'string', 'max:90'],
            'capacity'       => ['nullable', 'integer', 'between:1,10000'],
            'rate_per_day'   => ['nullable', 'numeric', 'min:0'],
            'currency'       => ['nullable', 'string', 'size:3'],
            'available_from' => ['nullable', 'date'],
            // Not before the start: a licence that expires before it begins is a typo, and letting
            // it through would show as permanently expired with no clue why.
            'available_to'   => ['nullable', 'date', 'after_or_equal:available_from'],
            'is_active'      => ['nullable', 'boolean'],
            'notes'          => ['nullable', 'string', 'max:2000'],
        ]);
    }

    /**
     * Replace the ladder.
     *
     * Steps are rewritten rather than diffed: they are ordered and small, and a live request holds
     * its position by number, so a rewrite that keeps the same positions keeps every pending
     * request pointing at a rung that still means what it meant.
     *
     * @param  list<array<string,mixed>>  $steps
     */
    private function syncSteps(ApprovalFlow $flow, array $steps): void
    {
        DB::transaction(function () use ($flow, $steps) {
            $flow->steps()->delete();

            foreach (array_values($steps) as $index => $step) {
                ApprovalStep::create([
                    'flow_id'             => $flow->id,
                    'position'            => $index + 1,
                    'name'                => $step['name'],
                    'approver_type'       => $step['approver_type'],
                    'approver_ref'        => $step['approver_ref'] ?? null,
                    'min_approvals'       => $step['min_approvals'] ?? 1,
                    'allow_self_approval' => (bool) ($step['allow_self'] ?? false),
                ]);
            }
        });
    }
}
