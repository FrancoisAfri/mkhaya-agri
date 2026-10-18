<?php

namespace App\Modules\HR\Hr\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ClockingRecorded implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int    $employeeId,
        public readonly string $employeeName,
        public readonly string $type,        // clock_in | clock_out
        public readonly string $method,
        public readonly string $clockedAt,
        public readonly bool   $isLate,
        public readonly ?float $latitude  = null,
        public readonly ?float $longitude = null,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('attendance.live');
    }

    public function broadcastAs(): string
    {
        return 'clocking.recorded';
    }

    public function broadcastWith(): array
    {
        return [
            'employee_id'   => $this->employeeId,
            'employee_name' => $this->employeeName,
            'type'          => $this->type,
            'method'        => $this->method,
            'clocked_at'    => $this->clockedAt,
            'is_late'       => $this->isLate,
            'latitude'      => $this->latitude,
            'longitude'     => $this->longitude,
        ];
    }
}
