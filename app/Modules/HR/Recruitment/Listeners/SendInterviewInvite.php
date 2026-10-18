<?php

namespace App\Modules\HR\Recruitment\Listeners;

use App\Modules\HR\Recruitment\Events\InterviewScheduled;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendInterviewInvite implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = 'notifications';

    public bool $afterCommit = true;

    public function handle(InterviewScheduled $event): void
    {
        $interview = $event->interview;
        $candidate = $interview->application->candidate ?? null;

        if (!$candidate) {
            return;
        }

        Log::info(
            "Interview scheduled for candidate {$candidate->email} " .
            "on {$interview->scheduled_at->toDateTimeString()}"
        );

        // Mail::to($candidate->email)->send(new InterviewInviteMail($interview));
        // Mail::to($interview->interviewer->email)->send(new InterviewerScheduledMail($interview));
    }
}
