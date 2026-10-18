<?php

namespace App\Modules\HR\Recruitment\Models;

use App\Modules\HR\Recruitment\Enums\ScreeningRiskLevel;
use App\Modules\HR\Recruitment\Enums\ScreeningStatus;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * An AI screening of a candidate's PUBLIC professional online presence. Holds the
 * category scores, reputation-risk level, AI summary and provenance of what was
 * discovered. Everything here is decision-support for a human recruiter — never an
 * automated hire/reject verdict.
 */
class CandidateScreening extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'candidate_screenings';

    protected $fillable = [
        'company_id',
        'candidate_id',
        'application_id',
        'status',
        'overall_score',
        'reputation_score',
        'professionalism_score',
        'communication_score',
        'leadership_score',
        'community_score',
        'risk_level',
        'ai_summary',
        'recommendation',
        'possible_misidentification',
        'discovered_profiles',
        'error',
        'analyzed_at',
        // NOT fillable: uuid (auto-generated)
    ];

    protected $casts = [
        'status'                     => ScreeningStatus::class,
        'risk_level'                 => ScreeningRiskLevel::class,
        'discovered_profiles'        => 'array',
        'possible_misidentification' => 'bool',
        'overall_score'              => 'decimal:2',
        'reputation_score'           => 'decimal:2',
        'professionalism_score'      => 'decimal:2',
        'communication_score'        => 'decimal:2',
        'leadership_score'           => 'decimal:2',
        'community_score'            => 'decimal:2',
        'analyzed_at'                => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (CandidateScreening $screening) {
            if (empty($screening->uuid)) {
                $screening->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    // ── Relations ──────────────────────────────────────────────────────────────

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(CandidateScreeningFinding::class, 'screening_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(CandidateScreeningQuestion::class, 'screening_id');
    }
}
