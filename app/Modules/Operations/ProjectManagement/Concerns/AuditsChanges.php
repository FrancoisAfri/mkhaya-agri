<?php

namespace App\Modules\Operations\ProjectManagement\Concerns;

use App\Modules\Operations\ProjectManagement\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes an audit row for every create, update and delete on the model (PM-1024).
 *
 * Runs inside the same transaction as the change, so an audit row cannot survive a rollback of the
 * thing it claims happened — and, equally, a committed change cannot be missing from the trail.
 *
 * Not applied to EvidenceEvent (the ledger is already its own immutable record, and mirroring it
 * would double every write on the highest-volume table in the module) or to AuditEntry itself,
 * which would recurse.
 */
trait AuditsChanges
{
    public static function bootAuditsChanges(): void
    {
        static::created(function (Model $model) {
            app(AuditLogger::class)->record($model, 'created', null, $model->getAttributes());
        });

        static::updated(function (Model $model) {
            $changes = $model->getChanges();

            // Only the fields that moved, and their previous values — a diff, not a snapshot.
            $before = array_intersect_key($model->getOriginal(), $changes);

            if ($changes === []) {
                return;
            }

            app(AuditLogger::class)->record($model, 'updated', $before, $changes);
        });

        static::deleted(function (Model $model) {
            $soft = method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting();

            app(AuditLogger::class)->record(
                $model,
                $soft ? 'deleted' : 'force_deleted',
                $model->getOriginal(),
                null
            );
        });

        // restored() only fires on models using SoftDeletes; harmless on those that do not.
        if (method_exists(static::class, 'restored')) {
            static::restored(function (Model $model) {
                app(AuditLogger::class)->record($model, 'restored', null, $model->getAttributes());
            });
        }
    }
}
