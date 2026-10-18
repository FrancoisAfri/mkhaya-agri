<?php

namespace App\Core\Compliance\Listeners;

use App\Core\Compliance\Events\DataBreachReported;
use App\Core\Compliance\Notifications\DataBreachNotification;
use App\Core\User\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyOnDataBreach implements ShouldQueue
{
    public function handle(DataBreachReported $event): void
    {
        $incident = $event->incident;

        // Notify the designated Information Officer(s)
        $recipients = User::where('is_information_officer', true)
            ->where('status', 1)
            ->get();

        // Fall back to platform admins if no IO is designated
        if ($recipients->isEmpty()) {
            $recipients = User::where('is_platform_admin', true)
                ->orWhere('is_master_admin', true)
                ->where('status', 1)
                ->get();
        }

        foreach ($recipients as $user) {
            $user->notify(new DataBreachNotification($incident));
        }
    }
}
