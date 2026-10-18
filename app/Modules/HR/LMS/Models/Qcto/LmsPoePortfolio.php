<?php

namespace App\Modules\HR\LMS\Models\Qcto;

use App\Core\User\Models\User;
use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\LMS\Models\LmsCourse;
use App\Modules\HR\LMS\Models\LmsEnrollment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class LmsPoePortfolio extends Model
{
    use SoftDeletes, LogsActivity;

    protected $table = 'lms_poe_portfolios';

    protected $fillable = [
        'user_id', 'hr_person_id', 'course_id', 'enrollment_id', 'learnership_enrolment_id',
        'title', 'status',
        'assessor_id', 'moderator_id',
        'submitted_at', 'assessed_at', 'moderated_at',
        'assessment_decision', 'moderation_decision',
        'assessor_feedback', 'moderator_feedback',
        'learner_declared', 'declared_at', 'created_by',
    ];

    protected $casts = [
        'submitted_at'    => 'datetime',
        'assessed_at'     => 'datetime',
        'moderated_at'    => 'datetime',
        'declared_at'     => 'datetime',
        'learner_declared'=> 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(fn($m) => $m->uuid ??= (string) Str::uuid());
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

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

    public function learnershipEnrolment()
    {
        return $this->belongsTo(LmsLearnershipEnrolment::class, 'learnership_enrolment_id');
    }

    public function assessor()
    {
        return $this->belongsTo(LmsRegisteredPerson::class, 'assessor_id');
    }

    public function moderator()
    {
        return $this->belongsTo(LmsRegisteredPerson::class, 'moderator_id');
    }

    public function sections()
    {
        return $this->hasMany(LmsPoeSection::class, 'poe_portfolio_id')->orderBy('sort_order');
    }

    public function isCompetent(): bool
    {
        return $this->assessment_decision === 'competent';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('LMS PoE Portfolio')
            ->logOnly(['title', 'status', 'assessor_id', 'moderator_id', 'assessment_decision', 'moderation_decision', 'learner_declared', 'submitted_at', 'assessed_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => ucfirst($e) . " PoE portfolio \"{$this->title}\" — status: {$this->status}.");
    }
}
