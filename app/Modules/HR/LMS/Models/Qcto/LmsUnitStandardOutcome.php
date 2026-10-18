<?php

namespace App\Modules\HR\LMS\Models\Qcto;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class LmsUnitStandardOutcome extends Model
{
    use LogsActivity;

    protected $table = 'lms_unit_standard_outcomes';

    protected $fillable = [
        'unit_standard_id', 'outcome_code', 'outcome_title',
        'performance_criteria', 'sort_order',
    ];

    protected $casts = ['sort_order' => 'integer'];

    public function unitStandard()
    {
        return $this->belongsTo(LmsUnitStandard::class, 'unit_standard_id');
    }

    public function poeSections()
    {
        return $this->hasMany(LmsPoeSection::class, 'unit_standard_outcome_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('LMS Unit Standard Outcome')
            ->logOnly(['outcome_code', 'outcome_title', 'sort_order'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => ucfirst($e) . " outcome \"{$this->outcome_code}: {$this->outcome_title}\" on unit standard #{$this->unit_standard_id}.");
    }
}
