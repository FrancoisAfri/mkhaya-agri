<?php

namespace App\Modules\HR\Onboarding\Http\Controllers;

use App\Modules\HR\Onboarding\Actions\UpdateProvisioningTaskStatusAction;
use App\Modules\HR\Onboarding\Http\Resources\OnboardingProvisioningTaskResource;
use App\Modules\HR\Onboarding\Models\OnboardingProvisioningTask;
use App\Modules\HR\PreBoarding\Models\HrPerson;
use Illuminate\Http\Request;

class OnboardingProvisioningController
{
    public function index(HrPerson $person)
    {
        $tasks = $person->provisioningTasks()
            ->orderBy('status')
            ->orderBy('due_date')
            ->get();

        return response()->json([
            'tasks' => OnboardingProvisioningTaskResource::collection($tasks),
        ]);
    }

    public function store(Request $request, HrPerson $person)
    {
        $validated = $request->validate([
            'type'                => ['required', 'string', 'in:laptop,email_account,system_access,building_access,payroll_profile,phone,other'],
            'title'               => ['required', 'string', 'max:200'],
            'notes'               => ['nullable', 'string'],
            'assigned_department' => ['nullable', 'string', 'max:100'],
            'assigned_to'         => ['nullable', 'integer', 'exists:users,id'],
            'due_date'            => ['nullable', 'date'],
        ]);

        // Auto-fill department from type if not provided
        if (empty($validated['assigned_department'])) {
            $type = \App\Modules\HR\Onboarding\Enums\ProvisioningTaskType::from($validated['type']);
            $validated['assigned_department'] = $type->defaultDepartment();
        }

        $task = OnboardingProvisioningTask::create([
            'hr_person_id'        => $person->id,
            'type'                => $validated['type'],
            'title'               => $validated['title'],
            'notes'               => $validated['notes'] ?? null,
            'assigned_department' => $validated['assigned_department'],
            'assigned_to'         => $validated['assigned_to'] ?? null,
            'due_date'            => $validated['due_date'] ?? null,
            'status'              => 'pending',
            'created_by'          => $request->user()->id,
        ]);

        return response()->json([
            'task' => (new OnboardingProvisioningTaskResource($task))->resolve(),
        ], 201);
    }

    public function updateStatus(
        Request $request,
        HrPerson $person,
        OnboardingProvisioningTask $task,
        UpdateProvisioningTaskStatusAction $action,
    ) {
        abort_unless($task->hr_person_id === $person->id, 404);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:pending,requested,in_progress,completed,cancelled'],
        ]);

        $task = $action->execute($task, $validated['status']);

        return response()->json([
            'task' => (new OnboardingProvisioningTaskResource($task))->resolve(),
        ]);
    }

    public function destroy(HrPerson $person, OnboardingProvisioningTask $task)
    {
        abort_unless($task->hr_person_id === $person->id, 404);
        abort_unless(in_array($task->status->value, ['pending', 'cancelled']), 422);

        $task->delete();

        return response()->json(['message' => 'Provisioning task deleted.']);
    }
}
