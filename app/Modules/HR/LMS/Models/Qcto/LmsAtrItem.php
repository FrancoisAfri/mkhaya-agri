<?php

namespace App\Modules\HR\LMS\Models\Qcto;

use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\LMS\Models\LmsCourse;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class LmsAtrItem extends Model
{
    use LogsActivity;
    protected $table = 'lms_atr_items';

    protected $fillable = [
        'atr_report_id', 'hr_person_id', 'course_id', 'learnership_id',
        'training_type', 'training_provider', 'provider_accreditation_number',
        'start_date', 'end_date',
        'nqf_level', 'credits', 'cost_per_person', 'result',
        'certificate_number', 'ofo_code', 'saqa_id',
        'race', 'gender', 'has_disability',
    ];

    protected $casts = [
        'start_date'     => 'date',
        'end_date'       => 'date',
        'credits'        => 'decimal:1',
        'cost_per_person'=> 'decimal:2',
        'has_disability' => 'boolean',
    ];

    public function atrReport()
    {
        return $this->belongsTo(LmsAtrReport::class, 'atr_report_id');
    }

    public function hrPerson()
    {
        return $this->belongsTo(HRPerson::class, 'hr_person_id');
    }

    public function course()
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function learnership()
    {
        return $this->belongsTo(LmsLearnership::class, 'learnership_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('LMS ATR Item')
            ->logOnly(['training_type', 'nqf_level', 'cost_per_person', 'result', 'race', 'gender', 'has_disability'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => ucfirst($e) . " ATR item for person #{$this->hr_person_id} in report #{$this->atr_report_id}.");
    }

    public function getBbeeCategory(): string
    {
        if ($this->has_disability) return 'disabled';
        $race   = strtoupper($this->race ?? '');
        $gender = strtolower($this->gender ?? '');
        return match(true) {
            $race === 'A' && $gender === 'female' => 'african_female',
            $race === 'A'                         => 'african_male',
            $race === 'C' && $gender === 'female' => 'coloured_female',
            $race === 'C'                         => 'coloured_male',
            $race === 'I' && $gender === 'female' => 'indian_female',
            $race === 'I'                         => 'indian_male',
            $race === 'W' && $gender === 'female' => 'white_female',
            $race === 'W'                         => 'white_male',
            default                               => 'other',
        };
    }
}
