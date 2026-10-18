<?php

namespace App\Modules\HR\LMS\Models\Qcto;

use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\LMS\Models\LmsCourse;
use App\Modules\HR\LMS\Models\LmsEnrollment;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class LmsBbeeTrainingSpend extends Model
{
    use LogsActivity;
    protected $table = 'lms_bbee_training_spend';

    protected $fillable = [
        'spend_year', 'hr_person_id', 'course_id', 'learnership_id', 'enrollment_id',
        'race', 'gender', 'has_disability', 'is_sa_citizen',
        'training_type', 'cost', 'currency',
        'nqf_level', 'credits',
        'is_accredited', 'is_learnership', 'is_bursary', 'is_absorbed',
        'training_date', 'result',
    ];

    protected $casts = [
        'cost'           => 'decimal:2',
        'credits'        => 'decimal:1',
        'training_date'  => 'date',
        'has_disability' => 'boolean',
        'is_sa_citizen'  => 'boolean',
        'is_accredited'  => 'boolean',
        'is_learnership' => 'boolean',
        'is_bursary'     => 'boolean',
        'is_absorbed'    => 'boolean',
    ];

    public function hrPerson()
    {
        return $this->belongsTo(HRPerson::class, 'hr_person_id');
    }

    public function course()
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function enrollment()
    {
        return $this->belongsTo(LmsEnrollment::class, 'enrollment_id');
    }

    public function learnership()
    {
        return $this->belongsTo(LmsLearnership::class, 'learnership_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('LMS B-BBEE Spend')
            ->logOnly(['spend_year', 'hr_person_id', 'cost', 'training_type', 'race', 'gender', 'is_learnership'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => ucfirst($e) . " B-BBEE training spend record for person #{$this->hr_person_id} ({$this->spend_year}).");
    }

    // Maps SETA race code to a human-readable display bucket for scorecard UI
    public function getDemographicBucket(): string
    {
        if ($this->has_disability) return 'disabled';

        $gender = strtolower($this->gender ?? 'male') === 'female' ? 'female' : 'male';

        return match(strtoupper($this->race ?? '')) {
            'A'     => 'african_'  . $gender,
            'C'     => 'coloured_' . $gender,
            'I'     => 'indian_'   . $gender,
            'W'     => 'white_'    . $gender,
            default => 'other',
        };
    }

    public function isBlack(): bool
    {
        return in_array(strtoupper($this->race ?? ''), ['A', 'C', 'I']);
    }

    public function isBlackFemale(): bool
    {
        return $this->isBlack() && strtolower($this->gender ?? '') === 'female';
    }
}
