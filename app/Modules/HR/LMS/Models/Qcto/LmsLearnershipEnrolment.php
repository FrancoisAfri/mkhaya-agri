<?php

namespace App\Modules\HR\LMS\Models\Qcto;

use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\LMS\Models\LmsEnrollment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class LmsLearnershipEnrolment extends Model
{
    use SoftDeletes, LogsActivity;

    protected $table = 'lms_learnership_enrolments';

    protected $fillable = [
        'learnership_id', 'hr_person_id',
        'seta_learner_id', 'seta_registration_number',
        'start_date', 'end_date', 'status',
        'k_enrollment_id', 'p_enrollment_id',
        'we_hours_logged', 'we_logbook_complete',
        'k_completed_at', 'p_completed_at', 'we_completed_at',
        'final_assessment_date', 'final_score', 'passed',
        'certificate_number', 'certificate_issued_at',
        'stipend_amount', 'notes', 'created_by',
    ];

    protected $casts = [
        'start_date'             => 'date',
        'end_date'               => 'date',
        'k_completed_at'         => 'datetime',
        'p_completed_at'         => 'datetime',
        'we_completed_at'        => 'datetime',
        'final_assessment_date'  => 'date',
        'certificate_issued_at'  => 'date',
        'we_hours_logged'        => 'decimal:1',
        'final_score'            => 'decimal:2',
        'stipend_amount'         => 'decimal:2',
        'we_logbook_complete'    => 'boolean',
        'passed'                 => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(fn($m) => $m->uuid ??= (string) Str::uuid());
    }

    public function learnership()
    {
        return $this->belongsTo(LmsLearnership::class, 'learnership_id');
    }

    public function hrPerson()
    {
        return $this->belongsTo(HRPerson::class, 'hr_person_id');
    }

    public function kEnrollment()
    {
        return $this->belongsTo(LmsEnrollment::class, 'k_enrollment_id');
    }

    public function pEnrollment()
    {
        return $this->belongsTo(LmsEnrollment::class, 'p_enrollment_id');
    }

    public function logbookEntries()
    {
        return $this->hasMany(LmsLearnershipLogbook::class, 'learnership_enrolment_id');
    }

    public function poePortfolio()
    {
        return $this->hasOne(LmsPoePortfolio::class, 'learnership_enrolment_id');
    }

    public function isAllComponentsComplete(): bool
    {
        return $this->k_completed_at
            && $this->p_completed_at
            && $this->we_logbook_complete;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('LMS Learnership Enrolment')
            ->logOnly(['status', 'start_date', 'end_date', 'we_hours_logged', 'passed', 'certificate_number', 'final_score'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => ucfirst($e) . " learnership enrolment #{$this->id} — status: {$this->status}.");
    }
}
