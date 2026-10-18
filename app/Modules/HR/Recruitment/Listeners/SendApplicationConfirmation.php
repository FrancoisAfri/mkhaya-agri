<?php

namespace App\Modules\HR\Recruitment\Listeners;

use App\Modules\HR\Recruitment\Events\CandidateApplied;
use App\Modules\HR\Recruitment\Mail\ApplicationConfirmationMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendApplicationConfirmation implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = 'notifications';

    public bool $afterCommit = true;

    public function handle(CandidateApplied $event): void
    {
        $application = $event->application;
        $candidate   = $application->candidate;

        if (!$candidate) {
            Log::warning("SendApplicationConfirmation: no candidate for application #{$application->id}");
            return;
        }

        Mail::to($candidate->email)->send(new ApplicationConfirmationMail($application));
    }
}
