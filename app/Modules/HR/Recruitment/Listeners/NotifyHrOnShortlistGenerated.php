<?php

namespace App\Modules\HR\Recruitment\Listeners;

use App\Modules\HR\Recruitment\Events\ShortlistGenerated;
use App\Modules\HR\Recruitment\Mail\ShortlistReadyMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class NotifyHrOnShortlistGenerated implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = 'notifications';

    public bool $afterCommit = true;

    public function handle(ShortlistGenerated $event): void
    {
        $shortlist = $event->shortlist;

        $recipients = \App\Core\User\Models\User::permission('recruitment.shortlists.approve')
            ->whereNotNull('email')
            ->pluck('email');

        if ($recipients->isEmpty()) {
            return;
        }

        foreach ($recipients as $email) {
            \Illuminate\Support\Facades\Mail::to($email)->send(new ShortlistReadyMail($shortlist));
        }
    }
}
