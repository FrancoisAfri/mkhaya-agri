<?php

namespace App\Core\Compliance\Events;

use App\Core\Compliance\Models\DataBreachIncident;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DataBreachReported
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly DataBreachIncident $incident,
    ) {}
}
