<?php

namespace App\Modules\Operations\ProjectManagement\Contracts\Providers;

use App\Modules\Operations\ProjectManagement\Support\Providers\ProvidedRecord;

/** Support tickets raised against a project. Integrated: Help Desk. */
interface TicketProvider
{
    /** @return list<ProvidedRecord> */
    public function forProject(string $projectId): array;

    public function isAvailable(): bool;
}
