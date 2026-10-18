<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Operations\ProjectManagement\Contracts\Providers\ContractorProvider;
use App\Modules\Operations\ProjectManagement\Models\ContractorInvoice;
use App\Modules\Operations\ProjectManagement\Models\ProjectContract;
use App\Modules\Operations\ProjectManagement\Models\ExternalRef;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Models\TaskAssignment;
use App\Modules\Operations\ProjectManagement\Support\Invoicing\Money;
use App\Modules\Operations\ProjectManagement\Providers\Local\LocalContractorProvider;
use App\Modules\Operations\ProjectManagement\Support\Providers\ProvidedRecord;
use Illuminate\Support\Facades\DB;

/**
 * The subcontractor register: one record per firm, however many projects they work on.
 *
 * ── Why this exists in Project Management ───────────────────────────────────
 *
 * A subcontractor is not an optional part of running a project, and a customer may buy Project
 * Management on its own. So the register is PM's when PM is alone, and becomes a view of the
 * Supplier module's records on a tenant that has that module — resolved per tenant by
 * ProviderRegistry, so the same deployment serves both.
 *
 * ── The firm and the engagement are different things ────────────────────────
 *
 * The register holds the firm. `pm_project_members` holds an engagement — this firm, on this
 * project, with a role and a portal login. One firm has many engagements, which is what makes a
 * contractor page able to show "everything about them" across the portfolio rather than per job.
 *
 * The link is `pm_project_members.external_ref_id`, which already existed for exactly this shape.
 */
class ContractorRegistryService
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly MemberInviteService $invites,
        private readonly AuditLogger $audit,
    ) {
    }

    private function provider(): ContractorProvider
    {
        return $this->providers->for(ContractorProvider::class);
    }

    /** True when subcontractors are the Supplier module's records on this tenant. */
    public function isIntegrated(): bool
    {
        return $this->provider()->isAuthoritative();
    }

    /**
     * Every subcontractor on the register, with enough to decide who to open.
     *
     * @return list<array<string, mixed>>
     */
    public function all(string $query = ''): array
    {
        $records = $this->provider()->search($query, 200);

        // One query for every engagement rather than one per contractor.
        // ref id => provider record id, so engagements can be counted against the right firm.
        $links = ExternalRef::query()
            ->where('local_type', LocalContractorProvider::LOCAL_TYPE)
            ->get()
            ->mapWithKeys(fn (ExternalRef $r) => [$r->id => $r->source_id ?: $r->uuid]);

        $engagements = ProjectMember::query()
            ->where('role_key', 'contractor')
            ->whereNotNull('external_ref_id')
            ->get()
            ->groupBy(fn (ProjectMember $m) => $links[$m->external_ref_id] ?? null);

        return array_map(function (ProvidedRecord $record) use ($engagements) {
            $members = $engagements->get($record->id, collect());

            return [
                'id'       => $record->id,
                'name'     => $record->label,
                'email'    => $record->email,
                'source'   => $record->source,
                'meta'     => $record->meta,
                'projects' => $members->count(),
                'awaiting' => ContractorInvoice::query()
                    ->whereIn('project_member_id', $members->pluck('id'))
                    ->where('status', 'submitted')
                    ->count(),
            ];
        }, $records);
    }

    /**
     * Add a subcontractor, and give them their portal login.
     *
     * There is no onboarding step: the client was explicit that adding a subcontractor is recording
     * a name and a contact, and that they should be able to reach the portal. The invitation goes
     * out with the record so nobody has to remember a second action — an added contractor who
     * cannot log in cannot invoice, which is the whole point of adding them.
     *
     * @param  array{name:string, email?:?string, phone?:?string, registration_number?:?string,
     *               vat_number?:?string, notes?:?string, project_id?:?int}  $data
     */
    public function add(array $data): ProvidedRecord
    {
        return DB::transaction(function () use ($data) {
            $record = $this->provider()->create($data);

            // Attaching to a project is optional here. A firm can be on the register before there
            // is a job for them, and the portal invitation follows the engagement rather than the
            // register entry — there is nothing to log in to without a project.
            if (! empty($data['project_id'])) {
                $project = Project::findOrFail($data['project_id']);
                $this->attachToProject($record, $project, $data['email'] ?? null);
            }

            return $record;
        });
    }

    /**
     * The `pm_external_refs` row that represents this firm inside PM.
     *
     * `pm_project_members.external_ref_id` is a foreign key, not a uuid, so an engagement points at
     * a row rather than at whatever the provider calls its record. Standalone, that row *is* the
     * subcontractor. Integrated, it is a link to the supplier — which is what gives a later sync
     * something to refresh, and what stops a supplier being re-created in PM's own list.
     */
    private function linkFor(ProvidedRecord $record): ExternalRef
    {
        $local = ExternalRef::query()
            ->where('local_type', LocalContractorProvider::LOCAL_TYPE)
            ->where('uuid', $record->id)
            ->first();

        if ($local) {
            return $local;
        }

        return ExternalRef::firstOrCreate(
            [
                'local_type'    => LocalContractorProvider::LOCAL_TYPE,
                'source_module' => $record->source,
                'source_id'     => $record->id,
            ],
            [
                'source_entity'  => 'contractor',
                'display_name'   => $record->label,
                'snapshot'       => $record->meta + array_filter(['email' => $record->email]),
                'sync_status'    => 'ok',
                'last_synced_at' => now(),
            ],
        );
    }

    /**
     * Put a registered subcontractor on a project, and invite them to the portal.
     *
     * Idempotent: a firm already engaged on this project keeps the engagement it has rather than
     * gaining a second one, which would split their invoices across two rows.
     */
    public function attachToProject(ProvidedRecord $record, Project $project, ?string $email = null): ProjectMember
    {
        $link = $this->linkFor($record);

        $member = ProjectMember::query()
            ->where('project_id', $project->id)
            ->where('external_ref_id', $link->id)
            ->first();

        if (! $member) {
            $member = ProjectMember::create([
                'project_id'      => $project->id,
                'external_ref_id' => $link->id,
                'role_key'        => 'contractor',
                'display_name'    => $record->label,
                'email'           => $email ?? $record->email,
                'is_active'       => true,
                'created_by'      => auth()->id(),
            ]);

            $this->audit->recordAction($member, 'contractor.engaged', [
                'project' => $project->code,
            ]);
        }

        // Invite on engagement, and only where we have somewhere to send it.
        if ($member->email && $member->user_id === null) {
            try {
                $this->invites->invite($member, null, auth()->id());
            } catch (\Throwable $e) {
                // A mail failure must not lose the engagement — the invitation can be resent.
                report($e);
            }
        }

        return $member;
    }

    /**
     * Everything about one subcontractor, across every project they are on.
     *
     * @return array<string, mixed>|null
     */
    public function detail(string $id, bool $showCosts): ?array
    {
        $record = $this->provider()->get($id);

        if (! $record) {
            return null;
        }

        $linkIds = ExternalRef::query()
            ->where('local_type', LocalContractorProvider::LOCAL_TYPE)
            ->where(fn ($q) => $q->where('uuid', $id)->orWhere('source_id', $id))
            ->pluck('id');

        $members = ProjectMember::query()
            ->whereIn('external_ref_id', $linkIds)
            ->where('role_key', 'contractor')
            ->with('project')
            ->get();

        $memberIds = $members->pluck('id');

        $invoices = ContractorInvoice::query()
            ->whereIn('project_member_id', $memberIds)
            ->with('lines')
            ->orderByDesc('id')
            ->get();

        return [
            'id'     => $record->id,
            'name'   => $record->label,
            'email'  => $record->email,
            'source' => $record->source,
            'meta'   => $record->meta,

            'projects' => $members->map(fn (ProjectMember $m) => [
                'member_id'  => $m->uuid,
                'project_id' => $m->project?->uuid,
                'code'       => $m->project?->code,
                'name'       => $m->project?->name,
                'active'     => (bool) $m->is_active,
                'has_login'  => $m->user_id !== null,
                'contract'   => $this->contractFor($m, $showCosts),
                'tasks'      => $this->tasksFor($m),
            ])->values()->all(),

            'invoices' => $invoices->map(fn (ContractorInvoice $i) => $this->invoiceRow($i, $showCosts))->all(),

            'totals' => $showCosts ? [
                'approved' => Money::money(Money::sum(
                    $invoices->where('status', 'approved')->pluck('total')->map(fn ($t) => (string) $t)->all()
                )),
                'billable' => Money::money(Money::sum(
                    $invoices->where('status', 'approved')->pluck('billable_total')->map(fn ($t) => (string) $t)->all()
                )),
                'awaiting' => $invoices->where('status', 'submitted')->count(),
            ] : null,
        ];
    }

    /** The work assigned to this engagement — the "give them tasks" half of a contractor page. */
    private function tasksFor(ProjectMember $member): array
    {
        return Task::query()
            ->whereIn('id', TaskAssignment::where('member_id', $member->id)->select('task_id'))
            ->orderBy('planned_end')
            ->limit(50)
            ->get()
            ->map(fn (Task $t) => [
                'id'     => $t->uuid,
                'code'   => $t->code,
                'name'   => $t->name,
                'status' => $t->status,
                'due'    => optional($t->planned_end)->toDateString(),
            ])->all();
    }

    /**
     * The contract this member is working under, read from PM's own register.
     *
     * It used to read Contract Management's `contracts` table and expose its `contract_status` and
     * `signature_status`. Both were left behind when the register moved here, so this screen was
     * showing a contract that no longer existed — and reaching into a module a PM-only tenant does
     * not own, which is the whole thing the move was for.
     */
    private function contractFor(ProjectMember $member, bool $showCosts): ?array
    {
        $contract = ProjectContract::query()
            ->where('project_member_id', $member->id)
            ->latest('id')
            ->first();

        if (! $contract) {
            return null;
        }

        return [
            'id'      => $contract->uuid,
            'number'  => $contract->contract_number,
            'title'   => $contract->title,
            'status'  => $contract->status,
            'version' => $contract->current_version,
            'value'   => $showCosts ? Money::money($contract->contract_value) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function invoiceRow(ContractorInvoice $invoice, bool $showCosts): array
    {
        $row = [
            'id'           => $invoice->uuid,
            'reference'    => $invoice->reference,
            'project'      => $invoice->project?->code,
            'invoice_date' => optional($invoice->invoice_date)->toDateString(),
            'status'       => $invoice->status,
            'currency'     => $invoice->currency,
        ];

        // Money only for viewers who may see it — the same rule as everywhere else in the module.
        return $showCosts ? $row + [
            'total'          => Money::money($invoice->total),
            'billable_total' => Money::money($invoice->billable_total),
            'markup_rate'    => $invoice->markup_rate === null ? null : (string) $invoice->markup_rate,
        ] : $row;
    }
}
