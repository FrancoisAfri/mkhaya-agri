<?php

namespace App\Modules\Operations\ProjectManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\ProjectManagement\Models\ContractorInvoice;
use App\Modules\Operations\ProjectManagement\Models\ContractTemplate;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Services\ContractorBillingService;
use App\Modules\Operations\ProjectManagement\Services\ContractorRegistryService;
use App\Modules\Operations\ProjectManagement\Support\Invoicing\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The delivery side of subcontractor contracts and billing.
 *
 * The contractor's own side lives on the portal (PortalController). This is where a project
 * manager reads what came in, approves or rejects it, and — where the practice on-charges the work
 * — decides the markup for that particular invoice.
 *
 * ── Two different permissions ───────────────────────────────────────────────
 *
 * Seeing that a contractor has submitted an invoice is delivery information. Seeing what that work
 * is *billed on at* is cost information, so the markup and the billable position are gated on
 * `pm.costs.view` separately from the rest of the screen. A site manager can work the queue
 * without learning the practice's margin.
 */
class ContractorBillingController extends Controller
{
    public function __construct(
        private readonly ContractorBillingService $billing,
        private readonly ContractorRegistryService $registry,
    ) {
    }

    /**
     * The register: every subcontractor, and the way in to add one.
     *
     * This is what the ribbon lands on. The invoice queue is one thing you do with a subcontractor,
     * not the subject itself — opening on it left adding a contractor, their contract and their
     * tasks with nowhere to be reached from.
     */
    public function register(Request $request): Response
    {
        $this->authorize('viewAny', Project::class);

        return Inertia::render('Modules/ProjectManagement/Contractors/Register', [
            'contractors' => $this->registry->all($request->string('q')->toString()),
            'q'           => $request->string('q')->toString() ?: null,
            // Where the Supplier module is installed these are its records, and the screen says so
            // rather than implying PM owns a list that procurement also keeps.
            'integrated'  => $this->registry->isIntegrated(),
            'projects'    => Project::query()->orderBy('name')->limit(300)->get()
                ->map(fn (Project $p) => ['id' => $p->uuid, 'label' => trim($p->code . ' — ' . $p->name)])->all(),
            'can'         => ['add' => $request->user()->can('create', Project::class)],
        ]);
    }

    /** Everything about one subcontractor: their projects, work, contracts and invoices. */
    public function show(Request $request, string $id): Response
    {
        $this->authorize('viewAny', Project::class);

        $detail = $this->registry->detail($id, $this->canSeeCosts($request));

        abort_if($detail === null, 404);

        return Inertia::render('Modules/ProjectManagement/Contractors/Show', [
            'contractor' => $detail,
            'projects'   => Project::query()->orderBy('name')->limit(300)->get()
                ->map(fn (Project $p) => ['id' => $p->uuid, 'label' => trim($p->code . ' — ' . $p->name)])->all(),
            'costs'      => $this->canSeeCosts($request),
            'can'        => ['manage' => $request->user()->can('create', Project::class)],
        ]);
    }

    /**
     * Add a subcontractor to the register.
     *
     * Name and contact, nothing more — there is no vetting step here by design. Where a project is
     * chosen they are engaged on it and invited to the portal at the same time, because a
     * subcontractor who cannot log in cannot invoice.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Project::class);

        $data = $request->validate([
            'name'                => ['required', 'string', 'max:190'],
            'email'               => ['nullable', 'email', 'max:190'],
            'phone'               => ['nullable', 'string', 'max:40'],
            'registration_number' => ['nullable', 'string', 'max:60'],
            'vat_number'          => ['nullable', 'string', 'max:40'],
            'notes'               => ['nullable', 'string', 'max:2000'],
            'project'             => ['nullable', 'uuid'],
        ]);

        if (! empty($data['project'])) {
            $data['project_id'] = Project::where('uuid', $data['project'])->value('id');
        }

        $this->registry->add($data);

        return back()->with('success', __('project_management.flash.contractor_added'));
    }

    /** Engage a registered subcontractor on another project. */
    public function engage(Request $request, string $id): RedirectResponse
    {
        $this->authorize('create', Project::class);

        $data = $request->validate(['project' => ['required', 'uuid']]);

        $project = Project::where('uuid', $data['project'])->firstOrFail();
        $found = $this->registry->detail($id, false);
        abort_if($found === null, 404);

        $this->registry->attachToProject(
            new \App\Modules\Operations\ProjectManagement\Support\Providers\ProvidedRecord(
                id: $found['id'], label: $found['name'], source: $found['source'], email: $found['email'],
            ),
            $project,
        );

        return back()->with('success', __('project_management.flash.contractor_engaged'));
    }

    /** The queue: what contractors have sent in, across the projects this person can see. */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Project::class);

        $showCosts = $this->canSeeCosts($request);

        $invoices = ContractorInvoice::query()
            ->with(['lines', 'member', 'project'])
            ->when(
                $request->string('status')->isNotEmpty(),
                fn ($q) => $q->where('status', $request->string('status')->toString()),
                // The default view is the work: what is waiting on a decision.
                fn ($q) => $q->where('status', 'submitted'),
            )
            ->when($request->string('project')->isNotEmpty(), fn ($q) => $q->whereHas(
                'project',
                fn ($p) => $p->where('uuid', $request->string('project')->toString()),
            ))
            ->orderByDesc('submitted_at')
            ->limit(200)
            ->get();

        return Inertia::render('Modules/ProjectManagement/Contractors/Index', [
            'invoices' => $invoices->map(fn (ContractorInvoice $i) => $this->rowFor($i, $showCosts))->all(),
            'filters'  => [
                'status'  => $request->string('status')->toString() ?: 'submitted',
                'project' => $request->string('project')->toString() ?: null,
            ],
            'statuses' => ContractorInvoice::STATUSES,
            'projects' => Project::query()->orderBy('name')->limit(300)->get()
                ->map(fn (Project $p) => ['id' => $p->uuid, 'label' => trim($p->code . ' — ' . $p->name)])->all(),
            'costs' => $showCosts,
        ]);
    }

    /** One contractor's position on one project: their contract, and what they have claimed. */
    public function forProject(Request $request, Project $project): Response
    {
        $this->authorize('view', $project);

        $showCosts = $this->canSeeCosts($request);

        $contractors = ProjectMember::query()
            ->where('project_id', $project->id)
            ->where('role_key', 'contractor')
            ->active()
            ->get();

        return Inertia::render('Modules/ProjectManagement/Contractors/Project', [
            'project' => [
                'id'   => $project->uuid,
                'code' => $project->code,
                'name' => $project->name,
            ],
            'contractors' => $contractors->map(function (ProjectMember $m) use ($project, $showCosts) {
                $contract = $this->billing->liveContractFor($project, $m);
                $position = $this->billing->positionFor($project, $m);

                return [
                    'id'    => $m->uuid,
                    'name'  => $m->display_name,
                    'email' => $m->email,
                    'contract' => $contract ? [
                        'id'      => $contract->uuid,
                        'number'  => $contract->contract_number,
                        'title'   => $contract->title,
                        'status'  => $contract->status,
                        'version' => $contract->current_version,
                        'value'   => $showCosts ? Money::money($contract->contract_value) : null,
                        'start_date' => optional($contract->start_date)->toDateString(),
                        'end_date'   => optional($contract->end_date)->toDateString(),
                    ] : null,
                    // Approved-to-date and what remains are cost. The count is not.
                    'position' => $showCosts ? $position : null,
                    'submitted_count' => ContractorInvoice::where('project_id', $project->id)
                        ->where('project_member_id', $m->id)->where('status', 'submitted')->count(),
                ];
            })->values()->all(),
            // PM's own library. Drafting happens on the contracts screen, so this screen only needs
            // to know whether there is anything to draft *from*.
            'templates' => ContractTemplate::query()->active()->orderBy('name')->limit(100)->get()
                ->map(fn (ContractTemplate $t) => ['id' => $t->uuid, 'name' => $t->name])->all(),
            'costs' => $showCosts,
            'can'   => [
                'manageTemplates' => $request->user()?->can('manageSettings', Project::class) ?? false,
            ],
        ]);
    }

    /**
     * Approve a submitted invoice, with the markup for this one.
     *
     * The markup is optional and is chosen here rather than configured anywhere, because the
     * client said so plainly: it does not always apply, and the project manager decides.
     */
    public function approve(Request $request, string $uuid): RedirectResponse
    {
        $invoice = $this->decidableInvoice($request, $uuid);

        $data = $request->validate([
            // Empty string clears a markup that was set; absent leaves it as it is.
            'markup_rate' => ['nullable', 'numeric', 'between:0,1'],
        ]);

        $this->billing->approve(
            $invoice,
            $request->has('markup_rate') ? (string) ($data['markup_rate'] ?? '') : null,
        );

        return back()->with('success', __('project_management.flash.cinvoice_approved'));
    }

    public function reject(Request $request, string $uuid): RedirectResponse
    {
        $invoice = $this->decidableInvoice($request, $uuid);

        $data = $request->validate([
            // Required, and not for form's sake: the contractor is shown this and has to be able
            // to act on it.
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $this->billing->reject($invoice, $data['reason']);

        return back()->with('success', __('project_management.flash.cinvoice_rejected'));
    }

    /** The contractor's own document, streamed rather than handed to the browser as a blob. */
    public function download(Request $request, string $uuid)
    {
        $invoice = ContractorInvoice::query()->where('uuid', $uuid)->firstOrFail();

        $this->authorize('view', $invoice->project);

        abort_if(! $invoice->hasBytes(), 404);

        return $invoice->fileResponse($invoice->file_name, $invoice->file_mime);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** A submitted invoice this person may decide on. */
    private function decidableInvoice(Request $request, string $uuid): ContractorInvoice
    {
        $invoice = ContractorInvoice::query()->with('lines')->where('uuid', $uuid)->firstOrFail();

        $this->authorize('update', $invoice->project);
        $this->authorizeCosts($request);

        abort_unless(
            $invoice->status === 'submitted',
            422,
            __('project_management.contractors.not_submitted'),
        );

        return $invoice;
    }

    private function contractorOf(Project $project, string $memberUuid): ProjectMember
    {
        $member = ProjectMember::query()->where('project_id', $project->id)
            ->where('uuid', $memberUuid)->active()->firstOrFail();

        abort_unless($member->role_key === 'contractor', 422);

        return $member;
    }

    /** @return array<string, mixed> */
    private function rowFor(ContractorInvoice $invoice, bool $showCosts): array
    {
        $row = [
            'id'           => $invoice->uuid,
            'reference'    => $invoice->reference,
            'contractor'   => $invoice->member?->display_name,
            'project'      => $invoice->project?->name,
            'project_code' => $invoice->project?->code,
            'invoice_date' => optional($invoice->invoice_date)->toDateString(),
            'period_end'   => optional($invoice->period_end)->toDateString(),
            'description'  => $invoice->description,
            'status'       => $invoice->status,
            'submitted_at' => optional($invoice->submitted_at)->toDateTimeString(),
            'has_document' => $invoice->file_name !== null,
            'currency'     => $invoice->currency,
            'lines'        => $invoice->lines->map(fn ($l) => $l->toPayload())->all(),
        ];

        if (! $showCosts) {
            return $row;
        }

        // Everything below this line is money, and only reaches somebody holding pm.costs.view.
        return $row + [
            'subtotal'       => Money::money($invoice->subtotal),
            'vat_amount'     => Money::money($invoice->vat_amount),
            'total'          => Money::money($invoice->total),
            'markup_rate'    => $invoice->markup_rate === null ? null : (string) $invoice->markup_rate,
            'markup_amount'  => Money::money($invoice->markup_amount),
            'billable_total' => Money::money($invoice->billable_total),
        ];
    }

    /** The module's own cost gate, so this screen agrees with every other one about money. */
    private function canSeeCosts(Request $request): bool
    {
        return $request->user()?->can('viewCosts', Project::class) ?? false;
    }

    private function authorizeCosts(Request $request): void
    {
        abort_unless($this->canSeeCosts($request), 403);
    }
}
