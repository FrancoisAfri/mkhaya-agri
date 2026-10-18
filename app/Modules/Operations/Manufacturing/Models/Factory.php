<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A manufacturing site. Keeps its own timezone, currency and measurement system (MFG-001.08),
 * because a group running plants in two countries schedules and costs each one locally.
 *
 * Not a warehouse. It may point at one for stock defaults, but a factory has a calendar and a
 * capacity and a warehouse has neither.
 */
class Factory extends Model
{
    use BelongsToCompany, HasPublicUuid, LogsActivity, SoftDeletes;

    /**
     * Confined to the factories the signed-in person is assigned to (MFG-001.09). Company
     * isolation underneath is untouched; this only narrows it.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('factory_access', new \App\Modules\Operations\Manufacturing\Scopes\FactoryAccessScope('id'));
    }

    protected $table = 'mfg_factories';

    protected $fillable = [
        'uuid', 'company_id', 'code', 'name', 'description', 'address', 'timezone',
        'currency', 'tax_code', 'tax_rate', 'tax_registration_number', 'measurement_system',
        'default_warehouse_id', 'is_active', 'created_by',
    ];

    protected $casts = ['is_active' => 'boolean', 'tax_rate' => 'decimal:4'];

    public const MEASUREMENT_SYSTEMS = ['METRIC', 'IMPERIAL'];

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class, 'factory_id');
    }

    public function productionLines(): HasMany
    {
        return $this->hasMany(ProductionLine::class, 'factory_id');
    }

    public function workCentres(): HasMany
    {
        return $this->hasMany(WorkCentre::class, 'factory_id');
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class, 'factory_id');
    }

    public function shutdowns(): HasMany
    {
        return $this->hasMany(Shutdown::class, 'factory_id');
    }

    /** MFG-001.10 — configuration changes are on the record. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'name', 'timezone', 'currency', 'tax_code', 'tax_rate',
                'tax_registration_number', 'measurement_system',
                'default_warehouse_id', 'is_active'])
            ->logOnlyDirty()
            ->useLogName('manufacturing');
    }
}
