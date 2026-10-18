<?php

namespace App\Modules\HR\LMS\Notifications;

use App\Modules\HR\LMS\Models\LmsCourse;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class LmsAiCourseReadyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly LmsCourse $course,
        private readonly int       $moduleCount,
        private readonly int       $lessonCount,
        private readonly int       $quizCount,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title'      => 'AI Course Ready',
            'body'       => "Your AI-generated course \"{$this->course->title}\" is ready — {$this->moduleCount} modules, {$this->lessonCount} lessons"
                          . ($this->quizCount > 0 ? ", {$this->quizCount} quiz questions" : '') . '.',
            'action_url' => url("/lms/admin/courses/{$this->course->uuid}/builder"),
            'icon'       => 'tabler-sparkles',
            'color'      => 'success',
        ];
    }
}
