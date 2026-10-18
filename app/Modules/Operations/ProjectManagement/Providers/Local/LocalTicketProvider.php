<?php

namespace App\Modules\Operations\ProjectManagement\Providers\Local;

use App\Modules\Operations\ProjectManagement\Contracts\Providers\TicketProvider;

/**
 * Support tickets against a project, when Help Desk is not installed.
 *
 * Same reasoning as purchases: PM does not run a ticket queue, so it says it has none rather than
 * growing a shadow one.
 */
class LocalTicketProvider implements TicketProvider
{
    public function forProject(string $projectId): array
    {
        return [];
    }

    public function isAvailable(): bool
    {
        return false;
    }
}
