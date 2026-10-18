<?php

namespace App\Modules\HR\Onboarding\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OnboardingTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'uuid'            => $this->uuid,
            'name'            => $this->name,
            'description'     => $this->description,
            'department'      => $this->department,
            'job_title'       => $this->job_title,
            'employment_type' => $this->employment_type,
            'is_active'       => $this->is_active,
            'task_count'      => $this->templateTasks_count ?? $this->templateTasks()->count(),
            'tasks'           => OnboardingTemplateTaskResource::collection($this->whenLoaded('templateTasks')),
            'created_at'      => $this->created_at->toISOString(),
        ];
    }
}
