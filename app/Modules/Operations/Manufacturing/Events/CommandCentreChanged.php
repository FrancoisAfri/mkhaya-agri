<?php

namespace App\Modules\Operations\Manufacturing\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something on the Command Centre changed — an incident opened, repeated, escalated or moved on, a
 * reading arrived, a command changed state. Pushed over WebSockets (Reverb) on the organisation's
 * private channel; the board then fetches its snapshot, so nothing about a farm a viewer may not
 * reach travels on the socket. Without Reverb the board's server-sent events carry it instead.
 */
class CommandCentreChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly int $companyId, public readonly string $what, public readonly ?string $severity = null) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("command-centre.{$this->companyId}");
    }

    public function broadcastAs(): string
    {
        return 'changed';
    }

    public function broadcastWith(): array
    {
        return ['what' => $this->what, 'severity' => $this->severity, 'at' => now()->toIso8601String()];
    }
}
