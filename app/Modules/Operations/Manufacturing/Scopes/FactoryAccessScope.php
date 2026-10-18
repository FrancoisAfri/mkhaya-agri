<?php

namespace App\Modules\Operations\Manufacturing\Scopes;

use App\Modules\Operations\Manufacturing\Services\FactoryAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Confines a factory-owned model to the factories the signed-in person is assigned to.
 *
 * Added as a global scope rather than a filter in each controller because the controllers are not
 * the only readers: a count on the dashboard, an option list in a dialog, a `whereHas` inside
 * another query, a report. A filter that must be remembered at each of those is a filter that a
 * later screen forgets, and the failure is a person at one plant reading another plant's books.
 *
 * It narrows the company scope; it never widens it. Company isolation is untouched underneath.
 */
class FactoryAccessScope implements Scope
{
    /**
     * @param  string  $column  The factory column on the model -- `id` for Factory itself.
     * @param  bool  $allowShared  Rows with no factory are company-wide (a sequence, a policy
     *                             default). They belong to nobody in particular, so they stay
     *                             visible rather than vanishing for a restricted person.
     * @param  string|null  $departmentColumn  A department column to confine by as well.
     */
    public function __construct(
        private readonly string $column = 'factory_id',
        private readonly bool $allowShared = false,
        private readonly ?string $departmentColumn = null,
    ) {}

    public function apply(Builder $builder, Model $model): void
    {
        $restriction = app(FactoryAccess::class)->restrictionFor();

        if ($restriction === null) {
            return;
        }

        $table = $model->getTable();
        $column = "{$table}.{$this->column}";
        $factoryIds = $restriction['factories'];

        $builder->where(function (Builder $q) use ($column, $factoryIds, $restriction, $table) {
            $q->whereIn($column, $factoryIds);

            if ($this->allowShared) {
                $q->orWhereNull($column);
            }

            // Department-level confinement: inside a factory the person holds only some
            // departments of, rows must belong to one of them. A row with no department at all
            // is a factory-level record and is not hidden by it.
            if ($this->departmentColumn !== null) {
                $deptColumn = "{$table}.{$this->departmentColumn}";

                $q->where(function (Builder $inner) use ($column, $deptColumn, $restriction) {
                    foreach ($restriction['departments'] as $factoryId => $deptIds) {
                        if ($deptIds === null) {
                            continue;
                        }

                        // For this factory the person holds only these departments; keep the
                        // others out, but keep rows with no department.
                        $inner->where(function (Builder $one) use ($column, $deptColumn, $factoryId, $deptIds) {
                            $one->where($column, '!=', $factoryId)
                                ->orWhereNull($deptColumn)
                                ->orWhereIn($deptColumn, $deptIds);
                        });
                    }
                });
            }
        });
    }
}
