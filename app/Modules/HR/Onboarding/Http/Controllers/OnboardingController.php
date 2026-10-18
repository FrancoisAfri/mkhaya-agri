<?php

namespace App\Modules\HR\Onboarding\Http\Controllers;

use App\Modules\HR\Contracts\Models\Contract;
use App\Modules\HR\Onboarding\Actions\CompleteOnboardingTaskAction;
use App\Modules\HR\Onboarding\Actions\CreateOnboardingPlanAction;
use App\Modules\HR\Onboarding\Actions\GenerateAIOnboardingPlanAction;
use App\Modules\HR\Onboarding\Http\Resources\OnboardingDocumentResource;
use App\Modules\HR\Onboarding\Http\Resources\OnboardingPlanResource;
use App\Modules\HR\Onboarding\Http\Resources\OnboardingProvisioningTaskResource;
use App\Modules\HR\Onboarding\Http\Resources\ProbationPeriodResource;
use App\Modules\HR\Onboarding\Models\OnboardingPlan;
use App\Modules\HR\Onboarding\Models\OnboardingTask;
use App\Modules\HR\PreBoarding\Actions\ConvertCandidateToEmployeeAction;
use App\Modules\HR\PreBoarding\Models\HrPerson;
use App\Modules\HR\Onboarding\Enums\OnboardingTaskType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController
{
    public function index(Request $request): Response
    {
        $plans = OnboardingPlan::with(['hrPerson.candidate'])
            ->when($request->input('status'), fn($q, $s) => $q->where('status', $s))
            ->when($request->input('search'), fn($q, $s) =>
                // See PreBoardingController: a person imported in bulk has no candidate,
                // so searching through the relation alone never finds them.
                $q->whereHas('hrPerson', fn($pq) => $pq
                    ->whereHas('candidate', fn($cq) =>
                        $cq->where('first_name', 'ilike', "%{$s}%")
                           ->orWhere('last_name', 'ilike', "%{$s}%")
                           ->orWhere('email', 'ilike', "%{$s}%")
                    )
                    ->orWhere('first_name', 'ilike', "%{$s}%")
                    ->orWhere('surname', 'ilike', "%{$s}%")
                    ->orWhere('email', 'ilike', "%{$s}%")
                )
            )
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $stats = [
            'total'     => OnboardingPlan::count(),
            'active'    => OnboardingPlan::where('status', 'active')->count(),
            'completed' => OnboardingPlan::where('status', 'completed')->count(),
            'pending'   => OnboardingPlan::where('status', 'pending')->count(),
        ];

        return Inertia::render('Modules/Onboarding/Index', [
            'plans'   => OnboardingPlanResource::collection($plans),
            'filters' => $request->only('status', 'search'),
            'stats'   => $stats,
        ]);
    }

    public function show(HrPerson $person, OnboardingPlan $plan): Response
    {
        abort_unless($plan->hr_person_id === $person->id, 404);

        $plan->load(['hrPerson.candidate', 'tasks']);
        $person->load(['onboardingDocuments', 'provisioningTasks', 'probationPeriod.reviews']);

        return Inertia::render('Modules/Onboarding/Show', [
            'plan'             => (new OnboardingPlanResource($plan))->resolve(),
            'documents'        => OnboardingDocumentResource::collection($person->onboardingDocuments),
            'provisioningTasks' => OnboardingProvisioningTaskResource::collection($person->provisioningTasks),
            'probation'        => $person->probationPeriod
                ? new ProbationPeriodResource($person->probationPeriod)
                : null,
        ]);
    }

    public function store(
        Request $request,
        HrPerson $person,
        CreateOnboardingPlanAction $action,
    ) {
        $validated = $request->validate([
            'start_date'             => ['required', 'date', 'after_or_equal:today'],
            'end_date'               => ['required', 'date', 'after:start_date'],
            'tasks'                  => ['nullable', 'array'],
            'tasks.*.type'           => ['required', 'string', Rule::in(array_column(OnboardingTaskType::cases(), 'value'))],
            'tasks.*.title'          => ['required', 'string', 'max:200'],
            'tasks.*.due_date'       => ['nullable', 'date'],
            'tasks.*.assigned_to'    => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $plan = $action->execute($person, $validated, $request->user()->id);

        return redirect()->route('onboarding.plans.show', [$person, $plan]);
    }

    public function completeTask(
        Request $request,
        HrPerson $person,
        OnboardingPlan $plan,
        OnboardingTask $task,
        CompleteOnboardingTaskAction $action,
    ) {
        abort_unless($plan->hr_person_id === $person->id, 404);
        abort_unless($task->onboarding_plan_id === $plan->id, 404);

        $action->execute($task, $request->user()->id, (bool) $request->boolean('skip'));

        return back();
    }

    public function cancel(Request $request, HrPerson $person, OnboardingPlan $plan)
    {
        abort_unless($plan->hr_person_id === $person->id, 404);

        if ($plan->status->isTerminal()) {
            throw ValidationException::withMessages([
                'plan' => 'This onboarding plan is already in a terminal state and cannot be cancelled.',
            ]);
        }

        $plan->update(['status' => 'cancelled']);

        return back();
    }

    public function generatePlan(Request $request, HrPerson $person, GenerateAIOnboardingPlanAction $action)
    {
        $validated = $request->validate([
            'start_date'  => ['required', 'date'],
            'department'  => ['nullable', 'string', 'max:100'],
            'job_title'   => ['nullable', 'string', 'max:100'],
        ]);

        $result = $action->execute($person, $validated);

        return response()->json($result);
    }

    public function convert(Request $request, Contract $contract, ConvertCandidateToEmployeeAction $action)
    {
        $hrPerson = $action->execute($contract, $request->user()->id);

        return redirect()->route('pre-boarding.show', $hrPerson);
    }
}
