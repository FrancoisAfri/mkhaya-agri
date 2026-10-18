<?php

namespace App\Modules\Operations\Manufacturing\Integrations;

use App\Modules\Operations\Manufacturing\Models\Integration;
use App\Modules\Operations\Manufacturing\Models\IntegrationEvent;

/** How one kind of external system is reached (MFG-022). */
interface IntegrationAdapter
{
    /** Can the system be reached with these settings and credentials? @return array{ok: bool, detail: string} */
    public function ping(Integration $integration): array;

    /** Messages waiting at the other end. @return list<array{type: string, id: string, data: array}> */
    public function fetch(Integration $integration): array;

    /** Deliver one outgoing event. @return array{ok: bool, reference: ?string, detail: string} */
    public function send(Integration $integration, IntegrationEvent $event): array;
}
