<?php

namespace App\Modules\HR\LMS\Models\Qcto;

use App\Core\Storage\Concerns\StoresFileBytes;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class LmsLearnershipLogbook extends Model
{
    use StoresFileBytes;

    use LogsActivity;

    protected $table = 'lms_learnership_logbook';

    protected $fillable = [
        'learnership_enrolment_id', 'log_date', 'activity_description',
        'hours_logged', 'workplace_coach', 'coach_signed_off', 'signed_off_at',
        'evidence_file_data', 'evidence_file_name', 'evidence_mime_type', 'evidence_size_bytes',
        'learner_reflection',
    ];

    protected $hidden = ['evidence_file_data'];

    protected $casts = [
        'log_date'            => 'date',
        'hours_logged'        => 'decimal:1',
        'coach_signed_off'    => 'boolean',
        'signed_off_at'       => 'datetime',
        'evidence_size_bytes' => 'integer',
    ];

    public function enrolment()
    {
        return $this->belongsTo(LmsLearnershipEnrolment::class, 'learnership_enrolment_id');
    }

    public function hasEvidence(): bool
    {
        return $this->hasBytes() && ! empty($this->evidence_file_name);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('LMS WBL Logbook')
            ->logOnly(['log_date', 'hours_logged', 'workplace_coach', 'coach_signed_off', 'evidence_file_name'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => ucfirst($e) . " logbook entry for enrolment #{$this->learnership_enrolment_id} on {$this->log_date} ({$this->hours_logged}h).");
    }

    protected function fileBlobColumn(): ?string
    {
        return 'evidence_file_data';
    }

    protected function filePathColumn(): string
    {
        return 'evidence_file_path';
    }

    protected function fileFolder(): string
    {
        return 'lms/logbook';
    }
}
