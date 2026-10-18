<?php

namespace App\Modules\CRM\Http\Controllers\Concerns;

use App\Modules\CRM\Services\CrmVisibilityService;
use Illuminate\Database\Eloquent\Model;

/**
 * Refuses a write to a record outside the user's reporting tree.
 *
 * The list screens have always been scoped by owner, but scoping a list only decides what gets
 * shown — the record stays reachable by id. Without this check a consultant could change or delete
 * a colleague's deal, or one team's, simply by addressing it directly.
 */
trait GuardsRecordOwnership
{
    protected function guard(Model $record, string $column = 'owner_id'): void
    {
        app(CrmVisibilityService::class)->authorize($record, $column);
    }
}
