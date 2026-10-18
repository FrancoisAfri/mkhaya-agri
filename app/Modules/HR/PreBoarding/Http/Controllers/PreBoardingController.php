<?php

namespace App\Modules\HR\PreBoarding\Http\Controllers;

use App\Core\User\Models\User;
use App\Modules\HR\PreBoarding\Actions\CompletePreBoardingTaskAction;
use App\Modules\HR\PreBoarding\Actions\ConvertCandidateToEmployeeAction;
use App\Modules\HR\PreBoarding\Http\Resources\HrPersonResource;
use App\Modules\HR\PreBoarding\Http\Resources\PreBoardingPlanResource;
use App\Modules\HR\PreBoarding\Models\HrPerson;
use App\Modules\HR\PreBoarding\Models\PreBoardingPlan;
use App\Modules\HR\PreBoarding\Models\PreBoardingTask;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PreBoardingController
{
    public function index(Request $request): Response
    {
        $persons = HrPerson::with(['candidate', 'activePlan.tasks'])
            ->when($request->input('status'), fn($q, $s) => $q->where('status', $s))
            ->when($request->input('search'), fn($q, $s) =>
                // Both places a name can live. Searching only the candidate made every
                // imported person unfindable, which reads as "the import did not work".
                $q->where(fn($outer) => $outer
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
            'total'        => HrPerson::count(),
            'pre_boarding' => HrPerson::where('status', 'pre_boarding')->count(),
            'onboarding'   => HrPerson::where('status', 'onboarding')->count(),
            'active'       => HrPerson::where('status', 'active')->count(),
        ];

        return Inertia::render('Modules/PreBoarding/Index', [
            'persons' => HrPersonResource::collection($persons),
            'filters' => $request->only('status', 'search'),
            'stats'   => $stats,
        ]);
    }

    public function show(HrPerson $person): Response
    {
        $person->load(['candidate', 'buddy', 'preBoardingPlans.tasks', 'onboardingPlans']);

        // users has no `name` column — display names live on hr_people (first_name + surname).
        $users = User::query()
            ->with('person:id,user_id,first_name,surname')
            ->where('status', 1)
            ->get(['id'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => trim(($u->person->first_name ?? '') . ' ' . ($u->person->surname ?? '')) ?: "User #{$u->id}"])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return Inertia::render('Modules/PreBoarding/Show', [
            'person' => (new HrPersonResource($person))->resolve(),
            'users'  => $users,
        ]);
    }

    public function assignBuddy(Request $request, HrPerson $person): RedirectResponse
    {
        $validated = $request->validate([
            'buddy_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $person->update(['buddy_user_id' => $validated['buddy_user_id']]);

        return back()->with('success', $validated['buddy_user_id']
            ? 'Onboarding buddy assigned.'
            : 'Onboarding buddy removed.'
        );
    }

    public function updateDetails(Request $request, HrPerson $person): RedirectResponse
    {
        $validated = $request->validate([
            'department' => ['nullable', 'string', 'max:100'],
            'job_title'  => ['nullable', 'string', 'max:150'],
        ]);

        $person->update($validated);

        return back()->with('success', 'Details updated.');
    }

    public function showPlan(HrPerson $person, PreBoardingPlan $plan): Response
    {
        abort_unless($plan->hr_person_id === $person->id, 404);

        $plan->load('tasks');

        return Inertia::render('Modules/PreBoarding/Plan', [
            'person' => (new HrPersonResource($person))->resolve(),
            'plan'   => (new PreBoardingPlanResource($plan))->resolve(),
        ]);
    }

    public function completeTask(
        Request $request,
        HrPerson $person,
        PreBoardingPlan $plan,
        PreBoardingTask $task,
        CompletePreBoardingTaskAction $action,
    ) {
        abort_unless($plan->hr_person_id === $person->id, 404);
        abort_unless($task->pre_boarding_plan_id === $plan->id, 404);

        $action->execute($task, $request->user()->id, (bool) $request->boolean('skip'));

        return back();
    }
}
