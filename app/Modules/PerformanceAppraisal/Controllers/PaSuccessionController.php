<?php

namespace App\Modules\PerformanceAppraisal\Controllers;

use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use App\Modules\PerformanceAppraisal\Models\PaCycle;
use App\Modules\PerformanceAppraisal\Models\PaHighPotential;
use App\Modules\PerformanceAppraisal\Models\PaSuccessionCandidate;
use App\Modules\PerformanceAppraisal\Models\PaSuccessionPlan;
use App\Modules\PerformanceAppraisal\Services\PaSuccessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PaSuccessionController extends Controller
{
    use \App\Modules\HR\Support\PicksTenantPeople;

    public function __construct(private PaSuccessionService $succession) {}

    public function index(): Response
    {
        $plans = PaSuccessionPlan::with(['incumbent', 'cycle', 'candidates.employee.person'])
            ->withCount('candidates')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($p) => [
                'id'              => $p->id,
                'position_title'  => $p->position_title,
                'department'      => $p->department,
                'incumbent_name'  => $this->personName($p->incumbent),
                'cycle_name'      => $p->cycle?->name,
                'is_critical_role'=> $p->is_critical_role,
                'status'          => $p->status,
                'bench_strength'  => $p->bench_strength,
                'candidates_count'=> $p->candidates_count,
                'ready_now_count' => $p->readyNowCount(),
                'ai_analysis'     => $p->ai_analysis,
                'notes'           => $p->notes,
                'candidates'      => $p->candidates->map(fn($c) => [
                    'id'                => $c->id,
                    'employee_id'       => $c->employee_id,
                    'employee_name'     => $this->personName($c->employee),
                    'readiness_level'   => $c->readiness_level,
                    'potential_rating'  => $c->potential_rating,
                    'risk_of_loss'      => $c->risk_of_loss,
                    'retention_priority'=> $c->retention_priority,
                    'ai_readiness_score'=> $c->ai_readiness_score,
                    'ai_recommendation' => $c->ai_recommendation,
                    'notes'             => $c->notes,
                ])->values(),
            ]);

        $highPotentials = PaHighPotential::with(['employee', 'cycle'])
            ->orderByDesc('composite_score')
            ->limit(30)
            ->get()
            ->map(fn($hp) => [
                'id'              => $hp->id,
                'employee_name'   => $this->personName($hp->employee),
                'cycle_name'      => $hp->cycle?->name,
                'composite_score' => $hp->composite_score,
                'potential_band'  => $hp->potential_band,
                'factors'         => $hp->factors,
                'is_ai_generated' => $hp->is_ai_generated,
            ]);

        return Inertia::render('Modules/PerformanceAppraisal/Succession/Index', [
            'plans'          => $plans,
            'highPotentials' => $highPotentials,
            'cycles'         => PaCycle::orderByDesc('start_date')->get(['id', 'name']),
            'employees'      => $this->personList(),
        ]);
    }

    // ── Plans ─────────────────────────────────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'position_title'  => ['required', 'string', 'max:200'],
            'department'      => ['nullable', 'string', 'max:200'],
            'incumbent_id'    => ['nullable', 'exists:users,id'],
            'cycle_id'        => ['nullable', 'exists:pa_cycles,id'],
            'is_critical_role'=> ['boolean'],
            'notes'           => ['nullable', 'string'],
        ]);
        $data['created_by'] = $request->user()->id;

        PaSuccessionPlan::create($data);

        return back()->with('success', 'Succession plan created.');
    }

    public function update(Request $request, PaSuccessionPlan $plan): RedirectResponse
    {
        $plan->update($request->validate([
            'position_title'  => ['sometimes', 'string', 'max:200'],
            'department'      => ['nullable', 'string'],
            'incumbent_id'    => ['nullable', 'exists:users,id'],
            'is_critical_role'=> ['boolean'],
            'status'          => ['sometimes', 'in:active,review,closed'],
            'notes'           => ['nullable', 'string'],
        ]));

        return back()->with('success', 'Plan updated.');
    }

    public function destroy(PaSuccessionPlan $plan): RedirectResponse
    {
        $plan->delete();

        return back()->with('success', 'Succession plan deleted.');
    }

    // ── Candidates ────────────────────────────────────────────────────────────

    public function candidateStore(Request $request, PaSuccessionPlan $plan): RedirectResponse
    {
        $data = $request->validate([
            'employee_id'       => ['required', 'exists:users,id'],
            'readiness_level'   => ['required', 'in:ready_now,1_year,2_years,not_ready'],
            'potential_rating'  => ['nullable', 'integer', 'min:1', 'max:5'],
            'risk_of_loss'      => ['sometimes', 'in:low,medium,high'],
            'retention_priority'=> ['sometimes', 'in:normal,high,critical'],
            'notes'             => ['nullable', 'string'],
        ]);
        $data['risk_of_loss']       ??= 'medium';
        $data['retention_priority'] ??= 'normal';
        $data['potential_rating']   ??= 3;

        if ($plan->candidates()->where('employee_id', $data['employee_id'])->exists()) {
            return back()->withErrors(['employee_id' => 'This employee is already a candidate.']);
        }

        $plan->candidates()->create($data);
        $this->succession->computeBenchStrength($plan);

        return back()->with('success', 'Candidate added.');
    }

    public function candidateUpdate(Request $request, PaSuccessionPlan $plan, PaSuccessionCandidate $candidate): RedirectResponse
    {
        $candidate->update($request->validate([
            'readiness_level'   => ['sometimes', 'in:ready_now,1_year,2_years,not_ready'],
            'potential_rating'  => ['integer', 'min:1', 'max:5'],
            'risk_of_loss'      => ['sometimes', 'in:low,medium,high'],
            'retention_priority'=> ['sometimes', 'in:normal,high,critical'],
            'notes'             => ['nullable', 'string'],
        ]));

        $this->succession->computeBenchStrength($plan);

        return back()->with('success', 'Candidate updated.');
    }

    public function candidateDestroy(PaSuccessionPlan $plan, PaSuccessionCandidate $candidate): RedirectResponse
    {
        $candidate->delete();
        $this->succession->computeBenchStrength($plan);

        return back()->with('success', 'Candidate removed.');
    }

    public function scoreCandidate(PaSuccessionPlan $plan, PaSuccessionCandidate $candidate, Request $request): RedirectResponse
    {
        $this->succession->scoreCandidate($candidate, $request->user()->id);

        return back()->with('success', 'Candidate scored.');
    }

    // ── AI ────────────────────────────────────────────────────────────────────

    public function generatePlanAnalysis(PaSuccessionPlan $plan): RedirectResponse
    {
        $this->succession->generatePlanAnalysis($plan);

        return back()->with('success', 'AI succession analysis generated.');
    }

    public function identifyHighPotentials(Request $request): RedirectResponse
    {
        $data  = $request->validate(['cycle_id' => ['required', 'exists:pa_cycles,id']]);
        $cycle = PaCycle::findOrFail($data['cycle_id']);

        $this->succession->identifyHighPotentials($cycle, $request->user()->id);

        return back()->with('success', 'High-potential identification complete.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**

     * Everybody this tenant may choose between.

     *

     * Was built here, in two different shapes across these controllers — some through

     * `User`, which leans on a global scope that steps aside for a platform administrator,

     * and some as a raw `DB::table('users')` where no scope applied at all and `users`

     * carries no row-level security to catch it. One of them offered every tenant's people

     * to every other tenant; the ones reading `hr_people` offered almost nobody.

     */

    private function personList(): \Illuminate\Support\Collection

    {

        return collect($this->tenantPeopleOptions());

    }

    private function personName(?User $user): string
    {
        if (!$user) return '-';
        $full = trim(($user->person?->first_name ?? '') . ' ' . ($user->person?->surname ?? ''));
        return $full ?: $user->email;
    }
}
