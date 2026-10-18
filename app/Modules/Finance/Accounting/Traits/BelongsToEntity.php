<?php

namespace App\Modules\Finance\Accounting\Traits;

use App\Modules\Finance\Accounting\Models\FinEntity;
use App\Modules\Finance\Accounting\Scopes\EntityScope;
use App\Modules\Finance\Accounting\Services\CurrentEntity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a finance model as belonging to one trading entity.
 *
 * Adds the global scope that limits reads to the active entity, and stamps `entity_id` on create
 * so a document cannot be written without saying which business issued it.
 *
 * Applied alongside BelongsToCompany, never instead of it: the tenant still owns the row, and the
 * entity says which of that tenant's businesses it belongs to.
 */
trait BelongsToEntity
{
    public static function bootBelongsToEntity(): void
    {
        static::addGlobalScope(new EntityScope);

        static::creating(function ($model) {
            $column = $model->getEntityColumn();

            // Already set wins: an import, a backfill or a transfer between entities is explicit
            // about where the row belongs, and must not be overruled by whatever the session says.
            if ($model->{$column} !== null) {
                return;
            }

            $entities = app(CurrentEntity::class);

            $model->{$column} = $entities->id();

            /*
             * Still nothing, but the row says which tenant it is for.
             *
             * The line above asks the request which business it is in, and a request is not the
             * only thing that writes: a console command, an import, a job, a test fixture. Any of
             * them can name the company explicitly and have no business in context — and since
             * Phase 4 a tenant's row without one is refused by the database, so this would fail at
             * the insert rather than quietly producing an invisible row.
             *
             * So the fall-back follows the row rather than the request: the company it says it
             * belongs to, and that company's default business. A row that names no company is left
             * alone — it belongs to nobody, which is a state the constraint allows and which the
             * system's own accounts depend on.
             */
            if ($model->{$column} === null && ($model->company_id ?? null) !== null) {
                $model->{$column} = $entities->defaultFor((int) $model->company_id)?->id;
            }
        });
    }

    public function getEntityColumn(): string
    {
        return 'entity_id';
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(FinEntity::class, $this->getEntityColumn());
    }

    /**
     * Read across every entity of this tenant.
     *
     * For group reporting and nothing else. The tenant boundary still applies — this lifts the
     * entity filter, never the company one.
     */
    public function scopeWithoutEntityScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(EntityScope::class);
    }
}
