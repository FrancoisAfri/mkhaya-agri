<?php

namespace App\Modules\Operations\ProjectManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\DocumentStore;
use App\Modules\Operations\ProjectManagement\Models\ApprovalRequest;
use App\Modules\Operations\ProjectManagement\Models\ChangeRequest;
use App\Modules\Operations\ProjectManagement\Models\ClientRating;
use App\Modules\Operations\ProjectManagement\Models\CompletionRecord;
use App\Modules\Operations\ProjectManagement\Models\DerivedValue;
use App\Modules\Operations\ProjectManagement\Models\ExternalRef;
use App\Modules\Operations\ProjectManagement\Models\ContractorInvoice;
use App\Modules\Operations\ProjectManagement\Models\ProgressClaim;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectContract;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Models\SiteInstruction;
use App\Modules\Operations\ProjectManagement\Models\Snag;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Models\TaskAssignment;
use App\Modules\Operations\ProjectManagement\Models\TimeEntry;
use App\Modules\Operations\ProjectManagement\Services\ApprovalService;
use App\Modules\Operations\ProjectManagement\Services\ContractorBillingService;
use App\Modules\Operations\ProjectManagement\Services\PermissionEvaluator;
use App\Modules\Operations\ProjectManagement\Services\ProviderRegistry;
use App\Modules\Operations\ProjectManagement\Services\TimesheetService;
use App\Modules\Operations\ProjectManagement\Support\Providers\ProvidedRecord;
use App\Modules\Operations\ProjectManagement\Notifications\ClientRequestedTaskNotification;
use App\Modules\Operations\ProjectManagement\Services\TaskService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The client and contractor portals (§20, §21).
 *
 * ── Not a second application ────────────────────────────────────────────────
 *
 * A portal user is an ordinary user who is a project member with the `client` or `contractor` role.
 * That is deliberate, and it is the whole security argument: a separate login realm would mean a
 * second authentication path, a second permission model and a second set of tenancy rules — three
 * more places for an external party to end up seeing something they should not. Here they go
 * through `PermissionEvaluator` and `ProjectPresenter::redactFor` like everybody else, and the cost
 * redaction that already applies to them applies here without being re-implemented.
 *
 * ── One page, two audiences ─────────────────────────────────────────────────
 *
 * The two portals share a shell because they answer the same shape of question — "what is happening
 * on my job, and what needs me" — and differ only in the panels. Splitting them into two
 * controllers would duplicate the project resolution, the redaction and the approval wiring to save
 * two `if`s.
 *
 * Somebody who is a client on one project and a contractor on another sees both, per project. That
 * is a real situation on subcontracted work and pretending otherwise would show them half their
 * jobs.
 */
class PortalController extends Controller
{
    public function __construct(
        private readonly PermissionEvaluator $evaluator,
        private readonly ProviderRegistry $providers,
        private readonly TaskService $tasks,
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $request->user();

        // Only the projects this person is externally attached to. A staff member with no external
        // membership sees an empty portal rather than the whole portfolio in a friendlier skin.
        $memberships = ProjectMember::query()
            ->where('user_id', $user->id)
            ->active()
            ->whereIn('role_key', ['client', 'contractor'])
            ->with('project')
            ->get()
            ->filter(fn (ProjectMember $m) => $m->project !== null
                // A finished project drops out of the portal entirely: an outside login has no
                // reason to still be reading a job that ended.
                && in_array($m->project->status, self::OPEN_STATUSES, true));

        $projects = $memberships->map(function (ProjectMember $m) use ($user) {
            $project = $m->project;
            $isClient = $m->role_key === 'client';

            return [
                'id'    => $project->uuid,
                'code'  => $project->code,
                'name'  => $project->name,
                'role'  => $m->role_key,
                'status' => $project->status,
                'stage' => $project->stage,
                'planned_end' => optional($project->planned_end)->toDateString(),
                // The one number everybody asks for. Taken from the derivation engine, so it is the
                // same figure the delivery team sees rather than a second opinion computed here.
                'progress' => $this->progressOf($project),

                /*
                 * What the client has asked for, and where it got to.
                 *
                 * Their own requests only — the delivery team's task list is not a client's
                 * business, and showing it would turn every internal note into something to be
                 * explained. Read back so a request that was answered is visibly answered, which
                 * is the whole reason to raise it here rather than by email.
                 */
                'requests' => $isClient
                    ? Task::where('project_id', $project->id)
                        ->where('created_by', $user->id)
                        ->real()
                        ->orderByDesc('id')
                        ->limit(50)->get()
                        ->map(fn (Task $t) => [
                            'id'      => $t->uuid,
                            'code'    => $t->code,
                            'name'    => $t->name,
                            'status'  => $t->status,
                            'raised'  => optional($t->created_at)->toDateString(),
                        ])->all()
                    : [],

                'milestones' => Task::where('project_id', $project->id)
                    ->where('is_milestone', true)
                    ->orderBy('planned_end')
                    ->limit(20)->get()
                    ->map(fn (Task $t) => [
                        'name'   => $t->name,
                        'due'    => optional($t->planned_end)->toDateString(),
                        'status' => $t->status,
                        'done'   => $t->status === 'done',
                    ])->all(),

                'documents' => array_map(fn (ProvidedRecord $d) => [
                    'id'   => $d->id,
                    'name' => $d->label,
                    'size' => $d->meta['size'] ?? null,
                ], $this->providers->for(DocumentStore::class)->forProject($project->uuid)),

                'completions' => CompletionRecord::where('project_id', $project->id)
                    ->orderBy('certified_on')->get()
                    ->map(fn (CompletionRecord $c) => [
                        'kind' => $c->kind,
                        'date' => $c->certified_on->toDateString(),
                        'defects_ends' => optional($c->defects_period_ends)->toDateString(),
                    ])->all(),

                // ── Client-side panels ──────────────────────────────────────
                'changes' => $isClient
                    ? ChangeRequest::where('project_id', $project->id)
                        ->whereIn('status', ['submitted', 'approved', 'implemented'])
                        ->orderByDesc('id')->limit(30)->get()
                        ->map(fn (ChangeRequest $c) => [
                            'code'   => $c->code,
                            'title'  => $c->title,
                            'status' => $c->status,
                            'days'   => $c->impact_days,
                        ])->all()
                    : [],

                // ── Contractor-side panels ──────────────────────────────────
                /**
                 * Their contracts on this job.
                 *
                 * A subcontractor has to be able to read what they are working under, and to sign
                 * it — the portal is the only screen they can reach, so a contract that only
                 * appears on the delivery side is one they cannot act on. `awaiting_me` is what
                 * turns the card into a call to action rather than a record.
                 */
                'contracts' => ! $isClient
                    ? ProjectContract::query()
                        ->where('project_id', $project->id)
                        ->where('project_member_id', $m->id)
                        ->orderByDesc('id')->limit(20)->get()
                        ->map(fn (ProjectContract $c) => [
                            'id'      => $c->uuid,
                            'number'  => $c->contract_number,
                            'title'   => $c->title,
                            'status'  => $c->status,
                            'version' => $c->current_version,
                            'awaiting_me' => $c->signatures()
                                ->where('status', 'pending')
                                ->where('user_id', $user->id)
                                ->exists(),
                        ])->all()
                    : [],

                'work' => ! $isClient
                    ? Task::where('project_id', $project->id)
                        ->whereIn('id', TaskAssignment::where('project_id', $project->id)
                            ->where('member_id', $m->id)->select('task_id'))
                        ->whereNotIn('status', ['done', 'cancelled'])
                        ->orderBy('planned_end')->limit(50)->get()
                        ->map(fn (Task $t) => [
                            'id'     => $t->uuid,
                            'name'   => $t->name,
                            'due'    => optional($t->planned_end)->toDateString(),
                            'status' => $t->status,
                        ])->all()
                    : [],

                'snags' => ! $isClient
                    ? Snag::where('project_id', $project->id)
                        ->where('assigned_member_id', $m->id)
                        ->whereNotIn('status', ['closed', 'rejected'])
                        ->orderBy('due_date')->limit(50)->get()
                        ->map(fn (Snag $s) => [
                            'reference' => $s->reference,
                            'title'     => $s->title,
                            'severity'  => $s->severity,
                            'due'       => optional($s->due_date)->toDateString(),
                        ])->all()
                    : [],

                'instructions' => ! $isClient
                    ? SiteInstruction::where('project_id', $project->id)
                        ->orderByDesc('issued_on')->limit(20)->get()
                        ->map(fn (SiteInstruction $s) => [
                            'number'    => $s->number,
                            'title'     => $s->title,
                            'issued_on' => $s->issued_on->toDateString(),
                            'variation' => $s->claimed_as_variation,
                        ])->all()
                    : [],

                // Their own invoices. externalPayload() is what keeps markup on our side of the
                // wall — a contractor must not learn what their work is being resold at.
                'invoices' => ! $isClient
                    ? ContractorInvoice::where('project_id', $project->id)
                        ->where('project_member_id', $m->id)
                        ->with('lines')
                        ->orderByDesc('id')->limit(30)->get()
                        ->map(fn (ContractorInvoice $i) => $i->externalPayload())->all()
                    : [],

                'claims' => ! $isClient
                    ? ProgressClaim::where('project_id', $project->id)
                        ->orderByDesc('id')->limit(20)->get()
                        ->map(fn (ProgressClaim $c) => [
                            'number' => $c->claim_number,
                            'status' => $c->status,
                            'period' => optional($c->period_end)->toDateString(),
                            // The net, which is the only figure a contractor is being paid.
                            'net'    => $c->net_due !== null ? (float) $c->net_due : null,
                        ])->all()
                    : [],

                // What this client already said this month, so the form is an edit rather than a
                // second ask — being asked twice in a month is how people stop answering.
                'my_rating' => $isClient
                    ? ClientRating::where('project_id', $project->id)
                        ->where('user_id', $user->id)
                        ->whereDate('period_month', now()->startOfMonth())
                        ->first(['score', 'comment'])
                    : null,

                'my_hours' => ! $isClient
                    ? (float) TimeEntry::where('project_id', $project->id)
                        ->where('member_id', $m->id)
                        ->where('worked_on', '>=', now()->startOfMonth())
                        ->sum('hours')
                    : null,
                'member_id' => $m->uuid,
            ];
        })->values()->all();

        return Inertia::render('Modules/ProjectManagement/Portal/Index', [
            // The portal runs in its own shell rather than the business's app frame, so it has to
            // carry its own identity: who is signed in, and whose job board this is.
            'me' => [
                /*
                 * The name somebody typed when they were invited, not the email.
                 *
                 * `$user->name` falls back to the email address when there is no `hr_people`
                 * record — and a project client has none by design, because they do not work here.
                 * So the portal header, which exists to say who is signed in, said
                 * "someone@gmail.com" to a person whose name we hold on their membership.
                 */
                'name' => $memberships->first()?->display_name ?: $user->name,
                /*
                 * No membership means no role, not the default one.
                 *
                 * This fell back to the literal 'contractor', so anybody who reached the portal
                 * without being on a project — an administrator following the link, most often —
                 * was labelled "Subcontractor" in the header while the page underneath correctly
                 * told them they are attached to nothing. The header was the more confident of the
                 * two and it was the one that was wrong.
                 */
                'role' => $memberships->first()?->role_key,
            ],
            'company' => optional($memberships->first()?->project)->name,
            'projects' => $projects,
            // Anything actually waiting on this person to decide, across every project. This is the
            // reason somebody opens a portal, so it is not buried inside a project card.
            'awaiting' => app(ApprovalService::class)->inboxFor($user)
                ->map(fn (ApprovalRequest $r) => [
                    'id'      => $r->uuid,
                    'label'   => $r->subject_label,
                    'subject' => $r->subject_type,
                    'project' => $r->project?->code,
                    'amount'  => $r->amount !== null ? (float) $r->amount : null,
                    'currency' => $r->currency,
                ])->values()->all(),
            'can' => [
                // A client uploads drawings and specifications; a contractor uploads progress and
                // compliance. Both need it, so it is granted to any portal member on a project they
                // are attached to — the DocumentStore still validates what it accepts.
                'upload' => true,
            ],
        ]);
    }

    /**
     * Upload a document from the portal.
     *
     * Authorised by membership rather than by `update`: a client who may not change the project
     * still has to be able to send a drawing, and a contractor has to be able to return a signed
     * compliance certificate. Anything beyond attaching a file remains closed to them.
     */
    public function upload(Request $request, Project $project): RedirectResponse
    {
        abort_unless($this->isPortalMemberOf($request, $project), 403);

        $request->validate([
            'file' => ['required', 'file', 'max:' . (DocumentsController::MAX_BYTES / 1024)],
        ]);

        try {
            $this->providers->for(DocumentStore::class)->put($project->uuid, $request->file('file'), [
                'uploaded_by' => $request->user()->id,
                // Marked so the delivery team can see at a glance that it came from outside.
                'source'      => 'portal',
            ]);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return back()->with('success', __('project_management.flash.document_uploaded'));
    }

    /**
     * A contractor books hours against work assigned to them.
     *
     * Goes through the same TimesheetService and the same approval routing as any other timesheet.
     * A separate contractor-hours path would be a second set of numbers that labour cost, the
     * reports and payroll would all have to learn about.
     */
    public function logTime(Request $request, Project $project, TimesheetService $timesheets): RedirectResponse
    {
        abort_unless($this->isPortalMemberOf($request, $project, 'contractor'), 403);

        $data = $request->validate([
            'task_id'   => ['nullable', 'uuid'],
            'worked_on' => ['required', 'date', 'before_or_equal:today'],
            'hours'     => ['required', 'numeric', 'gt:0', 'max:24'],
            'note'      => ['nullable', 'string', 'max:500'],
        ]);

        $member = ProjectMember::where('project_id', $project->id)
            ->where('user_id', $request->user()->id)->active()->firstOrFail();

        $timesheets->log($project, $member, $data);

        return back()->with('success', __('project_management.flash.time_logged'));
    }


    // ── A contractor's own invoices ─────────────────────────────────────────

    /**
     * Start an invoice.
     *
     * Guarded by contractor membership, like `logTime`: this is a person submitting their own
     * claim for their own work, not somebody operating the project.
     */
    public function openInvoice(
        Request $request,
        Project $project,
        ContractorBillingService $billing,
    ): RedirectResponse {
        abort_unless($this->isPortalMemberOf($request, $project, 'contractor'), 403);

        $data = $request->validate([
            'reference'    => ['nullable', 'string', 'max:60'],
            'invoice_date' => ['nullable', 'date'],
            'period_start' => ['nullable', 'date'],
            'period_end'   => ['nullable', 'date', 'after_or_equal:period_start'],
            'description'  => ['nullable', 'string', 'max:2000'],
            'vat_rate'     => ['nullable', 'numeric', 'between:0,1'],
        ]);

        $billing->openInvoice($project, $this->memberFor($request, $project), $data);

        return back()->with('success', __('project_management.flash.cinvoice_opened'));
    }

    /** Add or amend a line on an invoice they have not sent yet. */
    public function saveInvoiceLine(
        Request $request,
        Project $project,
        string $invoiceUuid,
        ContractorBillingService $billing,
    ): RedirectResponse {
        $invoice = $this->ownDraftInvoice($request, $project, $invoiceUuid);

        $data = $request->validate([
            'line_id'     => ['nullable', 'uuid'],
            'description' => ['required', 'string', 'max:255'],
            'quantity'    => ['nullable', 'numeric', 'gt:0'],
            'rate'        => ['nullable', 'numeric', 'min:0'],
        ]);

        $billing->saveLine($invoice, $data, $data['line_id'] ?? null);

        return back()->with('success', __('project_management.flash.cinvoice_updated'));
    }

    public function removeInvoiceLine(
        Request $request,
        Project $project,
        string $invoiceUuid,
        string $lineUuid,
        ContractorBillingService $billing,
    ): RedirectResponse {
        $billing->removeLine($this->ownDraftInvoice($request, $project, $invoiceUuid), $lineUuid);

        return back()->with('success', __('project_management.flash.cinvoice_updated'));
    }

    /** Attach their own PDF — most contractors have already issued one on their own letterhead. */
    public function attachInvoiceDocument(
        Request $request,
        Project $project,
        string $invoiceUuid,
    ): RedirectResponse {
        $invoice = $this->ownDraftInvoice($request, $project, $invoiceUuid);

        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:10240'],
        ]);

        $invoice->update($invoice->saveFileToDatabase($request->file('file')));

        return back()->with('success', __('project_management.flash.cinvoice_updated'));
    }

    /**
     * Send it to us.
     *
     * An invoice with no lines and no document is not an invoice — refusing here is kinder than
     * a project manager rejecting an empty claim a week later.
     */
    public function submitInvoice(
        Request $request,
        Project $project,
        string $invoiceUuid,
        ContractorBillingService $billing,
    ): RedirectResponse {
        $invoice = $this->ownDraftInvoice($request, $project, $invoiceUuid);

        if ($invoice->lines()->count() === 0 && $invoice->file_name === null) {
            return back()->withErrors([
                'invoice' => __('project_management.portal.cinvoice_empty'),
            ]);
        }

        $billing->submit($invoice);

        return back()->with('success', __('project_management.flash.cinvoice_submitted'));
    }

    /** This person's membership of this project. */
    private function memberFor(Request $request, Project $project): ProjectMember
    {
        return ProjectMember::where('project_id', $project->id)
            ->where('user_id', $request->user()->id)->active()->firstOrFail();
    }

    /**
     * An invoice that is theirs, on this project, and still a draft.
     *
     * Three checks in one place. The ownership one matters most: without it a contractor could
     * pass another contractor's invoice uuid and edit a claim that is not theirs.
     */
    private function ownDraftInvoice(Request $request, Project $project, string $uuid): ContractorInvoice
    {
        abort_unless($this->isPortalMemberOf($request, $project, 'contractor'), 403);

        $invoice = ContractorInvoice::where('project_id', $project->id)
            ->where('project_member_id', $this->memberFor($request, $project)->id)
            ->where('uuid', $uuid)
            ->firstOrFail();

        abort_unless($invoice->isEditableByContractor(), 422, __('project_management.portal.cinvoice_sent'));

        return $invoice;
    }

    /**
     * A client raises a task on their own project.
     *
     * The thing a client actually wants from a portal is to ask for something and know it was
     * received. Without this the portal is a noticeboard: they can see the work and rate the month,
     * and every actual request goes by email or phone and lands nowhere the project can see it.
     *
     * It is a task like any other, so it appears on the board, can be assigned, commented on and
     * scheduled by the delivery team exactly as if they had raised it themselves. Deliberately
     * *not* given a status, a date or an assignee from here: what a client is asking for is not the
     * same as what the team has committed to, and letting an outside login set a due date would put
     * a promise on the schedule that nobody made.
     */
    public function storeTask(Request $request, Project $project): RedirectResponse
    {
        abort_unless($this->isPortalMemberOf($request, $project, 'client'), 403);

        $data = $request->validate([
            'name'        => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);

        $task = $this->tasks->create($project, [
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'created_by'  => $request->user()->id,
        ]);

        /*
         * Told to the people who run the job, not left to be noticed. A request that sits on a
         * board nobody refreshed is the same as the email it replaced.
         */
        $this->notifyProjectOfClientRequest($project, $task, $request->user());

        return back()->with('success', __('project_management.portal.task_raised'));
    }

    /**
     * Tell whoever runs the project that the client has asked for something.
     *
     * The project's own members with a login — not everybody on the tenant. A notification that
     * reaches people with no connection to the job is one they learn to filter.
     */
    private function notifyProjectOfClientRequest(Project $project, Task $task, $client): void
    {
        $userIds = ProjectMember::where('project_id', $project->id)
            ->active()
            ->whereNotNull('user_id')
            ->whereNotIn('role_key', ['client', 'contractor'])
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->reject(fn (int $id) => $id === (int) $client->id)
            ->unique();

        if ($userIds->isEmpty()) {
            return;
        }

        $recipients = \App\Core\User\Models\User::query()->whereIn('id', $userIds)->get();

        if ($recipients->isNotEmpty()) {
            \Illuminate\Support\Facades\Notification::send(
                $recipients,
                new ClientRequestedTaskNotification($task, $this->clientName($client)),
            );
        }
    }

    /** Names live on the HR record on this platform; there is no `users.name` column. */
    private function clientName($user): ?string
    {
        $person = $user->person ?? null;

        return $person ? trim($person->first_name . ' ' . $person->surname) ?: $user->email : $user->email;
    }

    /**
     * A client rates the month.
     *
     * One rating per client per project per month, enforced by the unique index and by upserting
     * here: asking more often than monthly trains people to ignore the ask, and a satisfaction
     * average built from somebody clicking five times is not a satisfaction average.
     */
    public function rate(Request $request, Project $project): RedirectResponse
    {
        abort_unless($this->isPortalMemberOf($request, $project, 'client'), 403);

        $data = $request->validate([
            'score'   => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        ClientRating::updateOrCreate(
            [
                'project_id'   => $project->id,
                'user_id'      => $request->user()->id,
                'period_month' => now()->startOfMonth()->toDateString(),
            ],
            $data,
        );

        return back()->with('success', __('project_management.flash.rating_recorded'));
    }

    /**
     * Projects a portal login still has business seeing.
     *
     * Access is for the duration of the work. Once a project is finished — completed, cancelled or
     * archived — an outside login keeps no reason to be in the system, and leaving it open means a
     * client from two years ago still holds a door into a live tenant.
     *
     * The membership stays: it is the record of who they were on the job, and revoking access by
     * deleting people would lose that. What ends is what they can reach.
     */
    public const OPEN_STATUSES = ['draft', 'active', 'on_hold'];

    /** Membership on this project, optionally of a specific external role. */
    private function isPortalMemberOf(Request $request, Project $project, ?string $role = null): bool
    {
        // Checked here rather than at each caller, so a new portal action cannot forget it.
        if (! in_array($project->status, self::OPEN_STATUSES, true)) {
            return false;
        }

        return ProjectMember::where('project_id', $project->id)
            ->where('user_id', $request->user()->id)
            ->active()
            ->whereIn('role_key', $role ? [$role] : ['client', 'contractor'])
            ->exists();
    }

    /**
     * The project's progress, from the derivation engine.
     *
     * Read rather than recomputed on purpose: a portal that calculated its own percentage would
     * eventually disagree with the one the delivery team sees, and the client would be reading a
     * number nobody else has.
     */
    private function progressOf(Project $project): ?float
    {
        // `value_numeric`, not `value` — there is no `value` column, and asking for one threw
        // SQLSTATE 42703 on every portal page load for every user. It went unnoticed because
        // nothing exercised this controller; PortalInviteTest now loads the page.
        $value = DerivedValue::where('subject_type', 'project')
            ->where('subject_uuid', $project->uuid)
            ->where('metric', 'progress_pct')
            ->latest('id')
            ->value('value_numeric');

        return $value !== null ? round((float) $value, 1) : null;
    }
}
