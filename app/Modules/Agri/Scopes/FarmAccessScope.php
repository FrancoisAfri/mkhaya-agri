<?php

namespace App\Modules\Agri\Scopes;

use App\Modules\Agri\Services\FarmAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Confines a farm-owned model to the farms the signed-in person may reach.
 *
 * A global scope rather than a filter in each controller, because controllers are not the only
 * readers: a dashboard count, a dropdown, a report, an export and the AI assistant's tools all read
 * these tables, and a filter that has to be remembered at each of them is one a later screen
 * forgets. It narrows the company scope underneath; it never widens it.
 */
class FarmAccessScope implements Scope
{
    /** @param string $column the farm column on the model — `id` for Farm itself */
    public function __construct(private readonly string $column = 'farm_id') {}

    public function apply(Builder $builder, Model $model): void
    {
        $ids = app(FarmAccess::class)->farmIdsFor();

        if ($ids === null) {
            return;
        }

        $column = $model->getTable().'.'.$this->column;

        // No farms at all: match nothing, rather than an empty IN list some drivers reject.
        $ids === []
            ? $builder->whereRaw('1 = 0')
            : $builder->whereIn($column, $ids);
    }
}
