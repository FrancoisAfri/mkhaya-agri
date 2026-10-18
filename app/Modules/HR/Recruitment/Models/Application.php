<?php

namespace App\Modules\HR\Recruitment\Models;

use App\Modules\HR\Recruitment\Enums\ApplicationStatus;
use Database\Factories\Recruitment\ApplicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Application extends Model
{
    use SoftDeletes;
    use HasFactory, LogsActivity;

    protected $table = 'recruitment_applications';

    protected static function newFactory(): Factory
    {
        return ApplicationFactory::new();
    }

    protected $fillable = [
        'candidate_id',
        'job_id',
        'vacancy_id',
        'pipeline_stage_id',
        'recruiter_id',
        'source',
        'cover_letter',
        'applied_at',
        // NOT: status — transitions are enforced by MoveApplicationAction only.
        //      rejected_at, rejected_reason, withdrawn_at, stage_moved_at — server-set.
    ];

    protected $casts = [
        'status'         => ApplicationStatus::class,
        'applied_at'     => 'datetime',
        'stage_moved_at' => 'datetime',
        'rejected_at'    => 'datetime',
        'withdrawn_at'   => 'datetime',
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

    public function vacancy()
    {
        return $this->belongsTo(Vacancy::class);
    }

    public function pipelineStage()
    {
        return $this->belongsTo(PipelineStage::class);
    }

    public function recruiter()
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'recruiter_id');
    }

    public function fitScore()
    {
        return $this->hasOne(FitScore::class);
    }

    public function interviews()
    {
        return $this->hasMany(Interview::class)->orderBy('scheduled_at');
    }

    public function shortlistItems()
    {
        return $this->hasMany(ShortlistItem::class);
    }

    public function references()
    {
        return $this->hasMany(CandidateReference::class)->orderBy('created_at');
    }

    public function messages()
    {
        return $this->hasMany(CandidateMessage::class)->orderBy('sent_at');
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', [
            ApplicationStatus::Rejected->value,
            ApplicationStatus::Withdrawn->value,
        ]);
    }

    public function scopeForJob($query, int $jobId)
    {
        return $query->where('job_id', $jobId);
    }

    // Activity log

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Application')
            ->logOnly(['status', 'pipeline_stage_id', 'recruiter_id', 'rejected_reason'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $event) => $this->describeEvent($event));
    }

    private function describeEvent(string $event): string
    {
        $actor  = Auth::user()?->name ?? 'System';
        $status = $this->status?->label() ?? 'unknown';
        return match($event) {
            'created' => "{$actor} submitted application #{$this->id} for job #{$this->job_id}.",
            'updated' => "{$actor} updated application #{$this->id} — status: {$status}.",
            'deleted' => "{$actor} deleted application #{$this->id}.",
            default   => "{$actor} triggered \"{$event}\" on application #{$this->id}.",
        };
    }
}
