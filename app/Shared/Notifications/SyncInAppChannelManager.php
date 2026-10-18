<?php

namespace App\Shared\Notifications;

use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Notifications\ChannelManager;

/**
 * ChannelManager that wires in {@see SyncInAppNotificationSender} so the
 * in-app (database/broadcast) channels of every notification are delivered
 * synchronously, even for ShouldQueue notifications and with no queue worker.
 */
class SyncInAppChannelManager extends ChannelManager
{
    protected function resolveNotificationSender()
    {
        return $this->notificationSender ??= new SyncInAppNotificationSender(
            $this,
            $this->container->make(Bus::class),
            $this->container->make(Dispatcher::class),
            $this->locale
        );
    }
}
