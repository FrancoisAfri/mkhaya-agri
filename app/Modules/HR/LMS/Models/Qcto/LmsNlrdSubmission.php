<?php

namespace App\Modules\HR\LMS\Models\Qcto;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use App\Shared\Traits\KeepsDatabaseDefaults;

class LmsNlrdSubmission extends Model
{
    use SoftDeletes, LogsActivity, KeepsDatabaseDefaults;

    protected $table = 'lms_nlrd_submissions';

    protected $fillable = [
        'submission_type', 'period_start', 'period_end',
        'seta_id', 'sdf_id',
        'submission_date', 'reference_number', 'status',
        'records_count', 'file_path', 'error_log',
        'submitted_by', 'acknowledged_at',
    ];

    protected $casts = [
        'period_start'    => 'date',
        'period_end'      => 'date',
        'submission_date' => 'date',
        'acknowledged_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn($m) => $m->uuid ??= (string) Str::uuid());
    }

    public function seta()
    {
        return $this->belongsTo(LmsSeta::class, 'seta_id');
    }

    public function sdf()
    {
        return $this->belongsTo(LmsSdf::class, 'sdf_id');
    }

    public function submittedBy()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('LMS NLRD Submission')
            ->logOnly(['submission_type', 'status', 'records_count', 'reference_number', 'submission_date'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => ucfirst($e) . " NLRD submission #{$this->id} ({$this->submission_type}) — status: {$this->status}.");
    }
}
