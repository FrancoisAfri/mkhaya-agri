<?php

namespace App\Modules\HR\LMS\Models\Qcto;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class LmsAqp extends Model
{
    use SoftDeletes, LogsActivity;

    protected $table = 'lms_aqps';

    protected $fillable = [
        'name', 'acronym', 'seta_id', 'registration_number',
        'website', 'contact_email', 'contact_phone',
        'is_active', 'notes',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function seta()
    {
        return $this->belongsTo(LmsSeta::class, 'seta_id');
    }

    public function learnerships()
    {
        return $this->hasMany(LmsLearnership::class, 'aqp_id');
    }

    public function eisaSchedules()
    {
        return $this->hasMany(LmsEisaSchedule::class, 'aqp_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('LMS AQP')
            ->logOnly(['name', 'acronym', 'registration_number', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => ucfirst($e) . " AQP \"{$this->acronym}\" ({$this->name}).");
    }
}
