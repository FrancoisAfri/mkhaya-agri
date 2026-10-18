<?php

namespace App\Shared\Notifications;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\NotificationSender;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Str;

/**
 * Notification sender that guarantees in-app notifications "always go".
 *
 * Many notifications across the system implement ShouldQueue, which means the
 * whole notification (including its `database` channel) is pushed onto the queue
 * — and if no queue worker is running, the bell/notification dropdown never gets
 * the row. This sender splits the channels:
 *
 *   • `database` / `broadcast` (in-app)  → delivered SYNCHRONOUSLY, immediately.
 *   • everything else (mail, sms, …)     → queued as normal (best-effort).
 *
 * Non-queued notifications are unaffected (sent immediately, as before).
 */
class SyncInAppNotificationSender extends NotificationSender
{
    /** Channels that must always deliver immediately. */
    private const SYNC_CHANNELS = ['database', 'broadcast'];

    public function send($notifiables, $notification)
    {
        if (! $notification instanceof ShouldQueue) {
            $this->sendNow($notifiables, $notification);

            return;
        }

        // 1) Deliver in-app channels right now so they never depend on a worker.
        foreach ($this->formatNotifiables($notifiables) as $notifiable) {
            $sync = array_values(array_intersect(
                (array) $notification->via($notifiable),
                self::SYNC_CHANNELS
            ));

            if ($sync) {
                $this->sendNow($notifiable, $notification, $sync);
            }
        }

        // 2) Queue the remaining channels (mail, etc.) as usual.
        $this->queueNotification($notifiables, $notification);
    }

    /**
     * Queue the notification's channels, skipping the in-app channels already
     * delivered synchronously in send(). Mirrors the framework implementation.
     */
    protected function queueNotification($notifiables, $notification)
    {
        $notifiables = $this->formatNotifiables($notifiables);

        $original = clone $notification;

        foreach ($notifiables as $notifiable) {
            $notificationId = (string) Str::uuid();

            foreach ((array) $original->via($notifiable) as $channel) {
                // Already delivered synchronously in send() — don't re-queue it.
                if (in_array($channel, self::SYNC_CHANNELS, true)) {
                    continue;
                }

                $notification = clone $original;

                if (! $notification->id) {
                    $notification->id = $notificationId;
                }

                if (! is_null($this->locale)) {
                    $notification->locale = $this->locale;
                }

                $connection = $notification->connection;

                if (method_exists($notification, 'viaConnections')) {
                    $connection = $notification->viaConnections()[$channel] ?? $connection;
                }

                $queue = $notification->queue;

                if (method_exists($notification, 'viaQueues')) {
                    $queue = $notification->viaQueues()[$channel] ?? $queue;
                }

                $delay = $notification->delay;

                if (method_exists($notification, 'withDelay')) {
                    $delay = $notification->withDelay($notifiable, $channel) ?? null;
                }

                $messageGroup = $notification->messageGroup ?? (method_exists($notification, 'messageGroup') ? $notification->messageGroup() : null);

                if (method_exists($notification, 'withMessageGroups')) {
                    $messageGroup = $notification->withMessageGroups($notifiable, $channel) ?? null;
                }

                $deduplicator = $notification->deduplicator ?? (method_exists($notification, 'deduplicationId') ? $notification->deduplicationId(...) : null);

                if (method_exists($notification, 'withDeduplicators')) {
                    $deduplicator = $notification->withDeduplicators($notifiable, $channel) ?? null;
                }

                $middleware = $notification->middleware ?? [];

                if (method_exists($notification, 'middleware')) {
                    $middleware = array_merge(
                        $notification->middleware($notifiable, $channel),
                        $middleware
                    );
                }

                $this->bus->dispatch(
                    $this->manager->getContainer()->make(SendQueuedNotifications::class, [
                        'notifiables' => $notifiable,
                        'notification' => $notification,
                        'channels' => [$channel],
                    ])
                        ->onConnection($connection)
                        ->onQueue($queue)
                        ->delay(is_array($delay) ? ($delay[$channel] ?? null) : $delay)
                        ->onGroup(is_array($messageGroup) ? ($messageGroup[$channel] ?? null) : $messageGroup)
                        ->withDeduplicator(is_array($deduplicator) ? ($deduplicator[$channel] ?? null) : $deduplicator)
                        ->through($middleware)
                );
            }
        }
    }
}
