<?php

namespace App\Modules\HR\Onboarding\Http\Controllers;

use App\Modules\HR\Onboarding\Models\OnboardingProvisioningTask;
use App\Modules\HR\Onboarding\Models\OnboardingTask;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Everything assigned to me, across every new hire.
 *
 * `OnboardingTask.assigned_to` has always existed but there was no route that read it: the only way
 * to find your own onboarding work was to open each new hire in turn and look. For the people who
 * carry most of it — an IT admin owing laptops, a manager owing one-to-ones — that is the
 * difference between a list they work through and a job they never get to.
 */
class OnboardingInboxController
{
    public function index(Request $request): Response
    {
        $userId = $request->user()->id;

        $tasks = OnboardingTask::query()
            ->where('assigned_to', $userId)
            ->whereIn('status', ['pending', 'in_progress'])
            // Only live plans: work on a cancelled or completed onboarding is nobody's problem.
            ->whereHas('plan', fn ($q) => $q->where('status', 'active'))
            ->with('plan.hrPerson')
            // Undated last rather than first — a task with no due date sorts before everything
            // under a plain ascending sort, which puts the least urgent work at the top.
            ->orderByRaw('due_date is null, due_date asc')
            ->get()
            ->map(fn (OnboardingTask $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'person' => $task->plan?->hrPerson?->fullName() ?: '—',
                'person_id' => $task->plan?->hr_person_id,
                'plan_id' => $task->onboarding_plan_id,
                'due_date' => $task->due_date?->toDateString(),
                'overdue' => $task->due_date?->isPast() ?? false,
                'starts_on' => $task->plan?->start_date?->toDateString(),
            ])->values();

        // Provisioning is tracked per department rather than per user, so it is listed separately
        // rather than pretended into the same list under a false assignee.
        $provisioning = OnboardingProvisioningTask::query()
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->with('hrPerson')
            ->orderByRaw('due_date is null, due_date asc')
            ->limit(50)
            ->get()
            ->map(fn (OnboardingProvisioningTask $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'person' => $task->hrPerson?->fullName() ?: '—',
                'person_id' => $task->hr_person_id,
                'department' => $task->assigned_department,
                'due_date' => $task->due_date?->toDateString(),
                'overdue' => $task->due_date?->isPast() ?? false,
            ])->values();

        return Inertia::render('Modules/Onboarding/Inbox', [
            'tasks' => $tasks,
            'provisioning' => $provisioning,
            'overdueCount' => $tasks->where('overdue', true)->count(),
        ]);
    }
}
