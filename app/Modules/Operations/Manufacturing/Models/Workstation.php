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

/** A position within a work centre. The finest place an operation can be booked to. */
class Workstation extends Model
{
    use BelongsToCompany, HasPublicUuid, LogsActivity, SoftDeletes;

    /** A workstation is visible exactly when its work centre is, which carries the factory scope. */
    protected static function booted(): void
    {
        static::addGlobalScope('visible_work_centre', fn ($query) => $query->whereHas('workCentre'));
    }

    protected $table = 'mfg_workstations';

    protected $fillable = ['uuid', 'company_id', 'work_centre_id', 'code', 'name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function workCentre(): BelongsTo
    {
        return $this->belongsTo(WorkCentre::class, 'work_centre_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['code', 'name', 'is_active'])
            ->logOnlyDirty()->useLogName('manufacturing');
    }
}
