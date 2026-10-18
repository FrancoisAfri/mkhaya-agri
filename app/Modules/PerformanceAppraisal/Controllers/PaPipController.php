<?php

namespace App\Modules\PerformanceAppraisal\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PerformanceAppraisal\Models\PaPipPlan;
use App\Modules\PerformanceAppraisal\Models\PaPipObjective;
use App\Modules\PerformanceAppraisal\Models\PaPipReview;
use App\Modules\PerformanceAppraisal\Models\PaCycle;
use App\Modules\PerformanceAppraisal\Services\PerformanceAppraisalPolicyGuard;
use App\Modules\PerformanceAppraisal\Services\PaPipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PaPipController extends Controller
{
    use \App\Modules\HR\Support\PicksTenantPeople;

    public function __construct(
        private PaPipService                    $service,
        private PerformanceAppraisalPolicyGuard $policyGuard,
    ) {}

    public function index(Request $request): Response
    {
        $plans = PaPipPlan::query()
            ->when($request->get('cycle_id'), fn($q, $c) => $q->where('cycle_id', $c))
            ->when($request->get('status'),   fn($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($p) {
                $p->load('objectives');
                $p->employee_name    = $this->resolveEmployeeName($p->employee_id);
                $p->manager_name     = $this->resolveEmployeeName($p->manager_id);
                $p->overall_progress = $p->overallProgress();
                $p->days_remaining   = $p->daysRemaining();
                $p->start_date       = $p->start_date?->format('d M Y');
                $p->target_end_date  = $p->target_end_date?->format('d M Y');
                return $p;
            });

        $stats = [
            'active'             => $plans->where('status', 'active')->count(),
            'draft'              => $plans->where('status', 'draft')->count(),
            'closed_improved'    => $plans->where('status', 'closed_improved')->count(),
            'escalated'          => $plans->where('status', 'escalated')->count(),
        ];

        return Inertia::render('Modules/PerformanceAppraisal/PIP/Index', [
            'plans'     => $plans->values(),
            'cycles'    => PaCycle::orderByDesc('start_date')->get(['id', 'name']),
            'employees' => $this->personList(),
            'stats'     => $stats,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_id'      => 'required|integer',
            'manager_id'       => 'required|integer',
            'cycle_id'         => 'nullable|integer',
            'title'            => 'required|string|max:255',
            'reason'           => 'required|string',
            'start_date'       => 'required|date',
            'target_end_date'  => 'required|date|after:start_date',
        ]);

        $plan = new PaPipPlan($data);
        $this->policyGuard->forPipCreated($plan, Auth::id());

        PaPipPlan::create($data);
        return back();
    }

    public function show(PaPipPlan $pip): Response
    {
        $pip->load(['objectives', 'progressLogs', 'reviews']);

        $pip->employee_name    = $this->resolveEmployeeName($pip->employee_id);
        $pip->manager_name     = $this->resolveEmployeeName($pip->manager_id);
        $pip->overall_progress = $pip->overallProgress();
        $pip->days_remaining   = $pip->daysRemaining();

        $pip->progressLogs->each(function ($l) {
            $l->logged_by_name = $this->resolveEmployeeName($l->logged_by);
        });

        $pip->reviews->each(function ($r) {
            $r->conducted_by_name = $this->resolveEmployeeName($r->conducted_by);
        });

        return Inertia::render('Modules/PerformanceAppraisal/PIP/Show', [
            'pip'       => $pip,
            'employees' => $this->personList(),
        ]);
    }

    public function update(Request $request, PaPipPlan $pip): RedirectResponse
    {
        $data = $request->validate([
            'title'            => 'sometimes|string|max:255',
            'reason'           => 'sometimes|string',
            'start_date'       => 'sometimes|date',
            'target_end_date'  => 'sometimes|date',
            'actual_end_date'  => 'nullable|date',
            'status'           => 'sometimes|in:draft,active,extended,closed_improved,closed_not_improved,escalated',
            'outcome_notes'    => 'nullable|string',
        ]);
        $pip->update($data);
        return back();
    }

    public function destroy(PaPipPlan $pip): RedirectResponse
    {
        $pip->delete();
        return back();
    }

    // ── Objectives ────────────────────────────────────────────────────────────

    public function objectiveStore(Request $request, PaPipPlan $pip): RedirectResponse
    {
        $data = $request->validate([
            'title'        => 'required|string|max:255',
            'description'  => 'nullable|string',
            'metric'       => 'nullable|string|max:255',
            'target_value' => 'nullable|string|max:100',
            'weight'       => 'integer|min:0|max:100',
            'due_date'     => 'nullable|date',
        ]);

        $used = $pip->objectives()->sum('weight');
        $adding = $data['weight'] ?? 0;
        if ($used + $adding > 100) {
            return back()->withErrors(['weight' => "Total weight cannot exceed 100%. You have " . (100 - $used) . "% remaining."]);
        }

        $pip->objectives()->create($data);
        return back();
    }

    public function objectiveUpdate(Request $request, PaPipObjective $objective): RedirectResponse
    {
        $data = $request->validate([
            'title'        => 'sometimes|string|max:255',
            'description'  => 'nullable|string',
            'metric'       => 'nullable|string',
            'target_value' => 'nullable|string',
            'weight'       => 'sometimes|integer|min:0|max:100',
            'status'       => 'sometimes|in:not_started,in_progress,met,not_met',
            'due_date'     => 'nullable|date',
        ]);

        if (isset($data['weight'])) {
            $used = PaPipObjective::where('pip_plan_id', $objective->pip_plan_id)->where('id', '!=', $objective->id)->sum('weight');
            if ($used + $data['weight'] > 100) {
                return back()->withErrors(['weight' => "Total weight cannot exceed 100%. You have " . (100 - $used) . "% remaining."]);
            }
        }

        $objective->update($data);
        return back();
    }

    public function objectiveDestroy(PaPipObjective $objective): RedirectResponse
    {
        $objective->delete();
        return back();
    }

    // ── Progress Logs ─────────────────────────────────────────────────────────

    public function logProgress(Request $request, PaPipPlan $pip): RedirectResponse
    {
        $data = $request->validate([
            'objective_id' => 'nullable|exists:pa_pip_objectives,id',
            'progress_pct' => 'required|integer|min:0|max:100',
            'notes'        => 'nullable|string',
            'logged_at'    => 'required|date',
        ]);

        $pip->progressLogs()->create(array_merge($data, ['logged_by' => Auth::id()]));
        return back();
    }

    // ── Reviews ───────────────────────────────────────────────────────────────

    public function reviewStore(Request $request, PaPipPlan $pip): RedirectResponse
    {
        $data = $request->validate([
            'conducted_by'   => 'required|integer',
            'scheduled_date' => 'required|date',
            'notes'          => 'nullable|string',
        ]);
        $pip->reviews()->create($data);
        return back();
    }

    public function reviewUpdate(Request $request, PaPipReview $review): RedirectResponse
    {
        $data = $request->validate([
            'scheduled_date' => 'sometimes|date',
            'status'         => 'sometimes|in:scheduled,completed,cancelled',
            'notes'          => 'nullable|string',
            'outcome'        => 'nullable|in:on_track,needs_attention,at_risk,not_improving',
        ]);
        $review->update($data);
        return back();
    }

    public function reviewDestroy(PaPipReview $review): RedirectResponse
    {
        $review->delete();
        return back();
    }

    // ── AI ────────────────────────────────────────────────────────────────────

    public function generateCoaching(PaPipPlan $pip): RedirectResponse
    {
        $coaching = $this->service->generateCoachingRecommendation($pip);
        $pip->update(['ai_coaching' => $coaching]);
        return back();
    }

    public function recommendOutcome(PaPipPlan $pip): RedirectResponse
    {
        $rec = $this->service->recommendPIPOutcome($pip);
        $pip->update(['ai_outcome_recommendation' => $rec]);
        return back();
    }

    public function closeOut(Request $request, PaPipPlan $pip): RedirectResponse
    {
        $data = $request->validate([
            'status'        => 'required|in:closed_improved,closed_not_improved,escalated,extended',
            'outcome_notes' => 'nullable|string',
            'actual_end_date' => 'nullable|date',
        ]);
        $this->policyGuard->forPipClosed($pip, $data['status'], Auth::id());

        $pip->update(array_merge($data, ['actual_end_date' => $data['actual_end_date'] ?? now()->toDateString()]));
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
