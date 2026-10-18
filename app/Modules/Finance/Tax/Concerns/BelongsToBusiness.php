<?php

namespace App\Modules\Finance\Tax\Concerns;

use App\Modules\Finance\Accounting\Models\FinEntity;
use App\Modules\Finance\Accounting\Services\CurrentEntity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tax record belongs to one business (fin_entities row), as Finance's documents do — but without
 * Finance's session filter.
 *
 * Accounting shows one business at a time because a trial balance is one business's. The tax
 * screens deliberately show every business the company keeps tax for, with the business as a filter
 * (SRS §3.2): a registration added for another business must not vanish from the list because the
 * user's Accounting session points elsewhere. Tenant isolation is unchanged — BelongsToCompany and
 * row-level security still apply; Finance grants no per-user business restriction this would bypass.
 */
trait BelongsToBusiness
{
    public static function bootBelongsToBusiness(): void
    {
        static::creating(function ($model) {
            if ($model->entity_id !== null || ($model->company_id ?? null) === null) {
                return;
            }
            $model->entity_id = app(CurrentEntity::class)->defaultFor((int) $model->company_id)?->id;
        });
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(FinEntity::class, 'entity_id')->withoutGlobalScopes();
    }

    public function scopeForBusiness(Builder $query, ?int $entityId): Builder
    {
        return $entityId ? $query->where($this->getTable() . '.entity_id', $entityId) : $query;
    }
}
