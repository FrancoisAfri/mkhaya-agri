<?php

namespace App\Modules\HR\LMS\Http\Controllers;

use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use App\Modules\HR\LMS\Models\LmsOnboardingTrainingAssignment;
use App\Modules\HR\LMS\Models\LmsOnboardingTrainingRule;
use App\Modules\HR\LMS\Services\LmsOnboardingTrainingService;
use App\Modules\HR\Onboarding\Models\OnboardingPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class LmsOnboardingTrainingController extends Controller
{
    public function __construct(private readonly LmsOnboardingTrainingService $service) {}

    // ── Admin dashboard ───────────────────────────────────────────────────────

    public function dashboard(Request $request): Response
    {
        // Active onboarding plans with training status
        $plans = OnboardingPlan::with(['hrPerson.candidate'])
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        $planData = $plans->getCollection()->map(function (OnboardingPlan $plan) {
            $userId = $this->resolveUserId($plan);
            $user   = $userId ? User::with('person')->find($userId) : null;
            $status = $userId ? $this->service->getOnboardingTrainingStatus($plan) : null;

            return [
                'plan_id'         => $plan->id,
                'employee_name'   => $user?->person
                    ? trim("{$user->person->first_name} {$user->person->surname}")
                    : ($user?->email ?? '—'),
                'user_id'         => $userId,
                'start_date'      => $plan->start_date?->toDateString(),
                'plan_status'     => $plan->status?->value ?? $plan->status,
                'total'           => $status['total']          ?? 0,
                'completed'       => $status['completed']      ?? 0,
                'failed'          => $status['failed']         ?? 0,
                'pending'         => $status['pending']        ?? 0,
                'completion_pct'  => $status['completion_pct'] ?? 0,
                'is_blocked'      => $status['is_blocked']     ?? false,
                'blocking_count'  => $status['blocking_count'] ?? 0,
            ];
        })->all();

        $plans->setCollection(collect($planData));

        // Unresolved blocking assignments
        $blockingCount = LmsOnboardingTrainingAssignment::where('blocks_completion', true)
            ->whereNotIn('status', ['completed'])
            ->count();

        return Inertia::render('Modules/LMS/Admin/OnboardingTraining', [
            'plans'         => $plans,
            'blockingCount' => $blockingCount,
            'filters'       => $request->only(['search']),
        ]);
    }

    // ── Per-plan detail ───────────────────────────────────────────────────────

    public function planDetail(int $planId): Response
    {
        $plan   = OnboardingPlan::with(['hrPerson.candidate'])->findOrFail($planId);
        $userId = $this->resolveUserId($plan);
        $user   = $userId ? User::with('person')->find($userId) : null;
        $status = $userId ? $this->service->getOnboardingTrainingStatus($plan) : null;
        $block  = $userId ? $this->service->blockOnboardingCompletionIfTrainingFailed($plan) : null;

        $employeeName = $user?->person
            ? trim("{$user->person->first_name} {$user->person->surname}")
            : ($user?->email ?? '—');

        return Inertia::render('Modules/LMS/Admin/OnboardingTraining', [
            'plan'          => [
                'id'         => $plan->id,
                'start_date' => $plan->start_date?->toDateString(),
                'end_date'   => $plan->end_date?->toDateString(),
                'status'     => $plan->status?->value ?? $plan->status,
            ],
            'employeeName'  => $employeeName,
            'userId'        => $userId,
            'trainingStatus'=> $status,
            'blockStatus'   => $block,
            'activeTab'     => 'detail',
        ]);
    }

    // ── Rules management ──────────────────────────────────────────────────────

    public function rulesIndex(Request $request): Response
    {
        $rules = LmsOnboardingTrainingRule::with(['course', 'createdBy'])
            ->withCount('assignments')
            ->when($request->filled('rule_type'), fn ($q) => $q->where('rule_type', $request->rule_type))
            ->orderBy('rule_type')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Modules/LMS/Admin/OnboardingTraining', [
            'rules'      => $rules,
            'ruleTypes'  => LmsOnboardingTrainingRule::RULE_TYPES,
            'scopeTypes' => LmsOnboardingTrainingRule::SCOPE_TYPES,
            'filters'    => $request->only(['rule_type']),
            'activeTab'  => 'rules',
        ]);
    }

    public function storeRule(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'course_id'                    => 'required|exists:lms_courses,id',
            'rule_type'                    => 'required|in:induction,department,role_based,policy',
            'scope_type'                   => 'required|in:all,department,position',
            'scope_id'                     => 'nullable|integer',
            'scope_label'                  => 'nullable|string|max:100',
            'due_within_days'              => 'required|integer|min:1|max:365',
            'is_mandatory'                 => 'boolean',
            'blocks_onboarding_completion' => 'boolean',
            'min_pass_score'               => 'nullable|integer|min:0|max:100',
            'min_understanding_score'      => 'nullable|integer|min:0|max:100',
            'is_active'                    => 'boolean',
        ]);

        $data['created_by'] = Auth::id();
        LmsOnboardingTrainingRule::create($data);

        return redirect()->back()->with('success', 'Onboarding training rule created.');
    }

    public function updateRule(Request $request, LmsOnboardingTrainingRule $rule): RedirectResponse
    {
        $data = $request->validate([
            'rule_type'                    => 'required|in:induction,department,role_based,policy',
            'scope_type'                   => 'required|in:all,department,position',
            'scope_id'                     => 'nullable|integer',
            'scope_label'                  => 'nullable|string|max:100',
            'due_within_days'              => 'required|integer|min:1|max:365',
            'is_mandatory'                 => 'boolean',
            'blocks_onboarding_completion' => 'boolean',
            'min_pass_score'               => 'nullable|integer|min:0|max:100',
            'min_understanding_score'      => 'nullable|integer|min:0|max:100',
            'is_active'                    => 'boolean',
        ]);

        $rule->update($data);

        return redirect()->back()->with('success', 'Rule updated.');
    }

    public function destroyRule(LmsOnboardingTrainingRule $rule): RedirectResponse
    {
        $rule->delete();
        return redirect()->back()->with('success', 'Rule deleted.');
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    public function assignTraining(Request $request): RedirectResponse
    {
        $data   = $request->validate([
            'plan_id' => 'required|exists:onboarding_plans,id',
            'user_id' => 'required|exists:users,id',
        ]);

        $plan   = OnboardingPlan::findOrFail($data['plan_id']);
        $user   = User::with('person')->findOrFail($data['user_id']);
        $result = $this->service->assignAllOnboardingTraining($plan, $user, Auth::id());

        return redirect()->back()->with('success',
            "Assigned {$result['total_assigned']} training(s): {$result['induction']} induction, {$result['role_based']} role-based."
        );
    }

    public function syncCompletion(Request $request): RedirectResponse
    {
        $data  = $request->validate(['plan_id' => 'required|exists:onboarding_plans,id']);
        $plan  = OnboardingPlan::findOrFail($data['plan_id']);

        $completions = $this->service->trackOnboardingTrainingCompletion($plan);
        $scores      = $this->service->trackOnboardingUnderstandingScore($plan);

        return redirect()->back()->with('success',
            "Synced: {$completions} completion(s) updated, {$scores} understanding score(s) updated."
        );
    }

    public function sendReminders(): RedirectResponse
    {
        $notified = $this->service->notifyIncompleteOnboardingTraining();
        return redirect()->back()->with('success', "Reminders sent for {$notified} employee(s).");
    }

    public function checkBlock(Request $request): RedirectResponse
    {
        $data   = $request->validate(['plan_id' => 'required|exists:onboarding_plans,id']);
        $plan   = OnboardingPlan::findOrFail($data['plan_id']);
        $result = $this->service->blockOnboardingCompletionIfTrainingFailed($plan);

        $message = $result['is_blocked']
            ? "Onboarding BLOCKED: {$result['blocking_count']} mandatory training(s) incomplete."
            : 'No training blocks on onboarding completion.';

        return redirect()->back()->with($result['is_blocked'] ? 'error' : 'success', $message);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function resolveUserId(OnboardingPlan $plan): ?int
    {
        // First try: any existing onboarding training assignment already has user_id
        $existingAssignment = LmsOnboardingTrainingAssignment::where('onboarding_plan_id', $plan->id)->first();
        if ($existingAssignment) {
            return $existingAssignment->user_id;
        }

        // Second try: match via candidate email → User email
        $candidateEmail = $plan->hrPerson?->candidate?->email;
        if ($candidateEmail) {
            $userId = User::where('email', $candidateEmail)->value('id');
            if ($userId) return $userId;
        }

        return null;
    }
}
