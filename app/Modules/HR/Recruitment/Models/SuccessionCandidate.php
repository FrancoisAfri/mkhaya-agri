<?php

namespace App\Modules\HR\Recruitment\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SuccessionCandidate extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'succession_candidates';

    protected $fillable = [
        'candidate_id',
        'job_id',
        'readiness_level',
        'notes',
        'nominated_by',
    ];

    // Relationships

    public function candidate()
    {
        return $this->belongsTo(Candidate::class);
    }

    public function job()
    {
        return $this->belongsTo(Job::class);
    }

    public function nominatedBy()
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'nominated_by');
    }

    // Activity log

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('SuccessionCandidate')
            ->logOnly(['readiness_level', 'notes', 'nominated_by'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(function (string $event) {
                $actor = \Illuminate\Support\Facades\Auth::user()?->name ?? 'System';
                return "{$actor} {$event} succession candidate #{$this->id} for job #{$this->job_id}.";
            });
    }
}
