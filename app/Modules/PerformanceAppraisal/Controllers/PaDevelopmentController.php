<?php

namespace App\Modules\PerformanceAppraisal\Controllers;

use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use App\Modules\PerformanceAppraisal\Models\PaCycle;
use App\Modules\PerformanceAppraisal\Models\PaDevelopmentActivity;
use App\Modules\PerformanceAppraisal\Models\PaDevelopmentPlan;
use App\Modules\PerformanceAppraisal\Models\PaEmployeeSkill;
use App\Modules\PerformanceAppraisal\Models\PaSkill;
use App\Modules\PerformanceAppraisal\Services\PaDevelopmentService;
use App\Modules\PerformanceAppraisal\Trait\ReportsHierarchy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PaDevelopmentController extends Controller
{
    use \App\Modules\HR\Support\PicksTenantPeople;

    use ReportsHierarchy;

    public function __construct(private PaDevelopmentService $devService) {}

    // ── IDP List ──────────────────────────────────────────────────────────────

    public function index(): Response
    {
        /** @var User $user */
        $user    = Auth::user();
        $allowed = $this->getAllowedEmployeeIds($user);

        // Regular employees (no admin/manager role) have no access to the full list
        if ($allowed !== null && !$user->hasRole(self::MANAGER_ROLES)) {
            abort(403, 'Access to development plans requires a manager or HR role.');
        }

        $skills = PaSkill::where('is_active', true)->orderBy('category')->orderBy('name')->get();

        return Inertia::render('Modules/PerformanceAppraisal/Development/Index', [
            'plans'     => PaDevelopmentPlan::with(['employee', 'manager', 'cycle'])
                ->withCount('activities')
                ->when($allowed !== null, fn($q) => $q->whereIn('employee_id', $allowed))
                ->orderByDesc('created_at')
                ->get()
                ->map(fn($p) => [
                    'id'               => $p->id,
                    'title'            => $p->title,
                    'employee_name'    => $this->personName($p->employee),
                    'manager_name'     => $this->personName($p->manager),
                    'cycle_name'       => $p->cycle?->name,
                    'status'           => $p->status,
                    'overall_progress' => $p->overall_progress,
                    'activities_count' => $p->activities_count,
                    'target_completion_date' => $p->target_completion_date?->format('Y-m-d'),
                    'career_goal'      => $p->career_goal,
                ]),
            'skills'    => $skills->map(fn($s) => ['id' => $s->id, 'name' => $s->name, 'category' => $s->category]),
            'cycles'    => PaCycle::orderByDesc('start_date')->get(['id', 'name']),
            'employees' => $this->personList($allowed),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title'                  => ['required', 'string', 'max:200'],
            'employee_id'            => ['required', 'exists:users,id'],
            'manager_id'             => ['required', 'exists:users,id'],
            'cycle_id'               => ['nullable', 'exists:pa_cycles,id'],
            'career_goal'            => ['nullable', 'string'],
            'strengths'              => ['nullable', 'string'],
            'development_areas'      => ['nullable', 'string'],
            'target_completion_date' => ['nullable', 'date'],
        ]);
        $data['created_by'] = $request->user()->id;

        PaDevelopmentPlan::create($data);

        return back()->with('success', 'Development plan created.');
    }

    public function destroy(PaDevelopmentPlan $plan): RedirectResponse
    {
        $plan->delete();

        return back()->with('success', 'Development plan deleted.');
    }

    // ── Single Plan ───────────────────────────────────────────────────────────

    public function show(PaDevelopmentPlan $plan): Response
    {
        $this->authorisePlanAccess($plan);
        $plan->load(['employee', 'manager', 'cycle', 'activities']);
        $skillGap = $this->devService->skillGapMatrix($plan->employee_id);

        $employeeSkills = PaEmployeeSkill::where('employee_id', $plan->employee_id)
            ->with('skill')
            ->get()
            ->map(fn($es) => [
                'id'            => $es->id,
                'skill_id'      => $es->skill_id,
                'skill_name'    => $es->skill->name,
                'category'      => $es->skill->category,
                'current_level' => $es->current_level,
                'target_level'  => $es->target_level,
                'gap'           => $es->gapLevel(),
                'self_rated'    => $es->self_rated,
                'verified'      => $es->verified_at !== null,
                'evidence'      => $es->evidence,
            ]);

        return Inertia::render('Modules/PerformanceAppraisal/Development/Plan', [
            'plan'       => [
                'id'                     => $plan->id,
                'title'                  => $plan->title,
                'employee_name'          => $this->personName($plan->employee),
                'manager_name'           => $this->personName($plan->manager),
                'cycle_name'             => $plan->cycle?->name,
                'career_goal'            => $plan->career_goal,
                'strengths'              => $plan->strengths,
                'development_areas'      => $plan->development_areas,
                'status'                 => $plan->status,
                'overall_progress'       => $plan->overall_progress,
                'ai_recommendations'     => $plan->ai_recommendations,
                'manager_notes'          => $plan->manager_notes,
                'target_completion_date' => $plan->target_completion_date?->format('Y-m-d'),
            ],
            'activities'     => $plan->activities->map(fn($a) => [
                'id'             => $a->id,
                'title'          => $a->title,
                'activity_type'  => $a->activity_type,
                'description'    => $a->description,
                'target_date'    => $a->target_date?->format('Y-m-d'),
                'completed_date' => $a->completed_date?->format('Y-m-d'),
                'status'         => $a->status,
                'provider'       => $a->provider,
                'cost'           => $a->cost,
                'evidence_notes' => $a->evidence_notes,
                'lms_course_id'  => $a->lms_course_id,
            ]),
            'employeeSkills' => $employeeSkills,
            'skillGapMatrix' => $skillGap,
            'skills'         => PaSkill::where('is_active', true)->orderBy('category')->orderBy('name')->get(['id', 'name', 'category']),
        ]);
    }

    public function update(Request $request, PaDevelopmentPlan $plan): RedirectResponse
    {
        $data = $request->validate([
            'title'                  => ['sometimes', 'string', 'max:200'],
            'career_goal'            => ['nullable', 'string'],
            'strengths'              => ['nullable', 'string'],
            'development_areas'      => ['nullable', 'string'],
            'status'                 => ['sometimes', 'in:draft,active,completed,paused'],
            'manager_notes'          => ['nullable', 'string'],
            'target_completion_date' => ['nullable', 'date'],
        ]);

        $plan->update($data);

        return back()->with('success', 'Plan updated.');
    }

    // ── Activities ────────────────────────────────────────────────────────────

    public function activityStore(Request $request, PaDevelopmentPlan $plan): RedirectResponse
    {
        $data = $request->validate([
            'title'         => ['required', 'string', 'max:300'],
            'activity_type' => ['required', 'in:training,mentoring,project,certification,reading,shadowing,coaching'],
            'description'   => ['nullable', 'string'],
            'target_date'   => ['nullable', 'date'],
            'provider'      => ['nullable', 'string', 'max:200'],
            'cost'          => ['nullable', 'numeric', 'min:0'],
        ]);

        $plan->activities()->create($data);
        $this->devService->syncProgress($plan);

        return back()->with('success', 'Activity added.');
    }

    public function activityUpdate(Request $request, PaDevelopmentPlan $plan, PaDevelopmentActivity $activity): RedirectResponse
    {
        $data = $request->validate([
            'title'          => ['sometimes', 'string', 'max:300'],
            'activity_type'  => ['sometimes', 'in:training,mentoring,project,certification,reading,shadowing,coaching'],
            'description'    => ['nullable', 'string'],
            'target_date'    => ['nullable', 'date'],
            'completed_date' => ['nullable', 'date'],
            'status'         => ['sometimes', 'in:pending,in_progress,completed,cancelled'],
            'provider'       => ['nullable', 'string', 'max:200'],
            'cost'           => ['nullable', 'numeric', 'min:0'],
            'evidence_notes' => ['nullable', 'string'],
        ]);

        $activity->update($data);
        $this->devService->syncProgress($plan);

        return back()->with('success', 'Activity updated.');
    }

    public function activityDestroy(PaDevelopmentPlan $plan, PaDevelopmentActivity $activity): RedirectResponse
    {
        $activity->delete();
        $this->devService->syncProgress($plan);

        return back()->with('success', 'Activity deleted.');
    }

    // ── Skills ────────────────────────────────────────────────────────────────

    public function skillStore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:200'],
            'category'    => ['required', 'in:technical,soft,leadership,domain,compliance'],
            'description' => ['nullable', 'string'],
            'is_active'   => ['boolean'],
            'sort_order'  => ['integer'],
        ]);

        PaSkill::create($data);

        return back()->with('success', 'Skill added to library.');
    }

    public function skillUpdate(Request $request, PaSkill $skill): RedirectResponse
    {
        $skill->update($request->validate([
            'name'        => ['sometimes', 'string', 'max:200'],
            'category'    => ['sometimes', 'in:technical,soft,leadership,domain,compliance'],
            'description' => ['nullable', 'string'],
            'is_active'   => ['boolean'],
        ]));

        return back()->with('success', 'Skill updated.');
    }

    public function skillDestroy(PaSkill $skill): RedirectResponse
    {
        $skill->delete();

        return back()->with('success', 'Skill deleted.');
    }

    public function employeeSkillStore(Request $request, PaDevelopmentPlan $plan): RedirectResponse
    {
        $data = $request->validate([
            'skill_id'      => ['required', 'exists:pa_skills,id'],
            'current_level' => ['required', 'integer', 'min:1', 'max:5'],
            'target_level'  => ['required', 'integer', 'min:1', 'max:5'],
            'evidence'      => ['nullable', 'string'],
        ]);

        PaEmployeeSkill::updateOrCreate(
            ['employee_id' => $plan->employee_id, 'skill_id' => $data['skill_id']],
            ['current_level' => $data['current_level'], 'target_level' => $data['target_level'], 'self_rated' => true, 'evidence' => $data['evidence'] ?? null]
        );

        return back()->with('success', 'Skill profile updated.');
    }

    public function employeeSkillDestroy(PaDevelopmentPlan $plan, PaEmployeeSkill $skill): RedirectResponse
    {
        $skill->delete();

        return back()->with('success', 'Skill removed.');
    }

    // ── AI ────────────────────────────────────────────────────────────────────

    public function generateAiRecommendations(PaDevelopmentPlan $plan): RedirectResponse
    {
        $this->devService->generateRecommendations($plan);

        return back()->with('success', 'AI recommendations generated.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function authorisePlanAccess(PaDevelopmentPlan $plan): void
    {
        /** @var User $user */
        $user    = Auth::user();
        $allowed = $this->getAllowedEmployeeIds($user);

        if ($allowed !== null && !in_array($plan->employee_id, $allowed)) {
            abort(403, 'You do not have permission to view this development plan.');
        }
    }

    /**
     * Everybody this tenant may choose between, optionally narrowed to a set of ids.
     */
    private function personList(?array $allowedIds = null): \Illuminate\Support\Collection
    {
        return collect($this->tenantPeopleOptions())
            ->when($allowedIds !== null, fn ($people) => $people->whereIn('id', array_map('intval', $allowedIds)))
            ->values();
    }

    private function personName(?User $user): string
    {
        if (!$user) return '-';
        $full = trim(($user->person?->first_name ?? '') . ' ' . ($user->person?->surname ?? ''));
        return $full ?: $user->email;
    }
}
