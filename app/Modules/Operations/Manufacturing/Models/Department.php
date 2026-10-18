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
 * A production department inside a factory. Optionally mapped to an HR unit of the same name, but
 * not the same thing: "Extrusion" is a place work happens and may draw staff from several units,
 * or from a pool that belongs to none.
 */
class Department extends Model
{
    use BelongsToCompany, HasPublicUuid, LogsActivity, SoftDeletes;

    /**
     * Confined to the factories the signed-in person is assigned to (MFG-001.09). Company
     * isolation underneath is untouched; this only narrows it.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('factory_access', new \App\Modules\Operations\Manufacturing\Scopes\FactoryAccessScope('factory_id', false, 'id'));
    }

    protected $table = 'mfg_departments';

    protected $fillable = [
        'uuid', 'company_id', 'factory_id', 'company_unit_id', 'code', 'name',
        'manager_id', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class, 'factory_id');
    }

    public function workCentres(): HasMany
    {
        return $this->hasMany(WorkCentre::class, 'department_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['code', 'name', 'manager_id', 'is_active'])
            ->logOnlyDirty()->useLogName('manufacturing');
    }
}
