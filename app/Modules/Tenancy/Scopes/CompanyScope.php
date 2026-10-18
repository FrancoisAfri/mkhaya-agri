<?php

namespace App\Modules\Tenancy\Scopes;

use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts queries on tenant-owned models to the active company. Inactive when
 * no company is set (console/seeders/platform admin) — those see all rows.
 *
 * ── Shared rows ─────────────────────────────────────────────────────────────
 *
 * A model may declare `sharesGlobalRecords()` to mean that a null company_id is a row belonging to
 * everybody rather than to nobody — a system-wide library that every tenant reads, alongside the
 * rows each tenant owns. Those models are filtered to "mine or shared" instead of "mine".
 *
 * Default is false, so every existing model keeps the strict behaviour. Opting in is only safe
 * where the matching row-level security policy also carries a null allowance; without it Postgres
 * hides the shared rows again and the library looks installed but empty.
 */
class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $current = app(CurrentCompany::class);
        if (! $current->isSet()) {
            return;
        }

        $column = $model->getTable().'.'.$model->getCompanyColumn();

        if ($model::sharesGlobalRecords()) {
            $builder->where(fn ($q) => $q->where($column, $current->id())->orWhereNull($column));

            return;
        }

        $builder->where($column, $current->id());
    }
}
