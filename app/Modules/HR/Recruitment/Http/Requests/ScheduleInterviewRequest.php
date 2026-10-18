<?php

namespace App\Modules\HR\Recruitment\Http\Requests;

use App\Modules\HR\Recruitment\Enums\InterviewType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class ScheduleInterviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('recruitment.interviews.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'interviewer_id'   => 'required|exists:users,id',
            'type'             => ['required', new Enum(InterviewType::class)],
            'scheduled_at'     => 'required|date|after:now',
            'duration_minutes' => 'sometimes|integer|min:15|max:480',
            'location'         => 'nullable|string|max:255',
            'meeting_url'      => 'nullable|url|max:500',
            'notes'            => 'nullable|string|max:2000',
        ];
    }
}
