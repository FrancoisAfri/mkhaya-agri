<?php

namespace App\Modules\HR\Recruitment\Listeners;

use App\Modules\HR\Recruitment\Events\InterviewScheduled;
use App\Modules\HR\Recruitment\Mail\InterviewScheduledMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

class SendInterviewScheduledNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = 'notifications';

    public function handle(InterviewScheduled $event): void
    {
        $interview = $event->interview->load([
            'application.candidate',
            'application.job',
            'interviewer',
        ]);

        $candidate   = $interview->application?->candidate;
        $interviewer = $interview->interviewer;

        if ($candidate && $candidate->email) {
            Mail::to($candidate->email)->send(
                new InterviewScheduledMail($interview, recipient: 'candidate')
            );
        }

        if ($interviewer && $interviewer->email) {
            Mail::to($interviewer->email)->send(
                new InterviewScheduledMail($interview, recipient: 'interviewer')
            );
        }
    }
}
