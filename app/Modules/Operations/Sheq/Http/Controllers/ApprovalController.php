<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Approvals\ApprovalService;
use App\Modules\Operations\Sheq\Services\VisitorService;
use App\Modules\Operations\Sheq\Approvals\ApproverResolver;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The approvals queue and the workflow designer.
 *
 * Two audiences with almost nothing in common: somebody who has four things to approve before
 * lunch, and an administrator designing the chain of authority for the whole company. They get
 * separate screens because a queue that also has to explain approver types is a queue nobody uses.
 */
class ApprovalController extends Controller
{
    use ResolvesCompany;

    /** The subjects a company can build a workflow for. */
    private const SUBJECT_TYPES = [
        'PERMIT', 'VISITOR', 'AUDIT_FINDING', 'SUPPLIER_ONBOARDING', 'RISK_ASSESSMENT',
        'INCIDENT_CLOSURE', 'DOCUMENT', 'PPE_ISSUE', 'MEDICAL_CLEARANCE', 'CONTRACTOR',
    ];

    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly ApproverResolver $resolver,
    ) {}

    /** My queue. */
    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);
        $userId = (int) $request->user()?->id;

        return Inertia::render('Modules/Sheq/Approvals/Index', [
            'noCompany' => $companyId === null,
            'awaiting' => $companyId === null ? []
                : $this->approvals->awaiting($companyId, $userId),
            'raised' => $companyId === null ? [] : $this->raisedByMe($companyId, $userId),
        ]);
    }

    public function decide(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        $validated = $request->validate([
            'decision' => ['required', Rule::in(['APPROVED', 'REJECTED'])],
            'note' => ['nullable', 'string', 'max:2000'],
            'signature' => ['nullable', 'string', 'max:500000'],
        ]);

        $result = $this->approvals->decide($companyId, $uuid, (int) $request->user()->id, $validated);

        $this->applyToSubject($companyId, $uuid, $result);

        return back()->with('success', match ($result['status']) {
            'APPROVED' => 'Approved. Your name is on it.',
            'REJECTED' => 'Rejected.',
            default => 'Recorded. This now waits on the next step.',
        });
    }

    /**
     * Carry a completed decision back to the thing it was about.
     *
     * Most SHEQ subjects are *gated*: the approval is consulted at the moment somebody tries to
     * act — publish the document, approve the assessment, let the contractor on site — so nothing
     * needs pushing anywhere when it is signed.
     *
     * A visit is not like that. It carries its own status, and until now deciding its approval
     * recorded the decision and left the visit sitting at REQUESTED: the approver saw "Approved.
     * Your name is on it", the gatehouse saw a visitor who had not been approved, and nothing in
     * either screen explained the disagreement.
     */
    private function applyToSubject(int $companyId, string $uuid, array $result): void
    {
        // Only a completed decision applies. A chain with steps still to run has not decided
        // anything yet, whatever this one approver did.
        if (! in_array($result['status'] ?? null, [ApprovalService::APPROVED, ApprovalService::REJECTED], true)) {
            return;
        }

        $request = DB::table('sheq_approval_requests')
            ->where('company_id', $companyId)->where('uuid', $uuid)
            ->first(['subject_type', 'subject_id']);

        if ($request === null) {
            return;
        }

        $approved = $result['status'] === ApprovalService::APPROVED;

        match ($request->subject_type) {
            'VISITOR' => app(VisitorService::class)
                ->applyApproval($companyId, (int) $request->subject_id, $approved),
            default => null,
        };
    }

    /** The designer. */
    public function workflows(Request $request): Response
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Modules/Sheq/Approvals/Workflows', [
            'noCompany' => $companyId === null,
            'canManage' => (bool) $request->user()?->can('sheq.configure'),
            'subjectTypes' => self::SUBJECT_TYPES,
            'approverTypes' => [
                ['value' => 'ROLE', 'label' => 'Anyone with a role'],
                ['value' => 'USER', 'label' => 'A named person'],
                ['value' => 'LINE_MANAGER', 'label' => 'The requester’s line manager'],
                ['value' => 'SITE_ROLE', 'label' => 'A role, at the same site'],
                ['value' => 'COMPETENCY_HOLDER', 'label' => 'A holder of a competency'],
                ['value' => 'ANY_APPROVER', 'label' => 'Any authorised user'],
            ],
            'roles' => $this->availableRoles(),
            // Named approvers are picked from a list, never typed. A user id typed by hand is a
            // step that silently routes to the wrong person — or to nobody, which deadlocks every
            // request that reaches it.
            'users' => $companyId === null ? [] : $this->selectableUsers($companyId),
            'competencies' => $companyId === null ? [] : DB::table('sheq_competencies')
                ->where('company_id', $companyId)->where('is_active', true)
                ->whereNull('deleted_at')->orderBy('title')
                ->get(['code', 'title'])->map(fn ($c) => (array) $c)->all(),
            'sites' => $companyId === null ? [] : DB::table('sheq_sites')
                ->where('company_id', $companyId)->where('is_active', true)
                ->orderBy('name')->get(['id', 'name'])->map(fn ($s) => (array) $s)->all(),
            'workflows' => $companyId === null ? [] : $this->workflowList($companyId),
        ]);
    }

    /**
     * Retire a workflow, rather than delete it.
     *
     * Requests already in flight point at the version they opened under, and an approval whose
     * workflow vanished is a request nobody can finish and nobody can explain. Retiring stops it
     * being used for anything new and leaves every existing request intact — which is the same
     * promise the version bump on save makes.
     */
    public function retireWorkflow(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        abort_unless($request->user()?->can('sheq.configure'), 403,
            'ENTITLEMENT_REQUIRED: sheq.configure');

        $workflow = DB::table('sheq_approval_workflows')
            ->where('company_id', $companyId)->where('uuid', $uuid)
            ->whereNull('deleted_at')->first(['id', 'name']);

        abort_if($workflow === null, 404);

        DB::table('sheq_approval_workflows')->where('id', $workflow->id)
            ->update(['is_active' => false, 'updated_at' => now()]);

        return back()->with('success', __('sheq.approvals_setup.flash.retired',
            ['name' => $workflow->name]));
    }

    public function storeWorkflow(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        abort_unless($request->user()?->can('sheq.configure'), 403,
            'ENTITLEMENT_REQUIRED: sheq.configure');

        $validated = $request->validate([
            'subject_type' => ['required', Rule::in(self::SUBJECT_TYPES)],
            'subject_subtype' => ['nullable', 'string', 'max:60'],
            'site_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sla_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'steps' => ['required', 'array', 'min:1', 'max:10'],
            'steps.*.name' => ['required', 'string', 'max:160'],
            'steps.*.approver_type' => ['required', Rule::in(['ROLE', 'USER', 'LINE_MANAGER',
                'SITE_ROLE', 'COMPETENCY_HOLDER', 'ANY_APPROVER'])],
            'steps.*.approver_ref' => ['nullable', 'string', 'max:160'],
            'steps.*.quorum' => ['nullable', 'integer', 'min:1', 'max:20'],
            'steps.*.requires_signature' => ['nullable', 'boolean'],
        ]);

        DB::transaction(function () use ($companyId, $validated, $request) {
            // A new version rather than an edit in place. Requests already in flight keep the
            // steps they opened under, so nobody's half-finished approval changes shape.
            $existing = DB::table('sheq_approval_workflows')
                ->where('company_id', $companyId)
                ->where('subject_type', $validated['subject_type'])
                ->where('subject_subtype', $validated['subject_subtype'] ?? null)
                ->where('site_id', $validated['site_id'] ?? null)
                ->whereNull('deleted_at')->first(['id', 'version']);

            if ($existing !== null) {
                DB::table('sheq_approval_workflows')->where('id', $existing->id)
                    ->update(['is_active' => false, 'updated_at' => now()]);
            }

            $workflowId = DB::table('sheq_approval_workflows')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'company_id' => $companyId,
                'subject_type' => $validated['subject_type'],
                'subject_subtype' => $validated['subject_subtype'] ?? null,
                'site_id' => $validated['site_id'] ?? null,
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'sla_hours' => $validated['sla_hours'] ?? null,
                'version' => ($existing->version ?? 0) + 1,
                'is_active' => true,
                'created_by' => $request->user()?->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            foreach (array_values($validated['steps']) as $index => $step) {
                DB::table('sheq_approval_steps')->insert([
                    'uuid' => (string) Str::uuid(),
                    'company_id' => $companyId,
                    'workflow_id' => $workflowId,
                    'position' => $index + 1,
                    'name' => $step['name'],
                    'approver_type' => $step['approver_type'],
                    'approver_ref' => $step['approver_ref'] ?? null,
                    'quorum' => $step['quorum'] ?? 1,
                    'requires_signature' => (bool) ($step['requires_signature'] ?? false),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        return redirect('/sheq/approvals/workflows')->with('success',
            'Workflow saved. Requests already in progress keep the steps they started under.');
    }

    /**
     * People who can be named as an approver.
     *
     * Read from hr_people so the list shows names rather than email addresses — there is no
     * `users.name` column on this platform, and an administrator choosing between eleven
     *
     * @company.co.za addresses is choosing badly.
     *
     * @return list<array{id:int, name:string}>
     */
    private function selectableUsers(int $companyId): array
    {
        /*
         * The viewer is always selectable, even when they belong to no company.
         *
         * A platform administrator has `users.company_id = null` — that is what makes them
         * platform-level — so a list filtered on company alone leaves them out of every picker in
         * the module. The person setting a customer up then cannot name themselves as an approver,
         * and nothing on screen explains why: their own name is simply absent from a list they are
         * looking straight at.
         *
         * Only the *current* user is added, never every platform admin. Doing the latter would put
         * a directory of the vendor's staff into each customer's dropdowns to solve a problem
         * nobody has.
         */
        $viewerId = auth()->id();

        /*
         * HR is an optional neighbour — this module's own ModuleBridge lists it as such — so the
         * join only happens when its table is there. Without the guard a SHEQ-only install cannot
         * open this screen at all: the query throws on a missing relation, which is a fatal, not a
         * degraded list.
         */
        $hasHr = Schema::hasTable('hr_people');

        return DB::table('users as u')
            ->when($hasHr, fn ($q) => $q->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id'))
            ->where(function ($q) use ($companyId, $viewerId) {
                $q->where('u.company_id', $companyId);

                if ($viewerId !== null) {
                    $q->orWhere('u.id', $viewerId);
                }
            })
            ->whereNull('u.deleted_at')
            ->where('u.status', 1)
            ->orderByRaw($hasHr ? 'coalesce(p.first_name, u.email)' : 'u.email')
            ->limit(2000)
            ->get($hasHr ? ['u.id', 'u.email', 'p.first_name', 'p.surname'] : ['u.id', 'u.email'])
            ->map(fn ($u) => [
                'id' => (int) $u->id,
                // Without HR there are no names to show, so the address is the best label there is.
                'name' => trim(($u->first_name ?? '').' '.($u->surname ?? '')) ?: $u->email,
            ])->values()->all();
    }

    /** @return list<array<string,mixed>> */
    private function workflowList(int $companyId): array
    {
        $workflows = DB::table('sheq_approval_workflows as w')
            ->leftJoin('sheq_sites as s', 's.id', '=', 'w.site_id')
            ->where('w.company_id', $companyId)->where('w.is_active', true)
            ->whereNull('w.deleted_at')
            ->orderBy('w.subject_type')
            ->get(['w.id', 'w.uuid', 'w.subject_type', 'w.subject_subtype', 'w.name',
                'w.description', 'w.version', 'w.sla_hours', 's.name as site_name']);

        return $workflows->map(function ($workflow) use ($companyId) {
            $steps = $this->approvals->stepsFor($workflow->id);

            return [
                'uuid' => $workflow->uuid,
                'subject_type' => $workflow->subject_type,
                'subject_subtype' => $workflow->subject_subtype,
                'name' => $workflow->name,
                'description' => $workflow->description,
                'version' => (int) $workflow->version,
                'sla_hours' => $workflow->sla_hours,
                'site_name' => $workflow->site_name,
                'steps' => array_map(fn ($step) => $step + [
                    'describes' => $this->resolver->describe($step),
                    // Surfaced in the designer so a step with nobody able to fill it is caught
                    // before it deadlocks a real request.
                    'candidate_count' => count($this->resolver->candidates($companyId, $step)),
                ], $steps),
            ];
        })->values()->all();
    }

    /** @return list<array<string,mixed>> */
    private function raisedByMe(int $companyId, int $userId): array
    {
        return DB::table('sheq_approval_requests')
            ->where('company_id', $companyId)->where('requested_by', $userId)
            ->whereNull('deleted_at')->orderByDesc('created_at')->limit(25)
            ->get(['uuid', 'subject_type', 'subject_label', 'status', 'current_position',
                'created_at', 'completed_at'])
            ->map(fn ($r) => (array) $r)->values()->all();
    }

    /** @return list<string> */
    private function availableRoles(): array
    {
        return Schema::hasTable('roles')
            ? DB::table('roles')->orderBy('name')->pluck('name')->all()
            : [];
    }
}
