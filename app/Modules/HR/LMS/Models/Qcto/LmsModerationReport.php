<?php

namespace App\Modules\HR\LMS\Models\Qcto;

use App\Core\User\Models\User;
use App\Modules\HR\LMS\Models\LmsCourse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use App\Shared\Traits\KeepsDatabaseDefaults;

class LmsModerationReport extends Model
{
    use SoftDeletes, LogsActivity, KeepsDatabaseDefaults;

    protected $table = 'lms_moderation_reports';

    protected $fillable = [
        'course_id', 'moderation_type', 'moderator_id', 'assessor_id',
        'moderation_date', 'total_assessed', 'sampled_count', 'sample_pct',
        'agreed_count', 'disagreed_count', 'agreement_rate',
        'findings', 'recommendations', 'action_required', 'corrective_actions',
        'status', 'seta_submission_reference', 'seta_submitted_at',
        'seta_acknowledged_at', 'seta_acknowledgement_reference', 'acknowledged_by',
        'created_by',
    ];

    protected $casts = [
        'moderation_date'        => 'date',
        'seta_submitted_at'      => 'date',
        'seta_acknowledged_at'   => 'datetime',
        'sample_pct'             => 'decimal:2',
        'agreement_rate'         => 'decimal:2',
        'action_required'        => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(fn($m) => $m->uuid ??= (string) Str::uuid());
    }

    public function course()
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function moderator()
    {
        return $this->belongsTo(LmsRegisteredPerson::class, 'moderator_id');
    }

    public function assessor()
    {
        return $this->belongsTo(LmsRegisteredPerson::class, 'assessor_id');
    }

    public function acknowledgedBy()
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function isAcknowledged(): bool
    {
        return !is_null($this->seta_acknowledged_at);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('LMS Moderation Report')
            ->logOnly(['moderation_type', 'moderation_date', 'agreement_rate', 'action_required', 'status', 'seta_submission_reference'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => ucfirst($e) . " moderation report #{$this->id} — status: {$this->status}, agreement: {$this->agreement_rate}%.");
    }
}
