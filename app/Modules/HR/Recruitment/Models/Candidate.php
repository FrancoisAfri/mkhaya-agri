<?php

namespace App\Modules\HR\Recruitment\Models;

use App\Shared\Casts\EncryptedPhi;
use App\Shared\Traits\HasPhiAudit;
use App\Shared\Traits\IsDataSubject;
use Database\Factories\Recruitment\CandidateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Candidate extends Model
{
    use HasFactory, SoftDeletes, LogsActivity, HasPhiAudit, IsDataSubject;

    // A candidate's qualifications and identity documents, filed in the repository rather than only
    // against the application, so they survive the application being closed.
    use \App\Modules\Operations\DocumentManagement\Concerns\HasDocuments;

    // Retention window starts from soft-delete date (when no longer active candidate)
    protected int    $retentionYears = 5;
    protected string $retentionFrom  = 'deleted_at';

    protected array $phiFields = ['id_number'];

    protected $table = 'recruitment_candidates';

    protected static function newFactory(): Factory
    {
        return CandidateFactory::new();
    }

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'id_number',
        'nationality',
        'gender',
        'date_of_birth',
        'address',
        'linkedin_url',
        'is_duplicate_of',
        // NOT: uuid (auto-generated), ai_summary, fit_score_calculated_at,
        //      ai_consent_at, ai_consent_ip (server-set), cv_path, cv_mime_type,
        //      cv_verified_at (set by upload job), purge_at (set by retention policy)
    ];

    protected $casts = [
        'id_number'              => EncryptedPhi::class,
        'date_of_birth'          => 'date',
        'cv_verified_at'         => 'datetime',
        'ai_consent_at'          => 'datetime',
        'fit_score_calculated_at'=> 'datetime',
        'purge_at'               => 'datetime',
    ];

    protected $hidden = [
        'id_number',
        'date_of_birth',
        'ai_consent_ip',
        'cv_path',
    ];

    // Auto-generate UUID on creation — used in candidate-portal routes to prevent IDOR
    protected static function booted(): void
    {
        static::creating(function (Candidate $candidate) {
            $candidate->uuid = (string) Str::uuid();
        });
    }

    // Relationships

    public function documents()
    {
        return $this->hasMany(CandidateDocument::class);
    }

    public function skills()
    {
        return $this->hasMany(CandidateSkill::class);
    }

    public function experience()
    {
        return $this->hasMany(CandidateExperience::class)->orderByDesc('start_date');
    }

    public function qualifications()
    {
        return $this->hasMany(CandidateQualification::class);
    }

    public function certifications()
    {
        return $this->hasMany(CandidateCertification::class);
    }

    public function applications()
    {
        return $this->hasMany(Application::class);
    }

    public function aiProfile()
    {
        return $this->hasOne(AiProfile::class);
    }

    public function duplicate()
    {
        return $this->belongsTo(Candidate::class, 'is_duplicate_of');
    }

    public function mobilityMatches()
    {
        return $this->hasMany(InternalMobilityMatch::class);
    }

    // Helpers

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public function hasAiConsent(): bool
    {
        return $this->ai_consent_at !== null;
    }

    // Activity log

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Candidate')
            ->logOnly(['first_name', 'last_name', 'email', 'phone'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $event) => $this->describeEvent($event));
    }

    private function describeEvent(string $event): string
    {
        $actor = \Illuminate\Support\Facades\Auth::user()?->name ?? 'System';
        return match($event) {
            'created' => "{$actor} registered candidate \"{$this->full_name}\".",
            'updated' => "{$actor} updated candidate \"{$this->full_name}\" (#{$this->id}).",
            'deleted' => "{$actor} removed candidate \"{$this->full_name}\" (#{$this->id}).",
            default   => "{$actor} triggered \"{$event}\" on candidate #{$this->id}.",
        };
    }
}
