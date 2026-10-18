<?php

namespace App\Modules\HR\Recruitment\Jobs;

use App\Core\User\Models\User;
use App\Modules\HR\Recruitment\Models\Vacancy;
use App\Modules\HR\Recruitment\Notifications\InternalJobOpportunityNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

class SendInternalJobNotificationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly Vacancy $vacancy)
    {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        User::where('is_active', true)
            ->chunk(100, function ($users) {
                Notification::send($users, new InternalJobOpportunityNotification($this->vacancy));
            });
    }
}
