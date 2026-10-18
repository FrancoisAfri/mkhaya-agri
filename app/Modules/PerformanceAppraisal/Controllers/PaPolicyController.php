<?php

namespace App\Modules\PerformanceAppraisal\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PerformanceAppraisal\Models\PaPolicy;
use App\Modules\PerformanceAppraisal\Models\PaPolicyViolation;
use App\Modules\PerformanceAppraisal\Models\PaPolicyException;
use App\Modules\PerformanceAppraisal\Models\PaEscalation;
use App\Modules\PerformanceAppraisal\Models\PaComplianceScore;
use App\Modules\PerformanceAppraisal\Models\PaCycle;
use App\Modules\PerformanceAppraisal\Services\PaPolicyEngineService;
use App\Modules\Policies\Models\Policy as GlobalPolicy;
use App\Modules\Policies\Models\PolicyCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PaPolicyController extends Controller
{
    use \App\Modules\HR\Support\PicksTenantPeople;

    public function __construct(private PaPolicyEngineService $engine) {}

    public function index(Request $request): Response
    {
        $cycleId = $request->integer('cycle_id') ?: null;

        $policies = PaPolicy::withCount([
            'violations as open_violations_count' => fn($q) => $q->whereNotIn('status', ['resolved', 'exempted']),
        ])->get();

        $violations = PaPolicyViolation::with('policy')
            ->orderByRaw("CASE status WHEN 'detected' THEN 1 WHEN 'escalated' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($v) => array_merge($v->toArray(), [
                'employee_name' => $this->resolveEmployeeName($v->employee_id),
                'policy_name'   => $v->policy?->name ?? '—',
                'policy_level'  => $v->policy?->enforcement_level ?? 'warning',
            ]));

        $exceptions = PaPolicyException::with('policy')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($e) => array_merge($e->toArray(), [
                'employee_name'    => $this->resolveEmployeeName($e->employee_id),
                'policy_name'      => $e->policy?->name ?? '—',
                'approved_by_name' => $e->approved_by ? $this->resolveEmployeeName($e->approved_by) : null,
            ]));

        $escalations = PaEscalation::with('violation.policy')
            ->orderByRaw("CASE status WHEN 'pending' THEN 1 WHEN 'acknowledged' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($e) => array_merge($e->toArray(), [
                'escalated_to_name' => $this->resolveEmployeeName($e->escalated_to),
                'policy_name'       => $e->violation?->policy?->name ?? '—',
                'employee_name'     => $e->violation ? $this->resolveEmployeeName($e->violation->employee_id) : '—',
                'action_type'       => $e->violation?->action_type ?? '—',
            ]));

        $complianceScores = PaComplianceScore::query()
            ->when($cycleId, fn($q) => $q->where('cycle_id', $cycleId))
            ->orderByDesc('compliance_score')
            ->get()
            ->map(fn($cs) => array_merge($cs->toArray(), [
                'employee_name' => $this->resolveEmployeeName($cs->employee_id),
                'grade_label'   => $cs->gradeLabel(),
                'grade_color'   => $cs->gradeColor(),
            ]));

        $stats = [
            'active_policies'   => $policies->where('is_active', true)->count(),
            'open_violations'   => $violations->whereIn('status', ['detected', 'escalated'])->count(),
            'avg_compliance'    => $complianceScores->isEmpty()
                ? 0
                : round($complianceScores->avg('compliance_score'), 1),
            'active_exceptions' => $exceptions->count(),
        ];

        // Global policy documents from the Policy module (Performance Management category + HR)
        $pmCategoryIds = PolicyCategory::whereIn('name', ['Performance Management', 'HR'])->pluck('id');
        $globalPolicies = GlobalPolicy::with(['category'])
            ->whereIn('category_id', $pmCategoryIds)
            ->whereIn('status', ['published', 'active'])
            ->orderByDesc('effective_date')
            ->get()
            ->map(fn($p) => [
                'id'             => $p->id,
                'uuid'           => $p->uuid,
                'name'           => $p->name,
                'description'    => $p->description,
                'status'         => $p->status,
                'category'       => $p->category?->name,
                'effective_date' => $p->effective_date?->format('d M Y'),
                'review_date'    => $p->review_date?->format('d M Y'),
                'tags'           => $p->tags ?? [],
            ]);

        return Inertia::render('Modules/PerformanceAppraisal/PolicyEnforcement/Index', [
            'policies'         => $policies->values(),
            'violations'       => $violations->values(),
            'exceptions'       => $exceptions->values(),
            'escalations'      => $escalations->values(),
            'complianceScores' => $complianceScores->values(),
            'cycles'           => PaCycle::orderByDesc('start_date')->get(['id', 'name']),
            'employees'        => $this->personList(),
            'selectedCycle'    => $cycleId,
            'stats'            => $stats,
            'globalPolicies'   => $globalPolicies->values(),
        ]);
    }

    // ── Policy CRUD ───────────────────────────────────────────────────────────

    public function policyStore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'              => 'required|string|max:255',
            'code'              => 'required|string|max:100|unique:pa_policies,code',
            'description'       => 'nullable|string',
            'policy_type'       => 'required|in:appraisal,probation,bonus,kpi,feedback,general',
            'condition_type'    => 'required|string|max:100',
            'condition_config'  => 'nullable|array',
            'enforcement_level' => 'required|in:warning,soft_block,hard_block',
            'is_active'         => 'boolean',
        ]);

        PaPolicy::create($data);
        return back();
    }

    public function policyUpdate(Request $request, PaPolicy $policy): RedirectResponse
    {
        $data = $request->validate([
            'name'              => 'sometimes|string|max:255',
            'description'       => 'nullable|string',
            'policy_type'       => 'sometimes|in:appraisal,probation,bonus,kpi,feedback,general',
            'condition_type'    => 'sometimes|string|max:100',
            'condition_config'  => 'nullable|array',
            'enforcement_level' => 'sometimes|in:warning,soft_block,hard_block',
            'is_active'         => 'sometimes|boolean',
        ]);

        $policy->update($data);
        return back();
    }

    public function policyDestroy(PaPolicy $policy): RedirectResponse
    {
        $policy->delete();
        return back();
    }

    // ── Violation management ──────────────────────────────────────────────────

    public function escalateViolation(Request $request, PaPolicyViolation $violation): RedirectResponse
    {
        $data = $request->validate([
            'escalated_to'     => 'required|integer',
            'escalation_level' => 'required|integer|min:1|max:3',
            'message'          => 'nullable|string',
        ]);

        PaEscalation::create([
            'violation_id'     => $violation->id,
            'escalated_to'     => $data['escalated_to'],
            'escalation_level' => $data['escalation_level'],
            'message'          => $data['message'] ?? null,
            'status'           => 'pending',
        ]);

        $violation->update(['status' => 'escalated']);
        return back();
    }

    public function exemptViolation(Request $request, PaPolicyViolation $violation): RedirectResponse
    {
        $data = $request->validate([
            'reason'      => 'required|string',
            'valid_until' => 'nullable|date',
        ]);

        PaPolicyException::create([
            'policy_id'   => $violation->policy_id,
            'employee_id' => $violation->employee_id,
            'approved_by' => auth()->id(),
            'reason'      => $data['reason'],
            'valid_until' => $data['valid_until'] ?? null,
        ]);

        $violation->update(['status' => 'exempted', 'resolved_at' => now()]);
        return back();
    }

    public function resolveViolation(PaPolicyViolation $violation): RedirectResponse
    {
        $violation->update(['status' => 'resolved', 'resolved_at' => now()]);
        return back();
    }

    // ── Exceptions ────────────────────────────────────────────────────────────

    public function exceptionStore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'policy_id'   => 'required|exists:pa_policies,id',
            'employee_id' => 'required|integer',
            'reason'      => 'required|string',
            'valid_until' => 'nullable|date',
        ]);

        PaPolicyException::create(array_merge($data, ['approved_by' => auth()->id()]));
        return back();
    }

    public function exceptionDestroy(PaPolicyException $exception): RedirectResponse
    {
        $exception->delete();
        return back();
    }

    // ── Escalation management ─────────────────────────────────────────────────

    public function acknowledgeEscalation(PaEscalation $escalation): RedirectResponse
    {
        $escalation->update(['status' => 'acknowledged']);
        return back();
    }

    public function resolveEscalation(PaEscalation $escalation): RedirectResponse
    {
        $escalation->update(['status' => 'resolved']);
        return back();
    }

    // ── Compliance scoring ────────────────────────────────────────────────────

    public function computeAllCompliance(Request $request): RedirectResponse
    {
        $cycleId = $request->integer('cycle_id') ?: null;

        foreach ($this->tenantPeopleIds($request) as $userId) {
            try {
                $this->engine->computeComplianceScore($userId, $cycleId);
            } catch (\Throwable) {
                continue;
            }
        }

        return back();
    }

    // ── Violation detection ───────────────────────────────────────────────────

    public function detectViolations(Request $request): RedirectResponse
    {
        $data = $request->validate(['cycle_id' => 'required|integer']);
        $this->engine->detectViolationsForCycle((int) $data['cycle_id']);
        return back();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function resolveEmployeeName(int $id): string
    {
        return $this->tenantPersonName($id);
    }

    /**

     * Everybody this tenant may choose between.

     *

     * Was built here, in two different shapes across these controllers — some through

     * `User`, which leans on a global scope that steps aside for a platform administrator,

     * and some as a raw `DB::table('users')` where no scope applied at all and `users`

     * carries no row-level security to catch it. One of them offered every tenant's people

     * to every other tenant; the ones reading `hr_people` offered almost nobody.

     */

    private function personList(): array

    {

        return $this->tenantPeopleOptions();

    }
}
