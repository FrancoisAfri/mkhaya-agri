<?php

namespace App\Modules\Policies\Controllers;

use App\Core\CompanyUnits\Models\CompanyUnit;
use App\Http\Controllers\Controller;
use App\Modules\Policies\Models\Policy;
use App\Modules\Policies\Models\PolicyAttestation;
use App\Modules\Policies\Requests\SubmitAttestationRequest;
use App\Modules\Policies\Services\AttestationService;
use App\Modules\Policies\Services\QuizService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PolicyAttestationController extends Controller
{
    public function __construct(
        protected AttestationService $service,
        protected QuizService $quizService,
    ) {}

    // ── Employee Views ─────────────────────────────────────────────────────────

    /**
     * Employee: list all attestations assigned to the authenticated user.
     */
    public function index(Request $request): Response
    {
        $userId = Auth::id();
        $status = $request->query('status');

        $query = PolicyAttestation::with(['policy.category', 'version'])
            ->where('user_id', $userId)
            ->orderBy('deadline');

        if ($status) {
            $query->where('status', $status);
        }

        $attestations = $query->get()->map(fn($a) => [
            'id'             => $a->id,
            'uuid'           => $a->uuid,
            'policy_id'      => $a->policy_id,
            'policy_uuid'    => $a->policy?->uuid,
            'policy_name'    => $a->policy?->name,
            'policy_category'=> $a->policy?->category?->name,
            'version'        => $a->version?->version_number,
            'deadline'       => $a->deadline?->toDateString(),
            'status'         => $a->status,
            'viewed_at'      => $a->viewed_at?->toDateTimeString(),
            'acknowledged_at'=> $a->acknowledged_at?->toDateTimeString(),
            'signed_at'      => $a->signed_at?->toDateTimeString(),
            'is_overdue'     => $a->isOverdue(),
        ]);

        $summary = [
            'total'     => $attestations->count(),
            'pending'   => $attestations->where('status', 'pending')->count(),
            'completed' => $attestations->where('status', 'completed')->count(),
            'overdue'   => $attestations->where('is_overdue', true)->count(),
        ];

        return Inertia::render('Policies/Attestations/Index', [
            'attestations' => $attestations,
            'summary'      => $summary,
            'filters'      => ['status' => $status],
        ]);
    }

    /**
     * Employee: view a specific policy for reading and attestation.
     */
    public function show(PolicyAttestation $attestation): Response
    {
        // Ensure the authenticated user owns this attestation
        abort_unless((int) $attestation->user_id === (int) Auth::id(), 403);

        $this->service->markViewed($attestation);

        $policy = $attestation->policy()->with(['category', 'documents', 'clauses'])->first();

        $alreadyCompleted = $attestation->status === 'completed';

        $deadlinePassed = $attestation->deadline && $attestation->deadline->isPast();

        // Gather extracted text from the first document that has it
        $extractedText = $policy->documents
            ->whereNotNull('extracted_text')
            ->first()
            ?->extracted_text;

        return Inertia::render('Policies/Attestations/Show', [
            'attestation'     => [
                'id'             => $attestation->id,
                'uuid'           => $attestation->uuid,
                'status'         => $attestation->status,
                'deadline'       => $attestation->deadline?->toDateString(),
                'viewed_at'      => $attestation->viewed_at?->toDateTimeString(),
                'acknowledged_at'=> $attestation->acknowledged_at?->toDateTimeString(),
                'signed_at'      => $attestation->signed_at?->toDateTimeString(),
                'quiz_score'     => $attestation->quiz_score,
                'is_overdue'     => $attestation->isOverdue(),
                'deadline_passed'=> $deadlinePassed,
            ],
            'policy' => [
                'id'             => $policy->id,
                'uuid'           => $policy->uuid,
                'name'           => $policy->name,
                'description'    => $policy->description,
                'category'       => $policy->category?->name,
                'effective_date' => $policy->effective_date?->toDateString(),
                'review_date'    => $policy->review_date?->toDateString(),
                'extracted_text' => $extractedText,
                'documents'      => $policy->documents->map(fn($d) => [
                    'id'       => $d->id,
                    'filename' => $d->filename ?? basename($d->path ?? ''),
                    // view URL only — users can read inline but we never expose the download route here
                    'view_url' => route('policies.documents.view', [$policy, $d]),
                ]),
                'clauses' => $policy->clauses->map(fn($c) => [
                    'id'         => $c->id,
                    'heading'    => $c->clause_reference ?? ('Clause ' . $c->sort_order),
                    'type'       => $c->type,
                    'body'       => $c->content,
                ]),
            ],
            'alreadyCompleted' => $alreadyCompleted,
            'quiz' => [
                'has_quiz' => $this->quizService->getQuestions($policy)->isNotEmpty(),
                'can_attempt' => $this->quizService->canAttempt($policy, Auth::user()),
            ],
        ]);
    }

    /**
     * Employee: submit acknowledgement + digital confirmation.
     */
    public function submit(SubmitAttestationRequest $request, PolicyAttestation $attestation): RedirectResponse
    {
        if ($attestation->status === 'completed') {
            return back()->with('warning', 'This attestation has already been completed.');
        }

        if ($attestation->deadline && $attestation->deadline->isPast()) {
            return back()->with('error', 'The deadline for this attestation has passed. You can no longer submit.');
        }

        $this->service->complete($attestation, $request->validated());

        return redirect()
            ->route('policies.attestations.index')
            ->with('success', 'Thank you! Your attestation has been recorded.');
    }

    // ── Admin / Manager Dashboard ──────────────────────────────────────────────

    /**
     * Admin/Manager: attestation compliance dashboard.
     */
    public function dashboard(Request $request): Response
    {
        $stats        = $this->service->dashboardStats();
        $byDepartment = $stats['byDepartment'];
        unset($stats['byDepartment']);

        // Unacknowledged employees across all policies
        $unacknowledged = PolicyAttestation::with(['user.person', 'policy'])
            ->whereIn('status', ['pending', 'overdue'])
            ->orderBy('deadline')
            ->limit(50)
            ->get()
            ->map(fn($a) => [
                'id'          => $a->id,
                'user_name'   => $a->user?->name,
                'email'       => $a->user?->email,
                'policy_name' => $a->policy?->name,
                'deadline'    => $a->deadline?->toDateString(),
                'status'      => $a->status,
                'viewed_at'   => $a->viewed_at?->toDateTimeString(),
            ]);

        // Recent completions
        $recentCompleted = PolicyAttestation::with(['user', 'policy'])
            ->where('status', 'completed')
            ->orderByDesc('signed_at')
            ->limit(20)
            ->get()
            ->map(fn($a) => [
                'user_name'   => $a->user?->name,
                'policy_name' => $a->policy?->name,
                'signed_at'   => $a->signed_at?->toDateTimeString(),
            ]);

        return Inertia::render('Policies/Attestations/Dashboard', [
            'stats'          => $stats,
            'byDepartment'   => $byDepartment,
            'unacknowledged' => $unacknowledged,
            'recentCompleted'=> $recentCompleted,
        ]);
    }

    // ── Admin: Assign Attestation ──────────────────────────────────────────────

    /**
     * Admin: manually assign a policy attestation to users.
     */
    public function assign(Request $request, Policy $policy): RedirectResponse
    {
        $validated = $request->validate([
            'user_ids'      => ['nullable', 'array'],
            'user_ids.*'    => ['integer', 'exists:users,id'],
            'division_ids'  => ['nullable', 'array'],
            'division_ids.*'=> ['integer', 'exists:company_units,id'],
            'department_ids'=> ['nullable', 'array'],
            'department_ids.*'=> ['integer', 'exists:company_units,id'],
            'section_ids'   => ['nullable', 'array'],
            'section_ids.*' => ['integer', 'exists:company_units,id'],
            'team_ids'      => ['nullable', 'array'],
            'team_ids.*'    => ['integer', 'exists:company_units,id'],
            'deadline'    => ['nullable', 'date', 'after:today'],
            'campaign_id' => ['nullable', 'integer', 'exists:attestation_campaigns,id'],
        ]);

        $baseUnitIds = collect([
            ...($validated['division_ids'] ?? []),
            ...($validated['department_ids'] ?? []),
            ...($validated['section_ids'] ?? []),
            ...($validated['team_ids'] ?? []),
        ])->map(fn($id) => (int) $id)->unique()->values();

        $expandedUnitIds = $this->collectDescendantUnitIds($baseUnitIds->all());

        $unitUserIds = collect();
        if ($expandedUnitIds) {
            $unitUserIds = DB::table('company_unit_user_pivots')
                ->whereIn('company_id', $expandedUnitIds)
                ->pluck('user_id')
                ->map(fn($id) => (int) $id);
        }

        $targetUserIds = collect($validated['user_ids'] ?? [])
            ->map(fn($id) => (int) $id)
            ->merge($unitUserIds)
            ->unique()
            ->values();

        if ($targetUserIds->isEmpty()) {
            throw ValidationException::withMessages([
                'user_ids' => 'Select at least one user or one organisation unit to assign.',
            ]);
        }

        $created = $this->service->assignToUsers(
            $policy,
            $targetUserIds->all(),
            isset($validated['deadline']) ? \Carbon\Carbon::parse($validated['deadline']) : null,
            $validated['campaign_id'] ?? null
        );

        return back()->with('success', "Attestation assigned to {$created->count()} employee(s).");
    }

    private function collectDescendantUnitIds(array $seedUnitIds): array
    {
        if (!$seedUnitIds) {
            return [];
        }

        $allUnits = CompanyUnit::query()->select('id', 'parent_id')->get();
        $childrenByParent = [];
        foreach ($allUnits as $unit) {
            $parentId = $unit->parent_id;
            if (!array_key_exists($parentId, $childrenByParent)) {
                $childrenByParent[$parentId] = [];
            }
            $childrenByParent[$parentId][] = (int) $unit->id;
        }

        $seen = [];
        $queue = array_values(array_unique(array_map('intval', $seedUnitIds)));
        while ($queue) {
            $current = array_shift($queue);
            if (isset($seen[$current])) {
                continue;
            }

            $seen[$current] = true;
            $children = $childrenByParent[$current] ?? [];
            foreach ($children as $childId) {
                if (!isset($seen[$childId])) {
                    $queue[] = $childId;
                }
            }
        }

        return array_map('intval', array_keys($seen));
    }
}
