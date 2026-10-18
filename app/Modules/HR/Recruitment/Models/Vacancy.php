<?php

namespace App\Modules\HR\Recruitment\Models;

use App\Modules\HR\Recruitment\Enums\VacancyStatus;
use App\Modules\HR\Recruitment\Enums\VacancyType;
use App\Modules\HR\Recruitment\Enums\VacancyVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Vacancy extends Model
{
    use SoftDeletes;

    protected $table = 'recruitment_vacancies';

    protected $fillable = [
        'uuid',
        'vacancy_number',
        'requisition_id',
        'title',
        'department',
        'position_id',
        'job_type',
        'headcount',
        'job_description',
        'required_qualifications',
        'required_skills',
        'salary_band_min',
        'salary_band_max',
        'status',
        'published_at',
        'closed_at',
        'expires_at',
        'created_by',
        // Sprint 2
        'visibility',
        'internal_deadline',
        'application_token',
        'published_internally_at',
        'published_externally_at',
        'transitioned_to_external_at',
    ];

    protected $casts = [
        'status'                  => VacancyStatus::class,
        'job_type'                => VacancyType::class,
        'visibility'              => VacancyVisibility::class,
        'required_skills'         => 'array',
        'published_at'            => 'datetime',
        'closed_at'               => 'datetime',
        'expires_at'              => 'date',
        'internal_deadline'       => 'date',
        'published_internally_at' => 'datetime',
        'published_externally_at' => 'datetime',
        'transitioned_to_external_at' => 'datetime',
        'salary_band_min'         => 'decimal:2',
        'salary_band_max'         => 'decimal:2',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid              ??= (string) Str::uuid();
            $model->application_token ??= bin2hex(random_bytes(32));
        });
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class, 'requisition_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'created_by');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopePublished($query)
    {
        return $query->where('status', VacancyStatus::Published->value);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [
            VacancyStatus::Published->value,
            VacancyStatus::InternalOnly->value,
            VacancyStatus::External->value,
        ]);
    }

    public function scopeInternal($query)
    {
        return $query->where('status', VacancyStatus::InternalOnly->value);
    }

    /**
     * Vacancies visible to internal employees.
     */
    public function scopeVisibleInternally($query)
    {
        return $query
            ->whereIn('status', [
                VacancyStatus::InternalOnly->value,
                VacancyStatus::Published->value,
                VacancyStatus::External->value,
            ])
            ->whereIn('visibility', [
                VacancyVisibility::InternalOnly->value,
                VacancyVisibility::InternalFirstThenExternal->value,
            ]);
    }

    /**
     * Vacancies visible to external candidates via public portal.
     */
    public function scopeVisibleExternally($query)
    {
        return $query
            ->whereIn('status', [
                VacancyStatus::External->value,
                VacancyStatus::Published->value,
            ])
            ->whereIn('visibility', [
                VacancyVisibility::ExternalOnly->value,
                VacancyVisibility::InternalFirstThenExternal->value,
            ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Generate a unique vacancy number in the format VAC-YYYY-NNNN.
     */
    public static function generateVacancyNumber(): string
    {
        $year  = date('Y');
        $count = static::withTrashed()->count() + 1;

        return 'VAC-' . $year . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Returns the public application URL for external candidates, or null if no token is set.
     */
    public function publicApplicationUrl(): ?string
    {
        $externalStatuses = [VacancyStatus::External, VacancyStatus::Published];

        if (!$this->application_token || !in_array($this->status, $externalStatuses)) {
            return null;
        }

        return route('recruitment.public.apply', $this->application_token);
    }

    /**
     * Whether this vacancy is internal-only (employees cannot see it externally).
     */
    public function isInternalOnly(): bool
    {
        return $this->visibility === VacancyVisibility::InternalOnly;
    }

    /**
     * Whether this vacancy is accessible by external candidates (either directly or after transition).
     */
    public function isExternal(): bool
    {
        return in_array($this->visibility, [
            VacancyVisibility::ExternalOnly,
            VacancyVisibility::InternalFirstThenExternal,
        ]);
    }
}
