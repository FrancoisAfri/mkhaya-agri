<?php

namespace App\Modules\HR\LMS\Models\Qcto;

use App\Modules\HR\LMS\Models\LmsCourse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use App\Shared\Traits\KeepsDatabaseDefaults;

class LmsLearnership extends Model
{
    use SoftDeletes, LogsActivity, KeepsDatabaseDefaults;

    protected $table = 'lms_learnerships';

    protected $fillable = [
        'title', 'saqa_id', 'seta_id', 'aqp_id', 'nqf_level', 'total_credits',
        'duration_months', 'learnership_type', 'ofo_code', 'seta_programme_code',
        'k_course_id', 'p_course_id', 'we_hours_required', 'we_credits',
        'stipend_per_month', 'stipend_currency',
        'is_active', 'description', 'created_by',
    ];

    protected $casts = [
        'total_credits'    => 'decimal:1',
        'we_hours_required'=> 'decimal:1',
        'we_credits'       => 'decimal:1',
        'stipend_per_month'=> 'decimal:2',
        'is_active'        => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(fn($m) => $m->uuid ??= (string) Str::uuid());
    }

    public function seta()
    {
        return $this->belongsTo(LmsSeta::class, 'seta_id');
    }

    public function aqp()
    {
        return $this->belongsTo(LmsAqp::class, 'aqp_id');
    }

    public function eisaSchedules()
    {
        return $this->hasMany(LmsEisaSchedule::class, 'learnership_id');
    }

    public function knowledgeCourse()
    {
        return $this->belongsTo(LmsCourse::class, 'k_course_id');
    }

    public function practicalCourse()
    {
        return $this->belongsTo(LmsCourse::class, 'p_course_id');
    }

    public function enrolments()
    {
        return $this->hasMany(LmsLearnershipEnrolment::class, 'learnership_id');
    }

    public function activeEnrolments()
    {
        return $this->enrolments()->whereIn('status', ['registered', 'active']);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Sum of K-course credits + P-course credits + WE credits.
     * QCTO requires all three component credits to equal total_credits.
     */
    public function getTotalComponentCredits(): float
    {
        $this->loadMissing(['knowledgeCourse', 'practicalCourse']);
        return (float) ($this->knowledgeCourse?->credits ?? 0)
             + (float) ($this->practicalCourse?->credits ?? 0)
             + (float) ($this->we_credits ?? 0);
    }

    /**
     * Returns true when K+P+WE component credits don't match the registered total_credits.
     * Only flags when both component courses are set and total_credits is populated.
     */
    public function hasCreditMismatch(): bool
    {
        if (!$this->total_credits || !$this->k_course_id || !$this->p_course_id) {
            return false;
        }
        return abs($this->getTotalComponentCredits() - (float) $this->total_credits) > 0.1;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('LMS Learnership')
            ->logOnly(['title', 'saqa_id', 'seta_id', 'nqf_level', 'learnership_type', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => ucfirst($e) . " learnership \"{$this->title}\".");
    }
}
