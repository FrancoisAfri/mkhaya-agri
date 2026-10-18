<?php

namespace App\Modules\HR\LMS\Notifications;

use App\Modules\HR\LMS\Models\LmsCourse;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class LmsAiCourseFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly LmsCourse $course,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title'      => 'AI Course Generation Failed',
            'body'       => "Could not generate course \"{$this->course->title}\". Please try again from the Course Builder.",
            'action_url' => url("/lms/admin/courses/{$this->course->uuid}/builder"),
            'icon'       => 'tabler-alert-triangle',
            'color'      => 'error',
        ];
    }
}
