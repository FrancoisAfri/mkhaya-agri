<?php

namespace App\Modules\HR\PreBoarding\Models;

use App\Core\User\Models\User;
use App\Modules\HR\Contracts\Models\Contract;
use App\Modules\HR\Onboarding\Models\OnboardingDocument;
use App\Modules\HR\Onboarding\Models\OnboardingPlan;
use App\Modules\HR\Onboarding\Models\OnboardingProvisioningTask;
use App\Modules\HR\Onboarding\Models\ProbationPeriod;
use App\Modules\HR\PreBoarding\Enums\HrPersonStatus;
use App\Modules\HR\Recruitment\Models\Candidate;
use Database\Factories\PreBoarding\HrPersonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class HrPerson extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'hr_persons';

    protected $fillable = [
        'candidate_id',
        // Identity of a person who did not come through recruitment. Null for an ATS hire, whose
        // name lives on the candidate — see fullName().
        'first_name',
        'surname',
        'email',
        'phone',
        'id_number',
        'source',
        'bulk_import_id',
        'contract_id',
        'employee_number',
        'started_on',
        'created_by',
        'buddy_user_id',
        'department',
        'job_title',
        // Tenancy. Not fillable meant every create() passing company_id had it silently dropped —
        // including the bulk import — leaving rows that belong to no tenant and which the RLS
        // policy (`company_id = session`) then hides from everyone, including their owner.
        'company_id',
        'portal_token',
        'portal_token_expires_at',
        'portal_last_seen_at',
    ];

    protected $casts = [
        'portal_token_expires_at' => 'datetime',
        'portal_last_seen_at' => 'datetime',
        'status'     => HrPersonStatus::class,
        'started_on' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    protected static function newFactory(): HrPersonFactory
    {
        return HrPersonFactory::new();
    }


    /**
     * The person's name, wherever it happens to live.
     *
     * An ATS hire has no name of their own — it is the candidate's, and copying it here would
     * create a second version that can drift. Someone imported in bulk has no candidate at all.
     * Callers should not have to know which kind they are holding.
     */
    public function fullName(): string
    {
        $own = trim(($this->first_name ?? '') . ' ' . ($this->surname ?? ''));

        return $own !== '' ? $own : (string) ($this->candidate?->full_name ?? '');
    }

    public function emailAddress(): ?string
    {
        return $this->email ?: $this->candidate?->email;
    }

    /** Did this person come through recruitment, or straight from an import? */
    public function isStandalone(): bool
    {
        return $this->candidate_id === null;
    }


    /**
     * Issue (or re-issue) the new hire's portal link.
     *
     * Re-issuing replaces the old token rather than adding one, so a link that has been forwarded
     * to the wrong person can be revoked by simply issuing a new one.
     */
    public function issuePortalToken(int $daysValid = 45): string
    {
        $token = \Illuminate\Support\Str::random(48);

        $this->forceFill([
            'portal_token' => $token,
            'portal_token_expires_at' => now()->addDays($daysValid),
        ])->save();

        return $token;
    }

    public function portalTokenIsValid(): bool
    {
        return $this->portal_token !== null
            && $this->portal_token_expires_at !== null
            && $this->portal_token_expires_at->isFuture();
    }

    public function revokePortalToken(): void
    {
        $this->forceFill(['portal_token' => null, 'portal_token_expires_at' => null])->save();
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function preBoardingPlans(): HasMany
    {
        return $this->hasMany(PreBoardingPlan::class);
    }

    public function activePlan(): HasOne
    {
        return $this->hasOne(PreBoardingPlan::class)->whereIn('status', ['pending', 'in_progress'])->latest();
    }

    public function onboardingPlans(): HasMany
    {
        return $this->hasMany(OnboardingPlan::class);
    }

    public function onboardingDocuments(): HasMany
    {
        return $this->hasMany(OnboardingDocument::class);
    }

    public function provisioningTasks(): HasMany
    {
        return $this->hasMany(OnboardingProvisioningTask::class);
    }

    public function buddy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buddy_user_id');
    }

    public function probationPeriod(): HasOne
    {
        return $this->hasOne(ProbationPeriod::class)->latest();
    }
}
