<?php

namespace App\Modules\HR\Recruitment\Models;

use App\Modules\HR\Recruitment\Enums\ApplicationStatus;
use Database\Factories\Recruitment\JobFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Job extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $table = 'recruitment_jobs';

    protected static function newFactory(): Factory
    {
        return JobFactory::new();
    }

    protected $fillable = [
        'title',
        'reference_number',
        'company_unit_id',
        'location',
        'employment_type',
        'description',
        'requirements',
        'responsibilities',
        'salary_min',
        'salary_max',
        'currency',
        'is_active',
        'posted_at',
        'closes_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'salary_min'  => 'decimal:2',
        'salary_max'  => 'decimal:2',
        'is_active'   => 'boolean',
        'posted_at'   => 'datetime',
        'closes_at'   => 'datetime',
    ];

    // Relationships

    public function pipelineStages()
    {
        return $this->hasMany(PipelineStage::class)->orderBy('order_index');
    }

    public function applications()
    {
        return $this->hasMany(Application::class);
    }

    public function activeApplications()
    {
        return $this->hasMany(Application::class)
            ->whereNotIn('status', [
                ApplicationStatus::Rejected->value,
                ApplicationStatus::Withdrawn->value,
            ]);
    }

    public function shortlists()
    {
        return $this->hasMany(Shortlist::class);
    }

    public function successionCandidates(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SuccessionCandidate::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'updated_by');
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(fn($q) => $q->whereNull('closes_at')->orWhere('closes_at', '>', now()));
    }

    // Activity log

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('RecruitmentJob')
            ->logOnly(['title', 'is_active', 'posted_at', 'closes_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $event) => $this->describeEvent($event));
    }

    private function describeEvent(string $event): string
    {
        $actor = Auth::user()?->name ?? 'System';
        return match($event) {
            'created' => "{$actor} created job posting \"{$this->title}\".",
            'updated' => "{$actor} updated job posting \"{$this->title}\".",
            'deleted' => "{$actor} deleted job posting \"{$this->title}\".",
            default   => "{$actor} triggered \"{$event}\" on job posting #{$this->id}.",
        };
    }
}
