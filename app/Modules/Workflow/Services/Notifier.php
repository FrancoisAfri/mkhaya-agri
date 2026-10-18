<?php

namespace App\Modules\Workflow\Services;

use App\Core\User\Models\User;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Notifications\WorkflowNotification;
use App\Modules\Workflow\Support\WorkflowSettings;
use Illuminate\Support\Facades\Cache;

/**
 * Sends workflow notifications with duplicate suppression (PRS §11): the same notice about the same
 * thing to the same person within the window is sent once. Confidential workflows get a link only.
 */
class Notifier
{
    private const WINDOW_MINUTES = 30;

    public function __construct(private readonly WorkflowSettings $settings)
    {
    }

    /** @param list<int> $userIds */
    public function send(array $userIds, string $kind, string $title, string $message, ?string $url, ?WorkflowInstance $instance = null, ?string $about = null): int
    {
        $sent = 0;
        if ($instance?->definition?->is_confidential) {
            $title = 'A confidential workflow needs your attention';
            $message = 'Open Mkhaya Hub to see the details.';
        }
        $email = (bool) $this->settings->get('email_notifications', $instance?->company_id);
        foreach (array_unique(array_filter($userIds)) as $userId) {
            $key = 'wfnotify:' . $userId . ':' . $kind . ':' . ($about ?? $instance?->id ?? $title);
            if (! Cache::add($key, 1, now()->addMinutes(self::WINDOW_MINUTES))) {
                continue;
            }
            $user = User::find($userId);
            if (! $user || (int) $user->status === 0) {
                continue;
            }
            try {
                // The bell now, inside the step's transaction.
                $user->notify(new WorkflowNotification($kind, $title, $message, $url, ['database']));
                $sent++;
            } catch (\Throwable $e) {
                report($e); // a failed notice never undoes the workflow step that caused it
            }
            if ($email) {
                // Email after the response has gone back (no queue worker needed): a slow or unreachable
                // mail server never holds up the person deciding a task.
                dispatch(function () use ($userId, $kind, $title, $message, $url) {
                    \App\Core\User\Models\User::find($userId)?->notify(new WorkflowNotification($kind, $title, $message, $url, ['mail']));
                })->afterResponse();
            }
        }

        return $sent;
    }
}
