<?php

namespace App\Modules\HR\LMS\Models\Qcto;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class LmsSeta extends Model
{
    use LogsActivity;
    protected $table = 'lms_setas';

    protected $fillable = [
        'name', 'acronym', 'description', 'website',
        'levy_code', 'contact_email', 'contact_phone', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function unitStandards()
    {
        return $this->hasMany(LmsUnitStandard::class, 'seta_id');
    }

    public function sdfs()
    {
        return $this->hasMany(LmsSdf::class, 'seta_id');
    }

    public function learnerships()
    {
        return $this->hasMany(LmsLearnership::class, 'seta_id');
    }

    public function wspPlans()
    {
        return $this->hasMany(LmsWspPlan::class, 'seta_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('LMS SETA')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => ucfirst($e) . " SETA \"{$this->acronym}\".");
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
