<?php

namespace App\Modules\HR\Onboarding\Actions;

use App\Modules\HR\Onboarding\Models\OnboardingTemplate;
use App\Modules\HR\Onboarding\Models\OnboardingTemplateTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateOnboardingTemplateAction
{
    public function execute(array $data, int $createdBy): OnboardingTemplate
    {
        if (OnboardingTemplate::where('name', $data['name'])->whereNull('deleted_at')->exists()) {
            throw ValidationException::withMessages([
                'name' => 'An onboarding template with this name already exists.',
            ]);
        }

        return DB::transaction(function () use ($data, $createdBy): OnboardingTemplate {
            $template = OnboardingTemplate::create([
                'name'             => $data['name'],
                'description'      => $data['description'] ?? null,
                'department'       => $data['department'] ?? null,
                'job_title'        => $data['job_title'] ?? null,
                'employment_type'  => $data['employment_type'] ?? null,
                'is_active'        => $data['is_active'] ?? true,
                'created_by'       => $createdBy,
            ]);

            if (!empty($data['tasks'])) {
                foreach ($data['tasks'] as $order => $taskData) {
                    OnboardingTemplateTask::create([
                        'onboarding_template_id' => $template->id,
                        'type'                   => $taskData['type'],
                        'title'                  => $taskData['title'],
                        'description'            => $taskData['description'] ?? null,
                        'day_offset'             => $taskData['day_offset'] ?? 0,
                        'owner_type'             => $taskData['owner_type'] ?? null,
                        'sort_order'             => $taskData['sort_order'] ?? $order,
                    ]);
                }
            }

            return $template->load('templateTasks');
        });
    }
}
