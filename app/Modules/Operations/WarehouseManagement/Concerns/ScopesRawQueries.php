<?php

namespace App\Modules\Operations\WarehouseManagement\Concerns;

use App\Modules\Tenancy\Services\CurrentCompany;

/**
 * Raw report queries skip the Eloquent tenant scope, and a platform administrator has no current
 * company for row-level security to apply -- so without this a report run by one would add up
 * every tenant's stock. The company is the current one, else the signed-in user's own.
 */
trait ScopesRawQueries
{
    protected function tenantId(): ?int
    {
        $id = app(CurrentCompany::class)->id() ?? auth()->user()?->company_id;

        return $id ? (int) $id : null;
    }
}
