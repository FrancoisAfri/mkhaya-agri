<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Where an operation happens, and the reason capacity planning is possible at all: a work centre
 * carries a rate and a cost per hour. Both are nullable until somebody rates the centre -- a NULL
 * cost is honest, where 0.00 would quietly cost production at nothing.
 *
 * Machines are assets rather than a list of their own, so the serial number, the warranty and the
 * maintenance history stay in one place (MFG-001.03).
 */
class WorkCentre extends Model
{
    use BelongsToCompany, HasPublicUuid, LogsActivity, SoftDeletes;

    /**
     * Confined to the factories the signed-in person is assigned to (MFG-001.09). Company
     * isolation underneath is untouched; this only narrows it.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('factory_access', new \App\Modules\Operations\Manufacturing\Scopes\FactoryAccessScope('factory_id', false, 'department_id'));
    }

    protected $table = 'mfg_work_centres';

    protected $fillable = [
        'uuid', 'company_id', 'factory_id', 'production_line_id', 'department_id',
        'code', 'name', 'capacity_per_hour', 'capacity_uom_id', 'cost_per_hour', 'labour_rate_per_hour',
        'setup_minutes', 'concurrent_jobs', 'is_active',
    ];

    protected $casts = [
        'capacity_per_hour' => 'decimal:4',
        'cost_per_hour' => 'decimal:4',
        'labour_rate_per_hour' => 'decimal:4',
        'setup_minutes' => 'integer',
        'concurrent_jobs' => 'integer',
        'is_active' => 'boolean',
    ];

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class, 'factory_id');
    }

    public function productionLine(): BelongsTo
    {
        return $this->belongsTo(ProductionLine::class, 'production_line_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function workstations(): HasMany
    {
        return $this->hasMany(Workstation::class, 'work_centre_id');
    }

    /** The machines at this centre, from the asset register. */
    public function machines(): BelongsToMany
    {
        return $this->belongsToMany(\App\Modules\Assets\Models\Assets::class, 'mfg_work_centre_machines',
            'work_centre_id', 'asset_id')->withPivot('is_primary')->withTimestamps();
    }

    /** A centre that has been rated can be planned against; one that has not cannot. */
    public function isRated(): bool
    {
        return $this->capacity_per_hour !== null && $this->cost_per_hour !== null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'name', 'capacity_per_hour', 'cost_per_hour', 'setup_minutes',
                'concurrent_jobs', 'is_active'])
            ->logOnlyDirty()->useLogName('manufacturing');
    }
}
