<?php

namespace App\Modules\SupplierManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One pass of the watching, recorded whether or not it found anything.
 *
 * The empty runs are the point. "We monitor our suppliers continuously" is an assertion; a row per
 * pass, with what was asked and what came back, is evidence — and the day somebody asks why a
 * supplier's collapse came as a surprise, the difference between those two is the whole conversation.
 */
class SupplierMonitoringRun extends Model
{
    use BelongsToCompany;

    protected $table = 'supplier_monitoring_runs';

    protected $fillable = [
        'uuid', 'supplier_id', 'scope', 'trigger', 'platforms', 'subjects',
        'sources_queried', 'sources_unavailable', 'mentions_seen', 'findings_new',
        'findings_updated', 'below_confidence_floor', 'alerts_raised',
        'reputation_before', 'reputation_after', 'run_by', 'started_at', 'completed_at', 'error',
    ];

    protected $casts = [
        'platforms' => 'array',
        'subjects' => 'array',
        'reputation_before' => 'decimal:2',
        'reputation_after' => 'decimal:2',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $run) {
            $run->uuid ??= (string) Str::uuid();
            $run->started_at ??= Carbon::now();
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(SupplierFinding::class, 'monitoring_run_id');
    }

    /** Did this run change the picture, or confirm it? Both are worth saying out loud. */
    public function foundSomething(): bool
    {
        return $this->findings_new > 0 || $this->findings_updated > 0;
    }

    public function reputationMovement(): ?float
    {
        return $this->reputation_before === null || $this->reputation_after === null
            ? null
            : round((float) $this->reputation_after - (float) $this->reputation_before, 2);
    }
}
