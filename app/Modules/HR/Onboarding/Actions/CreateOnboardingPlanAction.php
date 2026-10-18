<?php

namespace App\Modules\HR\Onboarding\Actions;

use App\Modules\HR\Onboarding\Enums\OnboardingTaskType;
use App\Modules\HR\Onboarding\Models\OnboardingPlan;
use App\Modules\HR\Onboarding\Models\OnboardingTask;
use App\Modules\HR\PreBoarding\Enums\HrPersonStatus;
use App\Modules\HR\PreBoarding\Models\HrPerson;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateOnboardingPlanAction
{
    public function execute(HrPerson $hrPerson, array $data, int $createdBy): OnboardingPlan
    {
        if ($hrPerson->onboardingPlans()->whereNotIn('status', ['completed', 'cancelled'])->exists()) {
            throw ValidationException::withMessages([
                'hr_person_id' => 'An active onboarding plan already exists for this person.',
            ]);
        }

        return DB::transaction(function () use ($hrPerson, $data, $createdBy): OnboardingPlan {
            $plan = OnboardingPlan::create([
                'hr_person_id' => $hrPerson->id,
                'template_id'  => $data['template_id'] ?? null,
                'start_date'   => $data['start_date'],
                'end_date'     => $data['end_date'],
                'status'       => 'active',
                'created_by'   => $createdBy,
            ]);

            if (empty($data['tasks'])) {
                $this->seedDefaultTasks($plan);
            } else {
                foreach ($data['tasks'] as $order => $taskData) {
                    OnboardingTask::create([
                        'onboarding_plan_id' => $plan->id,
                        'type'               => $taskData['type'],
                        'title'              => $taskData['title'],
                        'description'        => $taskData['description'] ?? null,
                        'due_date'           => $taskData['due_date'] ?? null,
                        'assigned_to'        => $taskData['assigned_to'] ?? null,
                        'sort_order'         => $order,
                    ]);
                }
            }

            $hrPerson->update(['status' => HrPersonStatus::Onboarding->value]);

            return $plan->load('tasks');
        });
    }

    private function seedDefaultTasks(OnboardingPlan $plan): void
    {
        $defaults = [
            [OnboardingTaskType::Introduction,          'Meet the team'],
            [OnboardingTaskType::SystemSetup,           'Set up workstation & accounts'],
            [OnboardingTaskType::PolicyAcknowledgement, 'Read & acknowledge HR policies'],
            [OnboardingTaskType::Training,              'Complete mandatory compliance training'],
            [OnboardingTaskType::Meeting,               '1-on-1 with line manager (Day 1)'],
            [OnboardingTaskType::Reading,               'Review company strategy deck'],
            [OnboardingTaskType::Meeting,               '30-day check-in'],
        ];

        foreach ($defaults as $order => [$type, $title]) {
            OnboardingTask::create([
                'onboarding_plan_id' => $plan->id,
                'type'               => $type->value,
                'title'              => $title,
                'sort_order'         => $order,
            ]);
        }
    }
}
